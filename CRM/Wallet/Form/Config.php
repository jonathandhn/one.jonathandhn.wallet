<?php

use CRM_Wallet_ExtensionUtil as E;

class CRM_Wallet_Form_Config extends CRM_Admin_Form_Setting
{
    protected $_settings = [
        'wallet_apple_cert' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_apple_cert_password' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_apple_pass_type_id' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_apple_team_id' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_channel_apple_enabled' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_channel_google_enabled' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_channel_qr_enabled' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_type_participant_enabled' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_type_membership_enabled' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_type_donor_enabled' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_google_credentials' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_google_issuer_id' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_org_name' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_org_description' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_color_background' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_color_foreground' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_color_label' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_image_icon' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_image_logo' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_website_url' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_contact_url' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_color_membership_active' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_color_membership_expired' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_membership_renew_url' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_membership_profile_url' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_donor_donate_url' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_donor_profile_url' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_barcode_membership_mode' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_barcode_membership' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_barcode_donor_mode' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_barcode_donor' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_barcode_participant' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_barcode_participant_mode' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
        'wallet_barcode_participant_secret' => CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME,
    ];

    public function buildQuickForm()
    {
        $this->applyFilter('__ALL__', 'trim');

        // --- Channel Flags ---
        $this->add('advcheckbox', 'wallet_channel_apple_enabled', E::ts('Enable Apple Wallet'));
        $this->add('advcheckbox', 'wallet_channel_google_enabled', E::ts('Enable Google Wallet'));
        $this->add('advcheckbox', 'wallet_channel_qr_enabled', E::ts('Enable local QR'));

        // --- Pass Type Flags ---
        $this->add('advcheckbox', 'wallet_type_participant_enabled', E::ts('Enable Event Tickets'));
        $this->add('advcheckbox', 'wallet_type_membership_enabled', E::ts('Enable Membership Cards'));
        $this->add('advcheckbox', 'wallet_type_donor_enabled', E::ts('Enable Donor Cards'));

        // --- Apple Wallet Settings ---
        $this->add('text', 'wallet_apple_pass_type_id', E::ts('Pass Type ID'), ['size' => 50, 'placeholder' => 'pass.com.example.my-pass']);
        $this->add('text', 'wallet_apple_team_id', E::ts('Team ID'), ['size' => 20, 'placeholder' => 'A1B2C3D4E5']);

        // Certificate Upload
        $this->add('file', 'wallet_apple_cert_upload', E::ts('Apple Certificate (.p12)'));
        $this->add('password', 'wallet_apple_cert_password', E::ts('Certificate Password'));

        // Show current certificate status
        $certPath = Civi::settings()->get('wallet_apple_cert');
        if ($certPath && file_exists($certPath)) {
            $certInfo = $this->getCertInfo($certPath, Civi::settings()->get('wallet_apple_cert_password'));
            $status = E::ts('Certificate uploaded.');
            if ($certInfo) {
                $status .= ' ' . E::ts('Expires on: %1', [1 => $certInfo['validTo']]);
            }
            $this->assign('apple_cert_status', $status);
        }

        // --- Google Wallet Settings ---
        $this->add('text', 'wallet_google_issuer_id', E::ts('Issuer ID'), ['size' => 50]);
        $this->add('file', 'wallet_google_credentials_upload', E::ts('Google Credentials (.json)'));

        $credsPath = Civi::settings()->get('wallet_google_credentials');
        if ($credsPath && file_exists($credsPath)) {
            $this->assign('google_creds_status', E::ts('Credentials uploaded.'));
        }

        // --- Organization Settings ---
        // Default to CiviCRM Domain Name if not set
        $defaultOrgName = Civi::settings()->get('wallet_org_name');
        if (empty($defaultOrgName)) {
            $defaultOrgName = CRM_Core_BAO_Domain::getDomain()->name;
        }

        $this->add('text', 'wallet_org_name', E::ts('Organization Name'), ['size' => 50]);
        $this->setDefaults(['wallet_org_name' => $defaultOrgName]);

        $this->add('textarea', 'wallet_org_description', E::ts('Description'), ['rows' => 4, 'cols' => 50]);

        $this->add('text', 'wallet_website_url', E::ts('Website URL'), ['size' => 50, 'placeholder' => 'https://www.example.org']);
        $this->add('text', 'wallet_contact_url', E::ts('Contact URL'), ['size' => 50, 'placeholder' => 'https://www.example.org/contact']);

        // --- Graphics Settings ---
        $this->add('text', 'wallet_color_background', E::ts('Background Color'), ['type' => 'color', 'placeholder' => '#ea148c']);
        $this->add('text', 'wallet_color_foreground', E::ts('Foreground Color'), ['type' => 'color', 'placeholder' => '#ffffff']);
        $this->add('text', 'wallet_color_label', E::ts('Label Color'), ['type' => 'color', 'placeholder' => '#ffffff']);

        $this->add('file', 'wallet_image_icon_upload', E::ts('Icon (icon.png)'));
        $this->add('file', 'wallet_image_logo_upload', E::ts('Logo (logo.png)'));

        $barcodeModes = [
            'simple' => E::ts('Simple (ID / token compatible)'),
            'signed' => E::ts('Signed (HMAC-protected cw2 payload)'),
        ];

        // --- Membership Settings ---
        $this->add('text', 'wallet_membership_renew_url', E::ts('Membership Renew URL'), ['size' => 50, 'placeholder' => 'civicrm/contribute/transact?reset=1&id=1']);
        $this->add('text', 'wallet_membership_profile_url', E::ts('Profile Update URL'), ['size' => 50, 'placeholder' => 'civicrm/profile/edit?reset=1&gid=1']);
        $this->add('text', 'wallet_color_membership_active', E::ts('Membership Active Color'), ['type' => 'color', 'placeholder' => '#53b459']);
        $this->add('text', 'wallet_color_membership_expired', E::ts('Membership Expired Color'), ['type' => 'color', 'placeholder' => '#a0a0a0']);
        $this->add('select', 'wallet_barcode_membership_mode', E::ts('Membership QR Mode'), $barcodeModes);
        $this->add('text', 'wallet_barcode_membership', E::ts('Membership Barcode Content (Tokens allowed)'), ['size' => 50, 'placeholder' => '{contact.id}']);

        // --- Participant Settings ---
        $this->add('select', 'wallet_barcode_participant_mode', E::ts('Event Ticket QR Mode'), $barcodeModes);
        $this->add('text', 'wallet_barcode_participant', E::ts('Event Ticket Barcode Content (Tokens allowed)'), ['size' => 50, 'placeholder' => '{participant.id}']);
        $this->add('text', 'wallet_barcode_participant_secret', E::ts('Shared QR Secret (HMAC)'), ['size' => 72, 'placeholder' => E::ts('Auto-generated when signed mode is saved')]);

        // --- Donor Settings ---
        $this->add('text', 'wallet_donor_donate_url', E::ts('Donor Donate URL'), ['size' => 50, 'placeholder' => 'civicrm/contribute/transact?reset=1&id=1']);
        $this->add('text', 'wallet_donor_profile_url', E::ts('Donor Profile Update URL'), ['size' => 50, 'placeholder' => 'civicrm/profile/edit?reset=1&gid=1']);
        $this->add('select', 'wallet_barcode_donor_mode', E::ts('Donor Card QR Mode'), $barcodeModes);
        $this->add('text', 'wallet_barcode_donor', E::ts('Donor Card Barcode Content (Tokens allowed)'), ['size' => 50, 'placeholder' => '{contact.id}']);

        parent::buildQuickForm();
    }

    public function setDefaultValues()
    {
        $defaults = parent::setDefaultValues();
        $defaults['wallet_barcode_membership_mode'] = Civi::settings()->get('wallet_barcode_membership_mode') ?: 'simple';
        $defaults['wallet_barcode_donor_mode'] = Civi::settings()->get('wallet_barcode_donor_mode') ?: 'simple';
        $defaults['wallet_barcode_participant_mode'] = Civi::settings()->get('wallet_barcode_participant_mode') ?: 'simple';
        return $defaults;
    }

    public function postProcess()
    {
        $values = $this->exportValues();
        $config = CRM_Core_Config::singleton();
        $uploadDir = $config->customFileUploadDir;
        $booleanSettings = [
            'wallet_channel_apple_enabled',
            'wallet_channel_google_enabled',
            'wallet_channel_qr_enabled',
            'wallet_type_participant_enabled',
            'wallet_type_membership_enabled',
            'wallet_type_donor_enabled',
        ];

        // Handle Apple Cert Upload
        if (!empty($_FILES['wallet_apple_cert_upload']['name'])) {
            $dest = $uploadDir . 'wallet_apple_cert_' . time() . '.p12';
            if (move_uploaded_file($_FILES['wallet_apple_cert_upload']['tmp_name'], $dest)) {
                Civi::settings()->set('wallet_apple_cert', $dest);

                // Verify Password
                $password = $values['wallet_apple_cert_password'] ?? Civi::settings()->get('wallet_apple_cert_password');
                if (!$this->getCertInfo($dest, $password)) {
                    CRM_Core_Session::setStatus(E::ts('Invalid certificate password or corrupted file.'), E::ts('Error'), 'error');
                }
            }
        }

        // Handle Google Credentials Upload
        if (!empty($_FILES['wallet_google_credentials_upload']['name'])) {
            $dest = $uploadDir . 'wallet_google_creds_' . time() . '.json';
            if (move_uploaded_file($_FILES['wallet_google_credentials_upload']['tmp_name'], $dest)) {
                Civi::settings()->set('wallet_google_credentials', $dest);
            }
        }

        // Handle Images
        $extPath = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('one.jonathandhn.wallet');
        $imagesDir = $extPath . '/resources/images/';
        if (!file_exists($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }

        if (!empty($_FILES['wallet_image_icon_upload']['name'])) {
            move_uploaded_file($_FILES['wallet_image_icon_upload']['tmp_name'], $imagesDir . 'icon.png');
            copy($imagesDir . 'icon.png', $imagesDir . 'icon@2x.png');
        }

        if (!empty($_FILES['wallet_image_logo_upload']['name'])) {
            move_uploaded_file($_FILES['wallet_image_logo_upload']['tmp_name'], $imagesDir . 'logo.png');
        }

        // Manually Save Settings to avoid MAX_FILE_SIZE error from parent::postProcess()
        $settingsToSave = [
            'wallet_apple_pass_type_id',
            'wallet_apple_team_id',
            'wallet_apple_cert_password',
            'wallet_channel_apple_enabled',
            'wallet_channel_google_enabled',
            'wallet_channel_qr_enabled',
            'wallet_type_participant_enabled',
            'wallet_type_membership_enabled',
            'wallet_type_donor_enabled',
            'wallet_google_issuer_id',
            'wallet_org_name',
            'wallet_org_description',
            'wallet_website_url',
            'wallet_contact_url',
            'wallet_color_background',
            'wallet_color_foreground',
            'wallet_color_label',
            'wallet_color_membership_active',
            'wallet_color_membership_expired',
            'wallet_membership_renew_url',
            'wallet_membership_profile_url',
            'wallet_donor_donate_url',
            'wallet_donor_profile_url',
            'wallet_barcode_membership_mode',
            'wallet_barcode_membership',
            'wallet_barcode_donor_mode',
            'wallet_barcode_donor',
            'wallet_barcode_participant',
            'wallet_barcode_participant_mode',
            'wallet_barcode_participant_secret',
        ];

        $hasSigned = (($values['wallet_barcode_participant_mode'] ?? 'simple') === 'signed')
            || (($values['wallet_barcode_membership_mode'] ?? 'simple') === 'signed')
            || (($values['wallet_barcode_donor_mode'] ?? 'simple') === 'signed');

        if ($hasSigned && empty($values['wallet_barcode_participant_secret'])) {
            $values['wallet_barcode_participant_secret'] = civi_wallet_generate_participant_qr_secret();
        }

        foreach ($settingsToSave as $setting) {
            if (in_array($setting, $booleanSettings, true)) {
                Civi::settings()->set($setting, !empty($values[$setting]) ? 1 : 0);
                continue;
            }

            if (array_key_exists($setting, $values)) {
                Civi::settings()->set($setting, $values[$setting]);
            }
        }

        CRM_Core_Session::setStatus(E::ts('Settings saved successfully.'), E::ts('Saved'), 'success');
    }

    private function getCertInfo($path, $password)
    {
        if (!file_exists($path))
            return false;
        $pkcs12 = file_get_contents($path);
        $certs = [];
        if (openssl_pkcs12_read($pkcs12, $certs, $password)) {
            $certData = openssl_x509_parse($certs['cert']);
            return [
                'validTo' => date('Y-m-d H:i:s', $certData['validTo_time_t'])
            ];
        }
        return false;
    }
}
