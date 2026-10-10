<?php

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRGdImagePNG;

class CRM_Wallet_Page_QR extends CRM_Core_Page {

  private function isQrEnabled() {
    return (bool) Civi::settings()->get('wallet_channel_qr_enabled');
  }

  private function isContextEnabled($entity) {
    if ($entity === 'contact') {
      return TRUE;
    }

    $map = [
      'participant' => 'wallet_type_participant_enabled',
      'membership' => 'wallet_type_membership_enabled',
      'donor' => 'wallet_type_donor_enabled',
    ];

    return (bool) Civi::settings()->get($map[$entity] ?? '');
  }

  private function normalizeQrColor($colorHex) {
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

  private function normalizeBackgroundColor($colorHex) {
    $colorHex = ltrim((string) $colorHex, '#');

    return strlen($colorHex) === 6 && ctype_xdigit($colorHex) ? strtolower($colorHex) : 'ffffff';
  }

  private function relativeLuminance($colorHex) {
    $channels = [];
    foreach ([0, 2, 4] as $offset) {
      $channel = hexdec(substr($colorHex, $offset, 2)) / 255;
      $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
  }

  private function ensureQrContrast($foreground, $background) {
    $foregroundLuminance = $this->relativeLuminance($foreground);
    $backgroundLuminance = $this->relativeLuminance($background);
    $contrast = (max($foregroundLuminance, $backgroundLuminance) + 0.05)
      / (min($foregroundLuminance, $backgroundLuminance) + 0.05);

    return $foregroundLuminance < $backgroundLuminance && $contrast >= 4.5
      ? [$foreground, $background]
      : ['000000', 'ffffff'];
  }

  private function resolveParticipantById($participantId) {
    $dao = CRM_Core_DAO::executeQuery(
        'SELECT id, contact_id, event_id FROM civicrm_participant WHERE id = %1 LIMIT 1',
        [1 => [$participantId, 'Integer']]
    );

    if (!$dao->fetch()) {
      return NULL;
    }

    return [
      'id' => (int) $dao->id,
      'contact_id' => (int) $dao->contact_id,
      'event_id' => (int) $dao->event_id,
    ];
  }

  public function run() {
    $entityId = CRM_Utils_Request::retrieve('id', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
    $participantId = CRM_Utils_Request::retrieve('pid', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
    $contactId = CRM_Utils_Request::retrieve('cid', 'Integer', CRM_Core_DAO::$_nullObject, FALSE);
    $entity = CRM_Utils_Request::retrieve('entity', 'String', CRM_Core_DAO::$_nullObject, TRUE);
    $hash = CRM_Utils_Request::retrieve('cs', 'String', CRM_Core_DAO::$_nullObject, FALSE, '');
    $colorHex = CRM_Utils_Request::retrieve('color', 'String', CRM_Core_DAO::$_nullObject, FALSE, '000000');
    $backgroundHex = CRM_Utils_Request::retrieve('background', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'ffffff');
    $colorHex = $this->normalizeQrColor($colorHex);
    $backgroundHex = $this->normalizeBackgroundColor($backgroundHex);
    [$colorHex, $backgroundHex] = $this->ensureQrContrast($colorHex, $backgroundHex);

    if (!in_array($entity, ['participant', 'membership', 'donor', 'contact'], TRUE) || !$this->isQrEnabled() || !$this->isContextEnabled($entity)) {
      CRM_Utils_System::permissionDenied();
    }

    // Determine Contact ID and Entity ID
    if ($entity === 'participant') {
      if ($participantId) {
        $entityId = $participantId;
      }

      if ($entityId) {
        $participant = $this->resolveParticipantById($entityId);
      }
      else {
        CRM_Utils_System::permissionDenied();
      }
      if (!$participant) {
        CRM_Utils_System::permissionDenied();
      }
      $entityId = $participant['id'];
      $contactId = $participant['contact_id'];
      $eventId = $participant['event_id'];
    }
    elseif ($entity === 'membership') {
      if ($entityId) {
        $membership = \Civi\Api4\Membership::get(FALSE)
          ->addWhere('id', '=', $entityId)
          ->execute()->first();
        if (!$membership) {
          CRM_Utils_System::permissionDenied();
        }
        $contactId = $membership['contact_id'];
      }
      elseif ($contactId) {
        $membership = \Civi\Api4\Membership::get(FALSE)
          ->addWhere('contact_id', '=', $contactId)
          ->addOrderBy('end_date', 'DESC')
          ->setLimit(1)
          ->execute()->first();
        if (!$membership) {
          CRM_Utils_System::permissionDenied();
        }
        $entityId = $membership['id'];
      }
      else {
        CRM_Utils_System::permissionDenied();
      }
    }
    else {
      if ($contactId) {
        $entityId = $contactId;
      }
      elseif ($entityId) {
        $contactId = $entityId;
      }
      else {
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
    $isSignedMode = civi_wallet_get_participant_barcode_mode() === 'signed';

    if ($entity === 'participant') {
      $barcodeValue = civi_wallet_generate_participant_barcode($entityId, $contactId, $eventId ?? NULL);
      $entityType = 'Participant';
    }
    elseif ($entity === 'membership') {
      $barcodeValue = civi_wallet_generate_membership_barcode($contactId, $entityId);
      $entityType = 'Membership';
    }
    elseif ($entity === 'donor') {
      $barcodeValue = civi_wallet_generate_donor_barcode($contactId);
      $entityType = 'Contact';
    }
    elseif ($entity === 'contact') {
      if ($isSignedMode) {
        $barcodeValue = civi_wallet_build_signed_contact_barcode($contactId);
      }
      if (empty($barcodeValue)) {
        $barcodeValue = (string) $contactId;
      }
      $entityType = 'Contact';
    }

    $empty = NULL;
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
      'outputBase64'     => FALSE,
      'imageTransparent' => FALSE,
      'bgColor'          => [
        hexdec(substr($backgroundHex, 0, 2)),
        hexdec(substr($backgroundHex, 2, 2)),
        hexdec(substr($backgroundHex, 4, 2)),
      ],
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
    $imageData = $qrcode->render((string) $barcodeValue);

    // Output PNG
    header('Pragma: public');
    // Cache for 24h
    header('Cache-Control: max-age=86400');
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
    header('Content-Type: image/png');
    echo $imageData;
    CRM_Utils_System::civiExit();
  }

}
