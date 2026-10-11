<?php

use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_Page_API extends CRM_Core_Page {

  public function run() {
    // Disable debugging output that might corrupt JSON
    $config = CRM_Core_Config::singleton();
    $config->debug = FALSE;

    if (!class_exists('CiviWalletApiRequestGuard', FALSE)) {
      if (!defined('CIVI_WALLET_API_LIBRARY_ONLY')) {
        define('CIVI_WALLET_API_LIBRARY_ONLY', TRUE);
      }
      require_once E::path('extern/api.php');
    }
    $request = CiviWalletApiRequestGuard::inspect($_SERVER);
    if ($request['status'] !== NULL) {
      if ($request['allow']) {
        header('Allow: ' . implode(', ', $request['allow']));
      }
      http_response_code($request['status']);
      CRM_Utils_System::civiExit();
    }

    try {
      switch ($request['route']) {
        case 'register':
          $this->registerDevice($request['params']['deviceId'], $request['params']['passTypeId'], $request['params']['serialNumber']);
          break;

        case 'unregister':
          $this->unregisterDevice($request['params']['deviceId'], $request['params']['passTypeId'], $request['params']['serialNumber']);
          break;

        case 'list':
          $this->getSerialNumbers($request['params']['deviceId'], $request['params']['passTypeId']);
          break;

        case 'pass':
          $this->getLatestPass($request['params']['passTypeId'], $request['params']['serialNumber']);
          break;

        case 'log':
          $this->log();
          break;

        case 'redirect':
          $this->handleRedirect();
          break;
      }
    }
    catch (\Throwable $e) {
      http_response_code(500);
      CRM_Core_Error::debug_log_message("Apple Wallet API Error: " . $e->getMessage());
      echo json_encode(['error' => 'Internal server error']);
    }

    CRM_Utils_System::civiExit();
  }

  private function authenticate($pass) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers = array_change_key_case($headers, CASE_LOWER);
    $authHeader = $headers['authorization'] ?? '';

    if (!str_starts_with($authHeader, 'ApplePass ')) {
      return FALSE;
    }
    $token = substr($authHeader, 10);

    $expectedToken = (string) $pass->authentication_token;
    if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
      return FALSE;
    }

    return TRUE;
  }

  private function registerDevice($deviceId, $passTypeId, $serialNumber) {
    // Verify Pass and Auth Token
    $pass = $this->getPass($serialNumber, $passTypeId);
    if (!$pass) {
      http_response_code(404);
      return;
    }
    if (!$this->authenticate($pass)) {
      http_response_code(401);
      return;
    }

    $body = file_get_contents('php://input', FALSE, NULL, 0, CiviWalletApiRequestGuard::MAX_BODY_BYTES + 1);
    $registration = CiviWalletApiRequestGuard::validateRegistrationBody($body === FALSE ? '' : $body);
    if ($registration['status'] !== 200) {
      http_response_code($registration['status']);
      return;
    }
    $pushToken = $registration['pushToken'];

    $isRegistered = (bool) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_wallet_device WHERE pass_id = %1 AND device_library_identifier = %2',
      [
        1 => [$pass->id, 'Integer'],
        2 => [$deviceId, 'String'],
      ]
    );

    // Insert or update the device registration.
    $sql = "INSERT INTO civicrm_wallet_device (pass_id, device_library_identifier, push_token)
            VALUES (%1, %2, %3)
            ON DUPLICATE KEY UPDATE push_token = %3";

    CRM_Core_DAO::executeQuery($sql, [
      1 => [$pass->id, 'Integer'],
      2 => [$deviceId, 'String'],
      3 => [$pushToken, 'String'],
    ]);

    http_response_code($isRegistered ? 200 : 201);
  }

  private function unregisterDevice($deviceId, $passTypeId, $serialNumber) {
    $pass = $this->getPass($serialNumber, $passTypeId);
    if (!$pass) {
      http_response_code(404);
      return;
    }
    if (!$this->authenticate($pass)) {
      http_response_code(401);
      return;
    }

    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_wallet_device WHERE pass_id = %1 AND device_library_identifier = %2", [
      1 => [$pass->id, 'Integer'],
      2 => [$deviceId, 'String'],
    ]);

    http_response_code(200);
  }

  private function getSerialNumbers($deviceId, $passTypeId) {
    $expectedPassTypeId = \Civi::settings()->get('wallet_apple_pass_type_id');
    if ($expectedPassTypeId && $passTypeId !== $expectedPassTypeId) {
      http_response_code(404);
      return;
    }

    $updatedSince = CRM_Utils_Request::retrieve('passesUpdatedSince', 'String');
    if ($updatedSince !== NULL && $updatedSince !== '' && !ctype_digit($updatedSince)) {
      http_response_code(400);
      return;
    }

    // Find all passes for this device
    $sql = "SELECT p.serial_number, UNIX_TIMESTAMP(p.last_updated) AS update_tag
            FROM civicrm_wallet_pass p
            JOIN civicrm_wallet_device d ON d.pass_id = p.id
            WHERE d.device_library_identifier = %1
              AND p.type = 'apple'";

    $params = [1 => [$deviceId, 'String']];

    if ($updatedSince !== NULL && $updatedSince !== '') {
      $sql .= ' AND UNIX_TIMESTAMP(p.last_updated) > %2';
      $params[2] = [(int) $updatedSince, 'Integer'];
    }

    $dao = CRM_Core_DAO::executeQuery($sql, $params);
    $serialNumbers = [];
    $lastUpdated = 0;

    while ($dao->fetch()) {
      $serialNumbers[] = $dao->serial_number;
      $ts = (int) $dao->update_tag;
      if ($ts > $lastUpdated) {
        $lastUpdated = $ts;
      }
    }

    if (empty($serialNumbers)) {
      // No Content
      http_response_code(204);
      return;
    }

    header('Content-Type: application/json');
    echo json_encode([
      'lastUpdated' => (string) $lastUpdated,
      'serialNumbers' => $serialNumbers,
    ]);
  }

  private function getLatestPass($passTypeId, $serialNumber) {
    $pass = $this->getPass($serialNumber, $passTypeId);
    if (!$pass) {
      http_response_code(404);
      return;
    }
    if (!$this->authenticate($pass)) {
      http_response_code(401);
      return;
    }

    // Check If-Modified-Since
    if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
      $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
      $lastModified = strtotime($pass->last_updated);

      if ($lastModified <= $ifModifiedSince) {
        http_response_code(304);
        return;
      }
    }

    // Generate Pass Content
    // We need to call BAO but BAO currently echoes or returns content.
    // We need to make sure BAO doesn't re-generate serial number or auth token if they exist.
    // The current BAO implementation handles this (checks DB first).

    $entityId = $pass->entity_id;
    $entityTable = $pass->entity_table;

    try {
      $content = CRM_Wallet_BAO_Pass::createApplePass($entityTable, $entityId);

      header('Pragma: no-cache');
      header('Content-Type: application/vnd.apple.pkpass');
      header('Content-Length: ' . strlen($content));
      header('Content-Disposition: attachment; filename="pass.pkpass"');
      header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($pass->last_updated)) . ' GMT');

      echo $content;
    }
    catch (Exception) {
      http_response_code(500);
    }
  }

  private function log() {
    $body = file_get_contents('php://input', FALSE, NULL, 0, CiviWalletApiRequestGuard::MAX_BODY_BYTES + 1);
    http_response_code(CiviWalletApiRequestGuard::validateLogBody($body === FALSE ? '' : $body));
  }

  private function getPass($serialNumber, $passTypeId) {
    $expectedPassTypeId = \Civi::settings()->get('wallet_apple_pass_type_id');
    if ($expectedPassTypeId && $passTypeId !== $expectedPassTypeId) {
      return NULL;
    }
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_wallet_pass WHERE serial_number = %1 AND type = 'apple'", [
      1 => [$serialNumber, 'String'],
    ]);
    if ($dao->fetch()) {
      return $dao;
    }
    return NULL;
  }

  private function handleRedirect() {
    $token = CRM_Utils_Request::retrieve('token', 'String');
    $action = CRM_Utils_Request::retrieve('action', 'String');

    if (!$token || !$action) {
      CRM_Core_Error::debug_log_message("Wallet Redirect Error: Missing parameters");
      CRM_Utils_System::civiExit();
    }

    // Find the pass by token
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_wallet_pass WHERE authentication_token = %1", [
      1 => [$token, 'String'],
    ]);

    if (!$dao->fetch() || $dao->entity_table !== 'civicrm_contact') {
      CRM_Core_Error::debug_log_message("Wallet Redirect Error: Invalid token or not a contact pass");
      echo "Ce lien n'est plus valide ou le pass n'existe pas.";
      CRM_Utils_System::civiExit();
    }

    $contactId = $dao->entity_id;

    // Generate Checksum
    $cs = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);

    // Get Base URLs from settings
    $renewUrlBase = Civi::settings()->get('wallet_membership_renew_url') ?: 'civicrm/contribute/transact?reset=1&id=1';
    $profileUrlBase = Civi::settings()->get('wallet_membership_profile_url') ?: 'civicrm/profile/edit?reset=1&gid=1';

    $baseUrl = ($action === 'renew') ? $renewUrlBase : $profileUrlBase;

    // Append cid and cs
    $separator = (!str_contains($baseUrl, '?')) ? '?' : '&';
    $finalUrl = CRM_Utils_System::url($baseUrl, "cid={$contactId}&cs={$cs}", TRUE, NULL, FALSE);

    // Redirect
    CRM_Utils_System::redirect($finalUrl);
  }

}
