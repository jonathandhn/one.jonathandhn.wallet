<?php

namespace Civi\Wallet;

use RuntimeException;

/**
 * Durable two-stage outbox for Wallet mutations and provider updates.
 */
final class UpdateOutbox {

  public const MAX_ATTEMPTS = 8;

  private static bool $schemaReady = FALSE;

  public static function ensureSchema(): void {
    if (self::$schemaReady) {
      return;
    }

    if (!\CRM_Core_DAO::checkTableExists('civicrm_wallet_outbox_seq')) {
      \CRM_Core_DAO::executeQuery(<<<'SQL'
        CREATE TABLE `civicrm_wallet_outbox_seq` (
          `seq_key` VARCHAR(32) NOT NULL PRIMARY KEY,
          `val` BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }
    \CRM_Core_DAO::executeQuery(<<<'SQL'
      INSERT INTO `civicrm_wallet_outbox_seq` (`seq_key`, `val`)
      VALUES ('global', 0)
      ON DUPLICATE KEY UPDATE `val` = `val`
      SQL);

    if (!\CRM_Core_DAO::checkTableExists('civicrm_wallet_event_outbox')) {
      \CRM_Core_DAO::executeQuery(<<<'SQL'
        CREATE TABLE `civicrm_wallet_event_outbox` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `dedupe_key` VARCHAR(191) NOT NULL,
          `entity_name` VARCHAR(64) NOT NULL,
          `entity_id` BIGINT UNSIGNED NOT NULL,
          `operation` VARCHAR(16) NOT NULL,
          `payload` LONGTEXT NOT NULL,
          `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
          `lease_token` VARCHAR(64) NULL,
          `lease_until` DATETIME NULL,
          `created_at` DATETIME NOT NULL,
          `updated_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_wallet_event` (`dedupe_key`),
          KEY `idx_wallet_event_claim` (`lease_until`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    if (!\CRM_Core_DAO::checkTableExists('civicrm_wallet_update_outbox')) {
      \CRM_Core_DAO::executeQuery(<<<'SQL'
        CREATE TABLE `civicrm_wallet_update_outbox` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `entity_table` VARCHAR(64) NOT NULL,
          `entity_id` BIGINT UNSIGNED NOT NULL,
          `platform` VARCHAR(16) NOT NULL,
          `operation` VARCHAR(16) NOT NULL DEFAULT 'refresh',
          `generation` BIGINT UNSIGNED NOT NULL,
          `lease_token` VARCHAR(64) NULL,
          `available_at` DATETIME NOT NULL,
          `lease_until` DATETIME NULL,
          `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
          `last_error` TEXT NULL,
          `created_at` DATETIME NOT NULL,
          `updated_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_wallet_target` (`entity_table`, `entity_id`, `platform`),
          KEY `idx_wallet_target_claim` (`lease_until`, `available_at`, `attempt_count`),
          KEY `idx_wallet_target_generation` (`generation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    self::$schemaReady = TRUE;
  }

  public static function captureMutation(string $operation, string $entityName, int $entityId): void {
    if ($entityId <= 0 || !in_array($operation, ['create', 'edit', 'delete'], TRUE)) {
      return;
    }
    if (!in_array($entityName, ['Event', 'Participant', 'Membership', 'Contact', 'Donor'], TRUE)) {
      return;
    }

    self::ensureSchema();
    $payload = self::snapshot($entityName, $entityId);
    $dedupeKey = strtolower($entityName) . ':' . $entityId;
    $json = json_encode($payload);
    if (!is_string($json)) {
      throw new RuntimeException('Unable to encode the Wallet mutation payload: ' . json_last_error_msg());
    }
    $now = date('Y-m-d H:i:s');

    $key = \CRM_Core_DAO::escapeString($dedupeKey);
    $entity = \CRM_Core_DAO::escapeString($entityName);
    $op = \CRM_Core_DAO::escapeString($operation);
    $encoded = \CRM_Core_DAO::escapeString($json);
    \CRM_Core_DAO::executeQuery("
      INSERT INTO `civicrm_wallet_event_outbox`
        (`dedupe_key`, `entity_name`, `entity_id`, `operation`, `payload`, `revision`, `lease_token`, `lease_until`, `created_at`, `updated_at`)
      VALUES
        ('{$key}', '{$entity}', {$entityId}, '{$op}', '{$encoded}', 1, NULL, NULL, '{$now}', '{$now}')
      ON DUPLICATE KEY UPDATE
        `entity_name` = VALUES(`entity_name`),
        `entity_id` = VALUES(`entity_id`),
        `operation` = VALUES(`operation`),
        `payload` = VALUES(`payload`),
        `revision` = `revision` + 1,
        `updated_at` = VALUES(`updated_at`)
    ");
  }

  /**
   * Expand raw mutations, then process provider-specific targets.
   *
   * @return array{expanded:int,processed:int,failed:int}
   */
  public static function process(int $limit = 50): array {
    self::ensureSchema();
    $limit = max(1, min(500, $limit));
    $stats = [
      'expanded' => self::expandEvents($limit * 4),
      'processed' => 0,
      'failed' => 0,
    ];

    foreach (self::claimTargets($limit) as $target) {
      try {
        \CRM_Wallet_BAO_Pass::processOutboxTarget(
          (string) $target['platform'],
          (string) $target['entity_table'],
          (int) $target['entity_id'],
          (string) $target['operation'],
        );
        if (self::ackTarget($target)) {
          $stats['processed']++;
        }
      }
      catch (\Throwable $exception) {
        self::releaseTarget($target, $exception->getMessage());
        $stats['failed']++;
        \Civi::log()->warning('CiviWallet outbox target failed', [
          'platform' => $target['platform'],
          'entity_table' => $target['entity_table'],
          'entity_id' => $target['entity_id'],
          'exception' => $exception,
        ]);
      }
    }
    return $stats;
  }

  public static function deadLetterCount(): int {
    self::ensureSchema();
    return (int) (\CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_wallet_update_outbox WHERE attempt_count >= %1',
      [1 => [self::MAX_ATTEMPTS, 'Integer']],
    ) ?? 0);
  }

  /**
   * @return array<string,mixed>
   */
  private static function snapshot(string $entityName, int $entityId): array {
    if ($entityName === 'Participant') {
      $dao = \CRM_Core_DAO::executeQuery(
        'SELECT event_id, contact_id FROM civicrm_participant WHERE id = %1',
        [1 => [$entityId, 'Integer']],
      );
      return $dao->fetch() ? [
        'event_id' => (int) $dao->event_id,
        'contact_id' => (int) $dao->contact_id,
      ] : [];
    }

    if ($entityName === 'Membership') {
      $contactId = \CRM_Core_DAO::singleValueQuery(
        'SELECT contact_id FROM civicrm_membership WHERE id = %1',
        [1 => [$entityId, 'Integer']],
      );
      return $contactId ? ['contact_id' => (int) $contactId] : [];
    }

    if ($entityName === 'Event') {
      $participantIds = [];
      $dao = \CRM_Core_DAO::executeQuery(
        'SELECT id AS entity_id FROM civicrm_participant WHERE event_id = %1',
        [1 => [$entityId, 'Integer']],
      );
      while ($dao->fetch()) {
        $participantIds[] = (int) $dao->entity_id;
      }
      return ['participant_ids' => $participantIds];
    }

    return [];
  }

  private static function expandEvents(int $limit): int {
    $expanded = 0;
    foreach (self::claimEvents($limit) as $event) {
      try {
        $payload = json_decode((string) $event['payload'], TRUE, 32, JSON_THROW_ON_ERROR);
        foreach (MutationTargetResolver::resolve(
          (string) $event['entity_name'],
          (int) $event['entity_id'],
          (string) $event['operation'],
          is_array($payload) ? $payload : [],
        ) as $target) {
          foreach (['apple', 'google'] as $platform) {
            self::enqueueTarget(
              (string) $target['entity_table'],
              (int) $target['entity_id'],
              $platform,
              (string) $target['operation'],
            );
            $expanded++;
          }
        }
        self::ackEvent($event);
      }
      catch (\Throwable $exception) {
        self::releaseEvent($event);
        \Civi::log()->warning('Unable to expand CiviWallet mutation', [
          'entity_name' => $event['entity_name'],
          'entity_id' => $event['entity_id'],
          'exception' => $exception,
        ]);
      }
    }
    return $expanded;
  }

  private static function enqueueTarget(
    string $entityTable,
    int $entityId,
    string $platform,
    string $operation
  ): void {
    if ($entityId <= 0) {
      return;
    }
    $generation = self::nextGeneration();
    $now = date('Y-m-d H:i:s');
    $table = \CRM_Core_DAO::escapeString($entityTable);
    $provider = \CRM_Core_DAO::escapeString($platform);
    $op = \CRM_Core_DAO::escapeString($operation);
    \CRM_Core_DAO::executeQuery("
      INSERT INTO `civicrm_wallet_update_outbox`
        (`entity_table`, `entity_id`, `platform`, `operation`, `generation`, `lease_token`, `available_at`, `lease_until`, `attempt_count`, `last_error`, `created_at`, `updated_at`)
      VALUES
        ('{$table}', {$entityId}, '{$provider}', '{$op}', {$generation}, NULL, '{$now}', NULL, 0, NULL, '{$now}', '{$now}')
      ON DUPLICATE KEY UPDATE
        `operation` = IF(VALUES(`generation`) > `generation`, VALUES(`operation`), `operation`),
        `generation` = GREATEST(`generation`, VALUES(`generation`)),
        `available_at` = IF(VALUES(`generation`) > `generation`, VALUES(`available_at`), `available_at`),
        `attempt_count` = IF(VALUES(`generation`) > `generation`, 0, `attempt_count`),
        `last_error` = IF(VALUES(`generation`) > `generation`, NULL, `last_error`),
        `updated_at` = IF(VALUES(`generation`) > `generation`, VALUES(`updated_at`), `updated_at`)
    ");
  }

  private static function nextGeneration(): int {
    \CRM_Core_DAO::executeQuery("
      UPDATE `civicrm_wallet_outbox_seq`
      SET `val` = LAST_INSERT_ID(`val` + 1)
      WHERE `seq_key` = 'global'
    ");
    $generation = (int) \CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    if ($generation <= 0) {
      throw new RuntimeException('Unable to allocate a Wallet outbox generation.');
    }
    return $generation;
  }

  /**
   * @return list<array<string,mixed>>
   */
  private static function claimEvents(int $limit): array {
    $limit = max(1, min(2000, $limit));
    $token = bin2hex(random_bytes(16));
    $now = date('Y-m-d H:i:s');
    $leaseUntil = date('Y-m-d H:i:s', time() + 120);
    $transaction = new \CRM_Core_Transaction();
    try {
      $ids = [];
      $dao = \CRM_Core_DAO::executeQuery("
        SELECT id
        FROM civicrm_wallet_event_outbox
        WHERE lease_until IS NULL OR lease_until <= '{$now}'
        ORDER BY id ASC
        LIMIT {$limit}
        FOR UPDATE
      ");
      while ($dao->fetch()) {
        $ids[] = (int) $dao->id;
      }
      if ($ids === []) {
        $transaction->commit();
        return [];
      }
      $in = implode(',', $ids);
      \CRM_Core_DAO::executeQuery("
        UPDATE civicrm_wallet_event_outbox
        SET lease_token = '{$token}', lease_until = '{$leaseUntil}'
        WHERE id IN ({$in})
          AND (lease_until IS NULL OR lease_until <= '{$now}')
      ");
      $transaction->commit();

      return self::fetchRows("
        SELECT * FROM civicrm_wallet_event_outbox
        WHERE id IN ({$in}) AND lease_token = '{$token}'
        ORDER BY id ASC
      ");
    }
    catch (\Throwable $exception) {
      $transaction->rollback();
      throw $exception;
    }
  }

  /**
   * @return list<array<string,mixed>>
   */
  private static function claimTargets(int $limit): array {
    $token = bin2hex(random_bytes(16));
    $now = date('Y-m-d H:i:s');
    $leaseUntil = date('Y-m-d H:i:s', time() + 300);
    $transaction = new \CRM_Core_Transaction();
    try {
      $ids = [];
      $dao = \CRM_Core_DAO::executeQuery("
        SELECT id
        FROM civicrm_wallet_update_outbox
        WHERE attempt_count < " . self::MAX_ATTEMPTS . "
          AND available_at <= '{$now}'
          AND (lease_until IS NULL OR lease_until <= '{$now}')
        ORDER BY id ASC
        LIMIT {$limit}
        FOR UPDATE
      ");
      while ($dao->fetch()) {
        $ids[] = (int) $dao->id;
      }
      if ($ids === []) {
        $transaction->commit();
        return [];
      }
      $in = implode(',', $ids);
      \CRM_Core_DAO::executeQuery("
        UPDATE civicrm_wallet_update_outbox
        SET lease_token = '{$token}', lease_until = '{$leaseUntil}'
        WHERE id IN ({$in})
          AND (lease_until IS NULL OR lease_until <= '{$now}')
      ");
      $transaction->commit();

      return self::fetchRows("
        SELECT * FROM civicrm_wallet_update_outbox
        WHERE id IN ({$in}) AND lease_token = '{$token}'
        ORDER BY id ASC
      ");
    }
    catch (\Throwable $exception) {
      $transaction->rollback();
      throw $exception;
    }
  }

  /**
   * @param array<string,mixed> $event
   */
  private static function ackEvent(array $event): void {
    $id = (int) $event['id'];
    $revision = (int) $event['revision'];
    $token = \CRM_Core_DAO::escapeString((string) $event['lease_token']);
    $dao = \CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_wallet_event_outbox
      WHERE id = {$id} AND revision = {$revision} AND lease_token = '{$token}'
    ");
    if ($dao->affectedRows() === 0) {
      self::releaseEvent($event);
    }
  }

  /**
   * @param array<string,mixed> $event
   */
  private static function releaseEvent(array $event): void {
    $id = (int) $event['id'];
    $token = \CRM_Core_DAO::escapeString((string) $event['lease_token']);
    \CRM_Core_DAO::executeQuery("
      UPDATE civicrm_wallet_event_outbox
      SET lease_token = NULL, lease_until = NULL
      WHERE id = {$id} AND lease_token = '{$token}'
    ");
  }

  /**
   * @param array<string,mixed> $target
   */
  private static function ackTarget(array $target): bool {
    $id = (int) $target['id'];
    $generation = (int) $target['generation'];
    $token = \CRM_Core_DAO::escapeString((string) $target['lease_token']);
    $dao = \CRM_Core_DAO::executeQuery("
      DELETE FROM civicrm_wallet_update_outbox
      WHERE id = {$id} AND generation = {$generation} AND lease_token = '{$token}'
    ");
    if ($dao->affectedRows() > 0) {
      return TRUE;
    }
    \CRM_Core_DAO::executeQuery("
      UPDATE civicrm_wallet_update_outbox
      SET lease_token = NULL, lease_until = NULL
      WHERE id = {$id} AND lease_token = '{$token}'
    ");
    return FALSE;
  }

  /**
   * @param array<string,mixed> $target
   * @param string $message Provider failure message.
   */
  private static function releaseTarget(array $target, string $message): void {
    $id = (int) $target['id'];
    $generation = (int) $target['generation'];
    $token = \CRM_Core_DAO::escapeString((string) $target['lease_token']);
    $attempt = (int) $target['attempt_count'] + 1;
    $delay = min(3600, 30 * (2 ** min(7, $attempt - 1)));
    $availableAt = date('Y-m-d H:i:s', time() + $delay);
    $error = \CRM_Core_DAO::escapeString(substr($message, 0, 4000));
    $dao = \CRM_Core_DAO::executeQuery("
      UPDATE civicrm_wallet_update_outbox
      SET attempt_count = {$attempt},
          last_error = '{$error}',
          available_at = '{$availableAt}',
          lease_token = NULL,
          lease_until = NULL,
          updated_at = NOW()
      WHERE id = {$id} AND generation = {$generation} AND lease_token = '{$token}'
    ");
    if ($dao->affectedRows() === 0) {
      \CRM_Core_DAO::executeQuery("
        UPDATE civicrm_wallet_update_outbox
        SET lease_token = NULL, lease_until = NULL
        WHERE id = {$id} AND lease_token = '{$token}'
      ");
    }
  }

  /**
   * @return list<array<string,mixed>>
   */
  private static function fetchRows(string $sql): array {
    $rows = [];
    $dao = \CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $rows[] = $dao->toArray();
    }
    return $rows;
  }

}
