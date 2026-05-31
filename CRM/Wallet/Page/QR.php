<?php

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Data\QRMatrix;

class CRM_Wallet_Page_QR extends CRM_Core_Page
{
    public function run()
    {
        $entityId = CRM_Utils_Request::retrieve('id', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
        $contactId = CRM_Utils_Request::retrieve('cid', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
        $entity = CRM_Utils_Request::retrieve('entity', 'String', CRM_Core_DAO::$_nullObject, TRUE);
        $hash = CRM_Utils_Request::retrieve('cs', 'String', CRM_Core_DAO::$_nullObject, FALSE, '');
        $colorHex = CRM_Utils_Request::retrieve('color', 'String', CRM_Core_DAO::$_nullObject, FALSE, '000000');
        $colorHex = ltrim($colorHex, '#');

        if (!in_array($entity, ['participant', 'membership', 'donor'])) {
            CRM_Utils_System::permissionDenied();
        }

        // Determine Contact ID and Entity ID
        if ($entity === 'participant') {
            if (!$entityId) CRM_Utils_System::permissionDenied();
            $participant = \Civi\Api4\Participant::get(FALSE)
                ->addWhere('id', '=', $entityId)
                ->execute()->first();
            if (!$participant) {
                CRM_Utils_System::permissionDenied();
            }
            $contactId = $participant['contact_id'];
        } else {
            if ($entityId) {
                $membership = \Civi\Api4\Membership::get(FALSE)
                    ->addWhere('id', '=', $entityId)
                    ->execute()->first();
                if (!$membership) CRM_Utils_System::permissionDenied();
                $contactId = $membership['contact_id'];
            } else if ($contactId) {
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
        } else if ($entity === 'donor') {
            if ($contactId) {
                // For donor, entityId IS contactId in context of barcode (unless otherwise specified)
                $entityId = $contactId; 
            } else if ($entityId) {
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
        } else {
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
        } else if ($entity === 'donor') {
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
        CRM_Utils_Hook::singleton()->invoke(3, $barcodeValue, $entityType, $entityId, $empty, $empty, $empty, 'civicrm_wallet_barcode_alter');

        if (empty($barcodeValue)) {
            CRM_Utils_System::civiExit();
        }

        // Generate Image
        $options = new QROptions([
            'version'      => \chillerlan\QRCode\Common\Version::AUTO,
            'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel'     => QRCode::ECC_M,
            'scale'        => 10,
            'imageBase64'  => false,
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
