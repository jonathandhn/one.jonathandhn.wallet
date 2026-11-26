<?php

final class AppleCertificateTestSettings {
  private array $values = [];

  public function get(string $name): mixed {
    return $this->values[$name] ?? NULL;
  }

  public function set(string $name, mixed $value): void {
    $this->values[$name] = $value;
  }
}

final class Civi {
  public static AppleCertificateTestSettings $settings;

  public static function settings(): AppleCertificateTestSettings {
    return self::$settings;
  }
}

final class CRM_Core_Config {
  private string $configAndLogDir;
  private static self $instance;

  public static function singleton(): self {
    return self::$instance;
  }

  public static function initialize(string $directory): void {
    self::$instance = new self();
    self::$instance->configAndLogDir = $directory;
  }

  public function __get(string $name): mixed {
    return $name === 'configAndLogDir' ? $this->configAndLogDir : NULL;
  }
}

final class CRM_Utils_File {
  public static function createDir(string $directory, bool $abort): void {
    if (!mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new RuntimeException('Test directory could not be created.');
    }
  }
}

require_once __DIR__ . '/../Civi/Wallet/AppleCertificateManager.php';

use Civi\Wallet\AppleCertificateManager;

function appleCertificateAssertSame(mixed $expected, mixed $actual, string $message): void {
  if ($expected !== $actual) {
    fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export($expected, TRUE), var_export($actual, TRUE)));
    exit(1);
  }
}

function appleCertificateAssertTrue(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function removeAppleCertificateTestDirectory(string $directory): void {
  if (!is_dir($directory)) {
    return;
  }
  foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
    $path = $directory . DIRECTORY_SEPARATOR . $entry;
    is_dir($path) ? removeAppleCertificateTestDirectory($path) : unlink($path);
  }
  rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . '/wallet-apple-certificate-test-' . bin2hex(random_bytes(6));
mkdir($testDirectory, 0775, TRUE);
CRM_Core_Config::initialize($testDirectory);
Civi::$settings = new AppleCertificateTestSettings();

$passTypeId = 'pass.org.example.membership';
$teamId = 'A1B2C3D4E5';
$pending = AppleCertificateManager::generatePendingCsr(
  $passTypeId,
  $teamId,
  'Example Organization Apple Wallet',
  'wallet@example.org',
  'Example Organization',
  'FR'
);
appleCertificateAssertSame($passTypeId, $pending['pass_type_id'], 'pending CSR keeps the Pass Type ID');

$download = AppleCertificateManager::pendingCsrDownload();
appleCertificateAssertTrue(is_file($download['path']), 'CSR download file exists');
appleCertificateAssertTrue(str_ends_with($download['filename'], '.certSigningRequest'), 'CSR download uses the Apple filename extension');
$csrPem = file_get_contents($download['path']);
appleCertificateAssertTrue(is_string($csrPem) && str_contains($csrPem, 'BEGIN CERTIFICATE REQUEST'), 'CSR is PEM encoded');

$caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$caCsr = openssl_csr_new([
  'countryName' => 'US',
  'organizationName' => 'Apple Inc.',
  'organizationalUnitName' => 'Apple Worldwide Developer Relations',
  'commonName' => 'Apple Worldwide Developer Relations Certification Authority',
], $caKey, ['digest_alg' => 'sha256']);
$caCertificate = openssl_csr_sign($caCsr, NULL, $caKey, 3650, ['digest_alg' => 'sha256']);
appleCertificateAssertTrue($caCertificate !== FALSE, 'test Apple issuer certificate is generated');

$wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$wrongCsr = openssl_csr_new([
  'countryName' => 'FR',
  'organizationName' => 'Example Organization',
  'organizationalUnitName' => $teamId,
  'commonName' => 'Example Organization Apple Wallet',
  'emailAddress' => 'wallet@example.org',
  'UID' => $passTypeId,
], $wrongKey, ['digest_alg' => 'sha256']);
$wrongCertificate = openssl_csr_sign($wrongCsr, $caCertificate, $caKey, 365, ['digest_alg' => 'sha256']);
openssl_x509_export($wrongCertificate, $wrongCertificatePem);
$wrongCertificatePath = $testDirectory . '/wrong-apple-wallet.cer';
file_put_contents($wrongCertificatePath, $wrongCertificatePem);
try {
  AppleCertificateManager::stageSignedCertificate($wrongCertificatePath, $passTypeId, $teamId);
  appleCertificateAssertTrue(FALSE, 'a certificate generated for another private key is rejected');
}
catch (RuntimeException $exception) {
  appleCertificateAssertTrue(str_contains($exception->getMessage(), 'does not match the private key'), 'private-key mismatch has an explicit error');
}

$signedCertificate = openssl_csr_sign($csrPem, $caCertificate, $caKey, 365, ['digest_alg' => 'sha256']);
appleCertificateAssertTrue($signedCertificate !== FALSE, 'pending CSR is signed by the test Apple issuer');
openssl_x509_export($signedCertificate, $certificatePem);
$certificateDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $certificatePem), TRUE);
appleCertificateAssertTrue(is_string($certificateDer), 'test Apple certificate converts to DER');
$certificatePath = $testDirectory . '/apple-wallet.cer';
file_put_contents($certificatePath, $certificateDer);

$candidate = AppleCertificateManager::stageSignedCertificate($certificatePath, $passTypeId, $teamId);
appleCertificateAssertSame($passTypeId, $candidate['pass_type_id'], 'validated certificate matches the Pass Type ID');
appleCertificateAssertSame($teamId, $candidate['team_id'], 'validated certificate matches the Team ID');
appleCertificateAssertSame(TRUE, $candidate['signature_tested'], 'candidate passes the cryptographic signature test');

$oldCertificatePath = $testDirectory . '/old-active.p12';
file_put_contents($oldCertificatePath, 'previous certificate placeholder');
Civi::settings()->set('wallet_apple_cert', $oldCertificatePath);
Civi::settings()->set('wallet_apple_cert_password', 'previous-password');
$active = AppleCertificateManager::activateCandidate($passTypeId, $teamId);
appleCertificateAssertSame($passTypeId, $active['pass_type_id'], 'activated certificate retains the Pass Type ID');
$activePath = Civi::settings()->get('wallet_apple_cert');
appleCertificateAssertTrue(is_string($activePath) && is_file($activePath), 'activation switches the active certificate path');
appleCertificateAssertSame(0600, fileperms($activePath) & 0777, 'active certificate has private file permissions');
appleCertificateAssertSame($oldCertificatePath, Civi::settings()->get('wallet_apple_previous_cert'), 'previous certificate is retained for recovery');

$state = AppleCertificateManager::state();
appleCertificateAssertSame(NULL, $state['pending'], 'pending CSR is cleared after activation');
appleCertificateAssertSame(NULL, $state['candidate'], 'candidate is cleared after activation');
appleCertificateAssertSame($passTypeId, $state['configured_active']['pass_type_id'], 'active PKCS#12 metadata can be inspected');
appleCertificateAssertTrue(is_file($testDirectory . '/WalletCertificates/.htaccess'), 'private directory contains Apache access protection');

removeAppleCertificateTestDirectory($testDirectory);
echo "AppleCertificateManagerTest: OK\n";
