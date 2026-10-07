<?php

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRGdImagePNG;

class CRM_Wallet_Page_QR extends CRM_Core_Page
{
    private function isQrEnabled()
    {
        return (bool) Civi::settings()->get('wallet_channel_qr_enabled');
    }

    private function isContextEnabled($entity)
    {
        $map = [
            'participant' => 'wallet_type_participant_enabled',
            'membership' => 'wallet_type_membership_enabled',
            'donor' => 'wallet_type_donor_enabled',
        ];

        return (bool) Civi::settings()->get($map[$entity] ?? '');
    }

    private function normalizeQrColor($colorHex)
    {
        $colorHex = ltrim((string) $colorHex, '#');

        if (strlen($colorHex) !== 6 || !ctype_xdigit($colorHex)) {
            return '000000';
        }

        $r = hexdec(substr($colorHex, 0, 2));
        $g = hexdec(substr($colorHex, 2, 2));
        $b = hexdec(substr($colorHex, 4, 2));
        $luminance = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);

        return $luminance > 200 ? '000000' : strtolower($colorHex);
    }

    private function resolveParticipantByContact($contactId)
    {
        return \Civi\Api4\Participant::get(FALSE)
            ->addSelect('id', 'contact_id')
            ->addWhere('contact_id', '=', $contactId)
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();
    }

    public function run()
    {
        $entityId = CRM_Utils_Request::retrieve('id', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
        $participantId = CRM_Utils_Request::retrieve('pid', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
        $contactId = CRM_Utils_Request::retrieve('cid', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
        $entity = CRM_Utils_Request::retrieve('entity', 'String', CRM_Core_DAO::$_nullObject, TRUE);
        $hash = CRM_Utils_Request::retrieve('cs', 'String', CRM_Core_DAO::$_nullObject, FALSE, '');
        $colorHex = CRM_Utils_Request::retrieve('color', 'String', CRM_Core_DAO::$_nullObject, FALSE, '000000');
        $colorHex = $this->normalizeQrColor($colorHex);

        if (!in_array($entity, ['participant', 'membership', 'donor']) || !$this->isQrEnabled() || !$this->isContextEnabled($entity)) {
            CRM_Utils_System::permissionDenied();
        }

        // Determine Contact ID and Entity ID
        if ($entity === 'participant') {
            if ($participantId) {
                $entityId = $participantId;
            }

            if ($entityId) {
                $participant = \Civi\Api4\Participant::get(FALSE)
                    ->addWhere('id', '=', $entityId)
                    ->execute()
                    ->first();
            } elseif ($contactId) {
                $participant = $this->resolveParticipantByContact($contactId);
            } else {
                CRM_Utils_System::permissionDenied();
            }
            if (!$participant) {
                CRM_Utils_System::permissionDenied();
            }
            $entityId = $participant['id'];
            $contactId = $participant['contact_id'];
        } elseif ($entity === 'membership') {
            if ($entityId) {
                $membership = \Civi\Api4\Membership::get(FALSE)
                    ->addWhere('id', '=', $entityId)
                    ->execute()->first();
                if (!$membership) CRM_Utils_System::permissionDenied();
                $contactId = $membership['contact_id'];
            } elseif ($contactId) {
                $membership = \Civi\Api4\Membership::get(FALSE)
                    ->addWhere('contact_id', '=', $contactId)
                    ->addOrderBy('end_date', 'DESC')
                    ->setLimit(1)
                    ->execute()->first();
                if (!$membership) CRM_Utils_System::permissionDenied();
                $entityId = $membership['id'];
            } else {
                CRM_Utils_System::permissionDenied();
            }
        } elseif ($entity === 'donor') {
            if ($contactId) {
                // For donor, entityId IS contactId in context of barcode (unless otherwise specified)
                $entityId = $contactId; 
            } elseif ($entityId) {
                $contactId = $entityId;
            } else {
                CRM_Utils_System::permissionDenied();
            }
        }

        // Security Check
        $isAdmin = CRM_Core_Permission::check('edit all events');
        $isValidChecksum = CRM_Contact_BAO_Contact_Utils::validChecksum($contactId, $hash);
        if (!$isAdmin && !$isValidChecksum) {
            CRM_Utils_System::permissionDenied();
        }

        // Generate Barcode Value
        $barcodeValue = '';
        if ($entity === 'participant') {
            $barcodeToken = Civi::settings()->get('wallet_barcode_participant') ?: '{participant.id}';
            if (class_exists('\Civi\Token\TokenProcessor')) {
                $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => false]);
                $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
                $tokenProcessor->addRow(['contactId' => $contactId, 'participantId' => $entityId]);
                $tokenProcessor->evaluate();
                $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
            } else {
                $barcodeValue = $entityId;
            }
            $entityType = 'Participant';
        } elseif ($entity === 'membership') {
            $barcodeToken = Civi::settings()->get('wallet_barcode_membership') ?: '{contact.id}';
            if (class_exists('\Civi\Token\TokenProcessor')) {
                $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => false]);
                $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
                $tokenProcessor->addRow(['contactId' => $contactId, 'membershipId' => $entityId]);
                $tokenProcessor->evaluate();
                $barcodeValue = $tokenProcessor->getRow(0)->render('barcode');
            } else {
                $barcodeValue = $contactId;
            }
            $entityType = 'Membership';
        } elseif ($entity === 'donor') {
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
            $entityType = 'Contact'; // For alter hook
        }

        $empty = null;
        CRM_Utils_Hook::singleton()->invoke(['barcodeValue', 'entityType', 'entityId'], $barcodeValue, $entityType, $entityId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');

        if (empty($barcodeValue)) {
            CRM_Utils_System::civiExit();
        }

        // Generate Image
        $options = new QROptions([
            'version'          => \chillerlan\QRCode\Common\Version::AUTO,
            'outputInterface'  => QRGdImagePNG::class,
            'eccLevel'         => EccLevel::M,
            'scale'            => 10,
            'outputBase64'     => false,
            'imageTransparent' => true,
        ]);

        if (!empty($colorHex) && strlen($colorHex) === 6 && ctype_xdigit($colorHex)) {
            $r = hexdec(substr($colorHex, 0, 2));
            $g = hexdec(substr($colorHex, 2, 2));
            $b = hexdec(substr($colorHex, 4, 2));
            $rgb = [$r, $g, $b];

            if (class_exists('\chillerlan\QRCode\Data\QRMatrix')) {
                $options->moduleValues = [
                    QRMatrix::M_FINDER_DARK => $rgb,
                    QRMatrix::M_ALIGNMENT_DARK => $rgb,
                    QRMatrix::M_TIMING_DARK => $rgb,
                    QRMatrix::M_FORMAT_DARK => $rgb,
                    QRMatrix::M_VERSION_DARK => $rgb,
                    QRMatrix::M_DATA_DARK => $rgb,
                    QRMatrix::M_DARKMODULE => $rgb,
                ];
            }
        }

        $qrcode = new QRCode($options);
        $imageData = $qrcode->render((string)$barcodeValue);

        // Output PNG
        header('Pragma: public');
        header('Cache-Control: max-age=86400'); // Cache for 24h
        header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
        header('Content-Type: image/png');
        echo $imageData;
        CRM_Utils_System::civiExit();
    }
}
