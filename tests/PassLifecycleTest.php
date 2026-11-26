<?php

require_once __DIR__ . '/../Civi/Wallet/PassLifecycle.php';
require_once __DIR__ . '/../Civi/Wallet/MutationTargetResolver.php';

use Civi\Wallet\MutationTargetResolver;
use Civi\Wallet\PassLifecycle;

function lifecycleAssertSame(mixed $expected, mixed $actual, string $message): void {
  if ($expected !== $actual) {
    fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export($expected, TRUE), var_export($actual, TRUE)));
    exit(1);
  }
}

$now = new DateTimeImmutable('2026-11-26 12:00:00', new DateTimeZone('UTC'));

lifecycleAssertSame(PassLifecycle::ACTIVE, PassLifecycle::forParticipant(['class' => 'Positive']), 'positive participant status remains active');
lifecycleAssertSame(PassLifecycle::VOIDED, PassLifecycle::forParticipant(['class' => 'Negative']), 'negative participant status voids the pass');
lifecycleAssertSame(PassLifecycle::INACTIVE, PassLifecycle::forParticipant(['class' => 'Pending']), 'pending participant status is inactive');
lifecycleAssertSame('INACTIVE', PassLifecycle::googleState(PassLifecycle::VOIDED), 'voided records become inactive in Google Wallet');
lifecycleAssertSame(TRUE, PassLifecycle::isAppleVoided(PassLifecycle::VOIDED), 'voided records set the Apple voided flag');

lifecycleAssertSame(
  PassLifecycle::ACTIVE,
  PassLifecycle::forMembership(['name' => 'Current', 'is_current_member' => 1], '2027-12-31', $now),
  'current future membership remains active',
);
lifecycleAssertSame(
  PassLifecycle::ACTIVE,
  PassLifecycle::forMembership(['name' => 'Grace', 'is_current_member' => 1], '2026-10-31', $now),
  'CiviCRM current-member status keeps a grace membership active after its end date',
);
lifecycleAssertSame(
  PassLifecycle::VOIDED,
  PassLifecycle::forMembership(['name' => 'Cancelled', 'is_current_member' => 0], '2027-12-31', $now),
  'cancelled membership is voided even before its end date',
);
lifecycleAssertSame(
  PassLifecycle::VOIDED,
  PassLifecycle::forMembership(['label' => 'Adhésion annulée', 'is_current_member' => 0], '2027-12-31', $now),
  'localized cancelled membership is voided',
);
lifecycleAssertSame(
  PassLifecycle::EXPIRED,
  PassLifecycle::forMembership(['name' => 'Expired', 'is_current_member' => 0], '2025-12-31', $now),
  'past membership is expired rather than cancelled',
);
lifecycleAssertSame('EXPIRED', PassLifecycle::googleState(PassLifecycle::EXPIRED), 'expired membership uses the Google expired state');

lifecycleAssertSame([
  ['entity_table' => 'civicrm_participant', 'entity_id' => 42, 'operation' => 'retire'],
], MutationTargetResolver::resolve('Participant', 42, 'delete'), 'participant deletion retires its pass');

lifecycleAssertSame([
  ['entity_table' => 'civicrm_contact', 'entity_id' => 77, 'operation' => 'refresh'],
], MutationTargetResolver::resolve('Membership', 9, 'delete', ['contact_id' => 77]), 'membership deletion recomputes the contact card');

lifecycleAssertSame([
  ['entity_table' => 'civicrm_participant', 'entity_id' => 10, 'operation' => 'retire'],
  ['entity_table' => 'civicrm_participant', 'entity_id' => 11, 'operation' => 'retire'],
], MutationTargetResolver::resolve('Event', 3, 'delete', ['participant_ids' => [10, 11]]), 'event deletion retires every issued participant pass');

lifecycleAssertSame([
  ['entity_table' => 'civicrm_contact', 'entity_id' => 8, 'operation' => 'retire'],
  ['entity_table' => 'civicrm_donor', 'entity_id' => 8, 'operation' => 'retire'],
], MutationTargetResolver::resolve('Contact', 8, 'delete'), 'contact deletion retires membership and donor cards');

echo "PassLifecycleTest: OK\n";
