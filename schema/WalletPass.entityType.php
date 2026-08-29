<?php
use CRM_Wallet_ExtensionUtil as E;

return [
  'name' => 'WalletPass',
  'table' => 'civicrm_wallet_pass',
  'class' => 'CRM_Wallet_DAO_WalletPass',
  'getInfo' => fn() => [
    'title' => E::ts('Wallet Pass'),
    'title_plural' => E::ts('Wallet Passes'),
    'description' => E::ts('Apple and Google wallet passes linked to CiviCRM records.'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique wallet pass ID.'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'entity_table' => [
      'title' => E::ts('Entity Table'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Physical table name of the record linked to the pass.'),
    ],
    'entity_id' => [
      'title' => E::ts('Entity ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('ID of the record linked to the pass.'),
      'entity_reference' => [
        'dynamic_entity' => 'entity_table',
        'key' => 'id',
      ],
    ],
    'type' => [
      'title' => E::ts('Pass Type'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Wallet platform, such as apple or google.'),
    ],
    'serial_number' => [
      'title' => E::ts('Serial Number'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Pass serial number.'),
    ],
    'authentication_token' => [
      'title' => E::ts('Authentication Token'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Token used to authenticate wallet update requests.'),
    ],
    'last_updated' => [
      'title' => E::ts('Last Updated'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'required' => TRUE,
      'description' => E::ts('Date and time when the pass was last updated.'),
      'default' => 'CURRENT_TIMESTAMP',
    ],
  ],
  'getIndices' => fn() => [
    'index_entity_type' => [
      'fields' => [
        'entity_table' => TRUE,
        'entity_id' => TRUE,
        'type' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
  'getPaths' => fn() => [],
];
