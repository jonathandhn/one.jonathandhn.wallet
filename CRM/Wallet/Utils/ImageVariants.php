<?php

/**
 * Generates platform-specific Wallet image variants through MediaScaler.
 */
final class CRM_Wallet_Utils_ImageVariants {

  public static function isMediaScalerAvailable(): bool {
    $pipelineClass = 'CRM_MediaScaler_Service_Pipeline';
    if (!class_exists($pipelineClass)) {
      return FALSE;
    }
    try {
      new ReflectionMethod($pipelineClass, 'withImageRendition');
      return TRUE;
    }
    catch (ReflectionException) {
      return FALSE;
    }
  }

  /**
   * @var array<string,array{width:int,height:int}>
   */
  private const BRAND_ICON_VARIANTS = [
    'icon.png' => ['width' => 29, 'height' => 29],
    'icon@2x.png' => ['width' => 58, 'height' => 58],
    'icon@3x.png' => ['width' => 87, 'height' => 87],
    'google-logo.png' => ['width' => 660, 'height' => 660],
  ];

  /**
   * @var array<string,array{width:int,height:int}>
   */
  private const BRAND_LOGO_VARIANTS = [
    'logo.png' => ['width' => 160, 'height' => 50],
    'logo@2x.png' => ['width' => 320, 'height' => 100],
    'logo@3x.png' => ['width' => 480, 'height' => 150],
    'google-wide-logo.png' => ['width' => 1280, 'height' => 400],
  ];

  /**
   * @var array<string,array{width:int,height:int}>
   */
  private const EVENT_VARIANTS = [
    'strip.png' => ['width' => 375, 'height' => 98],
    'strip@2x.png' => ['width' => 750, 'height' => 196],
    'strip@3x.png' => ['width' => 1125, 'height' => 294],
    'google-hero.png' => ['width' => 1032, 'height' => 812],
  ];

  public static function generateBrandIcon(string $sourcePath): void {
    self::generateVariantSet(
      $sourcePath,
      'wallet.brand.icon',
      self::brandingDirectory(),
      self::BRAND_ICON_VARIANTS,
    );
  }

  public static function generateBrandLogo(string $sourcePath): void {
    self::generateVariantSet(
      $sourcePath,
      'wallet.brand.logo',
      self::brandingDirectory(),
      self::BRAND_LOGO_VARIANTS,
    );
  }

  /**
   * @return array{apple:array<string,string>,googleHeroUrl:string}
   */
  public static function generateEventAssets(int $eventId, string $sourcePath): array {
    if ($eventId < 1) {
      throw new InvalidArgumentException('A Wallet event identifier is required.');
    }
    if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
      throw new RuntimeException('The Wallet source image must be a readable regular file.');
    }
    $digest = hash_file('sha256', $sourcePath);
    if (!is_string($digest)) {
      throw new RuntimeException('The Wallet event image could not be digested.');
    }
    $prefix = sprintf('event-%d-%s-', $eventId, substr($digest, 0, 16));
    $variants = [];
    foreach (self::EVENT_VARIANTS as $name => $dimensions) {
      $variants[$prefix . $name] = $dimensions;
    }
    $directory = self::eventDirectory();
    if (!self::variantSetExists($directory, $variants)) {
      self::generateVariantSet(
        $sourcePath,
        'wallet.event.' . $eventId,
        $directory,
        $variants,
      );
    }

    return [
      'apple' => [
        'strip.png' => $directory . DIRECTORY_SEPARATOR . $prefix . 'strip.png',
        'strip@2x.png' => $directory . DIRECTORY_SEPARATOR . $prefix . 'strip@2x.png',
        'strip@3x.png' => $directory . DIRECTORY_SEPARATOR . $prefix . 'strip@3x.png',
      ],
      'googleHeroUrl' => self::eventBaseUrl() . '/' . rawurlencode($prefix . 'google-hero.png'),
    ];
  }

  public static function brandingPath(string $filename): string {
    $filename = self::safeFilename($filename);
    $generated = self::brandingDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($generated)) {
      return $generated;
    }
    return self::legacyBrandingPath($filename);
  }

  public static function brandingUrl(string $filename): string {
    $filename = self::safeFilename($filename);
    $path = self::brandingPath($filename);
    $version = is_file($path) ? (string) filemtime($path) : 'missing';
    $generated = self::brandingDirectory() . DIRECTORY_SEPARATOR . $filename;
    if ($path === $generated) {
      return self::brandingBaseUrl() . '/' . rawurlencode($filename) . '?v=' . rawurlencode($version);
    }
    $legacyUrl = CRM_Extension_System::singleton()->getMapper()->keyToUrl('one.jonathandhn.wallet');
    return rtrim($legacyUrl, '/') . '/resources/images/' . rawurlencode(basename($path))
      . '?v=' . rawurlencode($version);
  }

  /**
   * @param string $sourcePath
   * @param string $context
   * @param string $destinationDirectory
   * @param array<string,array{width:int,height:int}> $variants
   */
  private static function generateVariantSet(
    string $sourcePath,
    string $context,
    string $destinationDirectory,
    array $variants,
  ): void {
    self::assertMediaScalerAvailable();
    if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
      throw new RuntimeException('The Wallet source image must be a readable regular file.');
    }
    self::ensureDirectory($destinationDirectory);

    $staged = [];
    try {
      foreach ($variants as $filename => $dimensions) {
        $destination = $destinationDirectory . DIRECTORY_SEPARATOR . self::safeFilename($filename);
        $temporary = tempnam($destinationDirectory, '.wallet-variant-');
        if (!is_string($temporary)) {
          throw new RuntimeException('A Wallet image staging file could not be allocated.');
        }
        chmod($temporary, 0600);
        $policy = self::policy($dimensions['width'], $dimensions['height']);
        $pipelineClass = 'CRM_MediaScaler_Service_Pipeline';
        $pipelineClass::withImageRendition(
          $sourcePath,
          basename($sourcePath),
          $context . '.' . pathinfo($filename, PATHINFO_FILENAME),
          $policy,
          static function (object $image) use ($temporary): void {
            if (!copy($image->temporaryPath, $temporary)) {
              throw new RuntimeException('A Wallet image variant could not be staged.');
            }
          },
        );
        $staged[$destination] = $temporary;
      }

      foreach ($staged as $destination => $temporary) {
        if (!rename($temporary, $destination)) {
          throw new RuntimeException('A Wallet image variant could not be published.');
        }
        chmod($destination, 0644);
        unset($staged[$destination]);
      }
    }
    finally {
      foreach ($staged as $temporary) {
        if (is_file($temporary)) {
          unlink($temporary);
        }
      }
    }
  }

  /**
   * @return array<string,mixed>
   */
  private static function policy(int $width, int $height): array {
    return [
      'contract_version' => 1,
      'input_max_bytes' => 15 * 1024 * 1024,
      'maximum_width' => $width,
      'maximum_height' => $height,
      'quality' => 82,
      'resize_enabled' => TRUE,
      'auto_orient' => TRUE,
      'allow_upscale' => FALSE,
      'exact_dimensions' => TRUE,
      'crop_mode' => 'center',
      'formats' => [
        ['mime_type' => 'image/png', 'max_bytes' => 10 * 1024 * 1024, 'mode' => 'transform'],
      ],
    ];
  }

  private static function assertMediaScalerAvailable(): void {
    if (!self::isMediaScalerAvailable()) {
      throw new RuntimeException('A compatible MediaScaler version is required to generate Wallet images.');
    }
  }

  private static function brandingDirectory(): string {
    return self::civiPath('[civicrm.files]/wallet/branding');
  }

  private static function eventDirectory(): string {
    return self::civiPath('[civicrm.files]/wallet/events');
  }

  private static function brandingBaseUrl(): string {
    return self::civiUrl('[civicrm.files]/wallet/branding');
  }

  private static function eventBaseUrl(): string {
    return self::civiUrl('[civicrm.files]/wallet/events');
  }

  private static function civiPath(string $expression): string {
    $path = Civi::paths()->getPath($expression);
    if (!is_string($path) || $path === '') {
      throw new RuntimeException('The public CiviCRM files directory is not configured.');
    }
    return rtrim($path, '/\\');
  }

  private static function civiUrl(string $expression): string {
    $url = Civi::paths()->getUrl($expression, 'absolute');
    if (!is_string($url) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
      throw new RuntimeException('Wallet images require an absolute HTTPS public files URL.');
    }
    return rtrim($url, '/');
  }

  private static function ensureDirectory(string $directory): void {
    if (!is_dir($directory)) {
      CRM_Utils_File::createDir($directory, FALSE);
    }
    if (!is_dir($directory) || !is_writable($directory)) {
      throw new RuntimeException('The Wallet image directory is not writable.');
    }
  }

  /**
   * @param string $directory
   * @param array<string,array{width:int,height:int}> $variants
   */
  private static function variantSetExists(string $directory, array $variants): bool {
    foreach ($variants as $filename => $dimensions) {
      $path = $directory . DIRECTORY_SEPARATOR . self::safeFilename($filename);
      if (!is_file($path)) {
        return FALSE;
      }
      $info = getimagesize($path);
      if (!is_array($info)
        || (int) $info[0] !== $dimensions['width']
        || (int) $info[1] !== $dimensions['height']
      ) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private static function safeFilename(string $filename): string {
    if ($filename === '' || basename($filename) !== $filename || preg_match('/^[A-Za-z0-9@._-]+$/D', $filename) !== 1) {
      throw new InvalidArgumentException('The Wallet image filename is unsafe.');
    }
    return $filename;
  }

  private static function legacyBrandingPath(string $filename): string {
    $fallback = match ($filename) {
      'icon.png', 'icon@2x.png', 'icon@3x.png' => $filename,
      'google-logo.png' => 'icon.png',
      'logo.png' => 'logo.png',
      'logo@2x.png', 'logo@3x.png', 'google-wide-logo.png' => 'logo.png',
      default => $filename,
    };
    $base = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
    return rtrim($base, '/\\') . '/resources/images/' . $fallback;
  }

}
