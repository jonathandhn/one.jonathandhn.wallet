<?php

use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_Page_DeployApi extends CRM_Core_Page
{
    public function run()
    {
        $source = E::path('extern/api.php');

        // Try to find webroot
        $destDir = $_SERVER['DOCUMENT_ROOT'];

        // If we are in a subdirectory setup (e.g. /web), DOCUMENT_ROOT might be the parent or the webroot itself.
        // Let's try to be smart. If index.php is in DOCUMENT_ROOT, that's likely it.

        $dest = $destDir . '/civi-wallet-api.php';

        if (!file_exists($source)) {
            CRM_Core_Session::setStatus(E::ts('Source file not found: %1', [1 => $source]), E::ts('Error'), 'error');
            CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/status'));
            return;
        }

        if (!is_writable($destDir)) {
            CRM_Core_Session::setStatus(E::ts('Webroot is not writable: %1', [1 => $destDir]), E::ts('Error'), 'error');
            CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/status'));
            return;
        }

        if (copy($source, $dest)) {
            CRM_Core_Session::setStatus(E::ts('API script deployed successfully to %1', [1 => $dest]), E::ts('Success'), 'success');
        } else {
            CRM_Core_Session::setStatus(E::ts('Failed to copy API script to %1', [1 => $dest]), E::ts('Error'), 'error');
        }

        CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/status'));
        parent::run();
    }
}
