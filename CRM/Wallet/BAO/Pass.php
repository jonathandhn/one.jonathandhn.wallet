<?php

use CRM_Wallet_ExtensionUtil as E;
use Civi\Wallet\LocaleResolver;
use Civi\Wallet\ProviderConfigurationException;
use Civi\Wallet\UpdateOutbox;
use PKPass\PKPass;

class CRM_Wallet_BAO_Pass extends CRM_Core_DAO {

  private static function isChannelEnabled($type) {
    $map = [
      'apple' => 'wallet_channel_apple_enabled',
      'google' => 'wallet_channel_google_enabled',
    ];

    return (bool) Civi::settings()->get($map[$type] ?? '');
  }

  private static function isEntityTableEnabled($entityTable) {
    $map = [
      'civicrm_participant' => 'wallet_type_participant_enabled',
      'civicrm_contact' => 'wallet_type_membership_enabled',
      'civicrm_membership' => 'wallet_type_membership_enabled',
      'civicrm_donor' => 'wallet_type_donor_enabled',
    ];

    return (bool) Civi::settings()->get($map[$entityTable] ?? '');
  }

  /**
   * Create an Apple Wallet Pass for a participant or membership.
   */
  public static function createApplePass($entityTable, $entityId) {
    if (!self::isChannelEnabled('apple') || !self::isEntityTableEnabled($entityTable)) {
      throw new Exception("Apple Wallet channel or pass type disabled.");
    }

    if (!self::sourceEntityExists((string) $entityTable, (int) $entityId)) {
      return self::createVoidedApplePass((string) $entityTable, (int) $entityId);
    }

    $localeScope = LocaleResolver::swapForEntity($entityTable, (int) $entityId);
    try {
      if ($entityTable === 'civicrm_participant') {
        return CRM_Wallet_PassType_Participant::createApplePass($entityId);
      }
      elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
        return CRM_Wallet_PassType_Membership::createApplePass($entityId);
      }
      elseif ($entityTable === 'civicrm_donor') {
        return CRM_Wallet_PassType_Donor::createApplePass($entityId);
      }
      throw new Exception("Unsupported entity table for Apple Wallet: $entityTable");
    }
    finally {
      $localeScope?->cleanup();
    }
  }

  public static function createGooglePass($entityTable, $entityId) {
    if (!self::isChannelEnabled('google') || !self::isEntityTableEnabled($entityTable)) {
      throw new Exception("Google Wallet channel or pass type disabled.");
    }

    $localeScope = LocaleResolver::swapForEntity($entityTable, (int) $entityId);
    try {
      if ($entityTable === 'civicrm_participant') {
        return CRM_Wallet_PassType_Participant::createGooglePass($entityId);
      }
      elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
        return CRM_Wallet_PassType_Membership::createGooglePass($entityId);
      }
      elseif ($entityTable === 'civicrm_donor') {
        return CRM_Wallet_PassType_Donor::createGooglePass($entityId);
      }
      throw new Exception("Unsupported entity table for Google Wallet: $entityTable");
    }
    finally {
      $localeScope?->cleanup();
    }
  }

  public static function updatePasses($entity, $id) {
    UpdateOutbox::captureMutation('edit', (string) $entity, (int) $id);
  }

  public static function processPassUpdate(CRM_Queue_TaskContext $ctx, $entityTable, $entityId) {
    self::processOutboxTarget('google', (string) $entityTable, (int) $entityId, 'refresh');
    self::processOutboxTarget('apple', (string) $entityTable, (int) $entityId, 'refresh');
    return TRUE;
  }

  public static function processOutboxTarget(
    string $platform,
    string $entityTable,
    int $entityId,
    string $operation
  ): void {
    if (!self::isChannelEnabled($platform) || !self::isEntityTableEnabled($entityTable)) {
      return;
    }
    if ($operation === 'refresh' && !self::sourceEntityExists($entityTable, $entityId)) {
      $operation = 'retire';
    }
    if ($platform === 'apple' && !self::passExists($entityTable, $entityId, $platform)) {
      return;
    }

    if ($platform === 'google') {
      if ($operation === 'retire') {
        self::retireGooglePass($entityTable, $entityId);
      }
      else {
        self::createGooglePass($entityTable, $entityId);
      }
      return;
    }

    if ($platform !== 'apple') {
      throw new InvalidArgumentException("Unsupported Wallet platform: {$platform}");
    }

    if ($operation === 'retire') {
      self::createVoidedApplePass($entityTable, $entityId);
    }
    else {
      // Validate that the next pass can be generated before announcing it.
      self::createApplePass($entityTable, $entityId);
    }
    self::publishAppleUpdate($entityTable, $entityId);
  }

  public static function createVoidedApplePass(string $entityTable, int $entityId): string {
    $dao = CRM_Core_DAO::executeQuery("
      SELECT serial_number, authentication_token
      FROM civicrm_wallet_pass
      WHERE entity_table = %1 AND entity_id = %2 AND type = 'apple'
    ", [
      1 => [$entityTable, 'String'],
      2 => [$entityId, 'Integer'],
    ]);
    if (!$dao->fetch()) {
      throw new RuntimeException('The Apple Wallet pass to retire does not exist.');
    }

    $certPath = (string) Civi::settings()->get('wallet_apple_cert');
    $certPassword = (string) Civi::settings()->get('wallet_apple_cert_password');
    if ($certPath === '' || !is_file($certPath)) {
      throw new RuntimeException('Apple Wallet certificate not configured or not found.');
    }

    $orgName = (string) (Civi::settings()->get('wallet_org_name') ?: E::ts('Organization'));
    $pass = new PKPass($certPath, $certPassword);
    $passData = [
      'description' => E::ts('Cancelled Wallet pass'),
      'formatVersion' => 1,
      'organizationName' => $orgName,
      'passTypeIdentifier' => (string) Civi::settings()->get('wallet_apple_pass_type_id'),
      'serialNumber' => (string) $dao->serial_number,
      'authenticationToken' => (string) $dao->authentication_token,
      'webServiceURL' => CRM_Wallet_Utils_Url::webrootScript(),
      'teamIdentifier' => (string) Civi::settings()->get('wallet_apple_team_id'),
      'backgroundColor' => 'rgb(120,120,120)',
      'foregroundColor' => 'rgb(255,255,255)',
      'labelColor' => 'rgb(255,255,255)',
      'voided' => TRUE,
      'expirationDate' => gmdate('c'),
    ];
    $field = [
      [
        'key' => 'wallet-status',
        'label' => E::ts('Status'),
        'value' => E::ts('Cancelled'),
        'changeMessage' => E::ts('This pass was cancelled.'),
      ],
    ];
    if ($entityTable === 'civicrm_participant') {
      $passData['eventTicket'] = ['primaryFields' => $field];
    }
    elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
      $passData['storeCard'] = ['primaryFields' => $field];
    }
    else {
      $passData['generic'] = ['primaryFields' => $field];
    }

    $pass->setData($passData);
    foreach (['icon.png', 'icon@2x.png', 'icon@3x.png', 'logo.png', 'logo@2x.png', 'logo@3x.png'] as $asset) {
      $assetPath = CRM_Wallet_Utils_ImageVariants::brandingPath($asset);
      if (is_file($assetPath)) {
        $pass->addFile($assetPath, $asset);
      }
    }
    return $pass->create();
  }

  private static function publishAppleUpdate(string $entityTable, int $entityId): void {
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_wallet_pass
      SET last_updated = FROM_UNIXTIME(GREATEST(UNIX_TIMESTAMP(last_updated) + 1, UNIX_TIMESTAMP(NOW())))
      WHERE entity_table = %1 AND entity_id = %2 AND type = 'apple'
    ", [
      1 => [$entityTable, 'String'],
      2 => [$entityId, 'Integer'],
    ]);

    $dao = CRM_Core_DAO::executeQuery("
      SELECT d.id, d.push_token
      FROM civicrm_wallet_device d
      JOIN civicrm_wallet_pass p ON d.pass_id = p.id
      WHERE p.entity_table = %1 AND p.entity_id = %2 AND p.type = 'apple'
    ", [
      1 => [$entityTable, 'String'],
      2 => [$entityId, 'Integer'],
    ]);
    while ($dao->fetch()) {
      if (!self::sendApplePushNotification((string) $dao->push_token)) {
        CRM_Core_DAO::executeQuery('DELETE FROM civicrm_wallet_device WHERE id = %1', [
          1 => [(int) $dao->id, 'Integer'],
        ]);
      }
    }
  }

  private static function retireGooglePass(string $entityTable, int $entityId): void {
    $issuerId = (string) Civi::settings()->get('wallet_google_issuer_id');
    $credentialsFile = (string) Civi::settings()->get('wallet_google_credentials');
    if ($issuerId === '' || $credentialsFile === '' || !is_file($credentialsFile)) {
      throw new RuntimeException('Google Wallet credentials or Issuer ID not configured.');
    }

    $client = new \Google\Client();
    $client->setAuthConfig($credentialsFile);
    $client->addScope('https://www.googleapis.com/auth/wallet_object.issuer');
    $service = new \Google\Service\Walletobjects($client);
    $state = ['state' => 'INACTIVE'];
    try {
      if ($entityTable === 'civicrm_participant') {
        $objectId = $issuerId . '.Participant-' . $entityId;
        $service->eventticketobject->patch($objectId, new \Google\Service\Walletobjects\EventTicketObject($state));
      }
      elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
        $objectId = $issuerId . '.Contact-' . $entityId;
        $service->loyaltyobject->patch($objectId, new \Google\Service\Walletobjects\LoyaltyObject($state));
      }
      elseif ($entityTable === 'civicrm_donor') {
        $objectId = $issuerId . '.donor_obj_' . $entityId;
        $service->genericobject->patch($objectId, new \Google\Service\Walletobjects\GenericObject($state));
      }
    }
    catch (\Google\Service\Exception $exception) {
      if ($exception->getCode() !== 404) {
        throw $exception;
      }
    }
  }

  private static function sourceEntityExists(string $entityTable, int $entityId): bool {
    if ($entityTable === 'civicrm_participant') {
      return (bool) CRM_Core_DAO::singleValueQuery(
        'SELECT COUNT(*) FROM civicrm_participant WHERE id = %1',
        [1 => [$entityId, 'Integer']],
      );
    }
    if ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
      return (bool) CRM_Core_DAO::singleValueQuery(
        'SELECT COUNT(*) FROM civicrm_membership WHERE contact_id = %1',
        [1 => [$entityId, 'Integer']],
      );
    }
    if ($entityTable === 'civicrm_donor') {
      return (bool) CRM_Core_DAO::singleValueQuery(
        'SELECT COUNT(*) FROM civicrm_contact WHERE id = %1',
        [1 => [$entityId, 'Integer']],
      );
    }
    return FALSE;
  }

  private static function passExists(string $entityTable, int $entityId, string $platform): bool {
    return (bool) CRM_Core_DAO::singleValueQuery("
      SELECT COUNT(*)
      FROM civicrm_wallet_pass
      WHERE entity_table = %1 AND entity_id = %2 AND type = %3
    ", [
      1 => [$entityTable, 'String'],
      2 => [$entityId, 'Integer'],
      3 => [$platform, 'String'],
    ]);
  }

  /**
   * Notify one Apple device.
   *
   * @return bool TRUE when delivered, FALSE when the token is permanently invalid.
   */
  public static function sendApplePushNotification($pushToken): bool {
    $certPath = Civi::settings()->get('wallet_apple_cert');
    $certPass = Civi::settings()->get('wallet_apple_cert_password');

    if (!$certPath || !file_exists($certPath)) {
      throw new ProviderConfigurationException('Apple Push certificate not found or not configured.');
    }
    self::assertApplePushCertificateUsable((string) $certPath, (string) $certPass);

    if (!defined('CURL_HTTP_VERSION_2_0')) {
      define('CURL_HTTP_VERSION_2_0', 3);
    }

    $endpoints = [
      'production' => "https://api.push.apple.com/3/device/$pushToken",
      'sandbox' => "https://api.development.push.apple.com/3/device/$pushToken",
    ];

    foreach ($endpoints as $env => $url) {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_PORT, 443);
      curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
      curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
      curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
      curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

      $response = curl_exec($ch);
      $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);

      if ($httpcode === 200) {
        unset($ch);
        return TRUE;
      }

      $responseJson = is_string($response) ? json_decode($response, TRUE) : NULL;
      $reason = $responseJson['reason'] ?? 'Unknown';
      unset($ch);

      if ($httpcode === 400 && $reason === 'BadDeviceToken' && $env === 'production') {
        continue;
      }

      if (in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], TRUE)) {
        return FALSE;
      }
      if ($response === FALSE) {
        throw new RuntimeException("Apple Push transport error ({$env}): {$curlError}");
      }
      throw new RuntimeException("Apple Push HTTP error ({$env}): {$httpcode} {$reason}");
    }

    return FALSE;
  }

  private static function assertApplePushCertificateUsable(string $certPath, string $password): void {
    $pkcs12 = file_get_contents($certPath);
    $certificates = [];
    if (!is_string($pkcs12) || !openssl_pkcs12_read($pkcs12, $certificates, $password)) {
      throw new ProviderConfigurationException('Apple Push certificate cannot be opened with the configured password.');
    }
    $certificate = $certificates['cert'] ?? NULL;
    $parsed = is_string($certificate) ? openssl_x509_parse($certificate) : FALSE;
    if (!is_array($parsed)) {
      throw new ProviderConfigurationException('Apple Push certificate metadata cannot be read.');
    }
    $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
    $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
    $now = time();
    if ($validFrom > $now) {
      throw new ProviderConfigurationException('Apple Push certificate is not valid yet.');
    }
    if ($validTo <= $now) {
      $date = $validTo > 0 ? gmdate('Y-m-d', $validTo) : E::ts('unknown date');
      throw new ProviderConfigurationException(E::ts('Apple Push certificate expired on %1.', [1 => $date]));
    }
  }

}
