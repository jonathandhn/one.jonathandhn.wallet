<?php

namespace Civi\Wallet;

use RuntimeException;

/**
 * Manage a zero-downtime Apple Wallet certificate rotation.
 */
final class AppleCertificateManager {

  private const PENDING_SECRET_SETTING = 'wallet_apple_pending_key_password';
  private const CANDIDATE_SECRET_SETTING = 'wallet_apple_candidate_password';

  /**
   * Generate an encrypted private key and its public certificate request.
   *
   * @return array<string,mixed>
   */
  public static function generatePendingCsr(
    string $passTypeId,
    string $teamId,
    string $commonName,
    string $email,
    string $organization,
    string $country
  ): array {
    self::validateIdentity($passTypeId, $teamId, $commonName, $email, $organization, $country);
    $directory = self::privateDirectory();
    $state = self::readState($directory);
    self::removeWorkflowFiles($directory, $state);

    $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(32));
    $keyFilename = 'pending-' . $id . '.key.pem';
    $csrFilename = 'pending-' . $id . '.certSigningRequest';
    $keyPath = $directory . DIRECTORY_SEPARATOR . $keyFilename;
    $csrPath = $directory . DIRECTORY_SEPARATOR . $csrFilename;

    $key = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
      'digest_alg' => 'sha256',
    ]);
    if ($key === FALSE) {
      throw new RuntimeException('OpenSSL could not generate the Apple Wallet private key: ' . self::opensslErrors());
    }

    $dn = [
      'countryName' => strtoupper($country),
      'organizationName' => $organization,
      'organizationalUnitName' => $teamId,
      'commonName' => $commonName,
      'emailAddress' => $email,
      'UID' => $passTypeId,
    ];
    $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
    if ($csr === FALSE) {
      throw new RuntimeException('OpenSSL could not generate the Apple Wallet certificate request: ' . self::opensslErrors());
    }
    if (!openssl_pkey_export($key, $privateKeyPem, $password, [
      'encrypt_key' => TRUE,
      'encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC,
    ])) {
      throw new RuntimeException('OpenSSL could not encrypt the Apple Wallet private key: ' . self::opensslErrors());
    }
    if (!openssl_csr_export($csr, $csrPem)) {
      throw new RuntimeException('OpenSSL could not export the Apple Wallet certificate request: ' . self::opensslErrors());
    }

    self::writeSecretFile($keyPath, $privateKeyPem);
    self::writeSecretFile($csrPath, $csrPem);
    $publicKey = openssl_pkey_get_details($key);
    if (!is_array($publicKey) || empty($publicKey['key'])) {
      throw new RuntimeException('OpenSSL could not inspect the generated Apple Wallet public key.');
    }

    $pending = [
      'id' => $id,
      'key_file' => $keyFilename,
      'csr_file' => $csrFilename,
      'created_at' => gmdate('c'),
      'pass_type_id' => $passTypeId,
      'team_id' => $teamId,
      'common_name' => $commonName,
      'email' => $email,
      'organization' => $organization,
      'country' => strtoupper($country),
      'public_key_sha256' => hash('sha256', (string) $publicKey['key']),
    ];
    $state = ['pending' => $pending, 'candidate' => NULL, 'active' => $state['active'] ?? NULL];
    self::writeState($directory, $state);
    \Civi::settings()->set(self::PENDING_SECRET_SETTING, $password);
    \Civi::settings()->set(self::CANDIDATE_SECRET_SETTING, NULL);

    return $pending;
  }

  /**
   * Validate an Apple-issued certificate and stage an inactive PKCS#12 file.
   *
   * @return array<string,mixed>
   */
  public static function stageSignedCertificate(string $certificatePath, string $passTypeId, string $teamId): array {
    $directory = self::privateDirectory();
    $state = self::readState($directory);
    $pending = $state['pending'] ?? NULL;
    if (!is_array($pending)) {
      throw new RuntimeException('Generate a certificate request before importing the Apple certificate.');
    }
    if (($pending['pass_type_id'] ?? '') !== $passTypeId || ($pending['team_id'] ?? '') !== $teamId) {
      throw new RuntimeException('The configured Pass Type ID or Team ID changed after the certificate request was generated. Generate a new request.');
    }

    $keyPassword = (string) \Civi::settings()->get(self::PENDING_SECRET_SETTING);
    if ($keyPassword === '') {
      throw new RuntimeException('The password for the pending Apple Wallet private key is unavailable.');
    }
    $keyPath = self::workflowPath($directory, (string) ($pending['key_file'] ?? ''));
    $privateKey = openssl_pkey_get_private(self::readFile($keyPath), $keyPassword);
    if ($privateKey === FALSE) {
      throw new RuntimeException('The pending Apple Wallet private key cannot be decrypted.');
    }

    $certificatePem = self::readCertificate($certificatePath);
    $certificate = openssl_x509_read($certificatePem);
    if ($certificate === FALSE) {
      throw new RuntimeException('The uploaded file is not a readable X.509 certificate.');
    }
    $info = self::validateCertificate($certificate, $privateKey, $passTypeId, $teamId);

    $candidatePassword = bin2hex(random_bytes(32));
    if (!openssl_pkcs12_export($certificate, $pkcs12, $privateKey, $candidatePassword, [
      'friendly_name' => 'Apple Wallet ' . $passTypeId,
    ])) {
      throw new RuntimeException('OpenSSL could not assemble the Apple Wallet PKCS#12 certificate: ' . self::opensslErrors());
    }
    self::testPkcs12Signature($pkcs12, $candidatePassword);

    $candidateFilename = 'candidate-' . $pending['id'] . '.p12';
    self::writeSecretFile($directory . DIRECTORY_SEPARATOR . $candidateFilename, $pkcs12);
    $candidate = [
      'id' => $pending['id'],
      'file' => $candidateFilename,
      'validated_at' => gmdate('c'),
      'pass_type_id' => $info['pass_type_id'],
      'team_id' => $info['team_id'],
      'subject' => $info['subject'],
      'issuer' => $info['issuer'],
      'serial_number' => $info['serial_number'],
      'valid_from' => $info['valid_from'],
      'valid_to' => $info['valid_to'],
      'fingerprint_sha256' => $info['fingerprint_sha256'],
      'signature_tested' => TRUE,
    ];
    $state['candidate'] = $candidate;
    self::writeState($directory, $state);
    \Civi::settings()->set(self::CANDIDATE_SECRET_SETTING, $candidatePassword);

    return $candidate;
  }

  /**
   * Activate a validated candidate and retain the former certificate settings.
   *
   * @return array<string,mixed>
   */
  public static function activateCandidate(string $passTypeId, string $teamId): array {
    $directory = self::privateDirectory();
    $state = self::readState($directory);
    $candidate = $state['candidate'] ?? NULL;
    if (!is_array($candidate)) {
      throw new RuntimeException('No validated Apple Wallet certificate is waiting for activation.');
    }
    if (($candidate['pass_type_id'] ?? '') !== $passTypeId || ($candidate['team_id'] ?? '') !== $teamId) {
      throw new RuntimeException('The validated certificate does not match the configured Pass Type ID and Team ID.');
    }

    $candidatePassword = (string) \Civi::settings()->get(self::CANDIDATE_SECRET_SETTING);
    $candidatePath = self::workflowPath($directory, (string) ($candidate['file'] ?? ''));
    $pkcs12 = self::readFile($candidatePath);
    self::testPkcs12Signature($pkcs12, $candidatePassword);

    $activeFilename = 'active-' . $candidate['id'] . '.p12';
    $activePath = $directory . DIRECTORY_SEPARATOR . $activeFilename;
    self::writeSecretFile($activePath, $pkcs12);

    $settings = \Civi::settings();
    $previousPath = (string) $settings->get('wallet_apple_cert');
    $previousPassword = (string) $settings->get('wallet_apple_cert_password');
    if ($previousPath !== '' && is_file($previousPath)) {
      $settings->set('wallet_apple_previous_cert', $previousPath);
      $settings->set('wallet_apple_previous_cert_password', $previousPassword);
    }

    $settings->set('wallet_apple_cert', $activePath);
    $settings->set('wallet_apple_cert_password', $candidatePassword);
    $settings->set('wallet_apple_pass_type_id', $passTypeId);
    $settings->set('wallet_apple_team_id', $teamId);

    self::removeIfWorkflowFile($directory, (string) (($state['pending']['key_file'] ?? '')));
    self::removeIfWorkflowFile($directory, (string) (($state['pending']['csr_file'] ?? '')));
    self::removeIfWorkflowFile($directory, (string) ($candidate['file'] ?? ''));
    $active = $candidate;
    $active['file'] = $activeFilename;
    $active['activated_at'] = gmdate('c');
    unset($active['validated_at']);
    self::writeState($directory, ['pending' => NULL, 'candidate' => NULL, 'active' => $active]);
    $settings->set(self::PENDING_SECRET_SETTING, NULL);
    $settings->set(self::CANDIDATE_SECRET_SETTING, NULL);

    return $active;
  }

  /**
   * @return array<string,mixed>
   */
  public static function state(): array {
    $directory = self::privateDirectory();
    $state = self::readState($directory);
    $activePath = (string) \Civi::settings()->get('wallet_apple_cert');
    $activePassword = (string) \Civi::settings()->get('wallet_apple_cert_password');
    if ($activePath !== '' && is_file($activePath)) {
      try {
        $state['configured_active'] = self::inspectPkcs12($activePath, $activePassword);
      }
      catch (\Throwable $exception) {
        $state['configured_active_error'] = $exception->getMessage();
      }
    }
    return $state;
  }

  /**
   * @return array{path:string,filename:string}
   */
  public static function pendingCsrDownload(): array {
    $directory = self::privateDirectory();
    $state = self::readState($directory);
    $pending = $state['pending'] ?? NULL;
    if (!is_array($pending)) {
      throw new RuntimeException('No Apple Wallet certificate request is waiting for download.');
    }
    $path = self::workflowPath($directory, (string) ($pending['csr_file'] ?? ''));
    if (!is_file($path)) {
      throw new RuntimeException('The pending Apple Wallet certificate request file is missing.');
    }
    $identifier = preg_replace('/[^A-Za-z0-9.-]+/', '-', (string) ($pending['pass_type_id'] ?? 'wallet'));
    return [
      'path' => $path,
      'filename' => 'AppleWallet-' . $identifier . '.certSigningRequest',
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private static function validateCertificate(\OpenSSLCertificate $certificate, \OpenSSLAsymmetricKey $privateKey, string $passTypeId, string $teamId): array {
    if (!openssl_x509_check_private_key($certificate, $privateKey)) {
      throw new RuntimeException('The Apple certificate does not match the private key generated with this certificate request.');
    }
    $parsed = openssl_x509_parse($certificate);
    if (!is_array($parsed)) {
      throw new RuntimeException('The Apple certificate metadata cannot be read.');
    }
    $subject = $parsed['subject'] ?? [];
    $issuer = $parsed['issuer'] ?? [];
    $certificatePassTypeId = self::nameValue($subject, ['UID', 'userId']);
    $certificateTeamId = self::nameValue($subject, ['OU', 'organizationalUnitName']);
    $issuerName = self::nameValue($issuer, ['CN', 'commonName']);
    if ($certificatePassTypeId !== $passTypeId) {
      throw new RuntimeException('The Apple certificate Pass Type ID does not match the configured Pass Type ID.');
    }
    if ($certificateTeamId !== $teamId) {
      throw new RuntimeException('The Apple certificate Team ID does not match the configured Team ID.');
    }
    if (!str_contains(strtolower($issuerName), 'apple worldwide developer relations')) {
      throw new RuntimeException('The certificate was not issued by the Apple Worldwide Developer Relations certification authority.');
    }
    $now = time();
    $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
    $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
    if ($validFrom <= 0 || $validTo <= $now || $validFrom > $now + 300) {
      throw new RuntimeException('The Apple certificate is not currently valid.');
    }
    $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
    if (!is_string($fingerprint) || $fingerprint === '') {
      throw new RuntimeException('The Apple certificate fingerprint cannot be calculated.');
    }

    return [
      'pass_type_id' => $certificatePassTypeId,
      'team_id' => $certificateTeamId,
      'subject' => self::nameValue($subject, ['CN', 'commonName']),
      'issuer' => $issuerName,
      'serial_number' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
      'valid_from' => gmdate('c', $validFrom),
      'valid_to' => gmdate('c', $validTo),
      'fingerprint_sha256' => strtolower(str_replace(':', '', $fingerprint)),
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private static function inspectPkcs12(string $path, string $password): array {
    $pkcs12 = self::readFile($path);
    $certificates = [];
    if (!openssl_pkcs12_read($pkcs12, $certificates, $password) || empty($certificates['cert'])) {
      throw new RuntimeException('The configured Apple Wallet PKCS#12 certificate cannot be read.');
    }
    $parsed = openssl_x509_parse($certificates['cert']);
    if (!is_array($parsed)) {
      throw new RuntimeException('The configured Apple Wallet certificate metadata cannot be read.');
    }
    return [
      'pass_type_id' => self::nameValue($parsed['subject'] ?? [], ['UID', 'userId']),
      'team_id' => self::nameValue($parsed['subject'] ?? [], ['OU', 'organizationalUnitName']),
      'subject' => self::nameValue($parsed['subject'] ?? [], ['CN', 'commonName']),
      'issuer' => self::nameValue($parsed['issuer'] ?? [], ['CN', 'commonName']),
      'valid_from' => gmdate('c', (int) ($parsed['validFrom_time_t'] ?? 0)),
      'valid_to' => gmdate('c', (int) ($parsed['validTo_time_t'] ?? 0)),
    ];
  }

  private static function testPkcs12Signature(string $pkcs12, string $password): void {
    if ($password === '') {
      throw new RuntimeException('The candidate Apple Wallet certificate password is unavailable.');
    }
    $certificates = [];
    if (!openssl_pkcs12_read($pkcs12, $certificates, $password)
      || empty($certificates['cert'])
      || empty($certificates['pkey'])
    ) {
      throw new RuntimeException('The candidate Apple Wallet PKCS#12 certificate cannot be read.');
    }
    $payload = 'civiwallet-certificate-test-' . bin2hex(random_bytes(16));
    if (!openssl_sign($payload, $signature, $certificates['pkey'], OPENSSL_ALGO_SHA256)
      || openssl_verify($payload, $signature, $certificates['cert'], OPENSSL_ALGO_SHA256) !== 1
    ) {
      throw new RuntimeException('The candidate Apple Wallet certificate failed its cryptographic signature test.');
    }
  }

  private static function readCertificate(string $path): string {
    $contents = self::readFile($path);
    if (str_contains($contents, '-----BEGIN CERTIFICATE-----')) {
      return $contents;
    }
    return "-----BEGIN CERTIFICATE-----\n"
      . chunk_split(base64_encode($contents), 64, "\n")
      . "-----END CERTIFICATE-----\n";
  }

  private static function validateIdentity(string $passTypeId, string $teamId, string $commonName, string $email, string $organization, string $country): void {
    if (!preg_match('/^pass\.[A-Za-z0-9.-]+$/', $passTypeId)) {
      throw new RuntimeException('Enter a valid Apple Pass Type ID beginning with pass.');
    }
    if (!preg_match('/^[A-Z0-9]{10}$/', $teamId)) {
      throw new RuntimeException('Enter the 10-character Apple Team ID.');
    }
    if ($commonName === '' || strlen($commonName) > 128) {
      throw new RuntimeException('Enter a certificate request common name no longer than 128 characters.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Enter a valid email address for the Apple certificate request.');
    }
    if ($organization === '' || strlen($organization) > 128) {
      throw new RuntimeException('Enter an organization name no longer than 128 characters.');
    }
    if (!preg_match('/^[A-Za-z]{2}$/', $country)) {
      throw new RuntimeException('Enter a two-letter country code for the Apple certificate request.');
    }
  }

  private static function privateDirectory(): string {
    $config = \CRM_Core_Config::singleton();
    $base = rtrim((string) $config->__get('configAndLogDir'), '/\\');
    if ($base === '') {
      throw new RuntimeException('The CiviCRM ConfigAndLog directory is not configured.');
    }
    $directory = $base . DIRECTORY_SEPARATOR . 'WalletCertificates';
    if (!is_dir($directory)) {
      \CRM_Utils_File::createDir($directory, FALSE);
    }
    if (!is_dir($directory) || !is_writable($directory)) {
      throw new RuntimeException('The private Apple Wallet certificate directory is not writable.');
    }
    chmod($directory, 0700);
    self::ensureAccessProtection($directory);
    return $directory;
  }

  private static function ensureAccessProtection(string $directory): void {
    $protections = [
      '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n",
      'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
      'index.html' => '',
    ];
    foreach ($protections as $filename => $contents) {
      $path = $directory . DIRECTORY_SEPARATOR . $filename;
      if (!is_file($path)) {
        self::writeSecretFile($path, $contents);
      }
    }
  }

  /**
   * @param string $directory
   *   Protected certificate workflow directory.
   *
   * @return array<string,mixed>
   */
  private static function readState(string $directory): array {
    $path = $directory . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($path)) {
      return ['pending' => NULL, 'candidate' => NULL, 'active' => NULL];
    }
    $contents = self::readFile($path);
    try {
      $state = json_decode($contents, TRUE, 32, JSON_THROW_ON_ERROR);
      return is_array($state) ? $state : [];
    }
    catch (\JsonException) {
      throw new RuntimeException('The Apple Wallet certificate rotation state is corrupted.');
    }
  }

  /**
   * @param string $directory
   *   Protected certificate workflow directory.
   * @param array<string,mixed> $state
   *   Certificate workflow state.
   */
  private static function writeState(string $directory, array $state): void {
    $json = json_encode($state);
    if (!is_string($json)) {
      throw new RuntimeException('The Apple Wallet certificate rotation state cannot be encoded.');
    }
    self::writeSecretFile($directory . DIRECTORY_SEPARATOR . 'state.json', $json . "\n");
  }

  private static function writeSecretFile(string $path, string $contents): void {
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
      || !chmod($temporary, 0600)
      || !rename($temporary, $path)
    ) {
      if (is_file($temporary)) {
        unlink($temporary);
      }
      throw new RuntimeException('A private Apple Wallet certificate file could not be written.');
    }
  }

  private static function readFile(string $path): string {
    if (!is_file($path) || !is_readable($path)) {
      throw new RuntimeException('A required Apple Wallet certificate file is missing.');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
      throw new RuntimeException('A required Apple Wallet certificate file cannot be read.');
    }
    return $contents;
  }

  /**
   * @param string $directory
   *   Protected certificate workflow directory.
   * @param array<string,mixed> $state
   *   Certificate workflow state.
   */
  private static function removeWorkflowFiles(string $directory, array $state): void {
    foreach (['key_file', 'csr_file'] as $key) {
      self::removeIfWorkflowFile($directory, (string) (($state['pending'][$key] ?? '')));
    }
    self::removeIfWorkflowFile($directory, (string) (($state['candidate']['file'] ?? '')));
  }

  private static function removeIfWorkflowFile(string $directory, string $filename): void {
    if ($filename === '' || basename($filename) !== $filename) {
      return;
    }
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
      unlink($path);
    }
  }

  private static function workflowPath(string $directory, string $filename): string {
    if ($filename === '' || basename($filename) !== $filename) {
      throw new RuntimeException('The Apple Wallet certificate workflow contains an invalid filename.');
    }
    return $directory . DIRECTORY_SEPARATOR . $filename;
  }

  /**
   * @param array<string,mixed> $name
   * @param string[] $keys
   */
  private static function nameValue(array $name, array $keys): string {
    foreach ($keys as $key) {
      if (!isset($name[$key])) {
        continue;
      }
      $value = $name[$key];
      return is_array($value) ? (string) reset($value) : (string) $value;
    }
    return '';
  }

  private static function opensslErrors(): string {
    $errors = [];
    while ($error = openssl_error_string()) {
      $errors[] = $error;
    }
    return $errors === [] ? 'unknown OpenSSL error' : implode('; ', $errors);
  }

}
