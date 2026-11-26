<?php

namespace Civi\Wallet;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Resolve CiviCRM statuses into provider-neutral Wallet lifecycle states.
 */
final class PassLifecycle {

  public const ACTIVE = 'active';
  public const INACTIVE = 'inactive';
  public const EXPIRED = 'expired';
  public const VOIDED = 'voided';

  /**
   * @param array<string,mixed> $status
   */
  public static function forParticipant(array $status): string {
    $class = strtolower(trim((string) ($status['class'] ?? '')));
    if ($class === 'positive') {
      return self::ACTIVE;
    }
    if ($class === 'negative') {
      return self::VOIDED;
    }
    return self::INACTIVE;
  }

  /**
   * @param array<string,mixed> $status
   * @param string|null $endDate Membership end date.
   * @param \DateTimeImmutable|null $now Clock override for deterministic evaluation.
   */
  public static function forMembership(
    array $status,
    ?string $endDate,
    ?DateTimeImmutable $now = NULL
  ): string {
    if (self::isVoidedMembershipStatus($status)) {
      return self::VOIDED;
    }
    if (!empty($status['is_current_member'])) {
      return self::ACTIVE;
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($endDate !== NULL && $endDate !== '') {
      try {
        $end = new DateTimeImmutable(
          $endDate . (strlen($endDate) === 10 ? ' 23:59:59' : ''),
          $now->getTimezone(),
        );
        if ($end < $now) {
          return self::EXPIRED;
        }
      }
      catch (\Throwable) {
        // Keep the CiviCRM status authoritative when the date is malformed.
      }
    }

    return self::INACTIVE;
  }

  public static function googleState(string $lifecycle): string {
    return match ($lifecycle) {
      self::ACTIVE => 'ACTIVE',
      self::EXPIRED => 'EXPIRED',
      default => 'INACTIVE',
    };
  }

  public static function isAppleVoided(string $lifecycle): bool {
    return $lifecycle === self::VOIDED;
  }

  /**
   * @param array<string,mixed> $status
   */
  private static function isVoidedMembershipStatus(array $status): bool {
    $value = strtolower(trim(implode(' ', [
      (string) ($status['name'] ?? ''),
      (string) ($status['label'] ?? ''),
    ])));
    if ($value === '') {
      return FALSE;
    }

    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
      $value .= ' ' . strtolower($ascii);
    }

    return preg_match('/\b(cancel|cancelled|canceled|annul|deceas|deced|revok|revoqu)/i', $value) === 1;
  }

}
