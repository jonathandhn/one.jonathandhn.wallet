<div class="crm-block crm-form-block crm-wallet-config-form-block">
  <div class="help">
    {ts}Configure your Apple and Google Wallet settings here.{/ts}
  </div>

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
      <td class="label">{$form.wallet_apple_pass_type_id.label}</td>
      <td>{$form.wallet_apple_pass_type_id.html}</td>
    </tr>
    <tr class="crm-wallet-apple-team-id-form-block-wallet_apple_team_id">
      <td class="label">{$form.wallet_apple_team_id.label}</td>
      <td>{$form.wallet_apple_team_id.html}</td>
    </tr>
    <tr class="crm-wallet-apple-cert-upload-form-block-wallet_apple_cert_upload">
      <td class="label">{$form.wallet_apple_cert_upload.label}</td>
      <td>
        {$form.wallet_apple_cert_upload.html}
        {if $apple_cert_status}
          <div class="description">{$apple_cert_status}</div>
        {/if}
      </td>
    </tr>
    <tr class="crm-wallet-apple-cert-password-form-block-wallet_apple_cert_password">
      <td class="label">{$form.wallet_apple_cert_password.label}</td>
      <td>{$form.wallet_apple_cert_password.html}</td>
    </tr>
  </table>

  <h3>{ts}Google Wallet Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-google-issuer-id-form-block-wallet_google_issuer_id">
      <td class="label">{$form.wallet_google_issuer_id.label}</td>
      <td>{$form.wallet_google_issuer_id.html}</td>
    </tr>
    <tr class="crm-wallet-google-credentials-upload-form-block-wallet_google_credentials_upload">
      <td class="label">{$form.wallet_google_credentials_upload.label}</td>
      <td>
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
      <td class="label">{$form.wallet_org_name.label}</td>
      <td>{$form.wallet_org_name.html}</td>
    </tr>
    <tr class="crm-wallet-org-description-form-block-wallet_org_description">
      <td class="label">{$form.wallet_org_description.label}</td>
      <td>{$form.wallet_org_description.html}</td>
    </tr>
    <tr class="crm-wallet-website-url-form-block-wallet_website_url">
      <td class="label">{$form.wallet_website_url.label}</td>
      <td>{$form.wallet_website_url.html}</td>
    </tr>
    <tr class="crm-wallet-contact-url-form-block-wallet_contact_url">
      <td class="label">{$form.wallet_contact_url.label}</td>
      <td>{$form.wallet_contact_url.html}</td>
    </tr>
  </table>

  <h3>{ts}Graphics & Branding{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-color-background-form-block-wallet_color_background">
      <td class="label">{$form.wallet_color_background.label}</td>
      <td>{$form.wallet_color_background.html}</td>
    </tr>
    <tr class="crm-wallet-color-foreground-form-block-wallet_color_foreground">
      <td class="label">{$form.wallet_color_foreground.label}</td>
      <td>{$form.wallet_color_foreground.html}</td>
    </tr>
    <tr class="crm-wallet-color-label-form-block-wallet_color_label">
      <td class="label">{$form.wallet_color_label.label}</td>
      <td>{$form.wallet_color_label.html}</td>
    </tr>
    <tr class="crm-wallet-image-icon-upload-form-block-wallet_image_icon_upload">
      <td class="label">{$form.wallet_image_icon_upload.label}</td>
      <td>{$form.wallet_image_icon_upload.html}</td>
    </tr>
    <tr class="crm-wallet-image-logo-upload-form-block-wallet_image_logo_upload">
      <td class="label">{$form.wallet_image_logo_upload.label}</td>
      <td>{$form.wallet_image_logo_upload.html}</td>
    </tr>
  </table>

  <h3>{ts}Membership Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-membership-renew-url-form-block-wallet_membership_renew_url">
      <td class="label">{$form.wallet_membership_renew_url.label}</td>
      <td>
        {$form.wallet_membership_renew_url.html}
        <div class="description">{ts}Example: civicrm/contribute/transact?reset=1&id=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-membership-profile-url-form-block-wallet_membership_profile_url">
      <td class="label">{$form.wallet_membership_profile_url.label}</td>
      <td>
        {$form.wallet_membership_profile_url.html}
        <div class="description">{ts}Example: civicrm/profile/edit?reset=1&gid=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-color-membership-active-form-block-wallet_color_membership_active">
      <td class="label">{$form.wallet_color_membership_active.label}</td>
      <td>{$form.wallet_color_membership_active.html}</td>
    </tr>
    <tr class="crm-wallet-color-membership-expired-form-block-wallet_color_membership_expired">
      <td class="label">{$form.wallet_color_membership_expired.label}</td>
      <td>{$form.wallet_color_membership_expired.html}</td>
    </tr>
  </table>

  <h3>{ts}Donor Settings{/ts}</h3>
  <table class="form-layout-compressed">
    <tr class="crm-wallet-donor-donate-url-form-block-wallet_donor_donate_url">
      <td class="label">{$form.wallet_donor_donate_url.label}</td>
      <td>
        {$form.wallet_donor_donate_url.html}
        <div class="description">{ts}Example: civicrm/contribute/transact?reset=1&id=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-donor-profile-url-form-block-wallet_donor_profile_url">
      <td class="label">{$form.wallet_donor_profile_url.label}</td>
      <td>
        {$form.wallet_donor_profile_url.html}
        <div class="description">{ts}Example: civicrm/profile/edit?reset=1&gid=1{/ts}</div>
      </td>
    </tr>
    <tr class="crm-wallet-barcode-donor-form-block-wallet_barcode_donor">
      <td class="label">{$form.wallet_barcode_donor.label}</td>
      <td>{$form.wallet_barcode_donor.html}</td>
    </tr>
  </table>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
