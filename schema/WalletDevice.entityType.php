<?php
use CRM_Wallet_ExtensionUtil as E;

return [
  'name' => 'WalletDevice',
  'table' => 'civicrm_wallet_device',
  'class' => 'CRM_Wallet_DAO_WalletDevice',
  'getInfo' => fn() => [
    'title' => E::ts('Wallet Device'),
    'title_plural' => E::ts('Wallet Devices'),
    'description' => E::ts('Devices registered for Apple Wallet push notifications.'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique wallet device ID.'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'pass_id' => [
      'title' => E::ts('Wallet Pass ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('Wallet pass registered on the device.'),
      'entity_reference' => [
        'entity' => 'WalletPass',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'device_library_identifier' => [
      'title' => E::ts('Device Library Identifier'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Apple Wallet device library identifier.'),
    ],
    'push_token' => [
      'title' => E::ts('Push Token'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Apple Push Notification service token.'),
    ],
  ],
  'getIndices' => fn() => [
    'index_device_pass' => [
      'fields' => [
        'device_library_identifier' => TRUE,
        'pass_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
  'getPaths' => fn() => [],
];
