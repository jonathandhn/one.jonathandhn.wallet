<?php

namespace Civi\Wallet;

/**
 * Render email-safe, localized Wallet badge links.
 */
final class WalletBadgeRenderer {

  private const DISPLAY_HEIGHT = 48;
  private const SLOT_WIDTH = 210;

  public static function renderForContact(string $appleUrl, string $googleUrl, ?int $contactId): string {
    $locale = LocaleResolver::resolveForContact($contactId);
    $translate = static fn(string $text): string => LocaleResolver::translateForContact($contactId, $text);

    return self::render($appleUrl, $googleUrl, $locale, $translate);
  }

  /**
   * @param string $appleUrl
   *   Target Apple Wallet pass URL, or an empty string.
   * @param string $googleUrl
   *   Target Google Wallet pass URL, or an empty string.
   * @param string $locale
   *   Locale requested for the official artwork.
   * @param callable(string):string $translate
   *   Translates the accessible fallback and alternative text.
   */
  public static function render(string $appleUrl, string $googleUrl, string $locale, callable $translate): string {
    $badges = [];
    if ($appleUrl !== '') {
      $badges[] = self::renderBadge('apple', $appleUrl, $locale, $translate('Add to Apple Wallet'));
    }
    if ($googleUrl !== '') {
      $badges[] = self::renderBadge('google', $googleUrl, $locale, $translate('Add to Google Wallet'));
    }
    if ($badges === []) {
      return '';
    }

    return '<div style="font-size:0;line-height:0;text-align:center;">'
      . implode('', $badges)
      . '</div>';
  }

  private static function renderBadge(string $platform, string $targetUrl, string $locale, string $label): string {
    try {
      $asset = BadgeAssetManager::assetForLocale($platform, $locale);
    }
    catch (\Throwable) {
      $asset = NULL;
    }

    $safeTargetUrl = self::escape($targetUrl);
    $safeLabel = self::escape($label);
    $slotStyle = 'display:inline-block;width:' . self::SLOT_WIDTH . 'px;min-height:' . self::DISPLAY_HEIGHT
      . 'px;margin:8px;vertical-align:middle;text-align:center;line-height:' . self::DISPLAY_HEIGHT . 'px;';

    if ($asset === NULL) {
      return '<span style="' . $slotStyle . '"><a href="' . $safeTargetUrl
        . '" style="font-family:Arial,sans-serif;font-size:16px;line-height:48px;color:#1a73e8;text-decoration:underline;">'
        . $safeLabel . '</a></span>';
    }

    $displayWidth = max(1, (int) round(self::DISPLAY_HEIGHT * $asset['width'] / $asset['height']));
    return '<span style="' . $slotStyle . '"><a href="' . $safeTargetUrl
      . '" style="display:inline-block;line-height:0;text-decoration:none;" aria-label="' . $safeLabel . '">'
      . '<img src="' . self::escape($asset['url']) . '" width="' . $displayWidth . '" height="' . self::DISPLAY_HEIGHT
      . '" alt="' . $safeLabel . '" style="display:block;width:' . $displayWidth . 'px;height:' . self::DISPLAY_HEIGHT
      . 'px;border:0;outline:none;text-decoration:none;" />'
      . '</a></span>';
  }

  private static function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
