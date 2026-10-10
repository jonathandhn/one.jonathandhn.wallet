<?php

declare(strict_types = 1);

/**
 * Exercise the Apple Wallet web-service protocol without an Apple device.
 */

$options = getopt('', [
  'base-url:',
  'pass-type-id:',
  'serial-number:',
  'auth-token::',
]);

$baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
$passTypeId = (string) ($options['pass-type-id'] ?? '');
$serialNumber = (string) ($options['serial-number'] ?? '');
$authToken = (string) ($options['auth-token'] ?? getenv('APPLE_WALLET_AUTH_TOKEN'));

if ($baseUrl === '' || $passTypeId === '' || $serialNumber === '' || $authToken === '') {
  fwrite(
    STDERR,
    "Usage: APPLE_WALLET_AUTH_TOKEN=... php scripts/simulate-apple-wallet.php "
    . "--base-url=https://example.org/civi-wallet-api.php "
    . "--pass-type-id=pass.org.example --serial-number=example\n",
  );
  exit(2);
}

if (!extension_loaded('curl')) {
  fwrite(STDERR, "The PHP cURL extension is required.\n");
  exit(2);
}

$deviceId = 'civiwallet-simulator-' . bin2hex(random_bytes(8));
$pushToken = bin2hex(random_bytes(32));
$encodedDeviceId = rawurlencode($deviceId);
$encodedPassTypeId = rawurlencode($passTypeId);
$encodedSerialNumber = rawurlencode($serialNumber);
$registrationUrl = "{$baseUrl}/v1/devices/{$encodedDeviceId}/registrations/{$encodedPassTypeId}/{$encodedSerialNumber}";
$registrationsUrl = "{$baseUrl}/v1/devices/{$encodedDeviceId}/registrations/{$encodedPassTypeId}";
$passUrl = "{$baseUrl}/v1/passes/{$encodedPassTypeId}/{$encodedSerialNumber}";
$registered = FALSE;

try {
  $response = appleWalletRequest('POST', $registrationUrl, $authToken, ['pushToken' => $pushToken]);
  appleWalletExpectStatus($response, [201], 'new device registration');
  $registered = TRUE;
  appleWalletReport('New device registration', $response['status']);

  $response = appleWalletRequest('POST', $registrationUrl, $authToken, ['pushToken' => $pushToken]);
  appleWalletExpectStatus($response, [200], 'existing device registration');
  appleWalletReport('Existing device registration', $response['status']);

  $response = appleWalletRequest('GET', $registrationsUrl, $authToken);
  appleWalletExpectStatus($response, [200], 'updated pass list');
  $updates = json_decode($response['body'], TRUE, flags: JSON_THROW_ON_ERROR);
  if (!is_array($updates)
    || !isset($updates['lastUpdated'], $updates['serialNumbers'])
    || !ctype_digit((string) $updates['lastUpdated'])
    || !in_array($serialNumber, $updates['serialNumbers'], TRUE)
  ) {
    throw new RuntimeException('The updated pass list does not contain the expected serial number and numeric tag.');
  }
  appleWalletReport('Updated pass list', $response['status']);

  $updatedUrl = $registrationsUrl . '?passesUpdatedSince=' . rawurlencode((string) $updates['lastUpdated']);
  $response = appleWalletRequest('GET', $updatedUrl, $authToken);
  appleWalletExpectStatus($response, [204], 'unchanged pass list');
  appleWalletReport('Unchanged pass list', $response['status']);

  $response = appleWalletRequest('GET', $passUrl, $authToken);
  appleWalletExpectStatus($response, [200], 'pass download');
  $contentType = strtolower($response['headers']['content-type'] ?? '');
  if (!str_starts_with($contentType, 'application/vnd.apple.pkpass')) {
    throw new RuntimeException("Unexpected pass content type: {$contentType}");
  }
  appleWalletValidatePass($response['body']);
  appleWalletReport('Signed pass with modern barcodes', $response['status']);

  $response = appleWalletRequest('POST', $baseUrl . '/v1/log', NULL, [
    'logs' => ['CiviWallet protocol simulator'],
  ]);
  appleWalletExpectStatus($response, [200], 'device log');
  appleWalletReport('Device log', $response['status']);
}
catch (Throwable $e) {
  fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
  exitCode(1, $registered, $registrationUrl, $authToken);
}

exitCode(0, $registered, $registrationUrl, $authToken);

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function appleWalletRequest(string $method, string $url, ?string $authToken, ?array $json = NULL): array {
  $headers = [];
  $requestHeaders = ['Accept: application/json, application/vnd.apple.pkpass'];
  if ($authToken !== NULL) {
    $requestHeaders[] = 'Authorization: ApplePass ' . $authToken;
  }
  if ($json !== NULL) {
    $requestHeaders[] = 'Content-Type: application/json';
  }

  $curl = curl_init($url);
  curl_setopt_array($curl, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_FOLLOWLOCATION => FALSE,
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
      $separator = strpos($line, ':');
      if ($separator !== FALSE) {
        $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
      }
      return strlen($line);
    },
  ]);
  if ($json !== NULL) {
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_THROW_ON_ERROR));
  }

  $body = curl_exec($curl);
  if ($body === FALSE) {
    $error = curl_error($curl);
    throw new RuntimeException("HTTP request failed: {$error}");
  }
  $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

  return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

/**
 * @param array{status: int, headers: array<string, string>, body: string} $response
 * @param int[] $expected
 */
function appleWalletExpectStatus(array $response, array $expected, string $step): void {
  if (!in_array($response['status'], $expected, TRUE)) {
    $body = trim($response['body']);
    if (strlen($body) > 500) {
      $body = substr($body, 0, 500) . '...';
    }
    throw new RuntimeException("{$step} returned HTTP {$response['status']}: {$body}");
  }
}

function appleWalletValidatePass(string $content): void {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('The PHP Zip extension is required to inspect pass.json.');
  }

  $temporaryFile = tempnam(sys_get_temp_dir(), 'civiwallet-pass-');
  if ($temporaryFile === FALSE || file_put_contents($temporaryFile, $content) === FALSE) {
    throw new RuntimeException('Unable to create a temporary pass file.');
  }

  try {
    $archive = new ZipArchive();
    if ($archive->open($temporaryFile) !== TRUE) {
      throw new RuntimeException('The downloaded response is not a valid pkpass archive.');
    }
    $passJson = $archive->getFromName('pass.json');
    $archive->close();
    if (!is_string($passJson)) {
      throw new RuntimeException('The downloaded pkpass does not contain pass.json.');
    }
    $pass = json_decode($passJson, TRUE, flags: JSON_THROW_ON_ERROR);
    if (empty($pass['barcodes']) || !is_array($pass['barcodes'])) {
      throw new RuntimeException('pass.json does not use the modern barcodes array.');
    }
    if (isset($pass['barcode'])) {
      throw new RuntimeException('pass.json still contains the deprecated barcode object.');
    }
  }
  finally {
    unlink($temporaryFile);
  }
}

function appleWalletReport(string $step, int $status): void {
  fwrite(STDOUT, sprintf("PASS: %-38s HTTP %d\n", $step, $status));
}

function exitCode(int $code, bool $registered, string $registrationUrl, string $authToken): never {
  if ($registered) {
    try {
      $response = appleWalletRequest('DELETE', $registrationUrl, $authToken);
      appleWalletExpectStatus($response, [200], 'device unregistration');
      appleWalletReport('Device unregistration', $response['status']);
    }
    catch (Throwable $e) {
      fwrite(STDERR, "FAIL: Cleanup failed: {$e->getMessage()}\n");
      $code = 1;
    }
  }

  if ($code === 0) {
    fwrite(STDOUT, "Apple Wallet protocol simulation completed successfully.\n");
  }
  exit($code);
}
