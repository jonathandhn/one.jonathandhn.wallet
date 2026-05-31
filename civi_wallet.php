<?php

require_once 'civi_wallet.civix.php';
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
use CRM_Wallet_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function civi_wallet_civicrm_config(&$config)
{
    _civi_wallet_civix_civicrm_config($config);

    // Explicitly register templates directory
    $extRoot = __DIR__ . DIRECTORY_SEPARATOR;
    $templateDir = $extRoot . 'templates';
    if (file_exists($templateDir)) {
        CRM_Core_Smarty::singleton()->addTemplateDir($templateDir);
    }
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function civi_wallet_civicrm_install()
{
    _civi_wallet_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function civi_wallet_civicrm_enable()
{
    _civi_wallet_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_post().
 *
 * Trigger pass updates when Event or Participant is modified.
 */
function civi_wallet_civicrm_post($op, $objectName, $objectId, &$objectRef)
{
    if ($op == 'edit' || $op == 'create') {
        if ($objectName == 'Event') {
            // Update all passes for this event
            CRM_Wallet_BAO_Pass::updatePasses('Event', $objectId);
        } elseif ($objectName == 'Participant') {
            // Update pass for this participant
            CRM_Wallet_BAO_Pass::updatePasses('Participant', $objectId);
        } elseif ($objectName == 'Membership') {
            // Update pass for this membership's contact
            CRM_Wallet_BAO_Pass::updatePasses('Membership', $objectId);
        } elseif ($objectName == 'Contact') {
            // Update pass for this contact
            CRM_Wallet_BAO_Pass::updatePasses('Contact', $objectId);
        }
    }
}

/**
 * Implements hook_civicrm_cron().
 *
 * Process the wallet update queue.
 */
function civi_wallet_civicrm_cron($job)
{
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
        'isMinimal' => true,
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
            } catch (Exception $e) {
                CRM_Core_Error::debug_log_message("CiviWallet Queue Error: " . $e->getMessage());
                $queue->releaseItem($item);
            }
        } else {
            break;
        }
    }
}

/**
 * Access callback that always returns true.
 */
function civi_wallet_access_always_allow()
{
    return true;
}

/**
 * Implements hook_civicrm_alterMenu().
 *
 * Ensure the API path handles sub-paths and is public.
 */
function civi_wallet_civicrm_alterMenu(&$items)
{
    if (isset($items['civicrm/wallet/api'])) {
        $items['civicrm/wallet/api']['access_callback'] = 'civi_wallet_access_always_allow'; // Public access
        $items['civicrm/wallet/api']['is_public'] = true;
        $items['civicrm/wallet/api']['is_ssl'] = true;
    }

    // Ensure Pass Download URL is also public
    if (isset($items['civicrm/wallet/pass'])) {
        $items['civicrm/wallet/pass']['access_callback'] = 'civi_wallet_access_always_allow';
        $items['civicrm/wallet/pass']['is_public'] = true;
        $items['civicrm/wallet/pass']['is_ssl'] = true;
    } else {
        // Fallback: Define it if missing (e.g. XML not loaded yet or cache issue)
        $items['civicrm/wallet/pass'] = [
            'path' => 'civicrm/wallet/pass',
            'page_callback' => 'CRM_Wallet_Page_Pass',
            'access_callback' => 'civi_wallet_access_always_allow',
            'is_public' => true,
            'is_ssl' => true,
        ];
    }
    // Ensure Deploy API URL is registered
    if (isset($items['civicrm/wallet/deploy-api'])) {
        $items['civicrm/wallet/deploy-api']['access_callback'] = 'CRM_Core_Permission::check';
        $items['civicrm/wallet/deploy-api']['access_arguments'] = [['administer CiviCRM']];
    } else {
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
function civi_wallet_civicrm_alterSettingsFolders(&$metaDataFolders = NULL)
{
    $extRoot = __DIR__ . DIRECTORY_SEPARATOR;
    $metaDataFolders[] = $extRoot . 'settings';
}

/**
 * Implements hook_civicrm_check().
 *
 * Check if the API script is installed in the webroot.
 */
function civi_wallet_civicrm_check(&$messages)
{
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
 * Implements hook_civicrm_tokens().
 */
function civi_wallet_civicrm_tokens(&$tokens)
{
    $tokens['contact'] = $tokens['contact'] ?? [];
    $tokens['contact']['wallet_links'] = E::ts('Wallet Links (Membership)');
    $tokens['contact']['qr_membership'] = E::ts('QR Code (Membership)');
    $tokens['contact']['wallet_links_donor'] = E::ts('Wallet Links (Donor Card)');
    $tokens['contact']['qr_donor'] = E::ts('QR Code (Donor Card)');

    $tokens['participant'] = $tokens['participant'] ?? [];
    $tokens['participant']['wallet_links'] = E::ts('Wallet Links (Event Ticket)');
    $tokens['participant']['qr_participant'] = E::ts('QR Code (Event Ticket)');
}

/**
 * Implements hook_civicrm_tokenValues().
 */
function civi_wallet_civicrm_tokenValues(&$values, $cids, $job = null, $tokens = [], $context = null)
{
    $needsContactLinks = !empty($tokens['contact']) && (in_array('wallet_links', $tokens['contact']) || in_array('wallet_links_donor', $tokens['contact']));
    $needsContactQr = !empty($tokens['contact']) && (in_array('qr_membership', $tokens['contact']) || in_array('qr_donor', $tokens['contact']));
    
    $needsParticipantLinks = !empty($tokens['participant']) && in_array('wallet_links', $tokens['participant']);
    $needsParticipantQr = !empty($tokens['participant']) && in_array('qr_participant', $tokens['participant']);

    if ($needsContactLinks || $needsContactQr || $needsParticipantLinks || $needsParticipantQr) {
        // Generic CSS for emails
        $btnStyleApple = "display:inline-block; margin-right:10px; padding:10px 16px; background-color:#000000; color:#ffffff; text-decoration:none; border-radius:8px; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; font-weight:bold; text-align:center;";
        $btnStyleGoogle = "display:inline-block; padding:10px 16px; background-color:#3c4043; color:#ffffff; text-decoration:none; border-radius:8px; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; font-weight:bold; text-align:center;";

        foreach ($cids as $cid) {
            $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($cid);

            $htmlMembership = '';
            if (isset($tokens['contact']) && in_array('wallet_links', $tokens['contact'])) {
                $appleUrl = CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=apple&context=membership", true, null, false, true);
                $googleUrl = CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=google&context=membership", true, null, false, true);

                $htmlMembership .= "<a href='{$appleUrl}' style='{$btnStyleApple}'>" . E::ts('Ajouter à Apple Wallet') . "</a>";
                $htmlMembership .= "<a href='{$googleUrl}' style='{$btnStyleGoogle}'>" . E::ts('Ajouter à Google Wallet') . "</a>";
                
                $values[$cid]['contact.wallet_links'] = $htmlMembership;
            }

            $htmlParticipant = '';
            if (isset($tokens['participant']) && in_array('wallet_links', $tokens['participant'])) {
                $appleUrl = CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=apple&context=participant", true, null, false, true);
                $googleUrl = CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=google&context=participant", true, null, false, true);

                $htmlParticipant .= "<a href='{$appleUrl}' style='{$btnStyleApple}'>" . E::ts('Ajouter à Apple Wallet') . "</a>";
                $htmlParticipant .= "<a href='{$googleUrl}' style='{$btnStyleGoogle}'>" . E::ts('Ajouter à Google Wallet') . "</a>";
                
                $values[$cid]['participant.wallet_links'] = $htmlParticipant;
            }

            $htmlDonor = '';
            if (isset($tokens['contact']) && in_array('wallet_links_donor', $tokens['contact'])) {
                $appleUrl = CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=apple&context=donor", true, null, false, true);
                $googleUrl = CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=google&context=donor", true, null, false, true);

                $htmlDonor .= "<a href='{$appleUrl}' style='{$btnStyleApple}'>" . E::ts('Ajouter à Apple Wallet') . "</a>";
                $htmlDonor .= "<a href='{$googleUrl}' style='{$btnStyleGoogle}'>" . E::ts('Ajouter à Google Wallet') . "</a>";
                
                $values[$cid]['contact.wallet_links_donor'] = $htmlDonor;
            }

            if (isset($tokens['contact']) && in_array('qr_membership', $tokens['contact'])) {
                $color = ltrim(Civi::settings()->get('wallet_color_foreground') ?: '000000', '#');
                $qrUrl = CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=membership&cid={$cid}&cs={$cs}&color={$color}", true, null, false, true);
                $values[$cid]['contact.qr_membership'] = "<img src='{$qrUrl}' width='150' height='150' alt='" . E::ts('QR Code') . "'>";
            }

            if (isset($tokens['participant']) && in_array('qr_participant', $tokens['participant'])) {
                $color = ltrim(Civi::settings()->get('wallet_color_foreground') ?: '000000', '#');
                $base = rtrim(CRM_Utils_System::baseCMSURL(), '/');
                $qrUrl = $base . "/civicrm/wallet/api/qr?entity=participant&id={participant.id}&cs={$cs}&color={$color}";
                $values[$cid]['participant.qr_participant'] = "<img src='{$qrUrl}' width='150' height='150' alt='" . E::ts('QR Code') . "'>";
            }

            if (isset($tokens['contact']) && in_array('qr_donor', $tokens['contact'])) {
                $color = ltrim(Civi::settings()->get('wallet_color_foreground') ?: '000000', '#');
                $qrUrl = CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=donor&cid={$cid}&cs={$cs}&color={$color}", true, null, false, true);
                $values[$cid]['contact.qr_donor'] = "<img src='{$qrUrl}' width='150' height='150' alt='" . E::ts('QR Code') . "'>";
            }
        }
    }
}

// --- Functions below this ship commented out. Uncomment as required. ---


/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 *

 // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 *
function civi_wallet_civicrm_navigationMenu(&$menu) {
  _civi_wallet_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New sterile mailing'),
    'name' => 'mailing_sterile',
    'url' => 'civicrm/mailing/send',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _civi_wallet_civix_navigationMenu($menu);
} // */
