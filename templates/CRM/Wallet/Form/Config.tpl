<div class="crm-block crm-form-block crm-wallet-config-form-block">
  <div class="help">
    {ts}Configure your Apple and Google Wallet settings here.{/ts}
  </div>

  <style>
    .crm-wallet-config-form-block .wallet-field-label {
      display: block;
      font-weight: 600;
      margin-bottom: 0.35rem;
    }
  </style>

  <h3>{ts}Channel Flags{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-channel-apple-form-block-wallet_channel_apple_enabled">
      <td class="label">{ts}Apple Wallet{/ts}</td>
      <td>{$form.wallet_channel_apple_enabled.html} {$form.wallet_channel_apple_enabled.label}</td>
    </tr>
    <tr class="crm-wallet-channel-google-form-block-wallet_channel_google_enabled">
      <td class="label">{ts}Google Wallet{/ts}</td>
      <td>{$form.wallet_channel_google_enabled.html} {$form.wallet_channel_google_enabled.label}</td>
    </tr>
    <tr class="crm-wallet-channel-qr-form-block-wallet_channel_qr_enabled">
      <td class="label">{ts}Local QR{/ts}</td>
      <td>{$form.wallet_channel_qr_enabled.html} {$form.wallet_channel_qr_enabled.label}</td>
    </tr>
  </table>

  <h3>{ts}Pass Type Flags{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-type-participant-form-block-wallet_type_participant_enabled">
      <td class="label">{ts}Event Tickets{/ts}</td>
      <td>{$form.wallet_type_participant_enabled.html} {$form.wallet_type_participant_enabled.label}</td>
    </tr>
    <tr class="crm-wallet-type-membership-form-block-wallet_type_membership_enabled">
      <td class="label">{ts}Membership Cards{/ts}</td>
      <td>{$form.wallet_type_membership_enabled.html} {$form.wallet_type_membership_enabled.label}</td>
    </tr>
    <tr class="crm-wallet-type-donor-form-block-wallet_type_donor_enabled">
      <td class="label">{ts}Donor Cards{/ts}</td>
      <td>{$form.wallet_type_donor_enabled.html} {$form.wallet_type_donor_enabled.label}</td>
    </tr>
  </table>

  <h3>{ts}Apple Wallet Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-apple-pass-type-id-form-block-wallet_apple_pass_type_id">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_apple_pass_type_id">{ts}Pass Type ID{/ts}</label>
        {$form.wallet_apple_pass_type_id.html}
      </td>
    </tr>
    <tr class="crm-wallet-apple-team-id-form-block-wallet_apple_team_id">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_apple_team_id">{ts}Team ID{/ts}</label>
        {$form.wallet_apple_team_id.html}
      </td>
    </tr>
    <tr class="crm-wallet-apple-cert-upload-form-block-wallet_apple_cert_upload">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_apple_cert_upload">{ts}Apple Certificate (.p12){/ts}</label>
        {$form.wallet_apple_cert_upload.html}
        {if $apple_cert_status}
          <div class="description">{$apple_cert_status}</div>
        {/if}
      </td>
    </tr>
    <tr class="crm-wallet-apple-cert-password-form-block-wallet_apple_cert_password">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_apple_cert_password">{ts}Certificate Password{/ts}</label>
        {$form.wallet_apple_cert_password.html}
      </td>
    </tr>
  </table>

  <h3>{ts}Google Wallet Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-google-issuer-id-form-block-wallet_google_issuer_id">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_google_issuer_id">{ts}Issuer ID{/ts}</label>
        {$form.wallet_google_issuer_id.html}
      </td>
    </tr>
    <tr class="crm-wallet-google-credentials-upload-form-block-wallet_google_credentials_upload">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_google_credentials_upload">{ts}Google Credentials (.json){/ts}</label>
        {$form.wallet_google_credentials_upload.html}
        {if $google_creds_status}
          <div class="description">{$google_creds_status}</div>
        {/if}
      </td>
    </tr>
  </table>

  <h3>{ts}Organization Details{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-org-name-form-block-wallet_org_name">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_org_name">{ts}Organization Name{/ts}</label>
        {$form.wallet_org_name.html}
      </td>
    </tr>
    <tr class="crm-wallet-org-description-form-block-wallet_org_description">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_org_description">{ts}Description{/ts}</label>
        {$form.wallet_org_description.html}
      </td>
    </tr>
    <tr class="crm-wallet-website-url-form-block-wallet_website_url">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_website_url">{ts}Website URL{/ts}</label>
        {$form.wallet_website_url.html}
      </td>
    </tr>
    <tr class="crm-wallet-contact-url-form-block-wallet_contact_url">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_contact_url">{ts}Contact URL{/ts}</label>
        {$form.wallet_contact_url.html}
      </td>
    </tr>
  </table>

  <h3>{ts}Graphics & Branding{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-color-background-form-block-wallet_color_background">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_color_background">{ts}Background Color{/ts}</label>
        {$form.wallet_color_background.html}
      </td>
    </tr>
    <tr class="crm-wallet-color-foreground-form-block-wallet_color_foreground">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_color_foreground">{ts}Foreground Color{/ts}</label>
        {$form.wallet_color_foreground.html}
      </td>
    </tr>
    <tr class="crm-wallet-color-label-form-block-wallet_color_label">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_color_label">{ts}Label Color{/ts}</label>
        {$form.wallet_color_label.html}
      </td>
    </tr>
    <tr class="crm-wallet-image-icon-upload-form-block-wallet_image_icon_upload">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_image_icon_upload">{ts}Icon (icon.png){/ts}</label>
        {$form.wallet_image_icon_upload.html}
      </td>
    </tr>
    <tr class="crm-wallet-image-logo-upload-form-block-wallet_image_logo_upload">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_image_logo_upload">{ts}Logo (logo.png){/ts}</label>
        {$form.wallet_image_logo_upload.html}
      </td>
    </tr>
  </table>

  <h3>{ts}Membership Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-membership-renew-url-form-block-wallet_membership_renew_url">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_membership_renew_url">{ts}Membership Renew URL{/ts}</label>
        {$form.wallet_membership_renew_url.html}
        <div class="description">{ts}Example: civicrm/contribute/transact?reset=1&id=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-membership-profile-url-form-block-wallet_membership_profile_url">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_membership_profile_url">{ts}Profile Update URL{/ts}</label>
        {$form.wallet_membership_profile_url.html}
        <div class="description">{ts}Example: civicrm/profile/edit?reset=1&gid=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-color-membership-active-form-block-wallet_color_membership_active">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_color_membership_active">{ts}Membership Active Color{/ts}</label>
        {$form.wallet_color_membership_active.html}
      </td>
    </tr>
    <tr class="crm-wallet-color-membership-expired-form-block-wallet_color_membership_expired">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_color_membership_expired">{ts}Membership Expired Color{/ts}</label>
        {$form.wallet_color_membership_expired.html}
      </td>
    </tr>
    <tr class="crm-wallet-barcode-membership-mode-form-block-wallet_barcode_membership_mode">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_barcode_membership_mode">{ts}Membership QR Mode{/ts}</label>
        {$form.wallet_barcode_membership_mode.html}
        <div class="description">{ts}Choose Simple (contact ID) or Signed (HMAC-protected cw2 payload) for membership cards.{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-barcode-membership-form-block-wallet_barcode_membership">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_barcode_membership">{ts}Membership Barcode Content (Simple mode){/ts}</label>
        {$form.wallet_barcode_membership.html}
      </td>
    </tr>
  </table>

  <h3>{ts}Event Ticket Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-barcode-participant-mode-form-block-wallet_barcode_participant_mode">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_barcode_participant_mode">{ts}Event Ticket QR Mode{/ts}</label>
        {$form.wallet_barcode_participant_mode.html}
        <div class="description">{ts}Use simple mode for raw PID compatibility with exports, N8N, spreadsheets, or third-party senders. Use signed mode for a stronger HMAC cw2 payload when CiviScan verifies it.{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-barcode-participant-form-block-wallet_barcode_participant">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_barcode_participant">{ts}Event Ticket Barcode Content (Simple mode){/ts}</label>
        {$form.wallet_barcode_participant.html}
        <div class="description">{ts}Used by simple mode. Leave the default value to emit the participant ID explicitly.{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-barcode-participant-secret-form-block-wallet_barcode_participant_secret">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_barcode_participant_secret">{ts}Shared QR Secret (HMAC){/ts}</label>
        {$form.wallet_barcode_participant_secret.html}
        <div class="description">{ts}Used by signed cw2 mode across all pass types (tickets, memberships, donor cards). Auto-generated if left empty.{/ts}</div>
      </td>
    </tr>
  </table>

  <h3>{ts}Donor Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-donor-donate-url-form-block-wallet_donor_donate_url">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_donor_donate_url">{ts}Donor Donate URL{/ts}</label>
        {$form.wallet_donor_donate_url.html}
        <div class="description">{ts}Example: civicrm/contribute/transact?reset=1&id=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-donor-profile-url-form-block-wallet_donor_profile_url">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_donor_profile_url">{ts}Donor Profile Update URL{/ts}</label>
        {$form.wallet_donor_profile_url.html}
        <div class="description">{ts}Example: civicrm/profile/edit?reset=1&gid=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-barcode-donor-mode-form-block-wallet_barcode_donor_mode">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_barcode_donor_mode">{ts}Donor Card QR Mode{/ts}</label>
        {$form.wallet_barcode_donor_mode.html}
        <div class="description">{ts}Choose Simple (contact ID) or Signed (HMAC-protected cw2 payload) for donor cards.{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-barcode-donor-form-block-wallet_barcode_donor">
      <td colspan="2">
        <label class="wallet-field-label" for="wallet_barcode_donor">{ts}Donor Card Barcode Content (Simple mode){/ts}</label>
        {$form.wallet_barcode_donor.html}
      </td>
    </tr>
  </table>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
