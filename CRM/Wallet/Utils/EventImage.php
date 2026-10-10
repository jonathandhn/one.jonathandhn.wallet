<?php

class CRM_Wallet_Utils_EventImage {

  public static function publishForGoogle(int $eventId, string $sourcePath): string {
    if ($eventId <= 0 || !is_file($sourcePath) || !is_readable($sourcePath)) {
      throw new RuntimeException('The event Wallet image could not be read.');
    }

    $mimeType = self::detectMimeType($sourcePath);
    $extension = match ($mimeType) {
      'image/png' => 'png',
      'image/jpeg' => 'jpg',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      default => throw new RuntimeException("Unsupported event Wallet image type: {$mimeType}"),
    };

    $checksum = hash_file('sha256', $sourcePath);
    if ($checksum === FALSE) {
      throw new RuntimeException('The event Wallet image checksum could not be calculated.');
    }

    $directory = Civi::paths()->getPath('[civicrm.files]/wallet/events');
    if (!is_string($directory) || $directory === '') {
      throw new RuntimeException('The public CiviCRM files directory is not configured.');
    }
    if (!is_dir($directory)) {
      CRM_Utils_File::createDir($directory, FALSE);
    }
    if (!is_dir($directory) || !is_writable($directory)) {
      throw new RuntimeException('The public Wallet event image directory is not writable.');
    }

    $fileName = sprintf('event-%d-%s.%s', $eventId, substr($checksum, 0, 16), $extension);
    $destination = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($destination)) {
      self::copyAtomically($sourcePath, $destination);
    }

    $baseUrl = Civi::paths()->getUrl('[civicrm.files]/wallet/events', 'absolute');
    if (!is_string($baseUrl) || $baseUrl === '') {
      throw new RuntimeException('The public CiviCRM files URL is not configured.');
    }

    return rtrim($baseUrl, '/') . '/' . rawurlencode($fileName);
  }

  private static function detectMimeType(string $path): string {
    if (class_exists('finfo')) {
      $fileInfo = new finfo(FILEINFO_MIME_TYPE);
      $mimeType = $fileInfo->file($path);
      if (is_string($mimeType) && $mimeType !== '') {
        return strtolower($mimeType);
      }
    }
    if (function_exists('mime_content_type')) {
      $mimeType = mime_content_type($path);
      if (is_string($mimeType) && $mimeType !== '') {
        return strtolower($mimeType);
      }
    }

    throw new RuntimeException('The event Wallet image type could not be detected.');
  }

  private static function copyAtomically(string $source, string $destination): void {
    $temporary = $destination . '.' . bin2hex(random_bytes(6)) . '.tmp';
    try {
      if (!copy($source, $temporary) || !rename($temporary, $destination)) {
        throw new RuntimeException('The event Wallet image could not be published.');
      }
      chmod($destination, 0644);
    }
    finally {
      if (is_file($temporary)) {
        unlink($temporary);
      }
    }
  }

}
