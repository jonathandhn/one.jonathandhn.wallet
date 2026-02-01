<?php

use PKPass\PKPass;
use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_BAO_Pass extends CRM_Core_DAO
{

    /**
     * Create an Apple Wallet Pass for a participant.
     *
     * @param int $participantId
     * @return void
     */
    public static function createApplePass($participantId)
    {
        $participant = civicrm_api3('Participant', 'getsingle', ['id' => $participantId, 'check_permissions' => FALSE]);
        $event = civicrm_api3('Event', 'getsingle', ['id' => $participant['event_id'], 'check_permissions' => FALSE]);
        $contact = civicrm_api3('Contact', 'getsingle', ['id' => $participant['contact_id'], 'check_permissions' => FALSE]);

        // Configuration
        $certPath = Civi::settings()->get('wallet_apple_cert');
        $certPass = Civi::settings()->get('wallet_apple_cert_password');
        $passTypeIdentifier = Civi::settings()->get('wallet_apple_pass_type_id');
        $teamIdentifier = Civi::settings()->get('wallet_apple_team_id');
        $orgName = Civi::settings()->get('wallet_org_name') ?: 'SOS homophobie';
        $websiteUrl = Civi::settings()->get('wallet_website_url') ?: 'https://www.sos-homophobie.org';
        $contactUrl = Civi::settings()->get('wallet_contact_url') ?: 'https://www.sos-homophobie.org/nous-contacter';

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

        $serialNumber = $participantId . '-' . time(); // Simple serial number

        // Check if we already have a pass for this participant
        $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_wallet_pass WHERE participant_id = %1 AND type = 'apple'", [1 => [$participantId, 'Integer']]);
        if ($dao->fetch()) {
            $serialNumber = $dao->serial_number;
            $authenticationToken = $dao->authentication_token;
        } else {
            $authenticationToken = md5(uniqid(rand(), true)); // Generate 32-char token
            // Save new pass record
            CRM_Core_DAO::executeQuery("INSERT INTO civicrm_wallet_pass (participant_id, type, serial_number, authentication_token) VALUES (%1, 'apple', %2, %3)", [
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

                // Venue Name Priority
                if (!empty($address['supplemental_address_1'])) {
                    $venueName = $address['supplemental_address_1'];
                } elseif (!empty($address['name'])) {
                    $venueName = $address['name'];
                } elseif (!empty($address['street_address'])) {
                    $venueName = $address['street_address'];
                }

                // Venue Address Construction
                $addressParts = [
                    $address['street_address'] ?? '',
                    $address['supplemental_address_2'] ?? '',
                    $address['city'] ?? '',
                    $address['postal_code'] ?? ''
                ];
                $venueAddress = implode(', ', array_filter($addressParts));

                // GPS
                if (!empty($address['geo_code_1']) && !empty($address['geo_code_2'])) {
                    $locations[] = [
                        'latitude' => (float) $address['geo_code_1'],
                        'longitude' => (float) $address['geo_code_2'],
                        'relevantText' => $event['title'],
                    ];
                }
            }
        }

        // QR Code Content
        $qrCodeContent = (string) $participant['id'];

        // URLs
        if ($websiteUrl && !preg_match("~^(?:f|ht)tps?://~i", $websiteUrl)) {
            $websiteUrl = "https://" . $websiteUrl;
        }
        if ($contactUrl && !preg_match("~^(?:f|ht)tps?://~i", $contactUrl)) {
            $contactUrl = "https://" . $contactUrl;
        }

        // Prepare Back Fields
        $backFields = [];

        // 1. Venue Info (Top of Page 2)
        // Label = Venue Name, Value = Address
        if ($venueName || $venueAddress) {
            $backFields[] = [
                'key' => 'venue-info',
                'label' => $venueName ?: 'LIEU',
                'value' => $venueAddress ?: $venueName,
                'changeMessage' => 'Le lieu a changé : %@',
            ];
        }

        // 2. Description (Only if not empty)
        if (!empty($event['description'])) {
            $backFields[] = [
                'key' => 'description',
                'label' => 'Description',
                'value' => strip_tags($event['description']),
            ];
        }

        // 3. Organizer (Moved from Page 1)
        $backFields[] = [
            'key' => 'organizer-name',
            'label' => 'Organisateur',
            'value' => $orgName,
        ];

        // 4. Website (Informations)
        $backFields[] = [
            'key' => 'website',
            'label' => 'Informations',
            'value' => $websiteUrl,
            'attributedValue' => "<a href='$websiteUrl'>$websiteUrl</a>",
        ];

        // 5. Contact
        $backFields[] = [
            'key' => 'contact',
            'label' => 'Contact',
            'value' => $contactUrl,
            'attributedValue' => "<a href='$contactUrl'>$contactUrl</a>",
        ];

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
            'backgroundColor' => Civi::settings()->get('wallet_color_background') ?: 'rgb(234,20,140)',
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
                'auxiliaryFields' => [
                    [
                        'key' => 'participant-name',
                        'label' => 'TITULAIRE', // Uppercase
                        'value' => $contact['display_name'],
                    ],
                ],
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
        ];

        // Add Images
        $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('org.soshomophobie.wallet');
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
    public static function createGooglePass($participant, $event, $qrCodeContent)
    {
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
        $extUrl = CRM_Extension_System::singleton()->getMapper()->keyToUrl('org.soshomophobie.wallet');
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
                                $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('org.soshomophobie.wallet');
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
            'hexBackgroundColor' => Civi::settings()->get('wallet_color_background') ?: '#ea148c',
            'dateTime' => [
                'start' => date('Y-m-d\TH:i:s', strtotime($event['start_date'])),
                'end' => !empty($event['end_date']) ? date('Y-m-d\TH:i:s', strtotime($event['end_date'])) : null,
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

        // 1. Check/Create/Update Class
        try {
            $service->eventticketclass->get($classId);
            // Update existing class
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

        // 2. Check/Create/Update Object
        $objectMetadata = [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'ticketHolderName' => $contact['display_name'],
            'heroImage' => [
                'sourceUri' => ['uri' => $heroUrl]
            ],
            'textModulesData' => [
                [
                    'header' => E::ts('Organisateur'),
                    'body' => $orgName
                ]
            ],
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

        // 3. Generate JWT Link
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

    /**
     * Update passes for a given entity.
     *
     * @param string $entity 'Event' or 'Participant'
     * @param int $id Entity ID
     */
    /**
     * Queue pass updates for a given entity.
     *
     * @param string $entity 'Event' or 'Participant'
     * @param int $id Entity ID
     */
    public static function updatePasses($entity, $id)
    {
        $participantIds = [];

        if ($entity == 'Event') {
            // Find all participants for this event who have a pass
            $dao = CRM_Core_DAO::executeQuery("
                SELECT p.participant_id 
                FROM civicrm_wallet_pass p
                JOIN civicrm_participant cp ON p.participant_id = cp.id
                WHERE cp.event_id = %1
            ", [1 => [$id, 'Integer']]);
            while ($dao->fetch()) {
                $participantIds[] = $dao->participant_id;
            }
        } elseif ($entity == 'Participant') {
            $participantIds[] = $id;
        }

        if (empty($participantIds)) {
            return;
        }

        // Create or Get Queue
        $queue = CRM_Queue_Service::singleton()->create([
            'type' => 'Sql',
            'name' => 'org.soshomophobie.wallet.updates',
            'runner' => 'task',
        ]);

        foreach ($participantIds as $participantId) {
            $task = new CRM_Queue_Task(
                ['CRM_Wallet_BAO_Pass', 'processPassUpdate'],
                [$participantId],
                "Update Pass for Participant $participantId"
            );
            $queue->createItem($task);
        }
    }

    /**
     * Process a single pass update (Queue Callback).
     *
     * @param CRM_Queue_TaskContext $ctx
     * @param int $participantId
     * @return bool
     */
    public static function processPassUpdate(CRM_Queue_TaskContext $ctx, $participantId)
    {
        // 1. Update Google Wallet (Direct API Call)
        try {
            $participant = civicrm_api3('Participant', 'getsingle', ['id' => $participantId]);
            $event = civicrm_api3('Event', 'getsingle', ['id' => $participant['event_id']]);

            // Get Auth Token
            $dao = CRM_Core_DAO::executeQuery("SELECT authentication_token FROM civicrm_wallet_pass WHERE participant_id = %1 AND type = 'google'", [1 => [$participantId, 'Integer']]);
            if ($dao->fetch()) {
                $authToken = $dao->authentication_token;
            } else {
                // Should not happen for updates, but fallback
                $authToken = md5(uniqid(rand(), true));
            }

            $config = CRM_Core_Config::singleton();
            $baseUrl = $config->userFrameworkBaseURL;
            // User requested QR code to be just the participant ID
            $qrCodeContent = (string) $participantId;

            self::createGooglePass($participant, $event, $qrCodeContent);
        } catch (Exception $e) {
            // Ignore Google errors (e.g. if pass doesn't exist yet, it will be created, which is fine)
            CRM_Core_Error::debug_log_message("Google Wallet Update Error: " . $e->getMessage());
        }

        // 2. Update Apple Wallet (Push Notification)
        // Find devices registered for this participant's pass
        $dao = CRM_Core_DAO::executeQuery("
            SELECT d.push_token, p.authentication_token 
            FROM civicrm_wallet_device d
            JOIN civicrm_wallet_pass p ON d.pass_id = p.id
            WHERE p.participant_id = %1 AND p.type = 'apple'
        ", [1 => [$participantId, 'Integer']]);

        while ($dao->fetch()) {
            self::sendApplePushNotification($dao->push_token);
        }

        // Update last_updated timestamp
        CRM_Core_DAO::executeQuery("UPDATE civicrm_wallet_pass SET last_updated = NOW() WHERE participant_id = %1", [1 => [$participantId, 'Integer']]);

        return true;
    }

    /**
     * Send APNs Push Notification.
     *
     * @param string $pushToken
     */
    public static function sendApplePushNotification($pushToken)
    {
        $certPath = Civi::settings()->get('wallet_apple_cert');
        $certPass = Civi::settings()->get('wallet_apple_cert_password');

        if (!$certPath || !file_exists($certPath)) {
            CRM_Core_Error::debug_log_message("Apple Push Error: Certificate not found or not configured.");
            return;
        }

        // Apple APNs Endpoint (Production)
        // For development, use gateway.sandbox.push.apple.com:2195
        // For production, use gateway.push.apple.com:2195
        // Modern APNs uses HTTP/2, but old binary protocol is deprecated.
        // Let's use the simple binary protocol if it still works, or HTTP/2 if possible.
        // PHP's native stream_socket_client supports SSL.

        // NOTE: Apple has moved to HTTP/2 API. The old binary interface is unsupported.
        // We should use curl with HTTP/2.

        if (!defined('CURL_HTTP_VERSION_2_0')) {
            define('CURL_HTTP_VERSION_2_0', 3);
        }

        $ch = curl_init();
        // Production URL: https://api.push.apple.com/3/device/{token}
        // Development URL: https://api.development.push.apple.com/3/device/{token}
        // We should probably make this configurable. Defaulting to Production.
        // Apple APNs Endpoints
        $endpoints = [
            'production' => "https://api.push.apple.com/3/device/$pushToken",
            'sandbox' => "https://api.development.push.apple.com/3/device/$pushToken"
        ];

        // Try Production first, then Sandbox if BadDeviceToken
        foreach ($endpoints as $env => $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_PORT, 443);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "{}"); // Empty JSON payload
            curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            curl_close($ch); // Close handle immediately

            if ($httpcode === 200) {
                // Success!
                break;
            }

            $responseJson = json_decode($response, true);
            $reason = $responseJson['reason'] ?? 'Unknown';

            if ($httpcode === 400 && $reason === 'BadDeviceToken' && $env === 'production') {
                CRM_Core_Error::debug_log_message("Apple Push Info: BadDeviceToken on Production. Retrying with Sandbox...");
                continue; // Retry with next endpoint (sandbox)
            }

            // If we are here, it's a real error or we failed both attempts
            if ($response === false) {
                // Suppress HTTP2 framing error on Sandbox as it often indicates a dead connection/token
                if ($env === 'sandbox' && strpos($curlError, 'HTTP2 framing layer') !== false) {
                    // Ignore
                } else {
                    CRM_Core_Error::debug_log_message("Apple Push Curl Error ($env): " . $curlError);
                }
            } else {
                CRM_Core_Error::debug_log_message("Apple Push HTTP Error ($env): $httpcode - $response");
            }
            break; // Don't retry other errors
        }
    }

    /**
     * Get Custom Field IDs for Wallet Settings.
     *
     * @return array ['color' => int, 'image' => int]
     */
    private static function getWalletCustomFields()
    {
        static $fields = null;
        if ($fields === null) {
            $fields = [];
            try {
                $colorField = civicrm_api3('CustomField', 'getsingle', ['name' => 'Wallet_Background_Color', 'custom_group_id.name' => 'Wallet_Settings']);
                $fields['color'] = $colorField['id'];
            } catch (Exception $e) {
            }

            try {
                $imageField = civicrm_api3('CustomField', 'getsingle', ['name' => 'Wallet_Image', 'custom_group_id.name' => 'Wallet_Settings']);
                $fields['image'] = $imageField['id'];
            } catch (Exception $e) {
            }
        }
        return $fields;
    }
}
