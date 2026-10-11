<?php

use CRM_Wallet_ExtensionUtil as E;

return [
  [
    'name' => 'civi_wallet_settings',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('CiviWallet Settings'),
        'name' => 'civi_wallet_settings',
        'url' => 'civicrm/admin/wallet?reset=1',
        'permission' => 'administer CiviCRM',
        'permission_operator' => 'OR',
        'parent_id.name' => 'System Settings',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 95,
      ],
      'match' => ['name'],
    ],
  ],
];
