<?php

require_once __DIR__ . '/../Civi/Wallet/AppleCertificateSubmission.php';

use Civi\Wallet\AppleCertificateSubmission;

function appleCertificateSubmissionAssertSame(mixed $expected, mixed $actual, string $message): void {
  if ($expected !== $actual) {
    fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export($expected, TRUE), var_export($actual, TRUE)));
    exit(1);
  }
}

$signedCertificateUpload = [
  'wallet_apple_signed_cert_upload' => [
    'name' => 'previous.cer',
    'error' => UPLOAD_ERR_OK,
  ],
];

appleCertificateSubmissionAssertSame(
  AppleCertificateSubmission::GENERATE_CSR,
  AppleCertificateSubmission::resolve(
    ['_qf_Config_submit_generate_csr' => '1'],
    [],
    $signedCertificateUpload
  ),
  'generating a CSR ignores a certificate left in the upload field'
);

appleCertificateSubmissionAssertSame(
  AppleCertificateSubmission::ACTIVATE_CERTIFICATE,
  AppleCertificateSubmission::resolve(
    ['_qf_Config_submit_activate_certificate' => '1'],
    [],
    $signedCertificateUpload
  ),
  'activating a candidate ignores a certificate left in the upload field'
);

appleCertificateSubmissionAssertSame(
  AppleCertificateSubmission::IMPORT_SIGNED_CERTIFICATE,
  AppleCertificateSubmission::resolve([], [], $signedCertificateUpload),
  'a signed certificate upload is imported on a normal form submission'
);

appleCertificateSubmissionAssertSame(
  AppleCertificateSubmission::IMPORT_LEGACY_PKCS12,
  AppleCertificateSubmission::resolve([], [], [
    'wallet_apple_cert_upload' => [
      'name' => 'legacy.p12',
      'error' => UPLOAD_ERR_OK,
    ],
  ]),
  'a legacy PKCS#12 upload is imported on a normal form submission'
);

appleCertificateSubmissionAssertSame(
  AppleCertificateSubmission::NONE,
  AppleCertificateSubmission::resolve([], [], [
    'wallet_apple_signed_cert_upload' => [
      'name' => '',
      'error' => UPLOAD_ERR_NO_FILE,
    ],
  ]),
  'an empty upload field does not select a certificate action'
);

echo "AppleCertificateSubmissionTest: OK\n";
