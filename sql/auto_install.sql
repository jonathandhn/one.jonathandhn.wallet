-- +CRM_drop_table civicrm_wallet_device
-- +CRM_drop_table civicrm_wallet_pass

-- /*******************************************************
-- *
-- * civicrm_wallet_pass
-- *
-- * Wallet Passes (Apple/Google)
-- *
-- *******************************************************/
CREATE TABLE IF NOT EXISTS `civicrm_wallet_pass` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique Pass ID',
  `participant_id` int unsigned NOT NULL COMMENT 'FK to Participant ID',
  `type` varchar(32) NOT NULL COMMENT 'Pass Type (apple/google)',
  `serial_number` varchar(255) NOT NULL COMMENT 'Serial Number',
  `authentication_token` varchar(255) NOT NULL COMMENT 'Authentication Token',
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Last Updated Timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `index_participant_type` (`participant_id`, `type`),
  CONSTRAINT `FK_civicrm_wallet_pass_participant_id` FOREIGN KEY (`participant_id`) REFERENCES `civicrm_participant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- /*******************************************************
-- *
-- * civicrm_wallet_device
-- *
-- * Registered Devices for Apple Wallet Push Notifications
-- *
-- *******************************************************/
CREATE TABLE IF NOT EXISTS `civicrm_wallet_device` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique Device ID',
  `pass_id` int unsigned NOT NULL COMMENT 'FK to Wallet Pass',
  `device_library_identifier` varchar(255) NOT NULL COMMENT 'Device Library Identifier (from Apple)',
  `push_token` varchar(255) NOT NULL COMMENT 'Push Token (from Apple)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `index_device_pass` (`device_library_identifier`, `pass_id`),
  CONSTRAINT `FK_civicrm_wallet_device_pass_id` FOREIGN KEY (`pass_id`) REFERENCES `civicrm_wallet_pass` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
