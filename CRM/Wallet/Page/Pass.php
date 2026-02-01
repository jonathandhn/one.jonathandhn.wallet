<?php

class CRM_Wallet_Page_Pass extends CRM_Core_Page
{
    public function run()
    {
        $participantId = CRM_Utils_Request::retrieve('pid', 'Integer');
        $hash = CRM_Utils_Request::retrieve('cs', 'String'); // Checksum
        $type = CRM_Utils_Request::retrieve('type', 'String', NULL, FALSE, 'apple');

        if (!$participantId) {
            CRM_Utils_System::permissionDenied();
        }

        // Fetch participant to get Contact ID
        try {
            $participant = civicrm_api3('Participant', 'getsingle', ['id' => $participantId]);
            $contactId = $participant['contact_id'];
        } catch (Exception $e) {
            CRM_Utils_System::permissionDenied();
        }

        // Security Check: Allow if Admin OR valid Checksum
        $isAdmin = CRM_Core_Permission::check('edit all events');
        $isValidChecksum = CRM_Contact_BAO_Contact_Utils::validChecksum($contactId, $hash);

        if (!$isAdmin && !$isValidChecksum) {
            CRM_Utils_System::permissionDenied();
        }

        try {
            if ($type === 'apple') {
                $content = CRM_Wallet_BAO_Pass::createApplePass($participantId);

                // Output headers and content
                header('Pragma: no-cache');
                header('Content-Type: application/vnd.apple.pkpass');
                header('Content-Length: ' . strlen($content));
                header('Content-Disposition: attachment; filename="pass.pkpass"');
                echo $content;
                CRM_Utils_System::civiExit();
            } else {
                // Google Wallet
                $event = civicrm_api3('Event', 'getsingle', ['id' => $participant['event_id']]);

                // Get Auth Token
                $dao = CRM_Core_DAO::executeQuery("SELECT authentication_token FROM civicrm_wallet_pass WHERE participant_id = %1 AND type = 'google'", [1 => [$participantId, 'Integer']]);
                if ($dao->fetch()) {
                    $authToken = $dao->authentication_token;
                } else {
                    $authToken = md5(uniqid(rand(), true));
                    // We might need to insert it if it doesn't exist, but createGooglePass usually handles the pass record creation?
                    // Actually createGooglePass in BAO doesn't seem to insert the DB record for the pass itself if it's missing?
                    // Let's check BAO. But for now, let's assume we need a token.
                    // Wait, createApplePass inserts it. createGooglePass logic in BAO seems to assume it exists or doesn't explicitly handle DB insert for 'google' type in the snippet I saw?
                    // Let's look at BAO again.
                    // Actually, let's just generate the token. If the record doesn't exist, we should probably create it or let the BAO handle it.
                    // Re-reading BAO createGooglePass... it DOES NOT insert into civicrm_wallet_pass.
                    // So we should probably insert it here or ensure it exists.
                    // However, to be safe and quick, let's just generate the QR content.

                    // Ideally we should check if a pass exists, if not create one.
                    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_wallet_pass (participant_id, type, serial_number, authentication_token) VALUES (%1, 'google', %2, %3)", [
                        1 => [$participantId, 'Integer'],
                        2 => [$participantId . '-' . time(), 'String'],
                        3 => [$authToken, 'String']
                    ]);
                }

                $config = CRM_Core_Config::singleton();
                $baseUrl = $config->userFrameworkBaseURL;
                // User requested QR code to be just the participant ID
                $qrCodeContent = (string) $participantId;

                $url = CRM_Wallet_BAO_Pass::createGooglePass($participant, $event, $qrCodeContent);
                CRM_Utils_System::redirect($url);
            }
        } catch (Throwable $e) {
            // Debug: Print error directly
            echo "Error: " . $e->getMessage();
            echo "<br>Trace: <pre>" . $e->getTraceAsString() . "</pre>";
            CRM_Core_Error::debug_log_message("Wallet Error: " . $e->getMessage());
            return;
        }

        CRM_Utils_System::civiExit();
    }
}
