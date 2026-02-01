<?php

$eventId = 12; // Assumed from user's URL
echo "Debugging Pass Update for Event ID: $eventId\n";

// 1. Check Participants
$participants = \Civi\Api4\Participant::get(FALSE)
    ->addSelect('id', 'contact_id')
    ->addWhere('event_id', '=', $eventId)
    ->execute();

echo "Found " . count($participants) . " participants for this event.\n";

if (count($participants) > 0) {
    // 2. Ensure Pass Exists (Simulate Download)
    echo "Ensuring Google Pass exists for participant...\n";
    CRM_Wallet_BAO_Pass::createGooglePass($participants[0]['id']);

    $participantIds = array_column((array) $participants, 'id');
    $sql = "SELECT count(*) FROM civicrm_wallet_pass WHERE participant_id IN (" . implode(',', $participantIds) . ")";
    $passCount = CRM_Core_DAO::singleValueQuery($sql);
    echo "Found $passCount existing passes in database for these participants.\n";

    // 3. Run Update Logic
    echo "Calling CRM_Wallet_BAO_Pass::updatePasses...\n";
    CRM_Wallet_BAO_Pass::updatePasses('Event', $eventId);
    echo "Update logic finished.\n";

    // 4. Check Queue
    $queue = CRM_Queue_Service::singleton()->create([
        'type' => 'Sql',
        'name' => 'org.soshomophobie.wallet.updates',
        'runner' => 'task',
    ]);
    echo "Queue Size is now: " . $queue->numberOfItems() . "\n";
} else {
    echo "No participants found. Cannot test update.\n";
}
