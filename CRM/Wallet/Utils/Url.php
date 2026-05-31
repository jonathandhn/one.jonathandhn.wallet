<?php

class CRM_Wallet_Utils_Url {

  /**
   * Build an absolute URL to a CiviCRM route.
   */
  public static function civicrm(string $path, ?string $query = NULL): string {
    return CRM_Utils_System::url($path, $query, TRUE);
  }

  /**
   * Build the admin status page URL.
   */
  public static function adminStatus(): string {
    return self::civicrm('civicrm/admin/status');
  }

  /**
   * Build the deploy API page URL.
   */
  public static function deployApi(?string $query = NULL): string {
    return self::civicrm('civicrm/wallet/deploy-api', $query);
  }

  /**
   * Build the public Wallet pass download URL.
   */
  public static function passDownload(?string $query = NULL): string {
    return self::civicrm('civicrm/wallet/pass', $query);
  }

  /**
   * Build the routed Wallet API URL.
   *
   * Kept mainly for admin/debug contexts; Apple callbacks still use the
   * webroot script endpoint for maximum CMS portability.
   */
  public static function routedApi(?string $query = NULL): string {
    return self::civicrm('civicrm/wallet/api', $query);
  }

  /**
   * Build an absolute URL to a script deployed in the CMS webroot.
   *
   * Apple Wallet update callbacks still rely on the standalone webroot script
   * because some CMS stacks interfere with HTTP verbs on routed endpoints.
   */
  public static function webrootScript(string $scriptName = 'civi-wallet-api.php'): string {
    $config = CRM_Core_Config::singleton();
    $baseUrl = $config->userFrameworkBaseURL ?: CRM_Utils_System::baseCMSURL();
    return rtrim($baseUrl, '/') . '/' . ltrim($scriptName, '/');
  }

}
