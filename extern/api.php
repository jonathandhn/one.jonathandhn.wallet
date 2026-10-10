<?php

/**
 * Apple Wallet API endpoint.
 *
 * This webroot script bypasses CMS routing so Apple can use HTTP methods such
 * as DELETE even when the CMS integration blocks them.
 */

/**
 * Build a list of directories to inspect, starting at the webroot and script.
 *
 * @return string[]
 */
function civi_wallet_api_search_directories() {
  $directories = [__DIR__];
  if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $directories[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
  }

  foreach ($directories as $directory) {
    $current = $directory;
    for ($depth = 0; $depth < 6; $depth++) {
      $directories[] = $current;
      $parent = dirname($current);
      if ($parent === $current) {
        break;
      }
      $current = $parent;
    }
  }

  return array_values(array_unique($directories));
}

/**
 * Find the first matching file below one of the bootstrap directories.
 *
 * @param string[] $directories
 * @param string[] $relativePaths
 *
 * @return string|null
 */
function civi_wallet_api_find_file(array $directories, array $relativePaths) {
  foreach ($directories as $directory) {
    foreach ($relativePaths as $relativePath) {
      $path = $directory . '/' . $relativePath;
      if (is_file($path)) {
        return $path;
      }
    }
  }

  return NULL;
}

$searchDirectories = civi_wallet_api_search_directories();
$bootstrapped = FALSE;

// Drupal must use the CMS kernel because its routing layer is deliberately
// bypassed by this endpoint while CiviCRM still needs Drupal's container.
$drupalKernelFile = civi_wallet_api_find_file($searchDirectories, ['core/lib/Drupal/Core/DrupalKernel.php']);
if ($drupalKernelFile !== NULL) {
  $drupalRoot = dirname($drupalKernelFile, 5);
  $autoloadFile = $drupalRoot . '/autoload.php';
  if (is_file($autoloadFile)) {
    try {
      $autoloader = require_once $autoloadFile;
      $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
      $kernel = \Drupal\Core\DrupalKernel::createFromRequest($request, $autoloader, 'prod');
      $kernel->boot();
      $kernel->preHandle($request);

      if (\Drupal::hasService('civicrm')) {
        \Drupal::service('civicrm')->initialize();
        $bootstrapped = TRUE;
      }
    }
    catch (\Throwable $e) {
      error_log('CiviWallet: Drupal bootstrap failed: ' . $e->getMessage());
    }
  }
}

// WordPress loads CiviCRM through its normal plugin lifecycle.
if (!$bootstrapped) {
  $wordpressLoader = civi_wallet_api_find_file($searchDirectories, ['wp-load.php']);
  if ($wordpressLoader !== NULL) {
    try {
      require_once $wordpressLoader;
      if (function_exists('civicrm_initialize')) {
        civicrm_initialize();
      }
      $bootstrapped = class_exists('CRM_Core_Config');
    }
    catch (\Throwable $e) {
      error_log('CiviWallet: WordPress bootstrap failed: ' . $e->getMessage());
    }
  }
}

// Standalone exposes a project-level bootstrap file rather than CMS hooks.
if (!$bootstrapped) {
  $standaloneLoader = civi_wallet_api_find_file($searchDirectories, ['civicrm.standalone.php']);
  if ($standaloneLoader !== NULL) {
    try {
      require_once $standaloneLoader;
      CRM_Core_Config::singleton();
      $bootstrapped = TRUE;
    }
    catch (\Throwable $e) {
      error_log('CiviWallet: Standalone bootstrap failed: ' . $e->getMessage());
    }
  }
}

// Keep support for legacy WordPress, Joomla, and direct CiviCRM layouts.
if (!$bootstrapped) {
  $configFile = civi_wallet_api_find_file($searchDirectories, [
    'civicrm.config.php',
    'sites/default/civicrm.config.php',
    'wp-content/plugins/civicrm/civicrm.config.php',
    'administrator/components/com_civicrm/civicrm.config.php',
  ]);

  if ($configFile !== NULL) {
    try {
      require_once $configFile;
      if (class_exists('\Civi\Cv\Bootstrap')) {
        \Civi\Cv\Bootstrap::singleton()->boot();
      }
      if (class_exists('CRM_Core_Config')) {
        CRM_Core_Config::singleton();
        $bootstrapped = TRUE;
      }
    }
    catch (\Throwable $e) {
      error_log('CiviWallet: legacy bootstrap failed: ' . $e->getMessage());
    }
  }
}

if (!$bootstrapped) {
  http_response_code(500);
  die('Could not bootstrap CiviCRM. Searched from: ' . implode(', ', $searchDirectories));
}

if (!class_exists('CRM_Wallet_Page_API')) {
  try {
    $extensionPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
    if ($extensionPath && is_file($extensionPath . '/CRM/Wallet/Page/API.php')) {
      require_once $extensionPath . '/CRM/Wallet/Page/API.php';
    }
  }
  catch (\Throwable $e) {
    error_log('CiviWallet: API class loading failed: ' . $e->getMessage());
  }
}

if (!class_exists('CRM_Wallet_Page_API')) {
  http_response_code(500);
  die('Error: CRM_Wallet_Page_API class not found. Is the extension enabled?');
}

$page = new CRM_Wallet_Page_API();
$page->run();
