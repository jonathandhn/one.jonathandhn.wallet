<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

return [
    // The prefix configuration. If a non-null value is used, a unique prefix
    // will be generated.
    //
    // For more see: https://github.com/humbug/php-scoper/blob/master/docs/configuration.md#prefix
    'prefix' => 'Civi\Wallet\Vendor',

    // By default, when running php-scoper add-prefix, it will prefix all relevant code.
    // However, you can exclude some files or directories from being scoped.
    // In this case, we scope the composer vendor directory and our own source files so their references are updated.
    'finders' => [
        // Dependencies
        Finder::create()
            ->files()
            ->name('*.php')
            ->exclude('behat')
            ->exclude('phpunit')
            ->in(__DIR__ . '/vendor'),

        // Extension source files (to update their dependency references)
        Finder::create()
            ->files()
            ->name('*.php')
            ->in(__DIR__ . '/CRM'),

        Finder::create()
            ->files()
            ->name('*.php')
            ->in(__DIR__ . '/Civi'),

        Finder::create()
            ->files()
            ->name('civi_wallet.php')
            ->name('civi_wallet.civix.php')
            ->name('debug_queue.php')
            ->depth(0)
            ->in(__DIR__),
    ],

    // List of namespaces that should not be prefixed.
    // We MUST exclude our own namespaces and CiviCRM core classes.
    'exclude-namespaces' => [
        'Civi\Wallet',
        'Civi',
        'CRM',
        'Symfony\Component\EventDispatcher',
    ],

    // List of classes that should not be prefixed.
    'exclude-classes' => [
        '/^CRM_.*$/',
        'Civi',
    ],

    // List of functions that should not be prefixed.
    'exclude-functions' => [
        '/^civi_wallet_.*$/',
        '/^_civi_wallet_civix_.*$/',
    ],

    // Custom patchers to run after scoping
    'patchers' => [
        function (string $filePath, string $prefix, string $content): string {
            // If the file is one of our own extension files (not in vendor),
            // remove the namespace wrapping added by php-scoper.
            if (strpos($filePath, 'vendor/') === false) {
                // Remove 'namespace Civi\Wallet\Vendor;' or similar
                $content = preg_replace('/^namespace\s+' . preg_quote($prefix, '/') . ';\s*/m', '', $content);
                // Also remove any leading namespace declaration if it is 'namespace Civi\Wallet\Vendor\;'
                $content = preg_replace('/^namespace\s+' . preg_quote($prefix, '/') . '\\\\.*;\s*/m', '', $content);
                // Remove any class_alias calls added by php-scoper for our global CRM_ classes
                $content = preg_replace('/\\\\class_alias\(\s*[\'"]' . preg_quote($prefix, '/') . '\\\\CRM_[^\'"]+[\'"]\s*,\s*[\'"]CRM_[^\'"]+[\'"]\s*,\s*\\\\?false\s*\);/i', '', $content);
            }
            return $content;
        },
    ],
];
