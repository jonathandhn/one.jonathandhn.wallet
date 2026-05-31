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
        public static function createApplePass($entityTable, $entityId)
    {
        if ($entityTable === 'civicrm_participant') {
            return CRM_Wallet_PassType_Participant::createApplePass($entityId);
        } elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
            return CRM_Wallet_PassType_Membership::createApplePass($entityId);
        }
        throw new Exception("Unsupported entity table for Apple Wallet: $entityTable");
    }

    public static function createGooglePass($entityTable, $entityId)
    {
        if ($entityTable === 'civicrm_participant') {
            return CRM_Wallet_PassType_Participant::createGooglePass($entityId);
        } elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
            return CRM_Wallet_PassType_Membership::createGooglePass($entityId);
        }
        throw new Exception("Unsupported entity table for Google Wallet: $entityTable");
    }

        public static function updatePasses($entity, $id)
    {
        $tasks = [];

        if ($entity == 'Event') {
            $dao = CRM_Core_DAO::executeQuery("
                SELECT p.entity_id 
                FROM civicrm_wallet_pass p
                JOIN civicrm_participant cp ON p.entity_id = cp.id
                WHERE cp.event_id = %1 AND p.entity_table = 'civicrm_participant'
            ", [1 => [$id, 'Integer']]);
            while ($dao->fetch()) {
                $tasks[] = ['table' => 'civicrm_participant', 'id' => $dao->entity_id];
            }
        } elseif ($entity == 'Participant') {
            $tasks[] = ['table' => 'civicrm_participant', 'id' => $id];
        } elseif ($entity == 'Membership') {
            // Find contact for this membership
            $membership = civicrm_api3('Membership', 'getsingle', ['id' => $id]);
            $tasks[] = ['table' => 'civicrm_contact', 'id' => $membership['contact_id']];
        } elseif ($entity == 'Contact') {
            $tasks[] = ['table' => 'civicrm_contact', 'id' => $id];
        }

        if (empty($tasks)) {
            return;
        }

        $queue = CRM_Queue_Service::singleton()->create([
            'type' => 'Sql',
            'name' => 'one.jonathandhn.wallet.updates',
            'runner' => 'task',
        ]);

        foreach ($tasks as $taskInfo) {
            $task = new CRM_Queue_Task(
                ['CRM_Wallet_BAO_Pass', 'processPassUpdate'],
                [$taskInfo['table'], $taskInfo['id']],
                "Update Pass for {$taskInfo['table']} {$taskInfo['id']}"
            );
            $queue->createItem($task);
        }
    }

    public static function processPassUpdate(CRM_Queue_TaskContext $ctx, $participantId)
    {
        // 1. Update Google Wallet (Direct API Call)
        try {
            $participant = civicrm_api3('Participant', 'getsingle', ['id' => $participantId]);
            $event = civicrm_api3('Event', 'getsingle', ['id' => $participant['event_id']]);

            // Get Auth Token
            $dao = CRM_Core_DAO::executeQuery("SELECT authentication_token FROM civicrm_wallet_pass WHERE participant_id = %1 AND type = 'google'", [1 => [$entityTable, 'String'], 2 => [$entityId, 'Integer']]);
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
            WHERE p.entity_table = %1 AND p.entity_id = %2 AND p.type = 'apple'
        ", [1 => [$entityTable, 'String'], 2 => [$entityId, 'Integer']]);

        while ($dao->fetch()) {
            self::sendApplePushNotification($dao->push_token);
        }

        // Update last_updated timestamp
        CRM_Core_DAO::executeQuery("UPDATE civicrm_wallet_pass SET last_updated = NOW() WHERE entity_table = %1 AND entity_id = %2", [1 => [$entityTable, 'String'], 2 => [$entityId, 'Integer']]);

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
