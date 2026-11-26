<?php

/**
 * Download the public Apple Wallet certificate signing request.
 */
class CRM_Wallet_Page_AppleCsr extends CRM_Core_Page {

  public function run() {
    try {
      $download = \Civi\Wallet\AppleCertificateManager::pendingCsrDownload();
      CRM_Utils_System::setHttpHeader('Content-Type', 'application/pkcs10');
      CRM_Utils_System::setHttpHeader('Content-Disposition', 'attachment; filename="' . $download['filename'] . '"');
      CRM_Utils_System::setHttpHeader('Content-Length', (string) filesize($download['path']));
      CRM_Utils_System::setHttpHeader('Cache-Control', 'private, no-store, max-age=0');
      readfile($download['path']);
      CRM_Utils_System::civiExit();
    }
    catch (Throwable $exception) {
      CRM_Core_Session::setStatus($exception->getMessage(), 'Apple Wallet CSR', 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/wallet', 'reset=1'));
    }
  }

}
