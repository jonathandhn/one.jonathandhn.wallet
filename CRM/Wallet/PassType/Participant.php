<?php

use PKPass\PKPass;
use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_PassType_Participant implements CRM_Wallet_PassType_Interface
{

    public static function createApplePass($entityId)
    {
        $participantId = $entityId;
        $participant = \Civi\Api4\Participant::get(FALSE)->addWhere('id', '=', $participantId)->execute()->first();
        if (!$participant) throw new Exception("Participant not found");

        $event = \Civi\Api4\Event::get(FALSE)->addWhere('id', '=', $participant['event_id'])->execute()->first();
        $contact = \Civi\Api4\Contact::get(FALSE)->addWhere('id', '=', $participant['contact_id'])->execute()->first();

        // Initialize Wallet settings
        $certPath = Civi::settings()->get('wallet_apple_cert');
        $certPass = Civi::settings()->get('wallet_apple_cert_password');
        $passTypeIdentifier = Civi::settings()->get('wallet_apple_pass_type_id');
        $teamIdentifier = Civi::settings()->get('wallet_apple_team_id');
        $domain = \Civi\Api4\Domain::get(FALSE)->addWhere('id', '=', CRM_Core_Config::domainID())->execute()->first();
        $defaultOrgName = $domain['name'] ?? E::ts('Organisation');
        $defaultUrl = CRM_Utils_System::baseCMSURL();

        $orgName = Civi::settings()->get('wallet_org_name') ?: $defaultOrgName;
        $websiteUrl = Civi::settings()->get('wallet_website_url');
        $contactUrl = Civi::settings()->get('wallet_contact_url');

        if ($websiteUrl && !preg_match("~^(?:f|ht)tps?://~i", $websiteUrl)) $websiteUrl = "https://" . $websiteUrl;
        if ($contactUrl && !preg_match("~^(?:f|ht)tps?://~i", $contactUrl)) $contactUrl = "https://" . $contactUrl;

        if (!$certPath || !file_exists($certPath)) {
            throw new Exception("Apple Wallet Certificate not configured or not found.");
        }

        $pkcs12 = file_get_contents($certPath);
        $certs = [];
        if (!openssl_pkcs12_read($pkcs12, $certs, $certPass)) {
            throw new Exception("Invalid Apple Wallet certificate or password. OpenSSL Error: " . openssl_error_string());
        }

        $pass = new PKPass($certPath, $certPass);

        $serialNumber = $participantId . '-' . time();

        $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_wallet_pass WHERE entity_table = 'civicrm_participant' AND entity_id = %1 AND type = 'apple'", [1 => [$participantId, 'Integer']]);
        if ($dao->fetch()) {
            $serialNumber = $dao->serial_number;
            $authenticationToken = $dao->authentication_token;
        } else {
            $authenticationToken = md5(uniqid(rand(), true));
            CRM_Core_DAO::executeQuery("INSERT INTO civicrm_wallet_pass (entity_table, entity_id, type, serial_number, authentication_token) VALUES ('civicrm_participant', %1, 'apple', %2, %3)", [
                1 => [$participantId, 'Integer'],
                2 => [$serialNumber, 'String'],
                3 => [$authenticationToken, 'String']
            ]);
        }

        $venueName = '';
        $venueAddress = '';
        $locations = [];
        if (!empty($event['loc_block_id'])) {
            $locBlock = \Civi\Api4\LocBlock::get(FALSE)->addWhere('id', '=', $event['loc_block_id'])->execute()->first();
            if ($locBlock && !empty($locBlock['address_id'])) {
                $address = \Civi\Api4\Address::get(FALSE)->addWhere('id', '=', $locBlock['address_id'])->execute()->first();

                if ($address) {
                    if (!empty($address['supplemental_address_1'])) {
                        $venueName = $address['supplemental_address_1'];
                    } elseif (!empty($address['name'])) {
                        $venueName = $address['name'];
                    } elseif (!empty($address['street_address'])) {
                        $venueName = $address['street_address'];
                    }

                    $addressParts = [
                        $address['street_address'] ?? '',
                        $address['supplemental_address_2'] ?? '',
                        $address['city'] ?? '',
                        $address['postal_code'] ?? ''
                    ];
                    $venueAddress = implode(', ', array_filter($addressParts));

                    if (!empty($address['geo_code_1']) && !empty($address['geo_code_2'])) {
                        $locations[] = [
                            'latitude' => (float) $address['geo_code_1'],
                            'longitude' => (float) $address['geo_code_2'],
                            'relevantText' => $event['title'],
                        ];
                    }
                }
            }
        }

        $barcodeToken = Civi::settings()->get('wallet_barcode_participant') ?: '{participant.id}';
        
        if (class_exists('\Civi\Token\TokenProcessor')) {
            $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => false]);
            $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
            $tokenProcessor->addRow(['contactId' => $participant['contact_id'], 'participantId' => $participantId]);
            $tokenProcessor->evaluate();
            $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
        } else {
            $barcodeValue = $participantId; // Fallback
        }

        $entityType = 'Participant';
        $empty = null;
        CRM_Utils_Hook::singleton()->invoke(3, $barcodeValue, $entityType, $participantId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');
        
        $qrCodeContent = (string) $barcodeValue;
        $qrCodeAltText = is_numeric($qrCodeContent) ? $qrCodeContent : '';

        if ($websiteUrl && !preg_match("~^(?:f|ht)tps?://~i", $websiteUrl)) $websiteUrl = "https://" . $websiteUrl;
        if ($contactUrl && !preg_match("~^(?:f|ht)tps?://~i", $contactUrl)) $contactUrl = "https://" . $contactUrl;

        $backFields = [];

        if ($venueName || $venueAddress) {
            $backFields[] = [
                'key' => 'venue-info',
                'label' => $venueName ?: E::ts('LIEU'),
                'value' => $venueAddress ?: $venueName,
                'changeMessage' => E::ts('Le lieu a changé : %@'),
            ];
        }

        if (!empty($event['description'])) {
            $backFields[] = [
                'key' => 'description',
                'label' => E::ts('Description'),
                'value' => strip_tags($event['description']),
            ];
        }

        $backFields[] = [
            'key' => 'organizer-name',
            'label' => E::ts('Organisateur'),
            'value' => $orgName,
        ];

        if ($websiteUrl) {
            $backFields[] = [
                'key' => 'website',
                'label' => E::ts('Informations'),
                'value' => $websiteUrl,
                'attributedValue' => "<a href='$websiteUrl'>$websiteUrl</a>",
            ];
        }

        if ($contactUrl) {
            $backFields[] = [
                'key' => 'contact',
                'label' => E::ts('Contact'),
                'value' => $contactUrl,
                'attributedValue' => "<a href='$contactUrl'>$contactUrl</a>",
            ];
        }

        $auxiliaryFields = [
            [
                'key' => 'participant-name',
                'label' => E::ts('TITULAIRE'),
                'value' => $contact['display_name'],
            ],
        ];

        if (!empty($participant['fee_level'])) {
            $feeLevel = is_array($participant['fee_level']) ? implode(', ', $participant['fee_level']) : $participant['fee_level'];
            $auxiliaryFields[] = [
                'key' => 'fee-level',
                'label' => E::ts('BILLET'),
                'value' => strip_tags(str_replace('&nbsp;', ' ', $feeLevel)),
                'changeMessage' => E::ts('Votre billet a été mis à jour : %@'),
            ];
        }

        $passData = [
            'description' => $event['title'],
            'formatVersion' => 1,
            'organizationName' => $orgName,
            'passTypeIdentifier' => $passTypeIdentifier,
            'serialNumber' => $serialNumber,
            'authenticationToken' => $authenticationToken,
            'webServiceURL' => rtrim(CRM_Utils_System::baseCMSURL(), '/') . '/civicrm/wallet/api',
            'teamIdentifier' => $teamIdentifier,
            'backgroundColor' => Civi::settings()->get('wallet_color_background') ?: 'rgb(83,180,89)',
            'foregroundColor' => Civi::settings()->get('wallet_color_foreground') ?: 'rgb(255,255,255)',
            'labelColor' => Civi::settings()->get('wallet_color_label') ?: 'rgb(255,255,255)',
            'eventTicket' => [
                'primaryFields' => [
                    [
                        'key' => 'event-name',
                        'label' => $venueName,
                        'value' => $event['title'],
                        'changeMessage' => E::ts("L'événement a changé : %@"),
                    ],
                ],
                'secondaryFields' => [
                    [
                        'key' => 'start-date',
                        'label' => E::ts('DATE'),
                        'value' => date('d M Y', strtotime($event['start_date'])),
                        'textAlignment' => 'PKTextAlignmentLeft',
                        'changeMessage' => E::ts('La date a changé : %@'),
                    ],
                    [
                        'key' => 'start-time',
                        'label' => E::ts('HEURE'),
                        'value' => date('H:i', strtotime($event['start_date'])),
                        'textAlignment' => 'PKTextAlignmentRight',
                    ],
                ],
                'auxiliaryFields' => $auxiliaryFields,
                'backFields' => $backFields,
            ],
            'barcode' => [
                'format' => 'PKBarcodeFormatQR',
                'message' => $qrCodeContent,
                'messageEncoding' => 'iso-8859-1',
                'altText' => $qrCodeAltText,
            ],
            'locations' => $locations,
            'relevantDate' => date('c', strtotime($event['start_date'])),
            'expirationDate' => !empty($event['end_date']) ? date('c', strtotime($event['end_date'])) : date('c', strtotime($event['start_date'] . ' + 1 day')),
        ];

        $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
        $imagesPath = $extPath . '/resources/images/';
        $iconPath = $imagesPath . 'icon.png';
        $logoPath = $imagesPath . 'logo.png';

        if (file_exists($iconPath)) {
            $pass->addFile($iconPath, 'icon.png');
            $pass->addFile($iconPath, 'icon@2x.png');
        }
        if (file_exists($logoPath)) {
            $pass->addFile($logoPath, 'logo.png');
            $pass->addFile($logoPath, 'logo@2x.png');
        }

        $customFields = self::getWalletCustomFields();
        if (!empty($customFields['image'])) {
            try {
                $customImageValue = \Civi\Api4\Event::get(FALSE)->addSelect('custom_' . $customFields['image'])->addWhere('id', '=', $event['id'])->execute()->first();
                $customImageId = $customImageValue['custom_' . $customFields['image']] ?? null;
                if ($customImageId) {
                    $file = \Civi\Api4\File::get(FALSE)->addWhere('id', '=', $customImageId)->execute()->first();
                    $fileName = $file['uri'] ?? null;
                    if ($fileName) {
                        $customImagePath = CRM_Core_Config::singleton()->customFileUploadDir . $fileName;
                        if (file_exists($customImagePath)) {
                            $pass->addFile($customImagePath, 'strip.png');
                            $pass->addFile($customImagePath, 'strip@2x.png');
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        if (!empty($customFields['color'])) {
            try {
                $customColorValue = \Civi\Api4\Event::get(FALSE)->addSelect('custom_' . $customFields['color'])->addWhere('id', '=', $event['id'])->execute()->first();
                $customColor = $customColorValue['custom_' . $customFields['color']] ?? null;
                if ($customColor) {
                    list($r, $g, $b) = sscanf($customColor, "#%02x%02x%02x");
                    $passData['backgroundColor'] = "rgb($r,$g,$b)";
                }
            } catch (Exception $e) {}
        }

        $platform = 'apple';
        $entityType = 'Participant';
        $empty = null;
        CRM_Utils_Hook::singleton()->invoke(5, $passData, $entityType, $participantId, $platform, $empty, $empty, 'civicrm_wallet_pass_alter');

        $pass->setData($passData);

        return $pass->create();
    }

    public static function createGooglePass($entityId)
    {
        $participantId = $entityId;
        $participant = \Civi\Api4\Participant::get(FALSE)->addWhere('id', '=', $participantId)->execute()->first();
        if (!$participant) throw new Exception("Participant not found");

        $event = \Civi\Api4\Event::get(FALSE)->addWhere('id', '=', $participant['event_id'])->execute()->first();
        $barcodeToken = Civi::settings()->get('wallet_barcode_participant') ?: '{participant.id}';
        if (class_exists('\Civi\Token\TokenProcessor')) {
            $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => false]);
            $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
            $tokenProcessor->addRow(['contactId' => $participant['contact_id'], 'participantId' => $participantId]);
            $tokenProcessor->evaluate();
            $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
        } else {
            $barcodeValue = $participantId; // Fallback
        }

        $entityType = 'Participant';
        $empty = null;
        CRM_Utils_Hook::singleton()->invoke(3, $barcodeValue, $entityType, $participantId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');
        
        $qrCodeContent = (string) $barcodeValue;
        $qrCodeAltText = is_numeric($qrCodeContent) ? $qrCodeContent : '';

        $issuerId = Civi::settings()->get('wallet_google_issuer_id');
        $credentialsFile = Civi::settings()->get('wallet_google_credentials');
        $orgName = Civi::settings()->get('wallet_org_name') ?: E::ts('Organisation');
        $websiteUrl = Civi::settings()->get('wallet_website_url');
        $contactUrl = Civi::settings()->get('wallet_contact_url');

        if ($websiteUrl && !preg_match("~^(?:f|ht)tps?://~i", $websiteUrl)) $websiteUrl = "https://" . $websiteUrl;
        if ($contactUrl && !preg_match("~^(?:f|ht)tps?://~i", $contactUrl)) $contactUrl = "https://" . $contactUrl;

        $prettyWebsiteUrl = preg_replace('#^https?://(www\.)?#', '', $websiteUrl);
        $prettyWebsiteUrl = rtrim($prettyWebsiteUrl, '/');

        $prettyContactUrl = preg_replace('#^https?://(www\.)?#', '', $contactUrl);
        $prettyContactUrl = rtrim($prettyContactUrl, '/');

        if (!$issuerId || !$credentialsFile || !file_exists($credentialsFile)) {
            throw new Exception("Google Wallet Credentials or Issuer ID not configured.");
        }

        $client = new \Google\Client();
        $client->setAuthConfig($credentialsFile);
        $client->addScope('https://www.googleapis.com/auth/wallet_object.issuer');
        $service = new \Google\Service\Walletobjects($client);

        $classId = $issuerId . '.' . 'Event-' . $event['id'];
        $objectId = $issuerId . '.' . 'Participant-' . $participant['id'];

        $venueName = 'TBA';
        $venueAddress = '';
        $gpsLocation = null;
        try {
            $locBlockId = $event['loc_block_id'] ?? null;
            if ($locBlockId) {
                $locBlock = \Civi\Api4\LocBlock::get(FALSE)->addWhere('id', '=', $locBlockId)->execute()->first();
                if ($locBlock && !empty($locBlock['address_id'])) {
                    $address = \Civi\Api4\Address::get(FALSE)->addWhere('id', '=', $locBlock['address_id'])->execute()->first();

                    if ($address) {
                        $countryName = '';
                        if (!empty($address['country_id'])) {
                            $countryName = CRM_Core_PseudoConstant::country($address['country_id']);
                        }

                        $addressParts = [
                            $address['street_address'] ?? '',
                            $address['supplemental_address_1'] ?? '',
                            $address['supplemental_address_2'] ?? '',
                            $address['city'] ?? '',
                            $address['postal_code'] ?? '',
                            $countryName
                        ];
                        $venueAddress = implode(', ', array_filter($addressParts));

                        if (!empty($address['supplemental_address_1'])) {
                            $venueName = $address['supplemental_address_1'];
                        } elseif (!empty($address['name'])) {
                            $venueName = $address['name'];
                        } elseif (!empty($address['street_address'])) {
                            $venueName = $address['street_address'];
                        }

                        if (!empty($address['geo_code_1']) && !empty($address['geo_code_2'])) {
                            $gpsLocation = [
                                'kind' => 'walletobjects#latLongPoint',
                                'latitude' => (float) $address['geo_code_1'],
                                'longitude' => (float) $address['geo_code_2']
                            ];
                        }
                    }
                }
            }
        } catch (Exception $e) {}

        $extUrl = CRM_Extension_System::singleton()->getMapper()->keyToUrl('one.jonathandhn.wallet');
        $timestamp = time();

        $logoUrl = $extUrl . '/resources/images/icon.png?v=' . $timestamp;
        $heroUrl = $extUrl . '/resources/images/logo.png?v=' . $timestamp;

        $customFields = self::getWalletCustomFields();
        $customColor = null;

        if (!empty($customFields['color'])) {
            try {
                $customColorValue = \Civi\Api4\Event::get(FALSE)->addSelect('custom_' . $customFields['color'])->addWhere('id', '=', $event['id'])->execute()->first();
                $customColor = $customColorValue['custom_' . $customFields['color']] ?? null;
            } catch (Exception $e) {}
        }

        if (!empty($customFields['image'])) {
            try {
                $customImageValue = \Civi\Api4\Event::get(FALSE)->addSelect('custom_' . $customFields['image'])->addWhere('id', '=', $event['id'])->execute()->first();
                $customImageId = $customImageValue['custom_' . $customFields['image']] ?? null;
                if ($customImageId) {
                    $file = \Civi\Api4\File::get(FALSE)->addWhere('id', '=', $customImageId)->execute()->first();
                    $fileName = $file['uri'] ?? null;

                    if (!empty($fileName)) {
                        $config = CRM_Core_Config::singleton();
                        $src = $config->customFileUploadDir . $fileName;

                        if (file_exists($src)) {
                            $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
                            $publicImgDir = $extPath . '/resources/images/events/';
                            if (!file_exists($publicImgDir)) mkdir($publicImgDir, 0755, true);

                            $destName = 'event-' . $event['id'] . '.png';
                            $dest = $publicImgDir . $destName;
                            if (copy($src, $dest)) {
                                $heroUrl = $extUrl . '/resources/images/events/' . $destName . '?v=' . $timestamp;
                            }
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        $classMetadata = [
            'id' => $classId,
            'issuerName' => $orgName,
            'eventName' => [
                'defaultValue' => [
                    'language' => 'fr',
                    'value' => $event['title']
                ]
            ],
            'reviewStatus' => 'UNDER_REVIEW',
            'hexBackgroundColor' => Civi::settings()->get('wallet_color_background') ?: '#53b459',
            'dateTime' => [
                'start' => date('Y-m-d\TH:i:s', strtotime($event['start_date'])),
                'end' => !empty($event['end_date']) ? date('Y-m-d\TH:i:s', strtotime($event['end_date'])) : date('Y-m-d\TH:i:s', strtotime($event['start_date'] . ' + 1 day')),
            ],
        ];

        $imagesPath = \Civi::paths()->getPath('[one.jonathandhn.wallet]/resources/images/');
        if (file_exists($imagesPath . 'icon.png')) {
            $classMetadata['logo'] = ['sourceUri' => ['uri' => $logoUrl]];
        }
        
        $hasCustomHero = ($heroUrl !== $extUrl . '/resources/images/logo.png?v=' . $timestamp);
        if ($hasCustomHero || file_exists($imagesPath . 'logo.png')) {
            $classMetadata['heroImage'] = ['sourceUri' => ['uri' => $heroUrl]];
        }

        $classMetadata['classTemplateInfo'] = [
            'cardTemplateOverride' => [
                'cardRowTemplateInfos' => [
                        [
                            'twoItems' => [
                                'startItem' => [
                                    'firstValue' => [
                                        'fields' => [
                                            ['fieldPath' => 'object.ticketHolderName']
                                        ]
                                    ]
                                ],
                                'endItem' => [
                                    'firstValue' => [
                                        'fields' => [
                                            ['fieldPath' => 'class.dateTime.start']
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

        if ($customColor) {
            $classMetadata['hexBackgroundColor'] = $customColor;
        }

        if ($venueName && $venueName !== 'TBA') {
            $classMetadata['venue'] = [
                'name' => [
                    'defaultValue' => [
                        'language' => 'fr',
                        'value' => $venueName
                    ]
                ],
                'address' => [
                    'defaultValue' => [
                        'language' => 'fr',
                        'value' => $venueAddress
                    ]
                ]
            ];
            if ($gpsLocation) {
                // To attach GPS directly to class, the format requires setting lat/long on venue.
                // However EventTicketObject supports locations directly, which we attach later.
            }
        }

        $classObj = new \Google\Service\Walletobjects\EventTicketClass($classMetadata);

        try {
            $service->eventticketclass->get($classId);
            $service->eventticketclass->update($classId, $classObj);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 404) {
                $service->eventticketclass->insert($classObj);
            } else {
                throw $e;
            }
        }

        $contact = \Civi\Api4\Contact::get(FALSE)->addWhere('id', '=', $participant['contact_id'])->execute()->first();

        $textModulesData = [
            [
                'header' => E::ts('Organisateur'),
                'body' => $orgName
            ]
        ];

        if (!empty($participant['fee_level'])) {
            $feeLevel = is_array($participant['fee_level']) ? implode(', ', $participant['fee_level']) : $participant['fee_level'];
            $textModulesData[] = [
                'header' => E::ts('Billet'),
                'body' => strip_tags(str_replace('&nbsp;', ' ', $feeLevel))
            ];
        }

        $objectMetadata = [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'ticketHolderName' => $contact['display_name'],
            'textModulesData' => $textModulesData,
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $barcodeValue,
                'alternateText' => is_numeric($barcodeValue) ? (string)$barcodeValue : ''
            ]
        ];

        $uris = [];
        if ($websiteUrl) {
            $uris[] = [
                'uri' => $websiteUrl,
                'description' => $prettyWebsiteUrl ?: $websiteUrl,
                'id' => 'website_link_v2'
            ];
        }
        if ($contactUrl) {
            $uris[] = [
                'uri' => $contactUrl,
                'description' => E::ts('Contact'),
                'id' => 'contact_link_v2'
            ];
        }

        if (!empty($uris)) {
            $objectMetadata['linksModuleData'] = ['uris' => $uris];
        }

        if ($hasCustomHero || file_exists($imagesPath . 'logo.png')) {
            $objectMetadata['heroImage'] = ['sourceUri' => ['uri' => $heroUrl]];
        }

        $objectMetadata['barcode'] = [
            'type' => 'QR_CODE',
            'value' => $qrCodeContent,
            'alternateText' => $qrCodeAltText
        ];

        if ($gpsLocation) {
            $objectMetadata['locations'] = [$gpsLocation];
        }

        $platform = 'google';
        $entityType = 'Participant';
        $empty = null;
        CRM_Utils_Hook::singleton()->invoke(5, $objectMetadata, $entityType, $participantId, $platform, $empty, $empty, 'civicrm_wallet_pass_alter');

        $objectObj = new \Google\Service\Walletobjects\EventTicketObject($objectMetadata);

        try {
            $service->eventticketobject->get($objectId);
            $service->eventticketobject->update($objectId, $objectObj);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 404) {
                $service->eventticketobject->insert($objectObj);
            } else {
                throw $e;
            }
        }

        $creds = json_decode(file_get_contents($credentialsFile), true);
        $claims = [
            'iss' => $creds['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'payload' => [
                'eventTicketObjects' => [
                    ['id' => $objectId]
                ]
            ]
        ];

        if (class_exists('\Firebase\JWT\JWT')) {
            $token = \Firebase\JWT\JWT::encode($claims, $creds['private_key'], 'RS256');
        } else {
            throw new Exception("Firebase JWT library not found. Run composer update.");
        }

        return "https://pay.google.com/gp/v/save/{$token}";
    }

    private static function getWalletCustomFields()
    {
        static $fields = null;
        if ($fields === null) {
            $fields = [];
            try {
                $colorField = \Civi\Api4\CustomField::get(FALSE)
                    ->addWhere('name', '=', 'Wallet_Background_Color')
                    ->addWhere('custom_group_id.name', '=', 'Wallet_Settings')
                    ->execute()->first();
                if ($colorField) $fields['color'] = $colorField['id'];
            } catch (Exception $e) {}

            try {
                $imageField = \Civi\Api4\CustomField::get(FALSE)
                    ->addWhere('name', '=', 'Wallet_Image')
                    ->addWhere('custom_group_id.name', '=', 'Wallet_Settings')
                    ->execute()->first();
                if ($imageField) $fields['image'] = $imageField['id'];
            } catch (Exception $e) {}
        }
        return $fields;
    }
}