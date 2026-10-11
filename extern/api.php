<?php

/**
 * Apple Wallet API endpoint.
 *
 * This webroot script bypasses CMS routing so Apple can use HTTP methods such
 * as DELETE even when the CMS integration blocks them.
 */

/**
 * Validate Apple Wallet web-service requests before bootstrapping the CMS.
 *
 * This class deliberately lives in this deployable script so the public
 * civi-wallet-api.php file remains self-contained.
 */
final class CiviWalletApiRequestGuard {

  public const MAX_BODY_BYTES = 16384;
  public const MAX_LOG_ENTRIES = 10;
  public const MAX_LOG_ENTRY_BYTES = 1024;

  /**
   * Inspect the request and return a route decision.
   *
   * @return array{status: int|null, route: string|null, allow: string[], params: array<string, string>}
   */
  public static function inspect(array $server): array {
    $uri = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

    if (preg_match('~(?:^|/)v1/devices/([^/]{1,255})/registrations/([^/]{1,255})/([^/]{1,255})/?$~', $uri, $matches)) {
      $allow = ['POST', 'DELETE'];
      if (!in_array($method, $allow, TRUE)) {
        return self::reject(405, $allow);
      }
      if ($method === 'POST' && ($status = self::validateJsonRequestMetadata($server)) !== NULL) {
        return self::reject($status, $allow);
      }
      return self::accept($method === 'POST' ? 'register' : 'unregister', [
        'deviceId' => $matches[1],
        'passTypeId' => $matches[2],
        'serialNumber' => $matches[3],
      ], $allow);
    }

    if (preg_match('~(?:^|/)v1/devices/([^/]{1,255})/registrations/([^/]{1,255})/?$~', $uri, $matches)) {
      $allow = ['GET'];
      if ($method !== 'GET') {
        return self::reject(405, $allow);
      }
      return self::accept('list', [
        'deviceId' => $matches[1],
        'passTypeId' => $matches[2],
      ], $allow);
    }

    if (preg_match('~(?:^|/)v1/passes/([^/]{1,255})/([^/]{1,255})/?$~', $uri, $matches)) {
      $allow = ['GET'];
      if ($method !== 'GET') {
        return self::reject(405, $allow);
      }
      return self::accept('pass', [
        'passTypeId' => $matches[1],
        'serialNumber' => $matches[2],
      ], $allow);
    }

    if (preg_match('~(?:^|/)v1/log/?$~', $uri)) {
      $allow = ['POST'];
      if ($method !== 'POST') {
        return self::reject(405, $allow);
      }
      if (($status = self::validateJsonRequestMetadata($server)) !== NULL) {
        return self::reject($status, $allow);
      }
      return self::accept('log', [], $allow);
    }

    if (preg_match('~(?:^|/)redirect/?$~', $uri)) {
      $allow = ['GET'];
      if ($method !== 'GET') {
        return self::reject(405, $allow);
      }
      return self::accept('redirect', [], $allow);
    }

    return self::reject(404);
  }

  /**
   * Validate a registration request body and return its push token.
   *
   * @return array{status: int, pushToken: string|null}
   */
  public static function validateRegistrationBody(string $body): array {
    if (strlen($body) > self::MAX_BODY_BYTES) {
      return ['status' => 413, 'pushToken' => NULL];
    }

    $payload = self::decodeJsonObject($body);
    if ($payload === NULL) {
      return ['status' => 400, 'pushToken' => NULL];
    }

    $pushToken = $payload['pushToken'] ?? NULL;
    if (!is_string($pushToken) || $pushToken === '' || strlen($pushToken) > 255) {
      return ['status' => 400, 'pushToken' => NULL];
    }

    return ['status' => 200, 'pushToken' => $pushToken];
  }

  /**
   * Validate the diagnostic payload while deliberately discarding its content.
   */
  public static function validateLogBody(string $body): int {
    if (strlen($body) > self::MAX_BODY_BYTES) {
      return 413;
    }

    $payload = self::decodeJsonObject($body);
    if ($payload === NULL || !isset($payload['logs']) || !is_array($payload['logs'])) {
      return 400;
    }
    if (count($payload['logs']) > self::MAX_LOG_ENTRIES) {
      return 413;
    }

    foreach ($payload['logs'] as $entry) {
      if (!is_string($entry) || strlen($entry) > self::MAX_LOG_ENTRY_BYTES) {
        return 413;
      }
    }

    return 200;
  }

  private static function validateJsonRequestMetadata(array $server): ?int {
    $contentType = trim((string) ($server['CONTENT_TYPE'] ?? ''));
    if (!preg_match('~^application/json(?:\s*;|$)~i', $contentType)) {
      return 415;
    }

    $contentLength = (string) ($server['CONTENT_LENGTH'] ?? '');
    if ($contentLength !== '') {
      if (!ctype_digit($contentLength)) {
        return 400;
      }
      if ((int) $contentLength > self::MAX_BODY_BYTES) {
        return 413;
      }
    }

    return NULL;
  }

  private static function decodeJsonObject(string $body): ?array {
    if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
      return NULL;
    }

    try {
      $payload = json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }

    return is_array($payload) ? $payload : NULL;
  }

  /**
   * @return array{status: null, route: string, allow: string[], params: array<string, string>}
   */
  private static function accept(string $route, array $params, array $allow): array {
    return [
      'status' => NULL,
      'route' => $route,
      'allow' => $allow,
      'params' => $params,
    ];
  }

  /**
   * @return array{status: int, route: null, allow: string[], params: array<string, string>}
   */
  private static function reject(int $status, array $allow = []): array {
    return [
      'status' => $status,
      'route' => NULL,
      'allow' => $allow,
      'params' => [],
    ];
  }

}

// Allow the CiviCRM page controller and tests to load the shared guard without
// executing the public endpoint.
if (defined('CIVI_WALLET_API_LIBRARY_ONLY')) {
  return;
}

$request = CiviWalletApiRequestGuard::inspect($_SERVER);
if ($request['status'] !== NULL) {
  if ($request['allow']) {
    header('Allow: ' . implode(', ', $request['allow']));
  }
  http_response_code($request['status']);
  exit;
}

// Apple diagnostic logging is intentionally accepted and discarded. Keeping
// this route outside the CMS bootstrap prevents unauthenticated log flooding
// from consuming WordPress and CiviCRM resources.
if ($request['route'] === 'log') {
  $body = file_get_contents('php://input', FALSE, NULL, 0, CiviWalletApiRequestGuard::MAX_BODY_BYTES + 1);
  http_response_code(CiviWalletApiRequestGuard::validateLogBody($body === FALSE ? '' : $body));
  exit;
}

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
