<?php

use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_Page_API extends CRM_Core_Page
{

    public function run()
    {
        // Disable debugging output that might corrupt JSON
        $config = CRM_Core_Config::singleton();
        $config->debug = 0;

        $uri = $_SERVER['REQUEST_URI'];
        $method = $_SERVER['REQUEST_METHOD'];



        // Find the API path starting with /v1/ or /redirect
        $pos = strpos($uri, '/v1/');
        $posRedirect = strpos($uri, '/redirect');
        
        if ($posRedirect !== false) {
            $this->handleRedirect();
            CRM_Utils_System::civiExit();
        }

        if ($pos === false) {
            // Not a valid API request
            http_response_code(404);
            CRM_Utils_System::civiExit();
        }

        $path = substr($uri, $pos); // e.g., /v1/devices/...
        $parts = explode('/', ltrim($path, '/'));

        // $parts[0] = 'v1'
        // $parts[1] = 'devices' or 'passes' or 'log'

        try {
            if ($parts[1] == 'devices') {
                // /v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}
                $deviceLibraryIdentifier = $parts[2];
                $action = $parts[3]; // 'registrations'

                if ($action == 'registrations') {
                    $passTypeIdentifier = $parts[4];

                    // Handle optional serialNumber (it might be in the next part)
                    // The URL is .../registrations/{passTypeIdentifier}/{serialNumber}
                    // But sometimes there are query params.
                    // Let's assume standard structure.
                    $serialNumber = $parts[5] ?? null;

                    if ($method == 'POST') {
                        $this->registerDevice($deviceLibraryIdentifier, $passTypeIdentifier, $serialNumber);
                    } elseif ($method == 'DELETE') {
                        $this->unregisterDevice($deviceLibraryIdentifier, $passTypeIdentifier, $serialNumber);
                    } elseif ($method == 'GET') {
                        // Get Serial Numbers
                        // /v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}?passesUpdatedSince={tag}
                        $this->getSerialNumbers($deviceLibraryIdentifier, $passTypeIdentifier);
                    }
                }
            } elseif ($parts[1] == 'passes') {
                // /v1/passes/{passTypeIdentifier}/{serialNumber}
                $passTypeIdentifier = $parts[2];
                $serialNumber = $parts[3];
                if ($method == 'GET') {
                    $this->getLatestPass($passTypeIdentifier, $serialNumber);
                }
            } elseif ($parts[1] == 'log') {
                $this->log();
            }
        } catch (Exception $e) {
            http_response_code(500);
            CRM_Core_Error::debug_log_message("Apple Wallet API Error: " . $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
        }

        CRM_Utils_System::civiExit();
    }

    private function authenticate($pass)
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        // Log headers for debugging


        if (strpos($authHeader, 'ApplePass ') !== 0) {
            CRM_Core_Error::debug_log_message("Apple Wallet Auth Failed: Header format invalid");
            return false;
        }
        $token = substr($authHeader, 10);

        if ($token !== $pass->authentication_token) {
            CRM_Core_Error::debug_log_message("Apple Wallet Auth Failed: Token mismatch");
            return false;
        }

        return true;
    }

    private function registerDevice($deviceId, $passTypeId, $serialNumber)
    {
        CRM_Core_Error::debug_log_message("Apple Wallet Registering Device: $deviceId for Pass: $serialNumber");

        // Verify Pass and Auth Token
        $pass = $this->getPass($serialNumber, $passTypeId);
        if (!$pass) {
            CRM_Core_Error::debug_log_message("Apple Wallet Register Error: Pass not found");
            http_response_code(404);
            return;
        }
        if (!$this->authenticate($pass)) {
            http_response_code(401);
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        $pushToken = $payload['pushToken'] ?? null;



        if (!$pushToken) {
            http_response_code(400);
            return;
        }

        // Insert or Update Device
        $sql = "INSERT INTO civicrm_wallet_device (pass_id, device_library_identifier, push_token) 
            VALUES (%1, %2, %3) 
            ON DUPLICATE KEY UPDATE push_token = %3";

        CRM_Core_DAO::executeQuery($sql, [
            1 => [$pass->id, 'Integer'],
            2 => [$deviceId, 'String'],
            3 => [$pushToken, 'String']
        ]);



        http_response_code(201);
    }

    private function unregisterDevice($deviceId, $passTypeId, $serialNumber)
    {
        $pass = $this->getPass($serialNumber, $passTypeId);
        if (!$pass) {
            http_response_code(404);
            return;
        }
        if (!$this->authenticate($pass)) {
            http_response_code(401);
            return;
        }

        CRM_Core_DAO::executeQuery("DELETE FROM civicrm_wallet_device WHERE pass_id = %1 AND device_library_identifier = %2", [
            1 => [$pass->id, 'Integer'],
            2 => [$deviceId, 'String']
        ]);

        http_response_code(200);
    }

    private function getSerialNumbers($deviceId, $passTypeId)
    {
        // We only support one pass type for now, so we can ignore passTypeId filter strictly speaking, 
        // but we should check if passes exist for this device.

        // passesUpdatedSince is passed as query param
        $updatedSince = CRM_Utils_Request::retrieve('passesUpdatedSince', 'String');

        // Find all passes for this device
        $sql = "SELECT p.serial_number, p.last_updated 
            FROM civicrm_wallet_pass p
            JOIN civicrm_wallet_device d ON d.pass_id = p.id
            WHERE d.device_library_identifier = %1";

        $params = [1 => [$deviceId, 'String']];

        if ($updatedSince) {
            $sql .= " AND p.last_updated > %2";
            // Convert tag to date? Apple sends a tag. We use timestamp.
            // If we used timestamp as tag, we can compare.
            // Let's assume tag is timestamp.
            $params[2] = [$updatedSince, 'String'];
        }

        $dao = CRM_Core_DAO::executeQuery($sql, $params);
        $serialNumbers = [];
        $lastUpdated = 0;

        while ($dao->fetch()) {
            $serialNumbers[] = $dao->serial_number;
            $ts = strtotime($dao->last_updated);
            if ($ts > $lastUpdated) {
                $lastUpdated = $ts;
            }
        }

        if (empty($serialNumbers)) {
            http_response_code(204); // No Content
            return;
        }

        echo json_encode([
            'lastUpdated' => (string) $lastUpdated,
            'serialNumbers' => $serialNumbers
        ]);
    }

    private function getLatestPass($passTypeId, $serialNumber)
    {
        $pass = $this->getPass($serialNumber, $passTypeId);
        if (!$pass) {
            http_response_code(404);
            return;
        }
        if (!$this->authenticate($pass)) {
            http_response_code(401);
            return;
        }

        // Check If-Modified-Since
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            $lastModified = strtotime($pass->last_updated);

            if ($lastModified <= $ifModifiedSince) {
                http_response_code(304);
                return;
            }
        }

        // Generate Pass Content
        // We need to call BAO but BAO currently echoes or returns content.
        // We need to make sure BAO doesn't re-generate serial number or auth token if they exist.
        // The current BAO implementation handles this (checks DB first).

        $entityId = $pass->entity_id;
        $entityTable = $pass->entity_table;

        try {
            $content = CRM_Wallet_BAO_Pass::createApplePass($entityTable, $entityId);

            header('Pragma: no-cache');
            header('Content-Type: application/vnd.apple.pkpass');
            header('Content-Length: ' . strlen($content));
            header('Content-Disposition: attachment; filename="pass.pkpass"');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($pass->last_updated)) . ' GMT');

            echo $content;
        } catch (Exception $e) {
            http_response_code(500);
        }
    }

    private function log()
    {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!empty($payload['logs'])) {
            foreach ($payload['logs'] as $log) {
                CRM_Core_Error::debug_log_message("Apple Wallet Log: " . $log);
            }
        }
        http_response_code(200);
    }

    private function getPass($serialNumber, $passTypeId)
    {
        $expectedPassTypeId = \Civi::settings()->get('wallet_apple_pass_type_id');
        if ($expectedPassTypeId && $passTypeId !== $expectedPassTypeId) {
            return null;
        }
        $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_wallet_pass WHERE serial_number = %1", [
            1 => [$serialNumber, 'String']
        ]);
        if ($dao->fetch()) {
            return $dao;
        }
        return null;
    }

    private function handleRedirect()
    {
        $token = CRM_Utils_Request::retrieve('token', 'String');
        $action = CRM_Utils_Request::retrieve('action', 'String');

        if (!$token || !$action) {
            CRM_Core_Error::debug_log_message("Wallet Redirect Error: Missing parameters");
            CRM_Utils_System::civiExit();
        }

        // Find the pass by token
        $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_wallet_pass WHERE authentication_token = %1", [
            1 => [$token, 'String']
        ]);

        if (!$dao->fetch() || $dao->entity_table !== 'civicrm_contact') {
            CRM_Core_Error::debug_log_message("Wallet Redirect Error: Invalid token or not a contact pass");
            echo "Ce lien n'est plus valide ou le pass n'existe pas.";
            CRM_Utils_System::civiExit();
        }

        $contactId = $dao->entity_id;

        // Generate Checksum
        $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);

        // Get Base URLs from settings
        $renewUrlBase = Civi::settings()->get('wallet_membership_renew_url') ?: 'civicrm/contribute/transact?reset=1&id=1';
        $profileUrlBase = Civi::settings()->get('wallet_membership_profile_url') ?: 'civicrm/profile/edit?reset=1&gid=1';

        $baseUrl = ($action === 'renew') ? $renewUrlBase : $profileUrlBase;

        // Append cid and cs
        $separator = (strpos($baseUrl, '?') === false) ? '?' : '&';
        $finalUrl = CRM_Utils_System::url($baseUrl, "cid={$contactId}&cs={$cs}", true, null, false);

        // Redirect
        CRM_Utils_System::redirect($finalUrl);
    }
}
