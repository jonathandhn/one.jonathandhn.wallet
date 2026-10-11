<?php

require_once __DIR__ . '/../Civi/Wallet/LocaleResolver.php';

use Civi\Wallet\LocaleResolver;

function assertLocale(string $expected, ?string $preferred, string $default, array $available, string $message): void {
  $actual = LocaleResolver::selectAvailableLocale($preferred, $default, $available);
  if ($expected !== $actual) {
    fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $message, $expected, $actual));
    exit(1);
  }
}

$available = ['en_US', 'fr_FR'];
assertLocale('fr_FR', 'fr_FR', 'en_US', $available, 'exact contact locale wins');
assertLocale('fr_FR', 'fr_CA', 'en_US', $available, 'contact language falls back to an available regional locale');
assertLocale('fr_FR', 'de_DE', 'fr_FR', $available, 'unsupported contact locale falls back to the CRM locale');
assertLocale('en_US', NULL, 'en_US', $available, 'CRM English uses the source locale');
assertLocale('en_US', 'invalid', 'invalid', $available, 'invalid locales use the source locale');

echo "LocaleResolverTest: OK\n";
