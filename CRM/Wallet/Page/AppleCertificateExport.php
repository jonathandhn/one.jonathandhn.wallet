<?php

/**
 * Download the active Apple Wallet PKCS#12 bundle or its password.
 */
class CRM_Wallet_Page_AppleCertificateExport extends CRM_Core_Page {

  public function run() {
    try {
      $part = CRM_Utils_Request::retrieve('part', 'String');
      if ($part === 'bundle') {
        $download = \Civi\Wallet\AppleCertificateManager::activeCertificateDownload();
        $this->sendFile($download['path'], $download['filename'], 'application/x-pkcs12');
      }
      if ($part === 'password') {
        $password = \Civi\Wallet\AppleCertificateManager::activeCertificatePassword();
        $passTypeId = (string) Civi::settings()->get('wallet_apple_pass_type_id');
        $identifier = preg_replace('/[^A-Za-z0-9.-]+/', '-', $passTypeId ?: 'wallet');
        $contents = $password . "\n";
        CRM_Utils_System::setHttpHeader('Content-Type', 'text/plain; charset=utf-8');
        CRM_Utils_System::setHttpHeader('Content-Disposition', 'attachment; filename="AppleWallet-' . $identifier . '-password.txt"');
        CRM_Utils_System::setHttpHeader('Content-Length', (string) strlen($contents));
        CRM_Utils_System::setHttpHeader('Cache-Control', 'private, no-store, max-age=0');
        echo $contents;
        CRM_Utils_System::civiExit();
      }
      throw new RuntimeException('Choose the Apple Wallet bundle or password export.');
    }
    catch (Throwable $exception) {
      CRM_Core_Session::setStatus($exception->getMessage(), 'Apple Wallet certificate export', 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/wallet', 'reset=1'));
    }
  }

  private function sendFile(string $path, string $filename, string $contentType): void {
    CRM_Utils_System::setHttpHeader('Content-Type', $contentType);
    CRM_Utils_System::setHttpHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    CRM_Utils_System::setHttpHeader('Content-Length', (string) filesize($path));
    CRM_Utils_System::setHttpHeader('Cache-Control', 'private, no-store, max-age=0');
    readfile($path);
    CRM_Utils_System::civiExit();
  }

}
