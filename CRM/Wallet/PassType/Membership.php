<?php

use PKPass\PKPass;
use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_PassType_Membership implements CRM_Wallet_PassType_Interface {

  public static function createApplePass($entityId) {
    $contactId = $entityId;
    $contact = \Civi\Api4\Contact::get(FALSE)->addWhere('id', '=', $contactId)->execute()->first();

    // Fetch most recent membership
    $membership = \Civi\Api4\Membership::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addOrderBy('end_date', 'DESC')
      ->setLimit(1)
      ->execute()->first();

    if (!$membership) {
      throw new Exception("No membership found for Contact $contactId");
    }
    $membershipId = $membership['id'];

    // Fetch Membership Type
    $membershipType = \Civi\Api4\MembershipType::get(FALSE)->addWhere('id', '=', $membership['membership_type_id'])->execute()->first();

    // Initialize Wallet settings
    $certPath = Civi::settings()->get('wallet_apple_cert');
    $certPass = Civi::settings()->get('wallet_apple_cert_password');
    // Reusing the same cert
    $passTypeIdentifier = Civi::settings()->get('wallet_apple_pass_type_id');
    $teamIdentifier = Civi::settings()->get('wallet_apple_team_id');

    $domain = \Civi\Api4\Domain::get(FALSE)->addWhere('id', '=', CRM_Core_Config::domainID())->execute()->first();
    $orgName = Civi::settings()->get('wallet_org_name') ?: ($domain['name'] ?? E::ts('Organisation'));

    if (!$certPath || !file_exists($certPath)) {
      throw new Exception("Apple Wallet Certificate not configured.");
    }

    $pkcs12 = file_get_contents($certPath);
    $certs = [];
    if (!openssl_pkcs12_read($pkcs12, $certs, $certPass)) {
      throw new Exception("Invalid Apple Wallet certificate or password.");
    }

    $pass = new PKPass($certPath, $certPass);

    $serialNumber = 'c-' . $contactId . '-' . time();

    // Check for existing pass
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_wallet_pass WHERE entity_table = 'civicrm_contact' AND entity_id = %1 AND type = 'apple'", [1 => [$contactId, 'Integer']]);
    if ($dao->fetch()) {
      $serialNumber = $dao->serial_number;
      $authenticationToken = $dao->authentication_token;
    }
    else {
      $authenticationToken = bin2hex(random_bytes(16));
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_wallet_pass (entity_table, entity_id, type, serial_number, authentication_token) VALUES ('civicrm_contact', %1, 'apple', %2, %3)", [
        1 => [$contactId, 'Integer'],
        2 => [$serialNumber, 'String'],
        3 => [$authenticationToken, 'String'],
      ]);
    }

    $isSignedMode = (Civi::settings()->get('wallet_barcode_membership_mode') ?: 'simple') === 'signed';
    $barcodeValue = '';
    if ($isSignedMode) {
      $barcodeValue = civi_wallet_build_signed_contact_barcode($contactId);
    }

    if (empty($barcodeValue)) {
      $barcodeToken = Civi::settings()->get('wallet_barcode_membership') ?: '{contact.id}';
      if (class_exists('\Civi\Token\TokenProcessor')) {
        $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => FALSE]);
        $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
        $tokenProcessor->addRow(['contactId' => $contactId, 'membershipId' => $membershipId]);
        $tokenProcessor->evaluate();
        $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
      }
      else {
        // Fallback
        $barcodeValue = $contactId;
      }
    }

    $entityType = 'Membership';
    $empty = NULL;
    CRM_Utils_Hook::singleton()->invoke(['barcodeValue', 'entityType', 'entityId'], $barcodeValue, $entityType, $membershipId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');

    $qrCodeContent = (string) $barcodeValue;
    $qrCodeAltText = is_numeric($qrCodeContent) ? $qrCodeContent : '';

    // Colors
    $status = \Civi\Api4\MembershipStatus::get(FALSE)->addWhere('id', '=', $membership['status_id'])->execute()->first();
    $isActive = in_array($status['name'], ['New', 'Current', 'Grace']);

    $bgColor = $isActive ? (Civi::settings()->get('wallet_color_membership_active') ?: 'rgb(83,180,89)') : (Civi::settings()->get('wallet_color_membership_expired') ?: 'rgb(160,160,160)');

    $endDateStr = !empty($membership['end_date']) ? date('d M Y', strtotime($membership['end_date'])) : E::ts('À vie');

    // Base URL for redirection
    $apiBaseUrl = rtrim(CRM_Utils_System::baseCMSURL(), '/') . '/civicrm/wallet/api/redirect';
    $renewUrl = $apiBaseUrl . '?action=renew&token=' . $authenticationToken;
    $profileUrl = $apiBaseUrl . '?action=profile&token=' . $authenticationToken;

    // Prepare Pass Data Array using 'storeCard' model
    $passData = [
      'description' => E::ts('Carte d\'adhérent %1', [1 => $orgName]),
      'formatVersion' => 1,
      'organizationName' => $orgName,
      'passTypeIdentifier' => $passTypeIdentifier,
      'serialNumber' => $serialNumber,
      'authenticationToken' => $authenticationToken,
      'webServiceURL' => CRM_Wallet_Utils_Url::webrootScript(),
      'teamIdentifier' => $teamIdentifier,
      'backgroundColor' => $bgColor,
      'foregroundColor' => 'rgb(255,255,255)',
      'labelColor' => 'rgb(255,255,255)',
      'storeCard' => [
        'primaryFields' => [
                    [
                      'key' => 'member-name',
                      'label' => E::ts('Adhérent'),
                      'value' => $contact['display_name'],
                    ],
        ],
        'secondaryFields' => [
                    [
                      'key' => 'membership-type',
                      'label' => E::ts('Type'),
                      'value' => $membershipType['name'],
                    ],
                    [
                      'key' => 'membership-status',
                      'label' => E::ts('Statut'),
                      'value' => $status['label'],
                      'changeMessage' => E::ts('Votre statut d\'adhésion a changé : %@'),
                    ],
        ],
        'auxiliaryFields' => [
                    [
                      'key' => 'end-date',
                      'label' => E::ts("Valide jusqu'au"),
                      'value' => $endDateStr,
                      'changeMessage' => E::ts("Votre adhésion a été prolongée jusqu'au %@"),
                    ],
        ],
        'backFields' => [
                    [
                      'key' => 'renew-link',
                      'label' => E::ts('Renouveler mon adhésion'),
                      'value' => $renewUrl,
                      'attributedValue' => "<a href='$renewUrl'>" . E::ts('Renouveler en un clic') . "</a>",
                    ],
                    [
                      'key' => 'profile-link',
                      'label' => E::ts('Mettre à jour mes coordonnées'),
                      'value' => $profileUrl,
                      'attributedValue' => "<a href='$profileUrl'>" . E::ts('Mettre à jour mon profil') . "</a>",
                    ],
        ],
      ],
      'barcode' => [
        'format' => 'PKBarcodeFormatQR',
        'message' => $qrCodeContent,
        'messageEncoding' => 'iso-8859-1',
        'altText' => $qrCodeAltText,
      ],
    ];

    if (!empty($membership['end_date'])) {
      $passData['expirationDate'] = date('c', strtotime($membership['end_date']));
    }

    $platform = 'apple';
    $entityType = 'Membership';
    $empty = NULL;
    CRM_Utils_Hook::singleton()->invoke(['passData', 'entityType', 'entityId', 'platform'], $passData, $entityType, $membershipId, $platform, $empty, $empty, 'civicrm_wallet_pass_alter');

    // Add Images
    $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
    $imagesPath = $extPath . '/resources/images/';

    $pass->setData($passData);
    if (file_exists($imagesPath . 'icon.png')) {
      $pass->addFile($imagesPath . 'icon.png');
    }
    if (file_exists($imagesPath . 'icon@2x.png')) {
      $pass->addFile($imagesPath . 'icon@2x.png');
    }
    if (file_exists($imagesPath . 'icon@3x.png')) {
      $pass->addFile($imagesPath . 'icon@3x.png');
    }
    if (file_exists($imagesPath . 'logo.png')) {
      $pass->addFile($imagesPath . 'logo.png');
    }

    return $pass->create();
  }

  public static function createGooglePass($entityId) {
    $contactId = $entityId;
    $contact = \Civi\Api4\Contact::get(FALSE)->addWhere('id', '=', $contactId)->execute()->first();

    // Fetch most recent membership
    $membership = \Civi\Api4\Membership::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addOrderBy('end_date', 'DESC')
      ->setLimit(1)
      ->execute()->first();

    if (!$membership) {
      throw new Exception("No membership found for Contact $contactId");
    }
    $membershipId = $membership['id'];

    $membershipType = \Civi\Api4\MembershipType::get(FALSE)->addWhere('id', '=', $membership['membership_type_id'])->execute()->first();
    $status = \Civi\Api4\MembershipStatus::get(FALSE)->addWhere('id', '=', $membership['status_id'])->execute()->first();

    // Configuration
    $issuerId = Civi::settings()->get('wallet_google_issuer_id');
    $credentialsFile = Civi::settings()->get('wallet_google_credentials');

    $domain = \Civi\Api4\Domain::get(FALSE)->addWhere('id', '=', CRM_Core_Config::domainID())->execute()->first();
    $orgName = Civi::settings()->get('wallet_org_name') ?: ($domain['name'] ?? 'Organisation');
    $websiteUrl = Civi::settings()->get('wallet_website_url');
    $contactUrl = Civi::settings()->get('wallet_contact_url');

    if ($websiteUrl && !preg_match("~^(?:f|ht)tps?://~i", $websiteUrl)) {
      $websiteUrl = "https://" . $websiteUrl;
    }
    if ($contactUrl && !preg_match("~^(?:f|ht)tps?://~i", $contactUrl)) {
      $contactUrl = "https://" . $contactUrl;
    }

    if (!$issuerId || !$credentialsFile || !file_exists($credentialsFile)) {
      throw new Exception("Google Wallet Credentials or Issuer ID not configured.");
    }

    $client = new \Google\Client();
    $client->setAuthConfig($credentialsFile);
    $client->addScope('https://www.googleapis.com/auth/wallet_object.issuer');
    $service = new \Google\Service\Walletobjects($client);

    $classId = $issuerId . '.Membership';
    $barcodeToken = Civi::settings()->get('wallet_barcode_membership') ?: '{contact.id}';
    if (class_exists('\Civi\Token\TokenProcessor')) {
      $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => FALSE]);
      $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
      $tokenProcessor->addRow(['contactId' => $contactId, 'membershipId' => $membershipId]);
      $tokenProcessor->evaluate();
      $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
    }
    else {
      // Fallback
      $barcodeValue = $contactId;
    }

    $entityType = 'Membership';
    $empty = NULL;
    CRM_Utils_Hook::singleton()->invoke(['barcodeValue', 'entityType', 'entityId'], $barcodeValue, $entityType, $membershipId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');

    $qrCodeContent = (string) $barcodeValue;
    $qrCodeAltText = is_numeric($qrCodeContent) ? $qrCodeContent : '';

    $objectId = $issuerId . '.' . 'Contact-' . $contactId;

    // Image URLs
    $extUrl = CRM_Extension_System::singleton()->getMapper()->keyToUrl('one.jonathandhn.wallet');
    $timestamp = time();
    $logoUrl = $extUrl . '/resources/images/icon.png?v=' . $timestamp;
    $heroUrl = $extUrl . '/resources/images/logo.png?v=' . $timestamp;

    $isActive = in_array($status['name'], ['New', 'Current', 'Grace']);
    $bgColor = $isActive ? (Civi::settings()->get('wallet_color_membership_active') ?: '#53b459') : (Civi::settings()->get('wallet_color_membership_expired') ?: '#a0a0a0');

    $endDateStr = !empty($membership['end_date']) ? date('d M Y', strtotime($membership['end_date'])) : 'À vie';

    // Prepare Class Metadata (LoyaltyClass)
    $classMetadata = [
      'id' => $classId,
      'issuerName' => $orgName,
      'programName' => $orgName,
      'programLogo' => [
        'sourceUri' => ['uri' => $logoUrl],
      ],
      'heroImage' => [
        'sourceUri' => ['uri' => $heroUrl],
      ],
      'reviewStatus' => 'UNDER_REVIEW',
      'hexBackgroundColor' => $bgColor,
    ];

    $classObj = new \Google\Service\Walletobjects\LoyaltyClass($classMetadata);

    try {
      $service->loyaltyclass->get($classId);
      $service->loyaltyclass->update($classId, $classObj);
    }
    catch (\Google\Service\Exception $e) {
      if ($e->getCode() == 404) {
        $service->loyaltyclass->insert($classObj);
      }
      else {
        throw $e;
      }
    }

    // Check for existing pass in DB to get Auth Token
    $dao = CRM_Core_DAO::executeQuery("SELECT authentication_token FROM civicrm_wallet_pass WHERE entity_table = 'civicrm_contact' AND entity_id = %1 AND type = 'google'", [1 => [$contactId, 'Integer']]);
    if ($dao->fetch()) {
      $authenticationToken = $dao->authentication_token;
    }
    else {
      $authenticationToken = bin2hex(random_bytes(16));
      $serialNumber = 'c-' . $contactId . '-' . time();
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_wallet_pass (entity_table, entity_id, type, serial_number, authentication_token) VALUES ('civicrm_contact', %1, 'google', %2, %3)", [
        1 => [$contactId, 'Integer'],
        2 => [$serialNumber, 'String'],
        3 => [$authenticationToken, 'String'],
      ]);
    }

    $apiBaseUrl = rtrim(CRM_Utils_System::baseCMSURL(), '/') . '/civicrm/wallet/api/redirect';
    $renewUrl = $apiBaseUrl . '?action=renew&token=' . $authenticationToken;
    $profileUrl = $apiBaseUrl . '?action=profile&token=' . $authenticationToken;

    // LoyaltyObject
    $objectMetadata = [
      'id' => $objectId,
      'classId' => $classId,
      'state' => 'ACTIVE',
      'accountId' => (string) $contactId,
      'accountName' => $contact['display_name'],
      'barcode' => [
        'type' => 'QR_CODE',
        'value' => $qrCodeContent,
        'alternateText' => $qrCodeAltText,
      ],
      'loyaltyPoints' => [
        'label' => E::ts('Type'),
        'balance' => [
          'string' => $membershipType['name'],
        ],
      ],
      'textModulesData' => [
                [
                  'header' => E::ts('Statut'),
                  'body' => $status['label'],
                ],
                [
                  'header' => E::ts("Valide jusqu'au"),
                  'body' => $endDateStr,
                ],
      ],
    ];

    $uris = [];
    $uris[] = [
      'uri' => $renewUrl,
      'description' => E::ts('Renouveler mon adhésion'),
      'id' => 'renew_link_v2',
    ];
    $uris[] = [
      'uri' => $profileUrl,
      'description' => E::ts('Mettre à jour mon profil'),
      'id' => 'profile_link_v2',
    ];

    if ($websiteUrl) {
      $uris[] = [
        'uri' => $websiteUrl,
        'description' => E::ts('Site Internet'),
        'id' => 'website_link_v2',
      ];
    }
    if ($contactUrl) {
      $uris[] = [
        'uri' => $contactUrl,
        'description' => E::ts('Contact'),
        'id' => 'contact_link_v2',
      ];
    }

    $objectMetadata['linksModuleData'] = ['uris' => $uris];

    $platform = 'google';
    $entityType = 'Membership';
    $empty = NULL;
    CRM_Utils_Hook::singleton()->invoke(['passData', 'entityType', 'entityId', 'platform'], $objectMetadata, $entityType, $membershipId, $platform, $empty, $empty, 'civicrm_wallet_pass_alter');

    $objectObj = new \Google\Service\Walletobjects\LoyaltyObject($objectMetadata);

    try {
      $service->loyaltyobject->get($objectId);
      $service->loyaltyobject->update($objectId, $objectObj);
    }
    catch (\Google\Service\Exception $e) {
      if ($e->getCode() == 404) {
        $service->loyaltyobject->insert($objectObj);
      }
      else {
        throw $e;
      }
    }

    $creds = json_decode(file_get_contents($credentialsFile), TRUE);
    $claims = [
      'iss' => $creds['client_email'],
      'aud' => 'google',
      'typ' => 'savetowallet',
      'iat' => time(),
      'payload' => [
        'loyaltyObjects' => [
                    ['id' => $objectId],
        ],
      ],
    ];

    if (class_exists(\Firebase\JWT\JWT::class)) {
      $token = \Firebase\JWT\JWT::encode($claims, $creds['private_key'], 'RS256');
    }
    else {
      throw new Exception("Firebase JWT library not found.");
    }

    return "https://pay.google.com/gp/v/save/{$token}";
  }

}
