<?php

class CRM_Wallet_Page_Pass extends CRM_Core_Page
{
    public function run()
    {
        $participantId = CRM_Utils_Request::retrieve('pid', 'Integer');
        $contactId = CRM_Utils_Request::retrieve('cid', 'Integer');
        $hash = CRM_Utils_Request::retrieve('cs', 'String'); // Checksum
        $type = CRM_Utils_Request::retrieve('type', 'String', NULL, FALSE, 'apple');

        $context = CRM_Utils_Request::retrieve('context', 'String');

        if (!$participantId && !$contactId) {
            CRM_Utils_System::permissionDenied();
        }

        $entityTable = $participantId ? 'civicrm_participant' : ($context === 'donor' ? 'civicrm_donor' : 'civicrm_contact');
        $entityId = $participantId ? $participantId : $contactId;

        // Fetch participant to get Contact ID if it's an event ticket
        if ($participantId) {
            try {
                $participant = \Civi\Api4\Participant::get(FALSE)
                    ->addWhere('id', '=', $participantId)
                    ->execute()->first();
                if (!$participant) {
                    throw new Exception("Participant not found");
                }
                $contactId = $participant['contact_id'];
            } catch (Exception $e) {
                CRM_Utils_System::permissionDenied();
            }
        }

        // Security Check: Allow if Admin OR valid Checksum
        $isAdmin = CRM_Core_Permission::check('edit all events');
        $isValidChecksum = CRM_Contact_BAO_Contact_Utils::validChecksum($contactId, $hash);

        if (!$isAdmin && !$isValidChecksum) {
            CRM_Utils_System::permissionDenied();
        }

        try {
            if ($type === 'apple') {
                $content = CRM_Wallet_BAO_Pass::createApplePass($entityTable, $entityId);

                // Output headers and content
                header('Pragma: no-cache');
                header('Content-Type: application/vnd.apple.pkpass');
                header('Content-Length: ' . strlen($content));
                header('Content-Disposition: attachment; filename="pass.pkpass"');
                echo $content;
                CRM_Utils_System::civiExit();
            } else {
                // Google Wallet
                $url = CRM_Wallet_BAO_Pass::createGooglePass($entityTable, $entityId);
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
