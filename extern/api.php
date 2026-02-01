<?php

/**
 * Apple Wallet API Endpoint (Standalone)
 * 
 * This script bypasses the CMS (Drupal/WordPress) routing to allow
 * HTTP methods like DELETE which might be blocked by the CMS integration.
 */

// 1. Bootstrap CiviCRM
// Try to locate civicrm.config.php
$scriptsFile = NULL;
$currentDir = __DIR__;

// 1. Try Drupal 8/9/10 Bootstrap (Standard for Composer installs)
if (file_exists(__DIR__ . '/autoload.php') && file_exists(__DIR__ . '/core/lib/Drupal/Core/DrupalKernel.php')) {
    try {
        $autoloader = require_once __DIR__ . '/autoload.php';
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $kernel = \Drupal\Core\DrupalKernel::createFromRequest($request, $autoloader, 'prod');
        $kernel->boot();
        $kernel->preHandle($request);

        if (\Drupal::hasService('civicrm')) {
            \Drupal::service('civicrm')->initialize();
            $scriptsFile = true; // Flag as success
        }
    } catch (Exception $e) {
        // Fallback to standard search if Drupal boot fails
        error_log("CiviWallet: Drupal bootstrap failed: " . $e->getMessage());
    }
}

// 2. Fallback: Search for civicrm.config.php (Legacy / WP / Joomla)
if (empty($scriptsFile)) {
    $currentDir = __DIR__;
    $searchDirs = [$currentDir];
    for ($i = 0; $i < 6; $i++) {
        $currentDir = dirname($currentDir);
        $searchDirs[] = $currentDir;
    }

    // Add standard CMS locations relative to webroot
    $webRoot = $_SERVER['DOCUMENT_ROOT'];
    $searchDirs[] = $webRoot . '/sites/default';
    $searchDirs[] = $webRoot . '/wp-content/plugins/civicrm';
    $searchDirs[] = $webRoot . '/administrator/components/com_civicrm';

    foreach ($searchDirs as $dir) {
        if (file_exists($dir . '/civicrm.config.php')) {
            $scriptsFile = $dir . '/civicrm.config.php';
            break;
        }
    }
}

if ($scriptsFile === true) {
    // Already bootstrapped via Drupal
} elseif ($scriptsFile) {
    require_once $scriptsFile;
} else {
    // Last ditch effort: assume we are in a standard CiviCRM structure
    // and try to bootstrap via CRM_Core_Config if autoloader worked
    if (class_exists('CRM_Core_Config')) {
        CRM_Core_Config::singleton();
    } else {
        http_response_code(500);
        die("Could not bootstrap CiviCRM. civicrm.config.php not found. Searched in: " . implode(', ', $searchDirs));
    }
}

// 2. Run the API Page
// We instantiate the page class and run it.
// We need to ensure the class is loaded.
// The extension classloader should handle it if CiviCRM is booted.

if (!class_exists('CRM_Wallet_Page_API')) {
    // Try to load it manually if extension autoloader isn't ready
    try {
        $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
        if ($extPath && file_exists($extPath . '/CRM/Wallet/Page/API.php')) {
            require_once $extPath . '/CRM/Wallet/Page/API.php';
        }
    } catch (Exception $e) {
        // Ignore
    }
}

if (!class_exists('CRM_Wallet_Page_API')) {
    die("Error: CRM_Wallet_Page_API class not found. Is the extension enabled?");
}

$page = new CRM_Wallet_Page_API();
$page->run();
