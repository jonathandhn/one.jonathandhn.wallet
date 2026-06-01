# CiviWallet - Apple & Google Wallet for CiviCRM

Generate Apple Wallet (`.pkpass`) and Google Wallet passes for CiviCRM Event Participants.
This extension automatically generates wallet passes including QR codes, event details, venue location, and custom styling.

## Repository
[https://github.com/jonathandhn/org.jonathandhn.wallet](https://github.com/jonathandhn/org.jonathandhn.wallet)

## Features

- **Apple Wallet**: Generates signed `.pkpass` files used in Apple Wallet.
- **Google Wallet**: Creates "Event Ticket" objects via Google Wallet API and generates "Save to Android Pay" links.
- **QR Codes**: Encodes participant/membership ID for check-in scanning. Generates native QR Codes for emails and PDFs locally (no external dependencies).
- **Automatic Updates**: 
  - Updates Apple Wallet passes via Push Notifications when Event details change.
  - Updates Google Wallet passes automatically in the cloud.
- **Customization**:
  - Event Image & Background Color overrides via Custom Fields.
  - Venue location and GPS coordinates.

## Requirements

- CiviCRM 5.x
- PHP 7.4+ or 8.x
- `openssl` PHP extension
- `json` PHP extension

## Installation

1. Download/Clone this extension into your `extensions` directory.
2. Enable it via **Administer > System Settings > Extensions**.
3. Run database upgrades if prompted.

### API Deployment

For Apple Wallet updates and push notifications to work correctly, the API entry point must be accessible in your webroot.
The extension attempts to install `civi-wallet-api.php` automatically. If it fails, copy it manually:

```bash
cp extensions/one.jonathandhn.wallet/extern/api.php /path/to/webroot/civi-wallet-api.php
```

## Configuration

Navigate to **Administer > CiviEvent > Wallet Settings**.

### Geocoding & GPS (Optional but recommended)

To display passes dynamically on the user's lock screen when they are near the event venue, CiviCRM must generate GPS coordinates for event locations. 
If your CiviCRM instance does not automatically generate `geo_code_1` (Latitude) and `geo_code_2` (Longitude) for addresses, you must enable a Geocoding provider:
1. Navigate to **Administer > System Settings > Mapping and Geocoding** (`civicrm/admin/setting/mapping`).
2. Select a **Geocoding Provider** (e.g., Google, OpenStreetMap) and enter your API Key if required.
3. Once enabled, any new or updated Event Location will automatically fetch and save its GPS coordinates, which will then be passed to Apple and Google Wallet.

### Apple Wallet Setup

To generate Apple Passes, you need an Apple Developer Account and a Pass Type Certicate.

1. **Pass Type ID**: Register a Pass Type ID in your Apple Developer Console (e.g., `pass.org.example.event`).
2. **Apple Team ID**: Your Apple Developer Team ID (10 chars).
3. **Certificate**: 
   - Generate a Certificate for your Pass Type ID.
   - Download it and export it as `.p12` (including the private key) using Keychain Access or OpenSSL.
   - Upload the `.p12` file to your server (outside the webroot is recommended for security).
   - Configure the path and password in the CiviCRM Wallet Settings.

### Google Wallet Setup

To generate Google Passes, you need a Google Pay API for Passes account.

1. **Issuer ID**: Create a project in the [Google Pay & Wallet Console](https://pay.google.com/business/console). Note your Issuer ID (numeric).
2. **Service Account**:
   - Create a Service Account in Google Cloud Console.
   - Enable the "Google Wallet API".
   - Grant access to your Issuer Account in the Google Pay Console using the Service Account Email.
   - Download the Service Account Key key as `.json`.
   - Configure the path to the JSON file in CiviCRM Wallet Settings.

## Usage

### Event Configuration

The extension adds a "Wallet Settings" custom group to Events.
- **Wallet Image**: Upload a visual (strip image) for the pass (approx 375x98px for Apple).
- **Wallet Background Color**: Override the default background color (Hex code, e.g., `#FF0000`).

### Participant Workflow

- Participants receive a download link or QR Code in their email confirmation by inserting the following tokens in Message Templates.

HTML composite tokens:
- `{contact.wallet_links}`: Membership Apple/Google buttons as HTML.
- `{participant.wallet_links}`: Event ticket Apple/Google buttons as HTML.
- `{contact.wallet_links_donor}`: Donor card Apple/Google buttons as HTML.
- `{contact.qr_membership}`: Membership QR code as an HTML `<img>` tag.
- `{participant.qr_participant}`: Event ticket QR code as an HTML `<img>` tag.
- `{contact.qr_donor}`: Donor QR code as an HTML `<img>` tag.

Raw membership tokens:
- `{contact.wallet_apple_membership_url}`: Apple Wallet URL for a membership card.
- `{contact.wallet_google_membership_url}`: Google Wallet URL for a membership card.
- `{contact.qr_membership_url}`: Raw QR image URL for a membership card.

Raw donor tokens:
- `{contact.wallet_apple_donor_url}`: Apple Wallet URL for a donor card.
- `{contact.wallet_google_donor_url}`: Google Wallet URL for a donor card.
- `{contact.qr_donor_url}`: Raw QR image URL for a donor card.

Raw participant tokens:
- `{participant.wallet_apple_url}`: Apple Wallet URL for an event ticket.
- `{participant.wallet_google_url}`: Google Wallet URL for an event ticket.
- `{participant.qr_participant_url}`: Raw QR image URL for an event ticket.

- The raw URL tokens are intended for custom MJML/HTML templates where you want to place Apple, Google, or QR assets independently.
- Scanning the QR Code on the pass allows check-in. The recommended app is **[Civi Scan Webapp](https://github.com/jonathandhn/one.jonathandhn.scan)**, or standard QR lookup.

### Donor Cards
You can generate a pure Donor card for any Contact using the `{contact.wallet_links_donor}` token. 
This card is independent of memberships. By default, it automatically fetches and displays the last 3 completed Contributions (Donations) on the back of the Apple Pass or as text modules on the Google Pass, along with quick links to update their profile or make a new donation.

## Maintenance

### Force a pass update

You can enqueue a manual refresh with `cv ev`:

```bash
# Refresh all passes linked to an Event
cv ev 'CRM_Wallet_BAO_Pass::updatePasses("Event", 123);'

# Refresh one event ticket
cv ev 'CRM_Wallet_BAO_Pass::updatePasses("Participant", 456);'

# Refresh one membership card
cv ev 'CRM_Wallet_BAO_Pass::updatePasses("Membership", 789);'

# Refresh one donor card (contact id)
cv ev 'CRM_Wallet_BAO_Pass::updatePasses("Donor", 321);'

# Refresh one contact-based card
cv ev 'CRM_Wallet_BAO_Pass::updatePasses("Contact", 321);'
```

These commands enqueue work in the SQL queue `one.jonathandhn.wallet.updates`.

### Process pending pass updates

The queue is normally processed by cron. Each run processes up to `50` items.

You can force one run manually with:

```bash
cv ev 'civi_wallet_civicrm_cron(NULL);'
```

You can inspect the number of pending items with:

```bash
cv ev '$q = CRM_Queue_Service::singleton()->create(["type" => "Sql", "name" => "one.jonathandhn.wallet.updates", "runner" => "task"]); return $q->numberOfItems();' --out=shell
```

## Developer API (Hooks)

You can customize the QR Code content dynamically (for example, to inject a cryptographic signature, JSON Web Token (JWT), or HMAC) without forking the extension.

Use the `civicrm_wallet_barcode_alter` hook in your custom extension:

```php
/**
 * Implements hook_civicrm_wallet_barcode_alter().
 *
 * @param string $barcodeValue The string encoded in the QR Code
 * @param string $entityType   'Participant' or 'Membership'
 * @param int    $entityId     The ID of the Participant or Membership
 */
function myextension_civicrm_wallet_barcode_alter(&$barcodeValue, $entityType, $entityId) {
    if ($entityType === 'Participant') {
        // Example: Inject a signed JSON Web Token instead of the raw ID
        $payload = [
            'pid' => $entityId,
            'exp' => time() + 86400
        ];
        $secretKey = 'your-very-secret-key';
        
        // CiviCRM bundles \Firebase\JWT\JWT natively, so you can use it directly!
        $barcodeValue = \Firebase\JWT\JWT::encode($payload, $secretKey, 'HS256');
        
        // Example: Simple HMAC signature
        $signature = hash_hmac('sha256', (string)$entityId, $secretKey);
        $barcodeValue = json_encode(['id' => $entityId, 'sig' => $signature]);
    }
}
```

This hook alters the QR Code on:
1. Apple Wallet Passes
2. Google Wallet Passes
3. Local QR Codes generated via the `{wallet.qr_*}` tokens.

### Modifying the Pass Structure (e.g., Donor Cards)

If you want to inject custom data directly onto the Apple Pass (like the back of the card) or the Google Pass (like text modules), use the `civicrm_wallet_pass_alter` hook. This is perfect for creating Donor Cards that list recent contributions.

```php
/**
 * Implements hook_civicrm_wallet_pass_alter().
 *
 * @param array  $passData   The data array being passed to Apple or Google Wallet API
 * @param string $entityType 'Participant' or 'Membership'
 * @param int    $entityId   The ID of the Participant or Membership
 * @param string $platform   'apple' or 'google'
 */
function myextension_civicrm_wallet_pass_alter(&$passData, $entityType, $entityId, $platform) {
    if ($entityType === 'Membership') {
        // Example: Add recent donations to the back of the Apple Wallet card
        // Note: You must query your actual Contributions via CiviCRM APIv4 here!
        $recentDonations = "12/05/2026 : 50€\n15/12/2025 : 100€";

        if ($platform === 'apple') {
            $passData['storeCard']['backFields'][] = [
                'key' => 'recent-donations',
                'label' => 'Derniers dons',
                'value' => $recentDonations
            ];
        } elseif ($platform === 'google') {
            $passData['textModulesData'][] = [
                'header' => 'Derniers dons',
                'body' => $recentDonations
            ];
        }
    }
}
```

## Visibility

Wallet configuration fields are automatically hidden from public Event Info and Registration pages to keep the interface clean for participants. They are only visible to administrators in the backend.

## License

AGPL-3.0
