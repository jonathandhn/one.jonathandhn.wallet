<?php

namespace Civi\Wallet;

use CRM_Wallet_ExtensionUtil as E;

/**
 * Resolve the locale used for contact-facing Wallet content.
 */
final class LocaleResolver {

  /**
   * @var string[]
   */
  private static array $contactLocales = [];

  public static function resolveForEntity(string $entityTable, int $entityId): string {
    $contactId = match ($entityTable) {
      'civicrm_contact', 'civicrm_donor' => $entityId,
      'civicrm_participant' => self::lookupEntityContactId('Participant', $entityId),
      'civicrm_membership' => self::lookupEntityContactId('Membership', $entityId),
      default => NULL,
    };

    return self::resolveForContact($contactId);
  }

  public static function resolveForContact(?int $contactId): string {
    if ($contactId && isset(self::$contactLocales[$contactId])) {
      return self::$contactLocales[$contactId];
    }

    $preferredLocale = NULL;
    if ($contactId) {
      $contact = \Civi\Api4\Contact::get(FALSE)
        ->addSelect('preferred_language')
        ->addWhere('id', '=', $contactId)
        ->execute()
        ->first();
      $preferredLocale = $contact['preferred_language'] ?? NULL;
    }

    $defaultLocale = (string) (\Civi::settings()->get('lcMessages') ?: \CRM_Core_I18n::getLocale());
    $locale = self::selectAvailableLocale($preferredLocale, $defaultLocale, self::getAvailableLocales());

    if ($contactId) {
      self::$contactLocales[$contactId] = $locale;
    }

    return $locale;
  }

  public static function swapForEntity(string $entityTable, int $entityId): ?\CRM_Utils_AutoClean {
    return \CRM_Utils_AutoClean::swapLocale(self::resolveForEntity($entityTable, $entityId));
  }

  public static function translateForContact(?int $contactId, string $text, array $params = []): string {
    $localeScope = \CRM_Utils_AutoClean::swapLocale(self::resolveForContact($contactId));
    try {
      return E::ts($text, $params);
    }
    finally {
      $localeScope?->cleanup();
    }
  }

  /**
   * Choose a locale for which CiviWallet can actually provide translations.
   *
   * @param string|null $preferredLocale
   *   Contact preference.
   * @param string $defaultLocale
   *   CiviCRM default locale.
   * @param string[] $availableLocales
   *   Locales supported by the extension.
   */
  public static function selectAvailableLocale(?string $preferredLocale, string $defaultLocale, array $availableLocales): string {
    $availableLocales = array_values(array_unique($availableLocales));
    foreach ([$preferredLocale, $defaultLocale, 'en_US'] as $candidate) {
      if (!$candidate || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $candidate)) {
        continue;
      }
      if (in_array($candidate, $availableLocales, TRUE)) {
        return $candidate;
      }

      $language = substr($candidate, 0, 2);
      foreach ($availableLocales as $availableLocale) {
        if (str_starts_with($availableLocale, $language . '_')) {
          return $availableLocale;
        }
      }
    }

    return 'en_US';
  }

  /**
   * @return string[]
   */
  private static function getAvailableLocales(): array {
    $locales = ['en_US'];
    $catalogPatterns = [
      rtrim(\CRM_Core_I18n::getResourceDir(), DIRECTORY_SEPARATOR)
      . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'LC_MESSAGES'
      . DIRECTORY_SEPARATOR . 'civi_wallet.mo',
      E::path('l10n/*/LC_MESSAGES/civi_wallet.mo'),
    ];

    foreach ($catalogPatterns as $catalogPattern) {
      foreach (glob($catalogPattern) ?: [] as $catalogPath) {
        $locale = basename(dirname(dirname($catalogPath)));
        if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale)) {
          $locales[] = $locale;
        }
      }
    }

    return array_values(array_unique($locales));
  }

  private static function lookupEntityContactId(string $entity, int $entityId): ?int {
    $record = ("Civi\\Api4\\{$entity}")::get(FALSE)
      ->addSelect('contact_id')
      ->addWhere('id', '=', $entityId)
      ->execute()
      ->first();

    return isset($record['contact_id']) ? (int) $record['contact_id'] : NULL;
  }

}
