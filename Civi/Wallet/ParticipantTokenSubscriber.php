<?php

namespace Civi\Wallet;

use Civi\Core\Service\AutoService;
use Civi\Token\Event\TokenRegisterEvent;
use Civi\Token\Event\TokenValueEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Register and evaluate wallet participant and contact tokens with TokenProcessor.
 *
 * @service wallet.participant_tokens
 */
class ParticipantTokenSubscriber extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      'civi.token.list' => 'registerTokens',
      'civi.token.eval' => 'evaluateTokens',
    ];
  }

  public function registerTokens(TokenRegisterEvent $e): void {
    $schema = $e->getTokenProcessor()->context['schema'] ?? [];
    $appleEnabled = civi_wallet_is_channel_enabled('apple');
    $googleEnabled = civi_wallet_is_channel_enabled('google');
    $qrEnabled = civi_wallet_is_channel_enabled('qr');
    $walletLinksEnabled = $appleEnabled || $googleEnabled;

    // 1. Participant Tokens
    if ($this->hasParticipantTokenContext($schema) && civi_wallet_is_context_enabled('participant')) {
      if ($walletLinksEnabled) {
        $e->register([
          'entity' => 'participant',
          'field' => 'wallet_links',
          'label' => ts('Wallet Links (Event Ticket)'),
        ]);
      }

      if ($appleEnabled) {
        $e->register([
          'entity' => 'participant',
          'field' => 'wallet_apple_url',
          'label' => ts('Apple Wallet URL (Event Ticket)'),
        ]);
      }

      if ($googleEnabled) {
        $e->register([
          'entity' => 'participant',
          'field' => 'wallet_google_url',
          'label' => ts('Google Wallet URL (Event Ticket)'),
        ]);
      }

      if ($qrEnabled) {
        $e->register([
          'entity' => 'participant',
          'field' => 'qr_participant',
          'label' => ts('QR Code (Event Ticket)'),
        ]);
        $e->register([
          'entity' => 'participant',
          'field' => 'qr_participant_url',
          'label' => ts('QR Code URL (Event Ticket)'),
        ]);
      }
    }

    // 2. Contact Tokens (Membership, Donor, Contact Pass)
    if ($this->hasContactTokenContext($schema)) {
      if (civi_wallet_is_context_enabled('membership')) {
        if ($walletLinksEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'wallet_links',
            'label' => ts('Wallet Links (Membership)'),
          ]);
        }
        if ($appleEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'wallet_apple_membership_url',
            'label' => ts('Apple Wallet URL (Membership)'),
          ]);
        }
        if ($googleEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'wallet_google_membership_url',
            'label' => ts('Google Wallet URL (Membership)'),
          ]);
        }
        if ($qrEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'qr_membership',
            'label' => ts('QR Code (Membership)'),
          ]);
          $e->register([
            'entity' => 'contact',
            'field' => 'qr_membership_url',
            'label' => ts('QR Code URL (Membership)'),
          ]);
        }
      }

      if (civi_wallet_is_context_enabled('donor')) {
        if ($walletLinksEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'wallet_links_donor',
            'label' => ts('Wallet Links (Donor Card)'),
          ]);
        }
        if ($appleEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'wallet_apple_donor_url',
            'label' => ts('Apple Wallet URL (Donor Card)'),
          ]);
        }
        if ($googleEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'wallet_google_donor_url',
            'label' => ts('Google Wallet URL (Donor Card)'),
          ]);
        }
        if ($qrEnabled) {
          $e->register([
            'entity' => 'contact',
            'field' => 'qr_donor',
            'label' => ts('QR Code (Donor Card)'),
          ]);
          $e->register([
            'entity' => 'contact',
            'field' => 'qr_donor_url',
            'label' => ts('QR Code URL (Donor Card)'),
          ]);
        }
      }

      if ($qrEnabled) {
        $e->register([
          'entity' => 'contact',
          'field' => 'qr_contact_pass',
          'label' => ts('QR Code (Contact Pass / Badge)'),
        ]);
        $e->register([
          'entity' => 'contact',
          'field' => 'qr_contact_pass_url',
          'label' => ts('QR Code URL (Contact Pass / Badge)'),
        ]);
      }
    }
  }

  public function evaluateTokens(TokenValueEvent $e): void {
    $messageTokens = $e->getTokenProcessor()->getMessageTokens();
    $participantTokens = $messageTokens['participant'] ?? [];
    $contactTokens = $messageTokens['contact'] ?? [];

    if (empty($participantTokens) && empty($contactTokens)) {
      return;
    }

    $btnStyleApple = "display:inline-block; margin-right:10px; padding:10px 16px; background-color:#000000; color:#ffffff; text-decoration:none; border-radius:8px; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; font-weight:bold; text-align:center;";
    $btnStyleGoogle = "display:inline-block; padding:10px 16px; background-color:#3c4043; color:#ffffff; text-decoration:none; border-radius:8px; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; font-weight:bold; text-align:center;";

    foreach ($e->getRows() as $row) {
      $actionSearchResult = $row->context['actionSearchResult'] ?? NULL;
      $eventId = $row->context['eventId']
                ?? ($actionSearchResult->eventID ?? NULL)
                ?? ($actionSearchResult->tokenContext_eventId ?? NULL)
                ?? NULL;
      $cid = $row->context['contactId'] ?? NULL;
      $pid = $row->context['participantId']
                ?? ($actionSearchResult->entityID ?? NULL)
                ?? ($actionSearchResult->tokenContext_participantId ?? NULL)
                ?? $this->lookupParticipantIdByContactAndEvent($cid ? (int) $cid : NULL, $eventId ? (int) $eventId : NULL)
                ?? NULL;

      if ($pid && !$cid) {
        $cid = $this->lookupContactId((int) $pid);
      }

      $cs = $cid ? \CRM_Contact_BAO_Contact_Utils::generateChecksum((int) $cid) : '';
      $color = civi_wallet_get_qr_color();

      // Evaluate Participant Tokens
      if ($pid && !empty($participantTokens)) {
        $htmlParticipant = '';
        $participantAppleUrl = '';
        $participantGoogleUrl = '';
        $participantQrUrl = '';

        if (civi_wallet_is_context_enabled('participant') && civi_wallet_is_channel_enabled('apple') && $cid) {
          $participantAppleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "pid={$pid}&cid={$cid}&cs={$cs}&type=apple&context=participant", TRUE, NULL, FALSE, TRUE);
          $htmlParticipant .= "<a href='{$participantAppleUrl}' style='{$btnStyleApple}'>" . ts('Add to Apple Wallet') . "</a>";
        }

        if (civi_wallet_is_context_enabled('participant') && civi_wallet_is_channel_enabled('google') && $cid) {
          $participantGoogleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "pid={$pid}&cid={$cid}&cs={$cs}&type=google&context=participant", TRUE, NULL, FALSE, TRUE);
          if ($htmlParticipant !== '') {
            $htmlParticipant .= ' ';
          }
          $htmlParticipant .= "<a href='{$participantGoogleUrl}' style='{$btnStyleGoogle}'>" . ts('Add to Google Wallet') . "</a>";
        }

        if (civi_wallet_is_context_enabled('participant') && civi_wallet_is_channel_enabled('qr') && $cid) {
          $participantQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=participant&pid={$pid}&cs={$cs}&color={$color}", TRUE, NULL, FALSE, TRUE);
        }

        if (in_array('wallet_links', $participantTokens, TRUE)) {
          $row->format('text/html')->tokens('participant', 'wallet_links', $htmlParticipant);
          $row->format('text/plain')->tokens('participant', 'wallet_links', trim(strip_tags($htmlParticipant)));
        }
        if (in_array('wallet_apple_url', $participantTokens, TRUE)) {
          $row->format('text/html')->tokens('participant', 'wallet_apple_url', $participantAppleUrl);
          $row->format('text/plain')->tokens('participant', 'wallet_apple_url', $participantAppleUrl);
        }
        if (in_array('wallet_google_url', $participantTokens, TRUE)) {
          $row->format('text/html')->tokens('participant', 'wallet_google_url', $participantGoogleUrl);
          $row->format('text/plain')->tokens('participant', 'wallet_google_url', $participantGoogleUrl);
        }
        if (in_array('qr_participant', $participantTokens, TRUE)) {
          $row->format('text/html')->tokens('participant', 'qr_participant', $participantQrUrl ? "<img src='{$participantQrUrl}' width='150' height='150' alt='" . ts('QR Code') . "'>" : '');
          $row->format('text/plain')->tokens('participant', 'qr_participant', $participantQrUrl);
        }
        if (in_array('qr_participant_url', $participantTokens, TRUE)) {
          $row->format('text/html')->tokens('participant', 'qr_participant_url', $participantQrUrl);
          $row->format('text/plain')->tokens('participant', 'qr_participant_url', $participantQrUrl);
        }
      }

      // Evaluate Contact Tokens
      if ($cid && !empty($contactTokens)) {
        $htmlMembership = '';
        $membershipAppleUrl = '';
        $membershipGoogleUrl = '';
        $membershipQrUrl = '';

        if (civi_wallet_is_context_enabled('membership') && civi_wallet_is_channel_enabled('apple')) {
          $membershipAppleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=apple&context=membership", TRUE, NULL, FALSE, TRUE);
          $htmlMembership .= "<a href='{$membershipAppleUrl}' style='{$btnStyleApple}'>" . ts('Add to Apple Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('membership') && civi_wallet_is_channel_enabled('google')) {
          $membershipGoogleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=google&context=membership", TRUE, NULL, FALSE, TRUE);
          if ($htmlMembership !== '') {
            $htmlMembership .= ' ';
          }
          $htmlMembership .= "<a href='{$membershipGoogleUrl}' style='{$btnStyleGoogle}'>" . ts('Add to Google Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('membership') && civi_wallet_is_channel_enabled('qr')) {
          $membershipQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=membership&cid={$cid}&cs={$cs}&color={$color}", TRUE, NULL, FALSE, TRUE);
        }

        if (in_array('wallet_links', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'wallet_links', $htmlMembership);
          $row->format('text/plain')->tokens('contact', 'wallet_links', trim(strip_tags($htmlMembership)));
        }
        if (in_array('wallet_apple_membership_url', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'wallet_apple_membership_url', $membershipAppleUrl);
          $row->format('text/plain')->tokens('contact', 'wallet_apple_membership_url', $membershipAppleUrl);
        }
        if (in_array('wallet_google_membership_url', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'wallet_google_membership_url', $membershipGoogleUrl);
          $row->format('text/plain')->tokens('contact', 'wallet_google_membership_url', $membershipGoogleUrl);
        }
        if (in_array('qr_membership', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'qr_membership', $membershipQrUrl ? "<img src='{$membershipQrUrl}' width='150' height='150' alt='" . ts('QR Code') . "'>" : '');
          $row->format('text/plain')->tokens('contact', 'qr_membership', $membershipQrUrl);
        }
        if (in_array('qr_membership_url', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'qr_membership_url', $membershipQrUrl);
          $row->format('text/plain')->tokens('contact', 'qr_membership_url', $membershipQrUrl);
        }

        $htmlDonor = '';
        $donorAppleUrl = '';
        $donorGoogleUrl = '';
        $donorQrUrl = '';

        if (civi_wallet_is_context_enabled('donor') && civi_wallet_is_channel_enabled('apple')) {
          $donorAppleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=apple&context=donor", TRUE, NULL, FALSE, TRUE);
          $htmlDonor .= "<a href='{$donorAppleUrl}' style='{$btnStyleApple}'>" . ts('Add to Apple Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('donor') && civi_wallet_is_channel_enabled('google')) {
          $donorGoogleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=google&context=donor", TRUE, NULL, FALSE, TRUE);
          if ($htmlDonor !== '') {
            $htmlDonor .= ' ';
          }
          $htmlDonor .= "<a href='{$donorGoogleUrl}' style='{$btnStyleGoogle}'>" . ts('Add to Google Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('donor') && civi_wallet_is_channel_enabled('qr')) {
          $donorQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=donor&cid={$cid}&cs={$cs}&color={$color}", TRUE, NULL, FALSE, TRUE);
        }

        if (in_array('wallet_links_donor', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'wallet_links_donor', $htmlDonor);
          $row->format('text/plain')->tokens('contact', 'wallet_links_donor', trim(strip_tags($htmlDonor)));
        }
        if (in_array('wallet_apple_donor_url', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'wallet_apple_donor_url', $donorAppleUrl);
          $row->format('text/plain')->tokens('contact', 'wallet_apple_donor_url', $donorAppleUrl);
        }
        if (in_array('wallet_google_donor_url', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'wallet_google_donor_url', $donorGoogleUrl);
          $row->format('text/plain')->tokens('contact', 'wallet_google_donor_url', $donorGoogleUrl);
        }
        if (in_array('qr_donor', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'qr_donor', $donorQrUrl ? "<img src='{$donorQrUrl}' width='150' height='150' alt='" . ts('QR Code') . "'>" : '');
          $row->format('text/plain')->tokens('contact', 'qr_donor', $donorQrUrl);
        }
        if (in_array('qr_donor_url', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'qr_donor_url', $donorQrUrl);
          $row->format('text/plain')->tokens('contact', 'qr_donor_url', $donorQrUrl);
        }

        $contactPassQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=contact&cid={$cid}&cs={$cs}&color={$color}", TRUE, NULL, FALSE, TRUE);
        if (in_array('qr_contact_pass', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'qr_contact_pass', "<img src='{$contactPassQrUrl}' width='150' height='150' alt='" . ts('QR Code') . "'>");
          $row->format('text/plain')->tokens('contact', 'qr_contact_pass', $contactPassQrUrl);
        }
        if (in_array('qr_contact_pass_url', $contactTokens, TRUE)) {
          $row->format('text/html')->tokens('contact', 'qr_contact_pass_url', $contactPassQrUrl);
          $row->format('text/plain')->tokens('contact', 'qr_contact_pass_url', $contactPassQrUrl);
        }
      }
    }
  }

  private function hasParticipantTokenContext(array $schema): bool {
    if (in_array('participantId', $schema, TRUE)) {
      return TRUE;
    }

    return in_array('contactId', $schema, TRUE) && in_array('eventId', $schema, TRUE);
  }

  private function hasContactTokenContext(array $schema): bool {
    return in_array('contactId', $schema, TRUE) || in_array('contact', $schema, TRUE) || empty($schema);
  }

  private function lookupContactId(int $participantId): ?int {
    $participant = \Civi\Api4\Participant::get(FALSE)
      ->addSelect('contact_id')
      ->addWhere('id', '=', $participantId)
      ->execute()
      ->first();

    return isset($participant['contact_id']) ? (int) $participant['contact_id'] : NULL;
  }

  private function lookupParticipantIdByContactAndEvent(?int $contactId, ?int $eventId): ?int {
    if (!$contactId || !$eventId) {
      return NULL;
    }

    $participant = \Civi\Api4\Participant::get(FALSE)
      ->addSelect('id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('event_id', '=', $eventId)
      ->addOrderBy('id', 'DESC')
      ->setLimit(1)
      ->execute()
      ->first();

    return isset($participant['id']) ? (int) $participant['id'] : NULL;
  }

}
