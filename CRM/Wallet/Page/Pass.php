<?php

class CRM_Wallet_Page_Pass extends CRM_Core_Page
{
    private function isChannelEnabled($type)
    {
        $map = [
            'apple' => 'wallet_channel_apple_enabled',
            'google' => 'wallet_channel_google_enabled',
        ];

        return (bool) Civi::settings()->get($map[$type] ?? '');
    }

    private function isContextEnabled($context)
    {
        $map = [
            'participant' => 'wallet_type_participant_enabled',
            'membership' => 'wallet_type_membership_enabled',
            'donor' => 'wallet_type_donor_enabled',
        ];

        return (bool) Civi::settings()->get($map[$context] ?? '');
    }

    private function resolveParticipantIdFromContact($contactId)
    {
        $participant = \Civi\Api4\Participant::get(FALSE)
            ->addSelect('id')
            ->addWhere('contact_id', '=', $contactId)
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();

        return $participant['id'] ?? null;
    }

    public function run()
    {
        $participantId = CRM_Utils_Request::retrieve('pid', 'Integer');
        $contactId = CRM_Utils_Request::retrieve('cid', 'Integer');
        $hash = CRM_Utils_Request::retrieve('cs', 'String'); // Checksum
        $type = CRM_Utils_Request::retrieve('type', 'String', NULL, FALSE, 'apple');

        $context = CRM_Utils_Request::retrieve('context', 'String');
        if (!$context) {
            $context = $participantId ? 'participant' : 'membership';
        }

        if (!$participantId && !$contactId) {
            CRM_Utils_System::permissionDenied();
        }

        if (!$this->isChannelEnabled($type) || !$this->isContextEnabled($context)) {
            CRM_Utils_System::permissionDenied();
        }

        if (!$participantId && $contactId && $context === 'participant') {
            $participantId = $this->resolveParticipantIdFromContact($contactId);
            if (!$participantId) {
                CRM_Utils_System::permissionDenied();
            }
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
            CRM_Core_Error::debug_log_message("Wallet Error: " . $e->getMessage());
            CRM_Core_Session::setStatus(ts('Unable to generate the requested wallet pass.'), ts('Wallet Error'), 'error');
            CRM_Utils_System::permissionDenied();
            return;
        }

        CRM_Utils_System::civiExit();
    }
}
