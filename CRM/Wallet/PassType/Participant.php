<?php

use PKPass\PKPass;
use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_PassType_Participant implements CRM_Wallet_PassType_Interface
{

    /**
     * Create an Apple Wallet Pass for a participant.
     *
     * @param int $participantId
     * @return void
     */
    public static function createApplePass($entityId)
    {
        $participantId = $entityId;
        $participant = civicrm_api3('Participant', 'getsingle', ['id' => $participantId, 'check_permissions' => FALSE]);
        $event = civicrm_api3('Event', 'getsingle', ['id' => $participant['event_id'], 'check_permissions' => FALSE]);
        $contact = civicrm_api3('Contact', 'getsingle', ['id' => $participant['contact_id'], 'check_permissions' => FALSE]);

        // Initialize Wallet settings
        $certPath = Civi::settings()->get('wallet_apple_cert');
        $certPass = Civi::settings()->get('wallet_apple_cert_password');
        $passTypeIdentifier = Civi::settings()->get('wallet_apple_pass_type_id');
        $teamIdentifier = Civi::settings()->get('wallet_apple_team_id');
        $domain = civicrm_api3('Domain', 'getsingle', ['id' => CRM_Core_Config::domainID()]);
        $defaultOrgName = $domain['name'] ?? 'Organisation';
        $defaultUrl = CRM_Utils_System::baseCMSURL();

        $orgName = Civi::settings()->get('wallet_org_name') ?: $defaultOrgName;
        $websiteUrl = Civi::settings()->get('wallet_website_url') ?: rtrim($defaultUrl, '/');
        $contactUrl = Civi::settings()->get('wallet_contact_url') ?: rtrim($defaultUrl, '/') . '/contact';

        if (!$certPath || !file_exists($certPath)) {
            throw new Exception("Apple Wallet Certificate not configured or not found.");
        }

        // Validate Certificate and Password
        $pkcs12 = file_get_contents($certPath);
        $certs = [];
        if (!openssl_pkcs12_read($pkcs12, $certs, $certPass)) {
            throw new Exception("Invalid Apple Wallet certificate or password. OpenSSL Error: " . openssl_error_string());
        }

        $pass = new PKPass($certPath, $certPass);

        $serialNumber = $participantId . '-' . time();

        // Check for existing pass
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

        // Fetch Venue & Location
        $venueName = '';
        $venueAddress = '';
        $locations = [];
        if (!empty($event['loc_block_id'])) {
            $locBlock = civicrm_api3('LocBlock', 'getsingle', ['id' => $event['loc_block_id']]);
            if (!empty($locBlock['address_id'])) {
                $address = civicrm_api3('Address', 'getsingle', ['id' => $locBlock['address_id']]);

                // Determine Venue Name
                if (!empty($address['supplemental_address_1'])) {
                    $venueName = $address['supplemental_address_1'];
                } elseif (!empty($address['name'])) {
                    $venueName = $address['name'];
                } elseif (!empty($address['street_address'])) {
                    $venueName = $address['street_address'];
                }

                // Construct Venue Address
                $addressParts = [
                    $address['street_address'] ?? '',
                    $address['supplemental_address_2'] ?? '',
                    $address['city'] ?? '',
                    $address['postal_code'] ?? ''
                ];
                $venueAddress = implode(', ', array_filter($addressParts));

                // Add GPS Coordinates
                if (!empty($address['geo_code_1']) && !empty($address['geo_code_2'])) {
                    $locations[] = [
                        'latitude' => (float) $address['geo_code_1'],
                        'longitude' => (float) $address['geo_code_2'],
                        'relevantText' => $event['title'],
                    ];
                }
            }
        }

        // Generate QR Code Content
        $qrCodeContent = (string) $participant['id'];

        // Normalize URLs
        if ($websiteUrl && !preg_match("~^(?:f|ht)tps?://~i", $websiteUrl)) {
            $websiteUrl = "https://" . $websiteUrl;
        }
        if ($contactUrl && !preg_match("~^(?:f|ht)tps?://~i", $contactUrl)) {
            $contactUrl = "https://" . $contactUrl;
        }

        // Prepare Back Fields
        $backFields = [];

        // Venue Info
        if ($venueName || $venueAddress) {
            $backFields[] = [
                'key' => 'venue-info',
                'label' => $venueName ?: 'LIEU',
                'value' => $venueAddress ?: $venueName,
                'changeMessage' => 'Le lieu a changé : %@',
            ];
        }

        // Description
        if (!empty($event['description'])) {
            $backFields[] = [
                'key' => 'description',
                'label' => 'Description',
                'value' => strip_tags($event['description']),
            ];
        }

        // Organizer
        $backFields[] = [
            'key' => 'organizer-name',
            'label' => 'Organisateur',
            'value' => $orgName,
        ];

        // Website
        $backFields[] = [
            'key' => 'website',
            'label' => 'Informations',
            'value' => $websiteUrl,
            'attributedValue' => "<a href='$websiteUrl'>$websiteUrl</a>",
        ];

        // Contact
        $backFields[] = [
            'key' => 'contact',
            'label' => 'Contact',
            'value' => $contactUrl,
            'attributedValue' => "<a href='$contactUrl'>$contactUrl</a>",
        ];

        // Prepare Auxiliary Fields
        $auxiliaryFields = [
            [
                'key' => 'participant-name',
                'label' => 'TITULAIRE',
                'value' => $contact['display_name'],
            ],
        ];

        if (!empty($participant['fee_level'])) {
            $auxiliaryFields[] = [
                'key' => 'fee-level',
                'label' => 'BILLET',
                'value' => strip_tags(str_replace('&nbsp;', ' ', $participant['fee_level'])),
                'changeMessage' => 'Votre billet a été mis à jour : %@',
            ];
        }

        // Prepare Pass Data Array
        $passData = [
            'description' => $event['title'],
            'formatVersion' => 1,
            'organizationName' => $orgName,
            'passTypeIdentifier' => $passTypeIdentifier,
            'serialNumber' => $serialNumber,
            'authenticationToken' => $authenticationToken,
            'webServiceURL' => CRM_Utils_System::baseCMSURL() . 'civi-wallet-api.php',
            'teamIdentifier' => $teamIdentifier,
            'backgroundColor' => Civi::settings()->get('wallet_color_background') ?: 'rgb(83,180,89)', // CiviCRM Green
            'foregroundColor' => Civi::settings()->get('wallet_color_foreground') ?: 'rgb(255,255,255)',
            'labelColor' => Civi::settings()->get('wallet_color_label') ?: 'rgb(255,255,255)',
            'eventTicket' => [
                'primaryFields' => [
                    [
                        'key' => 'event-name',
                        'label' => $venueName, // Venue Name as Label
                        'value' => $event['title'], // Event Title as Value (Large)
                        'changeMessage' => 'L\'événement a changé : %@',
                    ],
                ],
                'secondaryFields' => [
                    [
                        'key' => 'start-date',
                        'label' => 'DATE', // Uppercase
                        'value' => date('d M Y', strtotime($event['start_date'])),
                        'textAlignment' => 'PKTextAlignmentLeft', // Try to force alignment
                        'changeMessage' => 'La date a changé : %@',
                    ],
                    [
                        'key' => 'start-time',
                        'label' => 'HEURE', // Uppercase
                        'value' => date('H:i', strtotime($event['start_date'])),
                        'textAlignment' => 'PKTextAlignmentRight', // Try to force alignment
                    ],
                ],
                'auxiliaryFields' => $auxiliaryFields,
                'backFields' => $backFields,
            ],
            'barcode' => [
                'format' => 'PKBarcodeFormatQR',
                'message' => $qrCodeContent,
                'messageEncoding' => 'iso-8859-1',
                'altText' => (string) $participant['id'],
            ],
            'locations' => $locations,
            'relevantDate' => date('c', strtotime($event['start_date'])),
            'expirationDate' => !empty($event['end_date']) ? date('c', strtotime($event['end_date'])) : date('c', strtotime($event['start_date'] . ' + 1 day')),
        ];

        // Add Images
        $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
        $imagesPath = $extPath . '/resources/images/';
        $iconPath = $imagesPath . 'icon.png';
        $logoPath = $imagesPath . 'logo.png';

        // Add files if they exist
        if (file_exists($iconPath)) {
            $pass->addFile($iconPath, 'icon.png');
            $pass->addFile($iconPath, 'icon@2x.png');
        }
        if (file_exists($logoPath)) {
            $pass->addFile($logoPath, 'logo.png');
            $pass->addFile($logoPath, 'logo@2x.png');
        }

        // Custom Event Image -> strip.png (Main Visual)
        $customFields = self::getWalletCustomFields();
        if (!empty($customFields['image'])) {
            try {
                $customImageId = civicrm_api3('Event', 'getvalue', ['id' => $event['id'], 'return' => 'custom_' . $customFields['image']]);
                if ($customImageId) {
                    $file = civicrm_api3('File', 'getsingle', ['id' => $customImageId]);
                    $fileName = $file['uri'] ?? $file['uri_file'] ?? null;
                    if ($fileName) {
                        $customImagePath = CRM_Core_Config::singleton()->customFileUploadDir . $fileName;
                        if (file_exists($customImagePath)) {
                            // Add as strip image (Main visual for Event Ticket)
                            $pass->addFile($customImagePath, 'strip.png');
                            $pass->addFile($customImagePath, 'strip@2x.png');
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore missing image
            }
        }

        // Custom Color Override
        if (!empty($customFields['color'])) {
            try {
                $customColor = civicrm_api3('Event', 'getvalue', ['id' => $event['id'], 'return' => 'custom_' . $customFields['color']]);
                if ($customColor) {
                    list($r, $g, $b) = sscanf($customColor, "#%02x%02x%02x");
                    $passData['backgroundColor'] = "rgb($r,$g,$b)";
                }
            } catch (Exception $e) {
            }
        }

        // Set Data ONCE
        $pass->setData($passData);

        return $pass->create();
    }

    /**
     * Create a Google Wallet Pass for a participant.
     *
     * @param array $participant Participant array from API.
     * @param array $event Event array from API.
     * @param string $qrCodeContent Content for the QR code.
     * @return string JWT Link
     */
    public static function createGooglePass($entityId)
    {
        $participantId = $entityId;
        $participant = civicrm_api3('Participant', 'getsingle', ['id' => $participantId, 'check_permissions' => FALSE]);
        $event = civicrm_api3('Event', 'getsingle', ['id' => $participant['event_id'], 'check_permissions' => FALSE]);
        $qrCodeContent = (string) $participantId;

        // Configuration
        $issuerId = Civi::settings()->get('wallet_google_issuer_id');
        $credentialsFile = Civi::settings()->get('wallet_google_credentials');
        $orgName = Civi::settings()->get('wallet_org_name') ?: 'SOS homophobie';
        $websiteUrl = Civi::settings()->get('wallet_website_url') ?: 'https://www.sos-homophobie.org';
        $contactUrl = Civi::settings()->get('wallet_contact_url') ?: 'https://www.sos-homophobie.org/nous-contacter';

        // Ensure URLs are absolute
        if ($websiteUrl && !preg_match("~^(?:f|ht)tps?://~i", $websiteUrl)) {
            $websiteUrl = "https://" . $websiteUrl;
        }
        if ($contactUrl && !preg_match("~^(?:f|ht)tps?://~i", $contactUrl)) {
            $contactUrl = "https://" . $contactUrl;
        }

        // Pretty URLs for display
        $prettyWebsiteUrl = preg_replace('#^https?://(www\.)?#', '', $websiteUrl);
        $prettyWebsiteUrl = rtrim($prettyWebsiteUrl, '/');

        $prettyContactUrl = preg_replace('#^https?://(www\.)?#', '', $contactUrl);
        $prettyContactUrl = rtrim($prettyContactUrl, '/');

        if (!$issuerId || !$credentialsFile || !file_exists($credentialsFile)) {
            throw new Exception("Google Wallet Credentials or Issuer ID not configured.");
        }

        // Initialize Google Client
        $client = new \Google\Client();
        $client->setAuthConfig($credentialsFile);
        $client->addScope('https://www.googleapis.com/auth/wallet_object.issuer');
        $service = new \Google\Service\Walletobjects($client);

        // Define Class ID and Object ID
        $classId = $issuerId . '.' . 'Event-' . $event['id'];
        $objectId = $issuerId . '.' . 'Participant-' . $participant['id'];

        // Get Venue
        $venueName = 'TBA';
        $venueAddress = '';
        $gpsLocation = null;
        try {
            $locBlockId = $event['loc_block_id'];
            if ($locBlockId) {
                $locBlock = civicrm_api3('LocBlock', 'getsingle', ['id' => $locBlockId]);
                if (!empty($locBlock['address_id'])) {
                    $address = civicrm_api3('Address', 'getsingle', ['id' => $locBlock['address_id']]);

                    // Fetch Country Name
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

                    // Try to get venue name if possible (not standard in LocBlock, usually Address Name or similar)
                    if (!empty($address['supplemental_address_1'])) {
                        $venueName = $address['supplemental_address_1'];
                    } elseif (!empty($address['name'])) {
                        $venueName = $address['name'];
                    } elseif (!empty($address['street_address'])) {
                        $venueName = $address['street_address']; // Fallback
                    }

                    // GPS
                    if (!empty($address['geo_code_1']) && !empty($address['geo_code_2'])) {
                        $gpsLocation = [
                            'kind' => 'walletobjects#latLongPoint',
                            'latitude' => (float) $address['geo_code_1'],
                            'longitude' => (float) $address['geo_code_2']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
        } catch (Exception $e) {
            // Silently fail for venue info
        }

        // Image URLs (Must be public)
        $extUrl = CRM_Extension_System::singleton()->getMapper()->keyToUrl('one.jonathandhn.wallet');
        $timestamp = time(); // Cache buster to force update

        $logoUrl = $extUrl . '/resources/images/icon.png?v=' . $timestamp;
        $heroUrl = $extUrl . '/resources/images/logo.png?v=' . $timestamp;

        $logoUrl = $extUrl . '/resources/images/icon.png?v=' . $timestamp;
        $heroUrl = $extUrl . '/resources/images/logo.png?v=' . $timestamp;

        // Event Overrides (Custom Fields)
        $customFields = self::getWalletCustomFields();
        $customColor = null;

        // 1. Color Override
        if (!empty($customFields['color'])) {
            try {
                $customColor = civicrm_api3('Event', 'getvalue', ['id' => $event['id'], 'return' => 'custom_' . $customFields['color']]);
            } catch (Exception $e) {
                // Ignore if not set
            }
        }

        // 2. Image Override (Hero Image)
        if (!empty($customFields['image'])) {
            try {
                $customImageId = civicrm_api3('Event', 'getvalue', ['id' => $event['id'], 'return' => 'custom_' . $customFields['image']]);
                if ($customImageId) {
                    try {
                        $file = civicrm_api3('File', 'getsingle', ['id' => $customImageId]);

                        // API v3 returns 'uri' for the filename
                        $fileName = $file['uri'] ?? $file['uri_file'] ?? null;

                        if (!empty($fileName)) {
                            $config = CRM_Core_Config::singleton();
                            $src = $config->customFileUploadDir . $fileName;

                            if (file_exists($src)) {
                                // Copy to public extension resource folder
                                $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
                                $publicImgDir = $extPath . '/resources/images/events/';
                                if (!file_exists($publicImgDir)) {
                                    mkdir($publicImgDir, 0755, true);
                                }

                                $destName = 'event-' . $event['id'] . '.png';
                                $dest = $publicImgDir . $destName;
                                $copyResult = copy($src, $dest);

                                if ($copyResult) {
                                    $heroUrl = $extUrl . '/resources/images/events/' . $destName . '?v=' . $timestamp;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // Silent fail
                    }
                }
            } catch (Exception $e) {
                // Ignore if not set
            }
        }

        // Prepare Class Metadata
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
            'logo' => [
                'sourceUri' => ['uri' => $logoUrl]
            ],
            'heroImage' => [
                'sourceUri' => ['uri' => $heroUrl]
            ],
            'hexBackgroundColor' => Civi::settings()->get('wallet_color_background') ?: '#53b459', // CiviCRM Green
            'dateTime' => [
                'start' => date('Y-m-d\TH:i:s', strtotime($event['start_date'])),
                'end' => !empty($event['end_date']) ? date('Y-m-d\TH:i:s', strtotime($event['end_date'])) : date('Y-m-d\TH:i:s', strtotime($event['start_date'] . ' + 1 day')),
            ],
            'classTemplateInfo' => [
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
            ],
        ];

        if ($customColor) {
            $classMetadata['hexBackgroundColor'] = $customColor;
        }

        // Add Venue if available
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

            // GPS
            if (!empty($address['geo_code_1']) && !empty($address['geo_code_2'])) {
                $gpsLocation = [
                    'kind' => 'walletobjects#latLongPoint',
                    'latitude' => (float) $address['geo_code_1'],
                    'longitude' => (float) $address['geo_code_2']
                ];
            }
        }

        $classObj = new \Google\Service\Walletobjects\EventTicketClass($classMetadata);

        // Check if class exists, otherwise create it
        try {
            $service->eventticketclass->get($classId);
            $service->eventticketclass->update($classId, $classObj);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 404) {
                // Create Class
                $service->eventticketclass->insert($classObj);
            } else {
                throw $e;
            }
        }

        // Fetch Contact
        $contact = civicrm_api3('Contact', 'getsingle', ['id' => $participant['contact_id']]);

        $textModulesData = [
            [
                'header' => E::ts('Organisateur'),
                'body' => $orgName
            ]
        ];

        if (!empty($participant['fee_level'])) {
            $textModulesData[] = [
                'header' => 'Billet',
                'body' => strip_tags(str_replace('&nbsp;', ' ', $participant['fee_level']))
            ];
        }

        // Check if object exists, otherwise create it
        $objectMetadata = [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'ticketHolderName' => $contact['display_name'],
            'heroImage' => [
                'sourceUri' => ['uri' => $heroUrl]
            ],
            'textModulesData' => $textModulesData,
            'linksModuleData' => [
                'uris' => [
                    [
                        'uri' => $websiteUrl, // Must be absolute (https://...)
                        'description' => $prettyWebsiteUrl ?: $websiteUrl, // Display text
                        'id' => 'website_link'
                    ],
                    [
                        'uri' => $contactUrl, // Must be absolute (https://...)
                        'description' => E::ts('Contact'), // Display text
                        'id' => 'contact_link'
                    ]
                ]
            ],
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $qrCodeContent,
                'alternateText' => (string) $participant['id']
            ]
        ];

        // Add GPS to Object (for notifications)
        if ($gpsLocation) {
            $objectMetadata['locations'] = [$gpsLocation];
        }

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

        // Generate JWT Link
        // We need to create a JWT that claims this object.
        // Google PHP Client doesn't have a direct helper for the "Save to Android Pay" JWT link generation 
        // in the same way as the old libraries, but we can construct the claims and sign it.

        $claims = [
            'iss' => $client->getConfig('client_email'),
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'payload' => [
                'eventTicketObjects' => [
                    ['id' => $objectId]
                ]
            ]
        ];

        // Sign JWT manually using the private key from credentials
        $key = json_decode(file_get_contents($credentialsFile), true)['private_key'];

        // Simple JWT signing (HS256 or RS256? Google Service Account uses RS256)
        // We can use firebase/php-jwt if available, or do it manually if we want to avoid deps, 
        // but google/apiclient usually pulls in phpseclib or similar.
        // Actually, let's use the Google Client to sign if possible, or just use a simple JWT lib function.
        // Since we might not have firebase/php-jwt, let's try to use what's available.
        // But wait, `google/apiclient` depends on `firebase/php-jwt`.

        if (class_exists('\Firebase\JWT\JWT')) {
            $token = \Firebase\JWT\JWT::encode($claims, $key, 'RS256');
        } else {
            // Fallback or Error
            throw new Exception("Firebase JWT library not found. Run composer update.");
        }

        return "https://pay.google.com/gp/v/save/{$token}";
    }

    
}
