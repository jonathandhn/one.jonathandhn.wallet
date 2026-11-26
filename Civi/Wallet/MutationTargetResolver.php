<?php

namespace Civi\Wallet;

/**
 * Expand one captured CiviCRM mutation into deduplicated Wallet targets.
 */
final class MutationTargetResolver {

  /**
   * @param string $entityName CiviCRM entity name.
   * @param int $entityId CiviCRM entity identifier.
   * @param string $operation Mutation operation.
   * @param array<string,mixed> $payload
   * @return list<array{entity_table:string,entity_id:int,operation:string}>
   */
  public static function resolve(
    string $entityName,
    int $entityId,
    string $operation,
    array $payload = []
  ): array {
    $isDelete = $operation === 'delete';

    if ($entityName === 'Event') {
      $targets = [];
      foreach ((array) ($payload['participant_ids'] ?? []) as $participantId) {
        if ((int) $participantId > 0) {
          $targets[] = [
            'entity_table' => 'civicrm_participant',
            'entity_id' => (int) $participantId,
            'operation' => $isDelete ? 'retire' : 'refresh',
          ];
        }
      }
      return $targets;
    }

    if ($entityName === 'Participant') {
      return [
        [
          'entity_table' => 'civicrm_participant',
          'entity_id' => $entityId,
          'operation' => $isDelete ? 'retire' : 'refresh',
        ],
      ];
    }

    if ($entityName === 'Membership') {
      $contactId = (int) ($payload['contact_id'] ?? 0);
      return $contactId > 0 ? [
        [
          'entity_table' => 'civicrm_contact',
          'entity_id' => $contactId,
          'operation' => 'refresh',
        ],
      ] : [];
    }

    if ($entityName === 'Contact') {
      return [
        [
          'entity_table' => 'civicrm_contact',
          'entity_id' => $entityId,
          'operation' => $isDelete ? 'retire' : 'refresh',
        ],
        [
          'entity_table' => 'civicrm_donor',
          'entity_id' => $entityId,
          'operation' => $isDelete ? 'retire' : 'refresh',
        ],
      ];
    }

    if ($entityName === 'Donor') {
      return [
        [
          'entity_table' => 'civicrm_donor',
          'entity_id' => $entityId,
          'operation' => 'refresh',
        ],
      ];
    }

    return [];
  }

}
