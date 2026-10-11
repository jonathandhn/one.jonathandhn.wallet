<?php

namespace Civi\Wallet;

use Civi\Core\Service\AutoService;
use Civi\Token\Event\TokenRegisterEvent;
use Civi\Token\Event\TokenValueEvent;
use CRM_Wallet_ExtensionUtil as E;
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

    // Use a dedicated namespace. Core entity namespaces such as "contact" and
    // "participant" interpret unknown fields as API fields before custom token
    // subscribers run, which produces undefined-key warnings.
    if ($this->hasParticipantTokenContext($schema) && civi_wallet_is_context_enabled('participant')) {
      if ($walletLinksEnabled) {
        $e->register([
          'entity' => 'wallet',
          'field' => 'participant_links',
          'label' => E::ts('Wallet Links (Event Ticket)'),
        ]);
      }

      if ($appleEnabled) {
        $e->register([
          'entity' => 'wallet',
          'field' => 'participant_apple_url',
          'label' => E::ts('Apple Wallet URL (Event Ticket)'),
        ]);
      }

      if ($googleEnabled) {
        $e->register([
          'entity' => 'wallet',
          'field' => 'participant_google_url',
          'label' => E::ts('Google Wallet URL (Event Ticket)'),
        ]);
      }

      if ($qrEnabled) {
        $e->register([
          'entity' => 'wallet',
          'field' => 'participant_qr',
          'label' => E::ts('QR Code (Event Ticket)'),
        ]);
        $e->register([
          'entity' => 'wallet',
          'field' => 'participant_qr_url',
          'label' => E::ts('QR Code URL (Event Ticket)'),
        ]);
      }
    }

    if ($this->hasContactTokenContext($schema)) {
      if (civi_wallet_is_context_enabled('membership')) {
        if ($walletLinksEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'membership_links',
            'label' => E::ts('Wallet Links (Membership)'),
          ]);
        }
        if ($appleEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'membership_apple_url',
            'label' => E::ts('Apple Wallet URL (Membership)'),
          ]);
        }
        if ($googleEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'membership_google_url',
            'label' => E::ts('Google Wallet URL (Membership)'),
          ]);
        }
        if ($qrEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'membership_qr',
            'label' => E::ts('QR Code (Membership)'),
          ]);
          $e->register([
            'entity' => 'wallet',
            'field' => 'membership_qr_url',
            'label' => E::ts('QR Code URL (Membership)'),
          ]);
        }
      }

      if (civi_wallet_is_context_enabled('donor')) {
        if ($walletLinksEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'donor_links',
            'label' => E::ts('Wallet Links (Donor Card)'),
          ]);
        }
        if ($appleEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'donor_apple_url',
            'label' => E::ts('Apple Wallet URL (Donor Card)'),
          ]);
        }
        if ($googleEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'donor_google_url',
            'label' => E::ts('Google Wallet URL (Donor Card)'),
          ]);
        }
        if ($qrEnabled) {
          $e->register([
            'entity' => 'wallet',
            'field' => 'donor_qr',
            'label' => E::ts('QR Code (Donor Card)'),
          ]);
          $e->register([
            'entity' => 'wallet',
            'field' => 'donor_qr_url',
            'label' => E::ts('QR Code URL (Donor Card)'),
          ]);
        }
      }

      if ($qrEnabled) {
        $e->register([
          'entity' => 'wallet',
          'field' => 'contact_qr',
          'label' => E::ts('QR Code (Contact Pass / Badge)'),
        ]);
        $e->register([
          'entity' => 'wallet',
          'field' => 'contact_qr_url',
          'label' => E::ts('QR Code URL (Contact Pass / Badge)'),
        ]);
      }
    }
  }

  public function evaluateTokens(TokenValueEvent $e): void {
    $messageTokens = $e->getTokenProcessor()->getMessageTokens();
    $walletTokens = $messageTokens['wallet'] ?? [];

    if (empty($walletTokens)) {
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

      $translate = static fn(string $text): string => LocaleResolver::translateForContact($cid ? (int) $cid : NULL, $text);

      $cs = $cid ? \CRM_Contact_BAO_Contact_Utils::generateChecksum((int) $cid) : '';
      $color = civi_wallet_get_qr_color();
      $background = civi_wallet_get_qr_background_color();

      // Evaluate Participant Tokens
      if ($pid) {
        $htmlParticipant = '';
        $participantAppleUrl = '';
        $participantGoogleUrl = '';
        $participantQrUrl = '';

        if (civi_wallet_is_context_enabled('participant') && civi_wallet_is_channel_enabled('apple') && $cid) {
          $participantAppleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "pid={$pid}&cid={$cid}&cs={$cs}&type=apple&context=participant", TRUE, NULL, FALSE, TRUE);
          $htmlParticipant .= "<a href='{$participantAppleUrl}' style='{$btnStyleApple}'>" . $translate('Add to Apple Wallet') . "</a>";
        }

        if (civi_wallet_is_context_enabled('participant') && civi_wallet_is_channel_enabled('google') && $cid) {
          $participantGoogleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "pid={$pid}&cid={$cid}&cs={$cs}&type=google&context=participant", TRUE, NULL, FALSE, TRUE);
          if ($htmlParticipant !== '') {
            $htmlParticipant .= ' ';
          }
          $htmlParticipant .= "<a href='{$participantGoogleUrl}' style='{$btnStyleGoogle}'>" . $translate('Add to Google Wallet') . "</a>";
        }

        if (civi_wallet_is_context_enabled('participant') && civi_wallet_is_channel_enabled('qr') && $cid) {
          $participantQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=participant&pid={$pid}&cs={$cs}&color={$color}&background={$background}", TRUE, NULL, FALSE, TRUE);
        }

        if (in_array('participant_links', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'participant_links', $htmlParticipant);
          $row->format('text/plain')->tokens('wallet', 'participant_links', $this->buildPlainLinks($participantAppleUrl, $participantGoogleUrl, $cid ? (int) $cid : NULL));
        }
        if (in_array('participant_apple_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'participant_apple_url', $participantAppleUrl);
          $row->format('text/plain')->tokens('wallet', 'participant_apple_url', $participantAppleUrl);
        }
        if (in_array('participant_google_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'participant_google_url', $participantGoogleUrl);
          $row->format('text/plain')->tokens('wallet', 'participant_google_url', $participantGoogleUrl);
        }
        if (in_array('participant_qr', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'participant_qr', $participantQrUrl ? "<img src='{$participantQrUrl}' width='150' height='150' alt='" . $translate('QR Code') . "'>" : '');
          $row->format('text/plain')->tokens('wallet', 'participant_qr', $participantQrUrl);
        }
        if (in_array('participant_qr_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'participant_qr_url', $participantQrUrl);
          $row->format('text/plain')->tokens('wallet', 'participant_qr_url', $participantQrUrl);
        }
      }

      // Evaluate Contact Tokens
      if ($cid) {
        $htmlMembership = '';
        $membershipAppleUrl = '';
        $membershipGoogleUrl = '';
        $membershipQrUrl = '';

        if (civi_wallet_is_context_enabled('membership') && civi_wallet_is_channel_enabled('apple')) {
          $membershipAppleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=apple&context=membership", TRUE, NULL, FALSE, TRUE);
          $htmlMembership .= "<a href='{$membershipAppleUrl}' style='{$btnStyleApple}'>" . $translate('Add to Apple Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('membership') && civi_wallet_is_channel_enabled('google')) {
          $membershipGoogleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=google&context=membership", TRUE, NULL, FALSE, TRUE);
          if ($htmlMembership !== '') {
            $htmlMembership .= ' ';
          }
          $htmlMembership .= "<a href='{$membershipGoogleUrl}' style='{$btnStyleGoogle}'>" . $translate('Add to Google Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('membership') && civi_wallet_is_channel_enabled('qr')) {
          $membershipQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=membership&cid={$cid}&cs={$cs}&color={$color}&background={$background}", TRUE, NULL, FALSE, TRUE);
        }

        if (in_array('membership_links', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'membership_links', $htmlMembership);
          $row->format('text/plain')->tokens('wallet', 'membership_links', $this->buildPlainLinks($membershipAppleUrl, $membershipGoogleUrl, (int) $cid));
        }
        if (in_array('membership_apple_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'membership_apple_url', $membershipAppleUrl);
          $row->format('text/plain')->tokens('wallet', 'membership_apple_url', $membershipAppleUrl);
        }
        if (in_array('membership_google_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'membership_google_url', $membershipGoogleUrl);
          $row->format('text/plain')->tokens('wallet', 'membership_google_url', $membershipGoogleUrl);
        }
        if (in_array('membership_qr', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'membership_qr', $membershipQrUrl ? "<img src='{$membershipQrUrl}' width='150' height='150' alt='" . $translate('QR Code') . "'>" : '');
          $row->format('text/plain')->tokens('wallet', 'membership_qr', $membershipQrUrl);
        }
        if (in_array('membership_qr_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'membership_qr_url', $membershipQrUrl);
          $row->format('text/plain')->tokens('wallet', 'membership_qr_url', $membershipQrUrl);
        }

        $htmlDonor = '';
        $donorAppleUrl = '';
        $donorGoogleUrl = '';
        $donorQrUrl = '';

        if (civi_wallet_is_context_enabled('donor') && civi_wallet_is_channel_enabled('apple')) {
          $donorAppleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=apple&context=donor", TRUE, NULL, FALSE, TRUE);
          $htmlDonor .= "<a href='{$donorAppleUrl}' style='{$btnStyleApple}'>" . $translate('Add to Apple Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('donor') && civi_wallet_is_channel_enabled('google')) {
          $donorGoogleUrl = \CRM_Utils_System::url('civicrm/wallet/pass', "cid={$cid}&cs={$cs}&type=google&context=donor", TRUE, NULL, FALSE, TRUE);
          if ($htmlDonor !== '') {
            $htmlDonor .= ' ';
          }
          $htmlDonor .= "<a href='{$donorGoogleUrl}' style='{$btnStyleGoogle}'>" . $translate('Add to Google Wallet') . "</a>";
        }
        if (civi_wallet_is_context_enabled('donor') && civi_wallet_is_channel_enabled('qr')) {
          $donorQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=donor&cid={$cid}&cs={$cs}&color={$color}&background={$background}", TRUE, NULL, FALSE, TRUE);
        }

        if (in_array('donor_links', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'donor_links', $htmlDonor);
          $row->format('text/plain')->tokens('wallet', 'donor_links', $this->buildPlainLinks($donorAppleUrl, $donorGoogleUrl, (int) $cid));
        }
        if (in_array('donor_apple_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'donor_apple_url', $donorAppleUrl);
          $row->format('text/plain')->tokens('wallet', 'donor_apple_url', $donorAppleUrl);
        }
        if (in_array('donor_google_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'donor_google_url', $donorGoogleUrl);
          $row->format('text/plain')->tokens('wallet', 'donor_google_url', $donorGoogleUrl);
        }
        if (in_array('donor_qr', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'donor_qr', $donorQrUrl ? "<img src='{$donorQrUrl}' width='150' height='150' alt='" . $translate('QR Code') . "'>" : '');
          $row->format('text/plain')->tokens('wallet', 'donor_qr', $donorQrUrl);
        }
        if (in_array('donor_qr_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'donor_qr_url', $donorQrUrl);
          $row->format('text/plain')->tokens('wallet', 'donor_qr_url', $donorQrUrl);
        }

        $contactPassQrUrl = \CRM_Utils_System::url('civicrm/wallet/api/qr', "entity=contact&cid={$cid}&cs={$cs}&color={$color}&background={$background}", TRUE, NULL, FALSE, TRUE);
        if (in_array('contact_qr', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'contact_qr', "<img src='{$contactPassQrUrl}' width='150' height='150' alt='" . $translate('QR Code') . "'>");
          $row->format('text/plain')->tokens('wallet', 'contact_qr', $contactPassQrUrl);
        }
        if (in_array('contact_qr_url', $walletTokens, TRUE)) {
          $row->format('text/html')->tokens('wallet', 'contact_qr_url', $contactPassQrUrl);
          $row->format('text/plain')->tokens('wallet', 'contact_qr_url', $contactPassQrUrl);
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

  private function buildPlainLinks(string $appleUrl, string $googleUrl, ?int $contactId): string {
    $links = [];
    if ($appleUrl !== '') {
      $links[] = LocaleResolver::translateForContact($contactId, 'Apple Wallet') . ': ' . $appleUrl;
    }
    if ($googleUrl !== '') {
      $links[] = LocaleResolver::translateForContact($contactId, 'Google Wallet') . ': ' . $googleUrl;
    }

    return implode("\n", $links);
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
