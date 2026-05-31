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
            $group = \Civi\Api4\CustomGroup::get(FALSE)
                ->addWhere('name', '=', 'Wallet_Settings')
                ->execute()->first();
            if ($group) {
                \Civi\Api4\CustomGroup::update(FALSE)
                    ->addWhere('id', '=', $group['id'])
                    ->addValue('is_public', 0)
                    ->execute();
            }
        } catch (Exception $e) {
            $this->ctx->log->warning("Upgrade 1002: Could not find or update Wallet_Settings group. " . $e->getMessage());
        }
        return TRUE;
    }

            public function upgrade_1005()
    {
        $this->ctx->log->info('Migrating wallet passes from participant_id to entity_id/entity_table');
        
        // Helper to safely execute queries
        $safeQuery = function($sql) {
            try {
                CRM_Core_DAO::executeQuery($sql);
            } catch (Exception $e) {
                $this->ctx->log->warning("Upgrade 1004 SQL Failed: $sql | Error: " . $e->getMessage());
            }
        };

        // Check if entity_table exists
        $dao = CRM_Core_DAO::executeQuery("SHOW COLUMNS FROM civicrm_wallet_pass LIKE 'entity_table'");
        if (!$dao->fetch()) {
            
            // Try dropping common foreign key names for participant_id
            $safeQuery("ALTER TABLE civicrm_wallet_pass DROP FOREIGN KEY FK_civicrm_wallet_pass_participant_id");
            $safeQuery("ALTER TABLE civicrm_wallet_pass DROP FOREIGN KEY civicrm_wallet_pass_ibfk_1");
            $safeQuery("ALTER TABLE civicrm_wallet_pass DROP FOREIGN KEY FK_participant_id");

            // Add new columns
            $safeQuery("ALTER TABLE civicrm_wallet_pass ADD COLUMN entity_table VARCHAR(64) NULL AFTER id");
            $safeQuery("ALTER TABLE civicrm_wallet_pass ADD COLUMN entity_id INT UNSIGNED NULL AFTER entity_table");

            // Check if participant_id exists before migrating data from it
            $hasParticipantId = CRM_Core_DAO::executeQuery("SHOW COLUMNS FROM civicrm_wallet_pass LIKE 'participant_id'")->fetch();
            
            if ($hasParticipantId) {
                // Migrate data
                $safeQuery("UPDATE civicrm_wallet_pass SET entity_table = 'civicrm_participant', entity_id = participant_id WHERE participant_id IS NOT NULL");
            }

            // Cleanup invalid rows
            $safeQuery("DELETE FROM civicrm_wallet_pass WHERE entity_id IS NULL OR entity_table IS NULL");
            
            // Make columns NOT NULL
            $safeQuery("ALTER TABLE civicrm_wallet_pass MODIFY COLUMN entity_table VARCHAR(64) NOT NULL");
            $safeQuery("ALTER TABLE civicrm_wallet_pass MODIFY COLUMN entity_id INT UNSIGNED NOT NULL");

            // Drop old column
            if ($hasParticipantId) {
                $safeQuery("ALTER TABLE civicrm_wallet_pass DROP COLUMN participant_id");
            }

            // Update index
            $safeQuery("ALTER TABLE civicrm_wallet_pass DROP INDEX index_participant_type");
            $safeQuery("ALTER TABLE civicrm_wallet_pass ADD UNIQUE INDEX index_entity_type (entity_table, entity_id, type)");
        } else {
            // Even if entity_table exists, participant_id might still exist if it crashed last time
            $hasParticipantId = CRM_Core_DAO::executeQuery("SHOW COLUMNS FROM civicrm_wallet_pass LIKE 'participant_id'")->fetch();
            if ($hasParticipantId) {
                $safeQuery("ALTER TABLE civicrm_wallet_pass DROP FOREIGN KEY FK_civicrm_wallet_pass_participant_id");
                $safeQuery("ALTER TABLE civicrm_wallet_pass DROP FOREIGN KEY civicrm_wallet_pass_ibfk_1");
                $safeQuery("ALTER TABLE civicrm_wallet_pass DROP COLUMN participant_id");
            }
            
            // Try creating the index just in case it failed last time
            $safeQuery("ALTER TABLE civicrm_wallet_pass DROP INDEX index_participant_type");
            $safeQuery("ALTER TABLE civicrm_wallet_pass ADD UNIQUE INDEX index_entity_type (entity_table, entity_id, type)");
        }

        return TRUE;
    }
        public function upgrade_1006()
    {
        $this->ctx->log->info('Dropping participant_id foreign keys and column dynamically');
        
        // Helper to safely execute queries
        $safeQuery = function($sql) {
            try {
                CRM_Core_DAO::executeQuery($sql);
            } catch (Exception $e) {
                $this->ctx->log->warning("Upgrade 1006 SQL Failed: $sql | Error: " . $e->getMessage());
            }
        };

        // Find the foreign key name for participant_id dynamically
        $dao = CRM_Core_DAO::executeQuery("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'civicrm_wallet_pass' 
              AND COLUMN_NAME = 'participant_id' 
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        while ($dao->fetch()) {
            $fkName = $dao->CONSTRAINT_NAME;
            $this->ctx->log->info("Found foreign key: $fkName. Dropping it...");
            $safeQuery("ALTER TABLE civicrm_wallet_pass DROP FOREIGN KEY `$fkName`");
        }

        // Now drop the column
        $hasParticipantId = CRM_Core_DAO::executeQuery("SHOW COLUMNS FROM civicrm_wallet_pass LIKE 'participant_id'")->fetch();
        if ($hasParticipantId) {
            $safeQuery("ALTER TABLE civicrm_wallet_pass MODIFY participant_id INT UNSIGNED NULL"); // Make it nullable just in case drop fails again
            $safeQuery("ALTER TABLE civicrm_wallet_pass DROP COLUMN participant_id");
        }

        // Just in case, ensure the new index exists
        $safeQuery("ALTER TABLE civicrm_wallet_pass DROP INDEX index_participant_type");
        $safeQuery("ALTER TABLE civicrm_wallet_pass ADD UNIQUE INDEX index_entity_type (entity_table, entity_id, type)");

        return TRUE;
    }
    private function createCustomFields()
    {
        try {
            $group = \Civi\Api4\CustomGroup::get(FALSE)
                ->addWhere('name', '=', 'Wallet_Settings')
                ->execute()->first();
            
            if (!$group) {
                $group = \Civi\Api4\CustomGroup::create(FALSE)
                    ->addValue('name', 'Wallet_Settings')
                    ->addValue('title', 'Wallet Settings')
                    ->addValue('extends', 'Event')
                    ->addValue('style', 'Inline')
                    ->addValue('is_active', 1)
                    ->addValue('is_public', 0)
                    ->execute()->first();
            }

            $imageField = \Civi\Api4\CustomField::get(FALSE)
                ->addWhere('name', '=', 'Wallet_Image')
                ->addWhere('custom_group_id', '=', $group['id'])
                ->execute()->first();
            
            if (!$imageField) {
                \Civi\Api4\CustomField::create(FALSE)
                    ->addValue('custom_group_id', $group['id'])
                    ->addValue('name', 'Wallet_Image')
                    ->addValue('label', 'Wallet Image (Icon)')
                    ->addValue('html_type', 'File')
                    ->addValue('data_type', 'File')
                    ->addValue('is_active', 1)
                    ->execute();
            }

            $colorField = \Civi\Api4\CustomField::get(FALSE)
                ->addWhere('name', '=', 'Wallet_Background_Color')
                ->addWhere('custom_group_id', '=', $group['id'])
                ->execute()->first();

            if (!$colorField) {
                \Civi\Api4\CustomField::create(FALSE)
                    ->addValue('custom_group_id', $group['id'])
                    ->addValue('name', 'Wallet_Background_Color')
                    ->addValue('label', 'Wallet Background Color')
                    ->addValue('html_type', 'Text')
                    ->addValue('data_type', 'String')
                    ->addValue('is_active', 1)
                    ->addValue('help_post', 'Hex color code (e.g. #ea148c)')
                    ->execute();
            }

        } catch (Exception $e) {
            $this->ctx->log->error("Error creating custom fields: " . $e->getMessage());
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
