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
        $this->ctx->log->info('Deferring the Wallet pass schema migration to revision 1008');
        return TRUE;
    }

    public function upgrade_1006()
    {
        $this->ctx->log->info('Deferring the Wallet pass schema migration to revision 1008');
        return TRUE;
    }

    public function upgrade_1007()
    {
        $this->ctx->log->info('Moving Wallet Settings event overrides out of the inline Event Settings form');
        $this->createCustomFields();
        return TRUE;
    }

    /**
     * Finish the participant_id to dynamic entity reference migration.
     *
     * Earlier upgrade steps swallowed SQL errors and could be marked complete
     * while leaving the legacy schema in place. This step is deliberately
     * idempotent and never deletes pass records.
     */
    public function upgrade_1008()
    {
        $this->ctx->log->info('Ensuring Wallet passes use entity_table and entity_id');

        if (!CRM_Core_DAO::checkTableExists('civicrm_wallet_pass')) {
            throw new CRM_Core_Exception('Cannot migrate CiviWallet: civicrm_wallet_pass does not exist.');
        }

        $hasParticipantId = $this->walletPassColumnExists('participant_id');
        $hasEntityTable = $this->walletPassColumnExists('entity_table');
        $hasEntityId = $this->walletPassColumnExists('entity_id');

        if ($hasParticipantId) {
            if (!$hasEntityTable) {
                CRM_Core_DAO::executeQuery(
                    'ALTER TABLE civicrm_wallet_pass ADD COLUMN entity_table VARCHAR(64) NULL AFTER id'
                );
                $hasEntityTable = TRUE;
            }
            if (!$hasEntityId) {
                CRM_Core_DAO::executeQuery(
                    'ALTER TABLE civicrm_wallet_pass ADD COLUMN entity_id INT UNSIGNED NULL AFTER entity_table'
                );
                $hasEntityId = TRUE;
            }

            $conflictingRows = (int) CRM_Core_DAO::singleValueQuery("
                SELECT COUNT(*)
                FROM civicrm_wallet_pass
                WHERE (entity_table IS NOT NULL AND entity_table <> 'civicrm_participant')
                   OR (entity_id IS NOT NULL AND entity_id <> participant_id)
            ");
            if ($conflictingRows > 0) {
                throw new CRM_Core_Exception(
                    "Cannot migrate CiviWallet: {$conflictingRows} pass row(s) conflict with participant_id."
                );
            }

            CRM_Core_DAO::executeQuery("
                UPDATE civicrm_wallet_pass
                SET entity_table = COALESCE(entity_table, 'civicrm_participant'),
                    entity_id = COALESCE(entity_id, participant_id)
            ");
        }

        if (!$hasEntityTable || !$hasEntityId) {
            throw new CRM_Core_Exception(
                'Cannot migrate CiviWallet: neither the legacy nor the current Wallet pass schema is complete.'
            );
        }

        $incompleteRows = (int) CRM_Core_DAO::singleValueQuery("
            SELECT COUNT(*)
            FROM civicrm_wallet_pass
            WHERE entity_table IS NULL OR entity_id IS NULL
        ");
        if ($incompleteRows > 0) {
            throw new CRM_Core_Exception(
                "Cannot migrate CiviWallet: {$incompleteRows} pass row(s) have no target entity."
            );
        }

        $duplicateGroups = (int) CRM_Core_DAO::singleValueQuery("
            SELECT COUNT(*)
            FROM (
                SELECT entity_table, entity_id, type
                FROM civicrm_wallet_pass
                GROUP BY entity_table, entity_id, type
                HAVING COUNT(*) > 1
            ) duplicate_wallet_passes
        ");
        if ($duplicateGroups > 0) {
            throw new CRM_Core_Exception(
                "Cannot migrate CiviWallet: {$duplicateGroups} duplicate entity/type group(s) require review."
            );
        }

        CRM_Core_DAO::executeQuery(
            'ALTER TABLE civicrm_wallet_pass MODIFY COLUMN entity_table VARCHAR(64) NOT NULL'
        );
        CRM_Core_DAO::executeQuery(
            'ALTER TABLE civicrm_wallet_pass MODIFY COLUMN entity_id INT UNSIGNED NOT NULL'
        );

        if ($hasParticipantId) {
            $foreignKeys = CRM_Core_DAO::executeQuery("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'civicrm_wallet_pass'
                  AND COLUMN_NAME = 'participant_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            while ($foreignKeys->fetch()) {
                $constraintName = str_replace('`', '``', $foreignKeys->CONSTRAINT_NAME);
                CRM_Core_DAO::executeQuery(
                    "ALTER TABLE civicrm_wallet_pass DROP FOREIGN KEY `{$constraintName}`"
                );
            }

            if ($this->walletPassIndexExists('index_participant_type')) {
                CRM_Core_DAO::executeQuery(
                    'ALTER TABLE civicrm_wallet_pass DROP INDEX index_participant_type'
                );
            }
            CRM_Core_DAO::executeQuery(
                'ALTER TABLE civicrm_wallet_pass DROP COLUMN participant_id'
            );
        }

        if (!$this->walletPassIndexExists('index_entity_type')) {
            CRM_Core_DAO::executeQuery("
                ALTER TABLE civicrm_wallet_pass
                ADD UNIQUE INDEX index_entity_type (entity_table, entity_id, type)
            ");
        }

        return TRUE;
    }

    private function walletPassColumnExists($columnName)
    {
        return (bool) CRM_Core_DAO::singleValueQuery("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'civicrm_wallet_pass'
              AND COLUMN_NAME = %1
        ", [
            1 => [$columnName, 'String'],
        ]);
    }

    private function walletPassIndexExists($indexName)
    {
        return (bool) CRM_Core_DAO::singleValueQuery("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'civicrm_wallet_pass'
              AND INDEX_NAME = %1
        ", [
            1 => [$indexName, 'String'],
        ]);
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
                    ->addValue('style', 'Tab')
                    ->addValue('is_active', 1)
                    ->addValue('is_public', 0)
                    ->execute()->first();
            }
            elseif (($group['style'] ?? NULL) !== 'Tab') {
                \Civi\Api4\CustomGroup::update(FALSE)
                    ->addWhere('id', '=', $group['id'])
                    ->addValue('style', 'Tab')
                    ->execute();
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
