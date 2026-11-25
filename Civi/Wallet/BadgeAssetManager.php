<?php

namespace Civi\Wallet;

use GuzzleHttp\Client;
use RuntimeException;
use ZipArchive;

/**
 * Install and resolve official Apple and Google Wallet badge artwork.
 *
 * Artwork is deliberately stored outside the extension so its own licence is
 * not conflated with the extension's AGPL licence.
 */
final class BadgeAssetManager {

  public const GOOGLE_PNG_PACKAGE_URL = 'https://developers.google.com/static/wallet/download-assets/add-to-wallet-png.zip';

  private const MAX_ARCHIVE_BYTES = 20 * 1024 * 1024;
  private const MAX_ENTRY_BYTES = 2 * 1024 * 1024;
  private const MAX_ENTRIES = 500;

  /**
   * Download and install Google's official localized SVG package.
   *
   * @return array<string,mixed>
   */
  public static function downloadGooglePackage(): array {
    $client = new Client([
      'allow_redirects' => ['max' => 3, 'strict' => TRUE, 'referer' => FALSE],
      'connect_timeout' => 10,
      'timeout' => 30,
      'http_errors' => TRUE,
    ]);
    $response = $client->request('GET', self::GOOGLE_PNG_PACKAGE_URL);
    $contents = (string) $response->getBody();
    if ($contents === '' || strlen($contents) > self::MAX_ARCHIVE_BYTES || !str_starts_with($contents, "PK")) {
      throw new RuntimeException('The Google Wallet artwork download is not a valid ZIP package.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'wallet-google-badges-');
    if ($temporaryPath === FALSE || file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)) {
      throw new RuntimeException('The Google Wallet artwork package could not be staged.');
    }

    try {
      return self::installZip('google', $temporaryPath, self::GOOGLE_PNG_PACKAGE_URL);
    }
    finally {
      if (is_file($temporaryPath)) {
        unlink($temporaryPath);
      }
    }
  }

  /**
   * Install official badge artwork from a vendor-provided ZIP package.
   *
   * @return array<string,mixed>
   */
  public static function installZip(string $platform, string $zipPath, string $source = 'administrative upload'): array {
    self::assertPlatform($platform);
    if (!is_file($zipPath) || filesize($zipPath) === FALSE || filesize($zipPath) > self::MAX_ARCHIVE_BYTES) {
      throw new RuntimeException('The Wallet artwork ZIP is missing or too large.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== TRUE) {
      throw new RuntimeException('The Wallet artwork package is not a readable ZIP file.');
    }

    try {
      if ($zip->numFiles > self::MAX_ENTRIES) {
        throw new RuntimeException('The Wallet artwork package contains too many files.');
      }

      $assets = [];
      $totalBytes = 0;
      for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        $stat = $zip->statIndex($index);
        if (!is_array($stat) || str_ends_with($name, '/') || str_contains($name, '__MACOSX/')) {
          continue;
        }

        $size = (int) ($stat['size'] ?? 0);
        $totalBytes += $size;
        if ($size <= 0 || $size > self::MAX_ENTRY_BYTES || $totalBytes > self::MAX_ARCHIVE_BYTES) {
          throw new RuntimeException('The Wallet artwork package exceeds the safe extraction limits.');
        }

        $candidate = self::classifyEntry($platform, $name);
        if ($candidate === NULL) {
          continue;
        }

        $contents = $zip->getFromIndex($index);
        if (!is_string($contents)) {
          throw new RuntimeException('A Wallet artwork file could not be read from the ZIP package.');
        }
        $dimensions = self::validateImage($candidate['extension'], $contents);
        if (($assets[$candidate['locale']]['extension'] ?? NULL) === 'png'
          && $candidate['extension'] === 'svg'
        ) {
          continue;
        }
        $assets[$candidate['locale']] = [
          'contents' => $contents,
          'extension' => $candidate['extension'],
          'source_file' => $name,
          'width' => $dimensions['width'],
          'height' => $dimensions['height'],
        ];
      }
    }
    finally {
      $zip->close();
    }

    if ($assets === []) {
      throw new RuntimeException('No localized official Wallet badges were recognized in the ZIP package.');
    }

    ksort($assets);
    $directory = self::platformDirectory($platform);
    self::ensureDirectory($directory);
    $manifestAssets = [];
    foreach ($assets as $locale => $asset) {
      $filename = $locale . '.' . $asset['extension'];
      self::writeAtomically($directory . DIRECTORY_SEPARATOR . $filename, $asset['contents']);
      $manifestAssets[$locale] = [
        'filename' => $filename,
        'source_file' => $asset['source_file'],
        'width' => $asset['width'],
        'height' => $asset['height'],
      ];
    }

    $manifest = [
      'platform' => $platform,
      'installed_at' => gmdate('c'),
      'source' => $source,
      'assets' => $manifestAssets,
    ];
    $json = json_encode($manifest);
    if (!is_string($json)) {
      throw new RuntimeException('The Wallet badge manifest could not be encoded.');
    }
    $json .= "\n";
    self::writeAtomically($directory . DIRECTORY_SEPARATOR . 'manifest.json', $json);

    return $manifest;
  }

  /**
   * Resolve the best installed badge for a CiviCRM locale.
   *
   * @return array{url:string,width:int,height:int,locale:string}|null
   */
  public static function assetForLocale(string $platform, string $locale): ?array {
    self::assertPlatform($platform);
    $manifest = self::manifest($platform);
    $assets = $manifest['assets'] ?? [];
    if (!is_array($assets) || $assets === []) {
      return NULL;
    }

    $selectedLocale = self::selectLocale($locale, array_keys($assets));
    if ($selectedLocale === NULL || !is_array($assets[$selectedLocale] ?? NULL)) {
      return NULL;
    }

    $asset = $assets[$selectedLocale];
    $filename = (string) ($asset['filename'] ?? '');
    if (!preg_match('/^[a-z]{2}_[A-Z]{2}\.(?:svg|png)$/', $filename)
      || !is_file(self::platformDirectory($platform) . DIRECTORY_SEPARATOR . $filename)
    ) {
      return NULL;
    }

    return [
      'url' => self::platformBaseUrl($platform) . '/' . rawurlencode($filename),
      'width' => max(1, (int) ($asset['width'] ?? 1)),
      'height' => max(1, (int) ($asset['height'] ?? 1)),
      'locale' => $selectedLocale,
    ];
  }

  /**
   * @return string[]
   */
  public static function installedLocales(string $platform): array {
    $manifest = self::manifest($platform);
    $assets = $manifest['assets'] ?? [];
    return is_array($assets) ? array_keys($assets) : [];
  }

  public static function normalizeLocaleCode(string $code): ?string {
    $code = trim($code);
    if (preg_match('/^([a-z]{2})[_-]?([A-Z]{2})$/', $code, $matches)) {
      return strtolower($matches[1]) . '_' . strtoupper($matches[2]);
    }
    if (preg_match('/^([a-z]{2})[_-]?([a-z]{2})$/i', $code, $matches) && strlen($code) >= 4) {
      return strtolower($matches[1]) . '_' . strtoupper($matches[2]);
    }

    $language = strtolower($code);
    $defaults = [
      'af' => 'af_ZA',
      'am' => 'am_ET',
      'ar' => 'ar_SA',
      'az' => 'az_AZ',
      'bg' => 'bg_BG',
      'bn' => 'bn_BD',
      'bs' => 'bs_BA',
      'br' => 'pt_BR',
      'by' => 'be_BY',
      'ca' => 'ca_ES',
      'cs' => 'cs_CZ',
      'cz' => 'cs_CZ',
      'da' => 'da_DK',
      'de' => 'de_DE',
      'dk' => 'da_DK',
      'el' => 'el_GR',
      'en' => 'en_US',
      'es' => 'es_ES',
      'es419' => 'es_ES',
      'et' => 'et_EE',
      'fa' => 'fa_IR',
      'fi' => 'fi_FI',
      'fl' => 'nl_BE',
      'fp' => 'tl_PH',
      'fr' => 'fr_FR',
      'gr' => 'el_GR',
      'he' => 'he_IL',
      'hi' => 'hi_IN',
      'hr' => 'hr_HR',
      'hu' => 'hu_HU',
      'hy' => 'hy_AM',
      'id' => 'id_ID',
      'is' => 'is_IS',
      'it' => 'it_IT',
      'ja' => 'ja_JP',
      'jp' => 'ja_JP',
      'ka' => 'ka_GE',
      'kh' => 'km_KH',
      'kk' => 'kk_KZ',
      'ko' => 'ko_KR',
      'ky' => 'ky_KG',
      'lo' => 'lo_LA',
      'lt' => 'lt_LT',
      'lv' => 'lv_LV',
      'ms' => 'ms_MY',
      'mk' => 'mk_MK',
      'mn' => 'mn_MN',
      'my' => 'my_MM',
      'ne' => 'ne_NP',
      'nl' => 'nl_NL',
      'no' => 'no_NO',
      'pl' => 'pl_PL',
      'pt' => 'pt_PT',
      'ro' => 'ro_RO',
      'ru' => 'ru_RU',
      'se' => 'sv_SE',
      'si' => 'si_LK',
      'sk' => 'sk_SK',
      'sl' => 'sl_SI',
      'sq' => 'sq_AL',
      'sr' => 'sr_RS',
      'sv' => 'sv_SE',
      'sw' => 'sw_KE',
      'th' => 'th_TH',
      'tr' => 'tr_TR',
      'uk' => 'uk_UA',
      'ur' => 'ur_PK',
      'uz' => 'uz_UZ',
      'vi' => 'vi_VN',
      'zh' => 'zh_CN',
    ];
    return $defaults[$language] ?? NULL;
  }

  /**
   * @param string $requestedLocale
   *   Locale requested by the contact or site.
   * @param string[] $availableLocales
   *   Locales present in the installed artwork package.
   */
  public static function selectLocale(string $requestedLocale, array $availableLocales): ?string {
    $requestedLocale = self::normalizeLocaleCode($requestedLocale) ?? 'en_US';
    if (in_array($requestedLocale, $availableLocales, TRUE)) {
      return $requestedLocale;
    }

    $language = substr($requestedLocale, 0, 2);
    foreach ($availableLocales as $availableLocale) {
      if (str_starts_with($availableLocale, $language . '_')) {
        return $availableLocale;
      }
    }
    if (in_array('en_US', $availableLocales, TRUE)) {
      return 'en_US';
    }
    foreach ($availableLocales as $availableLocale) {
      if (str_starts_with($availableLocale, 'en_')) {
        return $availableLocale;
      }
    }
    return $availableLocales[0] ?? NULL;
  }

  /**
   * @return array{locale:string,extension:string}|null
   */
  private static function classifyEntry(string $platform, string $name): ?array {
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['svg', 'png'], TRUE)) {
      return NULL;
    }

    if ($platform === 'google') {
      if (!preg_match('~(?:^|/)([a-z]{2}(?:[A-Z]{2}|419)?)_add_to_google_wallet_add-wallet-badge\.(?:svg|png)$~', $name, $matches)) {
        return NULL;
      }
      $locale = self::normalizeLocaleCode($matches[1]);
      return $locale ? ['locale' => $locale, 'extension' => $extension] : NULL;
    }

    $lowerName = strtolower($name);
    if (!str_contains($lowerName, 'apple') || !str_contains($lowerName, 'wallet')) {
      return NULL;
    }
    $locale = self::inferAppleLocale($name);
    return $locale ? ['locale' => $locale, 'extension' => $extension] : NULL;
  }

  private static function inferAppleLocale(string $name): ?string {
    if (preg_match('/(?:^|[^a-z])([a-z]{2})[_-]([A-Z]{2})(?:[^a-z]|$)/', $name, $matches)) {
      return strtolower($matches[1]) . '_' . strtoupper($matches[2]);
    }

    $tokens = preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($tokens as $token) {
      $locale = self::normalizeLocaleCode($token);
      if ($locale !== NULL) {
        return $locale;
      }
    }

    $aliases = [
      'arabic' => 'ar_SA',
      'brazilianportuguese' => 'pt_BR',
      'chinesesimplified' => 'zh_CN',
      'chinesetraditional' => 'zh_TW',
      'dutch' => 'nl_NL',
      'english' => 'en_US',
      'french' => 'fr_FR',
      'german' => 'de_DE',
      'italian' => 'it_IT',
      'japanese' => 'ja_JP',
      'korean' => 'ko_KR',
      'portuguese' => 'pt_PT',
      'spanish' => 'es_ES',
      'usuk' => 'en_US',
    ];
    $compact = strtolower(preg_replace('/[^A-Za-z]/', '', $name) ?? '');
    foreach ($aliases as $alias => $locale) {
      if (str_contains($compact, $alias)) {
        return $locale;
      }
    }
    return NULL;
  }

  /**
   * @return array{width:int,height:int}
   */
  private static function validateImage(string $extension, string $contents): array {
    if ($extension === 'png') {
      $info = getimagesizefromstring($contents);
      if (!is_array($info) || $info['mime'] !== 'image/png') {
        throw new RuntimeException('A badge labelled as PNG is not a valid PNG image.');
      }
      return ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    if (preg_match('/<(?:script|foreignObject)\b/i', $contents)
      || preg_match('/\son[a-z]+\s*=/i', $contents)
      || preg_match('/(?:href|src)\s*=\s*["\']\s*(?:https?:|data:|javascript:)/i', $contents)
    ) {
      throw new RuntimeException('A Wallet SVG contains unsafe active or external content.');
    }

    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    try {
      if (!$document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
        || strtolower((string) $document->documentElement?->localName) !== 'svg'
      ) {
        throw new RuntimeException('A Wallet SVG is not valid XML artwork.');
      }
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }

    $root = $document->documentElement;
    $width = self::svgNumber((string) $root->getAttribute('width'));
    $height = self::svgNumber((string) $root->getAttribute('height'));
    if (($width <= 0 || $height <= 0) && preg_match('/^\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*$/', (string) $root->getAttribute('viewBox'), $matches)) {
      $width = (float) $matches[1];
      $height = (float) $matches[2];
    }
    if ($width <= 0 || $height <= 0) {
      throw new RuntimeException('A Wallet SVG does not declare usable dimensions.');
    }
    return ['width' => (int) round($width), 'height' => (int) round($height)];
  }

  private static function svgNumber(string $value): float {
    return preg_match('/^\s*([\d.]+)/', $value, $matches) ? (float) $matches[1] : 0.0;
  }

  /**
   * @return array<string,mixed>
   */
  private static function manifest(string $platform): array {
    self::assertPlatform($platform);
    $path = self::platformDirectory($platform) . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($path)) {
      return [];
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
      return [];
    }
    try {
      $manifest = json_decode($contents, TRUE, 32, JSON_THROW_ON_ERROR);
      return is_array($manifest) ? $manifest : [];
    }
    catch (\JsonException) {
      return [];
    }
  }

  private static function platformDirectory(string $platform): string {
    return self::civiPath('[civicrm.files]/wallet/badges/' . $platform);
  }

  private static function platformBaseUrl(string $platform): string {
    return self::civiUrl('[civicrm.files]/wallet/badges/' . $platform);
  }

  private static function civiPath(string $expression): string {
    $path = \Civi::paths()->getPath($expression);
    if (!is_string($path) || $path === '') {
      throw new RuntimeException('The public CiviCRM files directory is not configured.');
    }
    return rtrim($path, '/\\');
  }

  private static function civiUrl(string $expression): string {
    $url = \Civi::paths()->getUrl($expression, 'absolute');
    if (!is_string($url) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
      throw new RuntimeException('Wallet badges require an absolute HTTPS public files URL.');
    }
    return rtrim($url, '/');
  }

  private static function ensureDirectory(string $directory): void {
    if (!is_dir($directory)) {
      \CRM_Utils_File::createDir($directory, FALSE);
    }
    if (!is_dir($directory) || !is_writable($directory)) {
      throw new RuntimeException('The Wallet badge directory is not writable.');
    }
  }

  private static function writeAtomically(string $path, string $contents): void {
    $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)
      || !rename($temporaryPath, $path)
    ) {
      if (is_file($temporaryPath)) {
        unlink($temporaryPath);
      }
      throw new RuntimeException('A Wallet badge file could not be installed.');
    }
    chmod($path, 0644);
  }

  private static function assertPlatform(string $platform): void {
    if (!in_array($platform, ['apple', 'google'], TRUE)) {
      throw new RuntimeException('Unsupported Wallet badge platform.');
    }
  }

}
