<?php

namespace Civi\Wallet;

use RuntimeException;

/**
 * Select the membership represented by a contact-level Wallet card.
 */
final class MembershipResolver {

  /**
   * Prefer a current membership, then the membership ending most recently.
   *
   * @return array{membership:array<string,mixed>,status:array<string,mixed>}
   */
  public static function resolveForContact(int $contactId): array {
    $memberships = \Civi\Api4\Membership::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addOrderBy('end_date', 'DESC')
      ->addOrderBy('id', 'DESC')
      ->setLimit(0)
      ->execute();

    $fallback = NULL;
    $statusCache = [];
    foreach ($memberships as $membership) {
      $statusId = (int) ($membership['status_id'] ?? 0);
      if (!isset($statusCache[$statusId])) {
        $statusCache[$statusId] = \Civi\Api4\MembershipStatus::get(FALSE)
          ->addWhere('id', '=', $statusId)
          ->execute()
          ->first() ?: [];
      }
      $candidate = [
        'membership' => $membership,
        'status' => $statusCache[$statusId],
      ];
      $fallback ??= $candidate;
      if (!empty($statusCache[$statusId]['is_current_member'])) {
        return $candidate;
      }
    }

    if ($fallback === NULL) {
      throw new RuntimeException("No membership found for Contact {$contactId}");
    }
    return $fallback;
  }

}
