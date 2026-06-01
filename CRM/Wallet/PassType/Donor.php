<?php

use PKPass\PKPass;
use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_PassType_Donor
{
    public static function createApplePass($contactId)
    {
        $contact = \Civi\Api4\Contact::get(FALSE)
            ->addSelect('display_name', 'first_name', 'last_name', 'organization_name', 'contact_type')
            ->addWhere('id', '=', $contactId)
            ->execute()->first();

        if (!$contact) {
            throw new Exception("Contact not found: $contactId");
        }

        $passTypeId = Civi::settings()->get('wallet_apple_pass_type_id');
        $teamId = Civi::settings()->get('wallet_apple_team_id');
        $certPath = Civi::settings()->get('wallet_apple_cert');
        $certPassword = Civi::settings()->get('wallet_apple_cert_password');
        
        $orgName = Civi::settings()->get('wallet_org_name') ?: CRM_Core_BAO_Domain::getDomain()->name;
        $orgDesc = Civi::settings()->get('wallet_org_description') ?: $orgName;
        
        $profileUrl = Civi::settings()->get('wallet_membership_profile_url'); // We can reuse this setting
        if ($profileUrl && strpos($profileUrl, 'http') !== 0) {
            $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);
            $profileUrl = CRM_Utils_System::url($profileUrl, "reset=1&cid={$contactId}&cs={$cs}", true, null, false, true);
        }

        $donateUrl = Civi::settings()->get('wallet_membership_renew_url'); // We can reuse the renew URL as a donate URL
        if ($donateUrl && strpos($donateUrl, 'http') !== 0) {
            $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);
            $donateUrl = CRM_Utils_System::url($donateUrl, "reset=1&cid={$contactId}&cs={$cs}", true, null, false, true);
        }

        // Fetch Last 3 Contributions
        $contributions = \Civi\Api4\Contribution::get(FALSE)
            ->addSelect('total_amount', 'currency', 'receive_date')
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('contribution_status_id:name', '=', 'Completed')
            ->addOrderBy('receive_date', 'DESC')
            ->setLimit(3)
            ->execute();

        $donationText = "";
        foreach ($contributions as $contrib) {
            $date = date('d/m/Y', strtotime($contrib['receive_date']));
            $amount = CRM_Utils_Money::format($contrib['total_amount'], $contrib['currency']);
            $donationText .= "{$date} : {$amount}\n";
        }
        if (empty($donationText)) {
            $donationText = E::ts("Aucun don récent");
        }

        // Barcode Value
        $barcodeToken = Civi::settings()->get('wallet_barcode_donor') ?: '{contact.id}';
        if (class_exists('\Civi\Token\TokenProcessor')) {
            $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => false]);
            $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
            $tokenProcessor->addRow(['contactId' => $contactId]);
            $tokenProcessor->evaluate();
            $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
        } else {
            $barcodeValue = $contactId;
        }

        $empty = null;
        $barcodeEntityType = 'Contact';
        CRM_Utils_Hook::singleton()->invoke(['barcodeValue', 'entityType', 'entityId'], $barcodeValue, $barcodeEntityType, $contactId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');
        
        $qrCodeContent = $barcodeValue;
        $qrCodeAltText = is_numeric($qrCodeContent) ? $qrCodeContent : '';

        // Generate Pass
        $pass = new PKPass($certPath, $certPassword);
        
        $passData = [
            'formatVersion' => 1,
            'passTypeIdentifier' => $passTypeId,
            'serialNumber' => 'donor_' . $contactId,
            'teamIdentifier' => $teamId,
            'organizationName' => $orgName,
            'description' => $orgDesc,
            'logoText' => $orgName,
            'foregroundColor' => Civi::settings()->get('wallet_color_foreground') ?: 'rgb(255, 255, 255)',
            'backgroundColor' => Civi::settings()->get('wallet_color_background') ?: 'rgb(0, 0, 0)',
            'labelColor' => Civi::settings()->get('wallet_color_label') ?: 'rgb(255, 255, 255)',
            'generic' => [
                'primaryFields' => [
                    [
                        'key' => 'name',
                        'label' => E::ts('Contact'),
                        'value' => $contact['display_name'],
                    ]
                ],
                'secondaryFields' => [
                    [
                        'key' => 'status',
                        'label' => E::ts('Statut'),
                        'value' => E::ts('Donateur'),
                    ]
                ],
                'backFields' => [
                    [
                        'key' => 'donations',
                        'label' => E::ts('Derniers dons'),
                        'value' => $donationText
                    ]
                ],
            ],
            'barcode' => [
                'format' => 'PKBarcodeFormatQR',
                'message' => $qrCodeContent,
                'messageEncoding' => 'iso-8859-1',
                'altText' => $qrCodeAltText,
            ],
        ];

        if ($donateUrl) {
            $passData['generic']['backFields'][] = [
                'key' => 'donate-link',
                'label' => E::ts('Faire un nouveau don'),
                'value' => $donateUrl,
                'attributedValue' => "<a href='$donateUrl'>" . E::ts('Faire un don') . "</a>",
            ];
        }

        if ($profileUrl) {
            $passData['generic']['backFields'][] = [
                'key' => 'profile-link',
                'label' => E::ts('Mettre à jour mes coordonnées'),
                'value' => $profileUrl,
                'attributedValue' => "<a href='$profileUrl'>" . E::ts('Mettre à jour mon profil') . "</a>",
            ];
        }

        $platform = 'apple';
        $entityType = 'Donor';
        CRM_Utils_Hook::singleton()->invoke(['passData', 'entityType', 'entityId', 'platform'], $passData, $entityType, $contactId, $platform, $empty, $empty, 'civicrm_wallet_pass_alter');

        // Add Images
        $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
        $imagesPath = $extPath . '/resources/images/';
        
        $pass->setData($passData);
        if (file_exists($imagesPath . 'icon.png')) $pass->addFile($imagesPath . 'icon.png');
        if (file_exists($imagesPath . 'icon@2x.png')) $pass->addFile($imagesPath . 'icon@2x.png');
        if (file_exists($imagesPath . 'icon@3x.png')) $pass->addFile($imagesPath . 'icon@3x.png');
        if (file_exists($imagesPath . 'logo.png')) $pass->addFile($imagesPath . 'logo.png');

        return $pass->create();
    }

    public static function createGooglePass($contactId)
    {
        $contact = \Civi\Api4\Contact::get(FALSE)
            ->addSelect('display_name')
            ->addWhere('id', '=', $contactId)
            ->execute()->first();

        if (!$contact) {
            throw new Exception("Contact not found: $contactId");
        }

        // Fetch Last 3 Contributions
        $contributions = \Civi\Api4\Contribution::get(FALSE)
            ->addSelect('total_amount', 'currency', 'receive_date')
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('contribution_status_id:name', '=', 'Completed')
            ->addOrderBy('receive_date', 'DESC')
            ->setLimit(3)
            ->execute();

        $donationText = "";
        foreach ($contributions as $contrib) {
            $date = date('d/m/Y', strtotime($contrib['receive_date']));
            $amount = CRM_Utils_Money::format($contrib['total_amount'], $contrib['currency']);
            $donationText .= "{$date} : {$amount}\n";
        }
        if (empty($donationText)) {
            $donationText = E::ts("Aucun don récent");
        }

        $issuerId = Civi::settings()->get('wallet_google_issuer_id');
        $orgName = Civi::settings()->get('wallet_org_name') ?: CRM_Core_BAO_Domain::getDomain()->name;
        
        $classId = "{$issuerId}.donor_class";
        $objectId = "{$issuerId}.donor_obj_{$contactId}";

        $client = new \Google_Client();
        $client->setApplicationName('CiviWallet');
        $client->setScopes([\Google\Service\Walletobjects::WALLET_OBJECT_ISSUER]);
        $client->setAuthConfig(Civi::settings()->get('wallet_google_credentials'));
        $service = new \Google\Service\Walletobjects($client);

        // Ensure Class Exists
        try {
            $service->genericclass->get($classId);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 404) {
                $classMetadata = [
                    'id' => $classId,
                    'classTemplateInfo' => [
                        'cardTemplateOverride' => [
                            'cardRowTemplateInfos' => [
                                [
                                    'twoItems' => [
                                        'startItem' => [
                                            'firstValue' => [
                                                'fields' => [
                                                    ['fieldPath' => "object.textModulesData['name']"]
                                                ]
                                            ]
                                        ],
                                        'endItem' => [
                                            'firstValue' => [
                                                'fields' => [
                                                    ['fieldPath' => "object.textModulesData['status']"]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
                $classObj = new \Google\Service\Walletobjects\GenericClass($classMetadata);
                $service->genericclass->insert($classObj);
            } else {
                throw $e;
            }
        }

        // Profile & Donate URL
        $profileUrl = Civi::settings()->get('wallet_membership_profile_url');
        if ($profileUrl && strpos($profileUrl, 'http') !== 0) {
            $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);
            $profileUrl = CRM_Utils_System::url($profileUrl, "reset=1&cid={$contactId}&cs={$cs}", true, null, false, true);
        }

        $donateUrl = Civi::settings()->get('wallet_membership_renew_url');
        if ($donateUrl && strpos($donateUrl, 'http') !== 0) {
            $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);
            $donateUrl = CRM_Utils_System::url($donateUrl, "reset=1&cid={$contactId}&cs={$cs}", true, null, false, true);
        }

        // Generate barcode
        $barcodeToken = Civi::settings()->get('wallet_barcode_donor') ?: '{contact.id}';
        if (class_exists('\Civi\Token\TokenProcessor')) {
            $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => false]);
            $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
            $tokenProcessor->addRow(['contactId' => $contactId]);
            $tokenProcessor->evaluate();
            $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
        } else {
            $barcodeValue = $contactId;
        }

        $empty = null;
        $barcodeEntityType = 'Contact';
        CRM_Utils_Hook::singleton()->invoke(['barcodeValue', 'entityType', 'entityId'], $barcodeValue, $barcodeEntityType, $contactId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');

        $objectMetadata = [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $barcodeValue,
                'alternateText' => is_numeric($barcodeValue) ? $barcodeValue : ''
            ],
            'header' => [
                'defaultValue' => [
                    'language' => 'fr-FR', // Ideally dynamic
                    'value' => $orgName
                ]
            ],
            'textModulesData' => [
                [
                    'header' => E::ts('Contact'),
                    'body' => $contact['display_name'],
                    'id' => 'name'
                ],
                [
                    'header' => E::ts('Statut'),
                    'body' => E::ts('Donateur'),
                    'id' => 'status'
                ],
                [
                    'header' => E::ts('Derniers dons'),
                    'body' => $donationText,
                    'id' => 'donations'
                ]
            ]
        ];

        $uris = [];
        if ($donateUrl) {
            $uris[] = [
                'uri' => $donateUrl,
                'description' => E::ts('Faire un nouveau don'),
                'id' => 'donate_link'
            ];
        }
        if ($profileUrl) {
            $uris[] = [
                'uri' => $profileUrl,
                'description' => E::ts('Mettre à jour mes coordonnées'),
                'id' => 'profile_link'
            ];
        }

        if (!empty($uris)) {
            $objectMetadata['linksModuleData'] = ['uris' => $uris];
        }

        $platform = 'google';
        $entityType = 'Donor';
        CRM_Utils_Hook::singleton()->invoke(['passData', 'entityType', 'entityId', 'platform'], $objectMetadata, $entityType, $contactId, $platform, $empty, $empty, 'civicrm_wallet_pass_alter');

        $objectObj = new \Google\Service\Walletobjects\GenericObject($objectMetadata);

        try {
            $service->genericobject->get($objectId);
            $service->genericobject->update($objectId, $objectObj);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 404) {
                $service->genericobject->insert($objectObj);
            } else {
                throw $e;
            }
        }

        // Generate Save URL
        $claims = [
            'iss' => Civi::settings()->get('wallet_google_credentials_email') ?? '',
            'aud' => 'google',
            'origins' => [CRM_Utils_System::baseCMSURL()],
            'typ' => 'savetowallet',
            'payload' => [
                'genericObjects' => [
                    ['id' => $objectId, 'classId' => $classId]
                ]
            ]
        ];

        $creds = json_decode(file_get_contents(Civi::settings()->get('wallet_google_credentials')), true);
        $claims['iss'] = $creds['client_email'];
        if (class_exists('\Firebase\JWT\JWT')) {
            $token = \Firebase\JWT\JWT::encode($claims, $creds['private_key'], 'RS256');
        } else {
            return '';
        }

        return "https://pay.google.com/gp/v/save/$token";
    }
}
