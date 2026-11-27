<?php

namespace Civi\Wallet;

/**
 * Resolve the single Apple certificate action requested by the settings form.
 */
final class AppleCertificateSubmission {

  public const NONE = 'none';
  public const GENERATE_CSR = 'generate_csr';
  public const ACTIVATE_CERTIFICATE = 'activate_certificate';
  public const IMPORT_SIGNED_CERTIFICATE = 'import_signed_certificate';
  public const IMPORT_LEGACY_PKCS12 = 'import_legacy_pkcs12';

  /**
   * @param array<string,mixed> $post
   * @param array<string,mixed> $values
   * @param array<string,mixed> $files
   */
  public static function resolve(array $post, array $values, array $files): string {
    if (isset($post['_qf_Config_submit_generate_csr'])
      || !empty($values['_qf_Config_submit_generate_csr'])
    ) {
      return self::GENERATE_CSR;
    }

    if (isset($post['_qf_Config_submit_activate_certificate'])
      || !empty($values['_qf_Config_submit_activate_certificate'])
    ) {
      return self::ACTIVATE_CERTIFICATE;
    }

    if (self::hasUpload($files, 'wallet_apple_signed_cert_upload')) {
      return self::IMPORT_SIGNED_CERTIFICATE;
    }

    if (self::hasUpload($files, 'wallet_apple_cert_upload')) {
      return self::IMPORT_LEGACY_PKCS12;
    }

    return self::NONE;
  }

  /**
   * @param array<string,mixed> $files
   * @param string $field
   *   Upload field name.
   */
  private static function hasUpload(array $files, string $field): bool {
    $upload = $files[$field] ?? NULL;
    return is_array($upload)
      && !empty($upload['name'])
      && ($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_NO_FILE;
  }

}
