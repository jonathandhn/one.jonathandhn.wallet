<?php

require_once 'civi_wallet.civix.php';

function civi_wallet_register_prefixed_vendor_autoloader($baseDir) {
  static $registered = FALSE;

  if ($registered) {
    return;
  }

  $registered = TRUE;

  $prefixes = [
    'Civi\\Wallet\\Vendor\\Composer\\Autoload\\' => [$baseDir . '/vendor/composer'],
    'Civi\\Wallet\\Vendor\\chillerlan\\Settings\\' => [$baseDir . '/vendor/chillerlan/php-settings-container/src'],
    'Civi\\Wallet\\Vendor\\chillerlan\\QRCode\\' => [$baseDir . '/vendor/chillerlan/php-qrcode/src'],
    'Civi\\Wallet\\Vendor\\Psr\\Log\\' => [$baseDir . '/vendor/psr/log/src'],
    'Civi\\Wallet\\Vendor\\Psr\\Http\\Message\\' => [
      $baseDir . '/vendor/psr/http-factory/src',
      $baseDir . '/vendor/psr/http-message/src',
    ],
    'Civi\\Wallet\\Vendor\\Psr\\Http\\Client\\' => [$baseDir . '/vendor/psr/http-client/src'],
    'Civi\\Wallet\\Vendor\\Psr\\Cache\\' => [$baseDir . '/vendor/psr/cache/src'],
    'Civi\\Wallet\\Vendor\\Monolog\\' => [$baseDir . '/vendor/monolog/monolog/src/Monolog'],
    'Civi\\Wallet\\Vendor\\GuzzleHttp\\Psr7\\' => [$baseDir . '/vendor/guzzlehttp/psr7/src'],
    'Civi\\Wallet\\Vendor\\GuzzleHttp\\Promise\\' => [$baseDir . '/vendor/guzzlehttp/promises/src'],
    'Civi\\Wallet\\Vendor\\GuzzleHttp\\' => [$baseDir . '/vendor/guzzlehttp/guzzle/src'],
    'Civi\\Wallet\\Vendor\\Google\\Service\\' => [$baseDir . '/vendor/google/apiclient-services/src'],
    'Civi\\Wallet\\Vendor\\Google\\Auth\\' => [$baseDir . '/vendor/google/auth/src'],
    'Civi\\Wallet\\Vendor\\Google\\' => [$baseDir . '/vendor/google/apiclient/src'],
    'Civi\\Wallet\\Vendor\\Firebase\\JWT\\' => [$baseDir . '/vendor/firebase/php-jwt/src'],
    'Civi\\Wallet\\Vendor\\PKPass\\' => [$baseDir . '/vendor/pkpass/pkpass/src'],
    'Civi\\Wallet\\Vendor\\Symfony\\Polyfill\\Php80\\' => [$baseDir . '/vendor/symfony/polyfill-php80'],
    'Civi\\Wallet\\Vendor\\Symfony\\Contracts\\Service\\' => [$baseDir . '/vendor/symfony/service-contracts'],
    'Civi\\Wallet\\Vendor\\Symfony\\Contracts\\EventDispatcher\\' => [$baseDir . '/vendor/symfony/event-dispatcher-contracts'],
  ];

  spl_autoload_register(static function ($class) use ($prefixes) {
    foreach ($prefixes as $prefix => $dirs) {
      if (!str_starts_with($class, $prefix)) {
        continue;
      }

      $relativePath = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

      foreach ($dirs as $dir) {
        $file = $dir . '/' . $relativePath;
        if (file_exists($file)) {
          require_once $file;
          return;
        }
      }
    }
  }, TRUE, TRUE);
}

civi_wallet_register_prefixed_vendor_autoloader(__DIR__);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
  if (file_exists(__DIR__ . '/vendor/scoper-autoload.php')) {
    require_once __DIR__ . '/vendor/scoper-autoload.php';
  }
}
use CRM_Wallet_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function civi_wallet_civicrm_config(&$config) {
  _civi_wallet_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function civi_wallet_civicrm_install() {
  _civi_wallet_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function civi_wallet_civicrm_enable() {
  _civi_wallet_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_tabset().
 */
function civi_wallet_civicrm_tabset($tabsetName, &$tabs, $context) {
  if ($tabsetName !== 'civicrm/event/manage') {
    return;
  }

  $eventID = (int) ($context['event_id'] ?? 0);
  if ($eventID <= 0) {
    return;
  }

  if (!CRM_Core_Permission::check('edit all events') && !CRM_Core_Permission::check('administer CiviCRM')) {
    return;
  }

  $tabs[] = [
    'id' => 'wallet',
    'title' => E::ts('Wallet'),
    'url' => CRM_Utils_System::url('civicrm/event/manage/wallet', "reset=1&snippet=1&action=update&id={$eventID}"),
    'weight' => 95,
    'icon' => 'crm-i fa-wallet',
  ];
}

/**
 * Implements hook_civicrm_post().
 *
 * Trigger pass updates when Event or Participant is modified.
 */
function civi_wallet_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($op == 'edit' || $op == 'create') {
    if ($objectName == 'Event') {
      // Update all passes for this event
      CRM_Wallet_BAO_Pass::updatePasses('Event', $objectId);
    }
    elseif ($objectName == 'Participant') {
      // Update pass for this participant
      CRM_Wallet_BAO_Pass::updatePasses('Participant', $objectId);
    }
    elseif ($objectName == 'Membership') {
      // Update pass for this membership's contact
      CRM_Wallet_BAO_Pass::updatePasses('Membership', $objectId);
    }
    elseif ($objectName == 'Contact') {
      // Update pass for this contact
      CRM_Wallet_BAO_Pass::updatePasses('Contact', $objectId);
    }
    elseif ($objectName == 'Contribution') {
      try {
        $contribution = \Civi\Api4\Contribution::get(FALSE)
          ->addSelect('contact_id', 'contribution_status_id:name')
          ->addWhere('id', '=', $objectId)
          ->execute()
          ->first();

        if ($contribution && !empty($contribution['contact_id']) && ($contribution['contribution_status_id:name'] ?? NULL) === 'Completed') {
          CRM_Wallet_BAO_Pass::updatePasses('Donor', $contribution['contact_id']);
        }
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_log_message("CiviWallet Contribution Hook Error: " . $e->getMessage());
      }
    }
  }
}

/**
 * Implements hook_civicrm_cron().
 *
 * Process the wallet update queue.
 */
function civi_wallet_civicrm_cron($job) {
  // Create or Get Queue
  $queue = CRM_Queue_Service::singleton()->create([
    'type' => 'Sql',
    'name' => 'one.jonathandhn.wallet.updates',
    'runner' => 'task',
  ]);

  // Process up to 50 items per cron run to avoid timeouts
  $runner = new CRM_Queue_Runner([
    'title' => 'CiviWallet Updates',
    'queue' => $queue,
    'errorMode' => CRM_Queue_Runner::ERROR_ABORT,
    'isMinimal' => TRUE,
  ]);

  // We use runNext in a loop to control the batch size
  for ($i = 0; $i < 50; $i++) {
    if ($queue->numberOfItems() == 0) {
      break;
    }

    $item = $queue->claimItem();
    if ($item) {
      try {
        // Execute task
        $ctx = new CRM_Queue_TaskContext();
        $ctx->queue = $queue;
        $item->data->run($ctx);
        $queue->deleteItem($item);
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_log_message("CiviWallet Queue Error: " . $e->getMessage());
        $queue->releaseItem($item);
      }
    }
    else {
      break;
    }
  }
}

/**
 * Access callback that always returns true.
 */
function civi_wallet_access_always_allow() {
  return TRUE;
}

function civi_wallet_get_qr_color() {
  $color = ltrim((string) (Civi::settings()->get('wallet_color_foreground') ?: '000000'), '#');

  if (strlen($color) !== 6 || !ctype_xdigit($color)) {
    return '000000';
  }

  $r = hexdec(substr($color, 0, 2));
  $g = hexdec(substr($color, 2, 2));
  $b = hexdec(substr($color, 4, 2));
  $luminance = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);

  return $luminance > 200 ? '000000' : strtolower($color);
}

function civi_wallet_is_channel_enabled($channel) {
  $map = [
    'apple' => 'wallet_channel_apple_enabled',
    'google' => 'wallet_channel_google_enabled',
    'qr' => 'wallet_channel_qr_enabled',
  ];

  $setting = $map[$channel] ?? NULL;

  if (!$setting) {
    return FALSE;
  }

  return (bool) Civi::settings()->get($setting);
}

function civi_wallet_is_context_enabled($context) {
  $map = [
    'participant' => 'wallet_type_participant_enabled',
    'membership' => 'wallet_type_membership_enabled',
    'donor' => 'wallet_type_donor_enabled',
  ];

  $setting = $map[$context] ?? NULL;

  if (!$setting) {
    return FALSE;
  }

  return (bool) Civi::settings()->get($setting);
}

function civi_wallet_resolve_participant_id_by_contact($contactId) {
  $participant = \Civi\Api4\Participant::get(FALSE)
    ->addSelect('id')
    ->addWhere('contact_id', '=', $contactId)
    ->addOrderBy('id', 'DESC')
    ->setLimit(1)
    ->execute()
    ->first();

  return $participant['id'] ?? NULL;
}

function civi_wallet_generate_participant_qr_secret() {
  return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function civi_wallet_get_participant_barcode_mode() {
  $mode = (string) (Civi::settings()->get('wallet_barcode_participant_mode') ?: 'simple');
  return in_array($mode, ['simple', 'signed'], TRUE) ? $mode : 'simple';
}

function civi_wallet_get_participant_barcode_secret() {
  return trim((string) (Civi::settings()->get('wallet_barcode_participant_secret') ?: ''));
}

function civi_wallet_base64url_encode($value) {
  return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function civi_wallet_base64url_decode($value) {
  $padding = strlen($value) % 4;
  if ($padding > 0) {
    $value .= str_repeat('=', 4 - $padding);
  }

  $decoded = base64_decode(strtr($value, '-_', '+/'), TRUE);
  return $decoded === FALSE ? NULL : $decoded;
}

function civi_wallet_load_participant_context($participantId) {
  $participant = \Civi\Api4\Participant::get(FALSE)
    ->addSelect('id', 'contact_id', 'event_id')
    ->addWhere('id', '=', $participantId)
    ->execute()
    ->first();

  if (!$participant) {
    return NULL;
  }

  return [
    'id' => (int) $participant['id'],
    'contact_id' => (int) $participant['contact_id'],
    'event_id' => (int) $participant['event_id'],
  ];
}

function civi_wallet_render_participant_barcode_token($contactId, $participantId) {
  $barcodeToken = Civi::settings()->get('wallet_barcode_participant') ?: '{participant.id}';

  if (!class_exists('\Civi\Token\TokenProcessor')) {
    return (string) $participantId;
  }

  $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['smarty' => FALSE]);
  $tokenProcessor->addMessage('barcode', $barcodeToken, 'text/plain');
  $tokenProcessor->addRow(['contactId' => $contactId, 'participantId' => $participantId]);
  $tokenProcessor->evaluate();

  $barcodeValue = trim((string) $tokenProcessor->getRow(0)->render('barcode'));
  return $barcodeValue !== '' ? $barcodeValue : (string) $participantId;
}

function civi_wallet_build_signed_participant_barcode($participantId, $eventId, $contactId) {
  $secret = civi_wallet_get_participant_barcode_secret();
  if ($secret === '') {
    return NULL;
  }

  $payload = pack('CNN', 1, (int) $eventId, (int) $participantId);
  $signature = substr(hash_hmac('sha256', $payload, $secret, TRUE), 0, 8);

  return 'cw2.' . civi_wallet_base64url_encode($payload . $signature);
}

function civi_wallet_build_signed_donor_barcode($contactId, $exp = 0) {
  $secret = civi_wallet_get_participant_barcode_secret();
  if ($secret === '') {
    return NULL;
  }

  $payload = pack('CNN', 3, (int) $contactId, (int) $exp);
  $signature = substr(hash_hmac('sha256', $payload, $secret, TRUE), 0, 8);

  return 'cw2.' . civi_wallet_base64url_encode($payload . $signature);
}

function civi_wallet_build_signed_contact_barcode($contactId, $exp = 0) {
  $secret = civi_wallet_get_participant_barcode_secret();
  if ($secret === '') {
    return NULL;
  }

  $payload = pack('CNN', 4, (int) $contactId, (int) $exp);
  $signature = substr(hash_hmac('sha256', $payload, $secret, TRUE), 0, 8);

  return 'cw2.' . civi_wallet_base64url_encode($payload . $signature);
}

function civi_wallet_generate_participant_barcode($participantId, $contactId = NULL, $eventId = NULL) {
  $participantId = (int) $participantId;
  $contactId = $contactId !== NULL ? (int) $contactId : 0;
  $eventId = $eventId !== NULL ? (int) $eventId : 0;

  if ($participantId <= 0) {
    return '';
  }

  if ($contactId <= 0 || $eventId <= 0) {
    $participant = civi_wallet_load_participant_context($participantId);
    if (!$participant) {
      return '';
    }
    $contactId = $participant['contact_id'];
    $eventId = $participant['event_id'];
  }

  if (civi_wallet_get_participant_barcode_mode() === 'signed') {
    $signed = civi_wallet_build_signed_participant_barcode($participantId, $eventId, $contactId);
    if ($signed !== NULL && $signed !== '') {
      return $signed;
    }
    CRM_Core_Error::debug_log_message("Wallet signed participant barcode fallback to simple mode for participant {$participantId}");
  }

  return civi_wallet_render_participant_barcode_token($contactId, $participantId);
}

/**
 * Implements hook_civicrm_alterMenu().
 *
 * Ensure the API path handles sub-paths and is public.
 */
function civi_wallet_civicrm_alterMenu(&$items) {
  if (isset($items['civicrm/wallet/api'])) {
    // Public access
    $items['civicrm/wallet/api']['access_callback'] = 'civi_wallet_access_always_allow';
    $items['civicrm/wallet/api']['is_public'] = TRUE;
    $items['civicrm/wallet/api']['is_ssl'] = TRUE;
  }
  if (isset($items['civicrm/wallet/api/qr'])) {
    // Public access
    $items['civicrm/wallet/api/qr']['access_callback'] = 'civi_wallet_access_always_allow';
    $items['civicrm/wallet/api/qr']['is_public'] = TRUE;
    $items['civicrm/wallet/api/qr']['is_ssl'] = TRUE;
  }

  // Ensure Pass Download URL is also public
  if (isset($items['civicrm/wallet/pass'])) {
    $items['civicrm/wallet/pass']['access_callback'] = 'civi_wallet_access_always_allow';
    $items['civicrm/wallet/pass']['is_public'] = TRUE;
    $items['civicrm/wallet/pass']['is_ssl'] = TRUE;
  }
  else {
    // Fallback: Define it if missing (e.g. XML not loaded yet or cache issue)
    $items['civicrm/wallet/pass'] = [
      'path' => 'civicrm/wallet/pass',
      'page_callback' => 'CRM_Wallet_Page_Pass',
      'access_callback' => 'civi_wallet_access_always_allow',
      'is_public' => TRUE,
      'is_ssl' => TRUE,
    ];
  }
  // Ensure Deploy API URL is registered
  if (isset($items['civicrm/wallet/deploy-api'])) {
    $items['civicrm/wallet/deploy-api']['access_callback'] = 'CRM_Core_Permission::check';
    $items['civicrm/wallet/deploy-api']['access_arguments'] = [['administer CiviCRM']];
  }
  else {
    $items['civicrm/wallet/deploy-api'] = [
      'path' => 'civicrm/wallet/deploy-api',
      'page_callback' => 'CRM_Wallet_Page_DeployApi',
      'access_callback' => 'CRM_Core_Permission::check',
      'access_arguments' => [['administer CiviCRM']],
    ];
  }
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function civi_wallet_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  $extRoot = __DIR__ . DIRECTORY_SEPARATOR;
  $metaDataFolders[] = $extRoot . 'settings';
}

/**
 * Implements hook_civicrm_check().
 *
 * Check if the API script is installed in the webroot.
 */
function civi_wallet_civicrm_check(&$messages) {
  $webRoot = $_SERVER['DOCUMENT_ROOT'];
  $apiScript = $webRoot . '/civi-wallet-api.php';

  if (!file_exists($apiScript)) {
    $deployUrl = CRM_Utils_System::url('civicrm/wallet/deploy-api');
    $messages[] = new CRM_Utils_Check_Message(
        'civi_wallet_api_missing',
        E::ts('The CiviWallet API script (civi-wallet-api.php) is missing from the webroot. This file is required for Apple Wallet updates and push notifications. <a href="%1" class="crm-hover-button">Click here to deploy it automatically</a>.', [1 => $deployUrl]),
        E::ts('CiviWallet API Script Missing'),
        \Psr\Log\LogLevel::WARNING,
        'fa-exclamation-triangle'
    );
  }
}

/**
 * // Participant and Contact tokens are registered and evaluated cleanly through
 * // Civi\Wallet\ParticipantTokenSubscriber using the CiviCRM TokenProcessor.
 *
 * // --- Functions below this ship commented out. Uncomment as required. ---
 *
 *
 * /**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 *
 *
 * // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 *
 * function civi_wallet_civicrm_navigationMenu(&$menu) {
 * _civi_wallet_civix_insert_navigation_menu($menu, 'Mailings', array(
 * 'label' => E::ts('New sterile mailing'),
 * 'name' => 'mailing_sterile',
 * 'url' => 'civicrm/mailing/send',
 * 'permission' => 'access CiviMail',
 * 'operator' => 'OR',
 * 'separator' => 0,
 * ));
 * _civi_wallet_civix_navigationMenu($menu);
} // */
