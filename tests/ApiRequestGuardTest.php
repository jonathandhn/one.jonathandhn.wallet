<?php

define('CIVI_WALLET_API_LIBRARY_ONLY', TRUE);
require_once __DIR__ . '/../extern/api.php';

function assertSameValue($expected, $actual, string $message): void {
  if ($expected !== $actual) {
    fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export($expected, TRUE), var_export($actual, TRUE)));
    exit(1);
  }
}

function inspect(string $uri, string $method = 'GET', array $extra = []): array {
  return CiviWalletApiRequestGuard::inspect($extra + [
    'REQUEST_URI' => $uri,
    'REQUEST_METHOD' => $method,
  ]);
}

$pass = inspect('/civi-wallet-api.php/v1/passes/pass.example/serial-1');
assertSameValue(NULL, $pass['status'], 'valid pass request is accepted');
assertSameValue('pass', $pass['route'], 'pass route is identified');

$registration = inspect('/civi-wallet-api.php/v1/devices/device-1/registrations/pass.example/serial-1', 'POST', [
  'CONTENT_TYPE' => 'application/json; charset=utf-8',
  'CONTENT_LENGTH' => '32',
]);
assertSameValue(NULL, $registration['status'], 'valid registration request is accepted');
assertSameValue('register', $registration['route'], 'registration route is identified');

assertSameValue(405, inspect('/civi-wallet-api.php/v1/passes/pass.example/serial-1', 'POST')['status'], 'wrong pass method is rejected');
assertSameValue(['GET'], inspect('/civi-wallet-api.php/v1/passes/pass.example/serial-1', 'POST')['allow'], 'wrong pass method reports the allowed method');
assertSameValue(404, inspect('/civi-wallet-api.php/not-an-api-route')['status'], 'unknown route is rejected');
assertSameValue('list', inspect('/civi-wallet-api.php/v1/devices/device-1/registrations/pass.example')['route'], 'registration list route is identified');
assertSameValue('unregister', inspect('/civi-wallet-api.php/v1/devices/device-1/registrations/pass.example/serial-1', 'DELETE')['route'], 'unregister route is identified');
assertSameValue('redirect', inspect('/civi-wallet-api.php/redirect')['route'], 'redirect route is identified');
assertSameValue(405, inspect('/civi-wallet-api.php/redirect', 'POST')['status'], 'wrong redirect method is rejected');
assertSameValue(415, inspect('/civi-wallet-api.php/v1/log', 'POST', ['CONTENT_TYPE' => 'text/plain'])['status'], 'wrong log content type is rejected');
assertSameValue(413, inspect('/civi-wallet-api.php/v1/log', 'POST', [
  'CONTENT_TYPE' => 'application/json',
  'CONTENT_LENGTH' => (string) (CiviWalletApiRequestGuard::MAX_BODY_BYTES + 1),
])['status'], 'oversized body is rejected before bootstrap');

$validRegistration = CiviWalletApiRequestGuard::validateRegistrationBody('{"pushToken":"abc123"}');
assertSameValue(200, $validRegistration['status'], 'valid push token is accepted');
assertSameValue('abc123', $validRegistration['pushToken'], 'push token is returned');
assertSameValue(400, CiviWalletApiRequestGuard::validateRegistrationBody('{"pushToken":false}')['status'], 'non-string push token is rejected');
assertSameValue(400, CiviWalletApiRequestGuard::validateRegistrationBody('{invalid')['status'], 'invalid registration JSON is rejected');
assertSameValue(413, CiviWalletApiRequestGuard::validateRegistrationBody(str_repeat('x', CiviWalletApiRequestGuard::MAX_BODY_BYTES + 1))['status'], 'oversized registration body is rejected');

assertSameValue(200, CiviWalletApiRequestGuard::validateLogBody('{"logs":["first","second"]}'), 'bounded Apple logs are accepted silently');
assertSameValue(413, CiviWalletApiRequestGuard::validateLogBody(json_encode([
  'logs' => array_fill(0, CiviWalletApiRequestGuard::MAX_LOG_ENTRIES + 1, 'entry'),
])), 'too many Apple logs are rejected');
assertSameValue(413, CiviWalletApiRequestGuard::validateLogBody(json_encode([
  'logs' => [str_repeat('x', CiviWalletApiRequestGuard::MAX_LOG_ENTRY_BYTES + 1)],
])), 'oversized Apple log entry is rejected');
assertSameValue(413, CiviWalletApiRequestGuard::validateLogBody(str_repeat('x', CiviWalletApiRequestGuard::MAX_BODY_BYTES + 1)), 'oversized Apple log body is rejected');

echo "ApiRequestGuardTest: OK\n";
