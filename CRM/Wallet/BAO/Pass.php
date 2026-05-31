<?php

use PKPass\PKPass;
use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_BAO_Pass extends CRM_Core_DAO
{

    /**
     * Create an Apple Wallet Pass for a participant or membership.
     */
    public static function createApplePass($entityTable, $entityId)
    {
        if ($entityTable === 'civicrm_participant') {
            return CRM_Wallet_PassType_Participant::createApplePass($entityId);
        } elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
            return CRM_Wallet_PassType_Membership::createApplePass($entityId);
        } elseif ($entityTable === 'civicrm_donor') {
            return CRM_Wallet_PassType_Donor::createApplePass($entityId);
        }
        throw new Exception("Unsupported entity table for Apple Wallet: $entityTable");
    }

    public static function createGooglePass($entityTable, $entityId)
    {
        if ($entityTable === 'civicrm_participant') {
            return CRM_Wallet_PassType_Participant::createGooglePass($entityId);
        } elseif ($entityTable === 'civicrm_contact' || $entityTable === 'civicrm_membership') {
            return CRM_Wallet_PassType_Membership::createGooglePass($entityId);
        } elseif ($entityTable === 'civicrm_donor') {
            return CRM_Wallet_PassType_Donor::createGooglePass($entityId);
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
            $membership = \Civi\Api4\Membership::get(FALSE)->addWhere('id', '=', $id)->execute()->first();
            if ($membership) {
                $tasks[] = ['table' => 'civicrm_contact', 'id' => $membership['contact_id']];
            }
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

    public static function processPassUpdate(CRM_Queue_TaskContext $ctx, $entityTable, $entityId)
    {
        // 1. Update Google Wallet
        try {
            self::createGooglePass($entityTable, $entityId);
        } catch (Exception $e) {
            // Ignore Google errors
            CRM_Core_Error::debug_log_message("Google Wallet Update Error: " . $e->getMessage());
        }

        // 2. Update Apple Wallet (Push Notification)
        $dao = CRM_Core_DAO::executeQuery("
            SELECT d.push_token
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

    public static function sendApplePushNotification($pushToken)
    {
        $certPath = Civi::settings()->get('wallet_apple_cert');
        $certPass = Civi::settings()->get('wallet_apple_cert_password');

        if (!$certPath || !file_exists($certPath)) {
            CRM_Core_Error::debug_log_message("Apple Push Error: Certificate not found or not configured.");
            return;
        }

        if (!defined('CURL_HTTP_VERSION_2_0')) {
            define('CURL_HTTP_VERSION_2_0', 3);
        }

        $endpoints = [
            'production' => "https://api.push.apple.com/3/device/$pushToken",
            'sandbox' => "https://api.development.push.apple.com/3/device/$pushToken"
        ];

        foreach ($endpoints as $env => $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_PORT, 443);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
            curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
            curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $certPass);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            curl_close($ch);

            if ($httpcode === 200) {
                break;
            }

            $responseJson = json_decode($response, true);
            $reason = $responseJson['reason'] ?? 'Unknown';

            if ($httpcode === 400 && $reason === 'BadDeviceToken' && $env === 'production') {
                CRM_Core_Error::debug_log_message("Apple Push Info: BadDeviceToken on Production. Retrying with Sandbox...");
                continue;
            }

            if ($response === false) {
                if ($env === 'sandbox' && strpos($curlError, 'HTTP2 framing layer') !== false) {
                    // Ignore
                } else {
                    CRM_Core_Error::debug_log_message("Apple Push Curl Error ($env): " . $curlError);
                }
            } else {
                CRM_Core_Error::debug_log_message("Apple Push HTTP Error ($env): $httpcode - $response");
            }
            break;
        }
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
