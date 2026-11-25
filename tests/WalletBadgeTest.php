<?php

final class WalletBadgeTestPaths {
  public function __construct(private string $directory) {
  }

  public function getPath(string $expression): string {
    $suffix = str_replace('[civicrm.files]', '', $expression);
    return $this->directory . $suffix;
  }

  public function getUrl(string $expression, string $mode): string {
    $suffix = str_replace('[civicrm.files]', '', $expression);
    return 'https://example.org/civicrm-files' . $suffix;
  }
}

final class Civi {
  public static WalletBadgeTestPaths $paths;

  public static function paths(): WalletBadgeTestPaths {
    return self::$paths;
  }
}

final class CRM_Utils_File {
  public static function createDir(string $directory, bool $abort): void {
    if (!mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new RuntimeException('Test directory could not be created.');
    }
  }
}

require_once __DIR__ . '/../Civi/Wallet/BadgeAssetManager.php';
require_once __DIR__ . '/../Civi/Wallet/WalletBadgeRenderer.php';

use Civi\Wallet\BadgeAssetManager;
use Civi\Wallet\WalletBadgeRenderer;

function badgeAssertSame(mixed $expected, mixed $actual, string $message): void {
  if ($expected !== $actual) {
    fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export($expected, TRUE), var_export($actual, TRUE)));
    exit(1);
  }
}

function badgeAssertContains(string $needle, string $haystack, string $message): void {
  if (!str_contains($haystack, $needle)) {
    fwrite(STDERR, sprintf("FAIL: %s\nMissing: %s\n", $message, $needle));
    exit(1);
  }
}

function removeBadgeTestDirectory(string $directory): void {
  if (!is_dir($directory)) {
    return;
  }
  foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
    $path = $directory . DIRECTORY_SEPARATOR . $entry;
    is_dir($path) ? removeBadgeTestDirectory($path) : unlink($path);
  }
  rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . '/wallet-badge-test-' . bin2hex(random_bytes(6));
mkdir($testDirectory, 0775, TRUE);
Civi::$paths = new WalletBadgeTestPaths($testDirectory);

badgeAssertSame('fr_FR', BadgeAssetManager::normalizeLocaleCode('frFR'), 'compact Google locale is normalized');
badgeAssertSame('pt_BR', BadgeAssetManager::normalizeLocaleCode('br'), 'Google Brazilian locale is normalized');
badgeAssertSame('fr_FR', BadgeAssetManager::selectLocale('fr_CA', ['en_US', 'fr_FR']), 'language fallback selects installed locale');
badgeAssertSame('en_US', BadgeAssetManager::selectLocale('de_DE', ['fr_FR', 'en_US']), 'English fallback is deterministic');

$zipPath = $testDirectory . '/google.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$svg = '<svg width="199" height="55" viewBox="0 0 199 55" xmlns="http://www.w3.org/2000/svg"><rect width="199" height="55" fill="#000"/></svg>';
$zip->addFromString('add-to-wallet-svg/svg/frFR_add_to_google_wallet_add-wallet-badge.svg', $svg);
$zip->addFromString('add-to-wallet-svg/svg/enUS_add_to_google_wallet_add-wallet-badge.svg', $svg);
$zip->addFromString('add-to-wallet-svg/svg/frFR_add_to_google_wallet_wallet-button.svg', $svg);
$zip->close();

$manifest = BadgeAssetManager::installZip('google', $zipPath, 'automated test');
badgeAssertSame(['en_US', 'fr_FR'], array_keys($manifest['assets']), 'only primary localized Google badges are installed');
$asset = BadgeAssetManager::assetForLocale('google', 'fr_CA');
badgeAssertSame('fr_FR', $asset['locale'] ?? NULL, 'installed badge follows contact language');

$appleZipPath = $testDirectory . '/apple.zip';
$zip = new ZipArchive();
$zip->open($appleZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
for ($index = 0; $index < 510; $index++) {
  $zip->addFromString('metadata/entry-' . $index . '.txt', 'ignored');
}
$embeddedPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL5WQAAAABJRU5ErkJggg==';
$appleSvg = '<svg width="113" height="35" viewBox="0 0 113 35" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image width="1" height="1" xlink:href="data:image/png;base64,' . $embeddedPng . '"/></svg>';
$zip->addFromString('Add-to-Apple-Wallet/Add to Apple Wallet Badges/FR/RGB/FR_Add_to_Apple_Wallet_RGB.svg', $appleSvg);
$zip->close();

$appleManifest = BadgeAssetManager::installZip('apple', $appleZipPath, 'automated Apple test');
badgeAssertSame(['fr_FR'], array_keys($appleManifest['assets']), 'large official Apple package layout is accepted');
$appleAsset = BadgeAssetManager::assetForLocale('apple', 'fr_CA');
badgeAssertSame('fr_FR', $appleAsset['locale'] ?? NULL, 'Apple badge uses the installed language fallback');

$html = WalletBadgeRenderer::render(
  'https://example.org/apple?a=1&b=2',
  'https://example.org/google',
  'fr_CA',
  static fn(string $text): string => $text
);
badgeAssertContains('height="48"', $html, 'official badge height is fixed at 48 pixels');
badgeAssertContains('width:210px', $html, 'both badges use equal layout slots');
badgeAssertContains('fr_FR.svg', $html, 'localized Google artwork is rendered');
badgeAssertContains('a=1&amp;b=2', $html, 'target URLs are HTML escaped');
badgeAssertContains('Add to Apple Wallet', $html, 'missing Apple artwork uses an accessible text fallback');

removeBadgeTestDirectory($testDirectory);
echo "WalletBadgeTest: OK\n";
