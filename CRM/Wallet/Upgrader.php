<?php

/**
 * Collection of upgrade steps.
 */
class CRM_Wallet_Upgrader extends CRM_Extension_Upgrader_Base
{

    // By convention, functions that look like "function upgrade_NNNN()" are
    // automatically dispatched. We can also use "install()", "enable()",
    // "disable()", and "uninstall()".

    /**
     * Example: Run a simple query when a module is enabled.
     *
     * @return TRUE on success
     * @throws Exception
     *
    public function enable() {
      CRM_Core_DAO::executeQuery('UPDATE foo SET bar = 1');
      return TRUE;
    } // */

    public function install()
    {
        $this->copyApiScript();
        $this->executeSqlFile('sql/auto_install.sql');
        $this->createCustomFields();
        return TRUE;
    }

    public function upgrade_1001()
    {
        $this->ctx->log->info('Creating Custom Fields for Wallet');
        $this->createCustomFields();
        return TRUE;
    }

    public function upgrade_1002()
    {
        try {
            // Update the Custom Group to be not public
            // We use 'create' with ID (or name) to update existing records.
            $group = civicrm_api3('CustomGroup', 'getsingle', ['name' => 'Wallet_Settings']);
            civicrm_api3('CustomGroup', 'create', [
                'id' => $group['id'],
                'is_public' => 0,
            ]);
        } catch (Exception $e) {
            $this->ctx->log->warning("Upgrade 1002: Could not find or update Wallet_Settings group. " . $e->getMessage());
        }
        return TRUE;
    }

    private function createCustomFields()
    {
        // Check if Custom Group exists
        try {
            $group = civicrm_api3('CustomGroup', 'getsingle', ['name' => 'Wallet_Settings']);
        } catch (Exception $e) {
            // Create it
            $group = civicrm_api3('CustomGroup', 'create', [
                'name' => 'Wallet_Settings',
                'title' => 'Wallet Settings',
                'extends' => 'Event',
                'style' => 'Inline',
                'is_active' => 1,
                'is_public' => 0, // Hidden from public forms
            ]);
        }

        // Check if Field exists
        try {
            civicrm_api3('CustomField', 'getsingle', ['name' => 'Wallet_Image', 'custom_group_id' => $group['id']]);
        } catch (Exception $e) {
            civicrm_api3('CustomField', 'create', [
                'custom_group_id' => $group['id'],
                'name' => 'Wallet_Image',
                'label' => 'Wallet Image (Icon)',
                'html_type' => 'File',
                'data_type' => 'File',
                'is_active' => 1,
            ]);
        }

        // Add Background Color override
        try {
            civicrm_api3('CustomField', 'getsingle', ['name' => 'Wallet_Background_Color', 'custom_group_id' => $group['id']]);
        } catch (Exception $e) {
            civicrm_api3('CustomField', 'create', [
                'custom_group_id' => $group['id'],
                'name' => 'Wallet_Background_Color',
                'label' => 'Wallet Background Color',
                'html_type' => 'Text',
                'data_type' => 'String',
                'is_active' => 1,
                'help_post' => 'Hex color code (e.g. #ea148c)',
            ]);
        }
    }

    public function postUpgrade($opQueue, $revQueue, $originalVer, $targetVer)
    {
        parent::postUpgrade($opQueue, $revQueue, $originalVer, $targetVer);
        $this->copyApiScript();
    }

    /**
     * Copy the API script to the webroot to bypass server restrictions.
     */
    private function copyApiScript()
    {
        $source = __DIR__ . '/../../extern/api.php';
        $config = CRM_Core_Config::singleton();
        // userFrameworkResourceURL is usually something like https://site.com/sites/default/files/civicrm...
        // We want the webroot.
        // A reliable way to get webroot in CiviCRM is tricky, but usually it's where index.php is.
        // Let's try to use the CMS root path if available.

        $destDir = NULL;
        if (defined('CIVICRM_UF_BASEPATH')) {
            $destDir = CIVICRM_UF_BASEPATH;
        } elseif (isset($config->userFrameworkBaseURL)) {
            // Try to guess from URL? No, filesystem path needed.
            // Fallback to $_SERVER['DOCUMENT_ROOT']
            $destDir = $_SERVER['DOCUMENT_ROOT'];
        }

        if ($destDir && file_exists($destDir)) {
            $dest = $destDir . '/civi-wallet-api.php';
            if (file_exists($source)) {
                if (!copy($source, $dest)) {
                    CRM_Core_Error::debug_log_message("CiviWallet: Failed to copy API script to $dest");
                    CRM_Core_Session::setStatus("Could not copy civi-wallet-api.php to webroot. Please copy it manually from extensions/one.jonathandhn.wallet/extern/api.php", "Installation Warning", "alert");
                } else {
                    CRM_Core_Session::setStatus("civi-wallet-api.php installed to webroot.", "Installation Success", "success");
                }
            }
        }
    }

}
