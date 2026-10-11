# CiviWallet - QR codes, Apple & Google Wallet for CiviCRM

Generate Apple Wallet (`.pkpass`) and Google Wallet passes and QR codes for
CiviCRM event participants, memberships, and donors. The extension supports
event details, venue locations, membership validity, donor information, and
custom styling.

## Repository

- Canonical repository: [CiviCRM GitLab](https://lab.civicrm.org/extensions/wallet)
- GitHub mirror: [jonathandhn/one.jonathandhn.wallet](https://github.com/jonathandhn/one.jonathandhn.wallet)

## Release package

Install the single scoped package named `one.jonathandhn.wallet.zip`. GitHub
releases publish this file with its SHA-256 checksum. GitLab releases expose a
stable download endpoint at:

```text
https://lab.civicrm.org/extensions/wallet/-/releases/permalink/latest/downloads/wallet.zip
```

Source-code archives are not installable release packages because they do not
contain the scoped production dependencies.

## Features

- **Apple Wallet**: Generates signed `.pkpass` files used in Apple Wallet.
- **Google Wallet**: Creates wallet objects through the Google Wallet API and generates "Add to Google Wallet" links.
- **QR Codes**: Generates native QR Codes for emails and PDFs locally (no external dependencies). Event tickets can stay in simple PID-compatible mode or switch to a signed HMAC payload.
- **Automatic Updates**: 
  - Updates Apple Wallet passes via Push Notifications when Event details change.
  - Updates Google Wallet passes automatically in the cloud.
- **Customization**:
  - Event Image & Background Color overrides via Custom Fields.
  - Venue location and GPS coordinates.

## Requirements

- CiviCRM 6.16
- PHP 8.4 or 8.5
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

You can test the public endpoint without an Apple device by using an existing
Apple pass record. The simulator verifies registration, update polling, pass
download, the current `barcodes` and `relevantDates` payloads, logging, and unregistration. It does
not validate delivery through APNs because it uses a synthetic push token.

```bash
APPLE_WALLET_AUTH_TOKEN='pass-authentication-token' \
php scripts/simulate-apple-wallet.php \
  --base-url=https://example.org/civi-wallet-api.php \
  --pass-type-id=pass.org.example.event \
  --serial-number=existing-pass-serial
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

To generate Apple passes, you need an Apple Developer account and a Pass Type certificate.

1. **Pass Type ID**: Register a Pass Type ID in your Apple Developer Console (e.g., `pass.org.example.event`).
2. **Apple Team ID**: Your Apple Developer Team ID (10 chars).
3. **Certificate**: 
   - Generate a Certificate for your Pass Type ID.
   - Download it and export it as `.p12` (including the private key) using Keychain Access or OpenSSL.
   - Upload the `.p12` file to your server (outside the webroot is recommended for security).
   - Configure the path and password in the CiviCRM Wallet Settings.

### Google Wallet Setup

To generate Google passes, you need access to the Google Wallet API.

1. **Issuer ID**: Create a project in the [Google Pay & Wallet Console](https://pay.google.com/business/console). Note your Issuer ID (numeric).
2. **Service Account**:
   - Create a Service Account in Google Cloud Console.
   - Enable the "Google Wallet API".
   - Grant access to your Issuer Account in the Google Pay Console using the Service Account Email.
   - Download the Service Account Key key as `.json`.
   - Configure the path to the JSON file in CiviCRM Wallet Settings.

## Usage

### Event Configuration

The extension adds a **Wallet** tab on Event configuration pages for event-level visual overrides.
- **Wallet Image**: Upload a visual override for the event pass.
- **Wallet Background Color**: Override the default background color for this event only (Hex code, e.g., `#FF0000`).

### Image Recommendations

Use the following source assets when preparing Wallet images. These source
recommendations are intended for the MediaScaler rendition pipeline; the
current release does not yet generate every target variant automatically.

| Source asset | Preferred format | Recommended size | Composition |
| --- | --- | --- | --- |
| Brand icon | PNG | At least 660 x 660 px (1:1) | Keep the mark inside the central 70%; Google applies a circular mask. |
| Wide brand logo | Transparent PNG | 1280 x 400 px (16:5) | Use a light logo on dark passes and a dark logo on light passes. |
| Event image master | PNG or high-quality JPEG | 2064 x 1624 px preferred; 1032 x 812 px minimum | Keep the subject near the selected focus point, leave vertical breathing room, and do not embed text. |

The event master is expected to produce these platform renditions:

- Google Wallet hero image: 1032 x 812 px, approximately 5:4, preferably PNG.
- Apple Wallet legacy event strip: 375 x 98 px, plus 750 x 196 px (`@2x`)
  and 1125 x 294 px (`@3x`) PNG variants.

Apple and Google may crop images on different devices. Important content must
therefore remain around the focus point. Titles, dates, sponsor names, QR codes,
and other essential information belong in pass fields rather than inside the
image.

References: [Apple Wallet image guidance](https://developer.apple.com/design/human-interface-guidelines/wallet),
[Apple legacy pass dimensions](https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Creating.html),
and [Google Wallet event-ticket brand guidelines](https://developers.google.com/wallet/tickets/events/resources/brand-guidelines).

### Participant Workflow

- Participants receive a download link or QR Code in their email confirmation by inserting the following tokens in Message Templates.
- Event ticket QR generation now has two modes:
  - `simple`: keeps maximum compatibility with raw participant IDs, exports, spreadsheets, N8N, and third-party senders.
  - `signed`: emits a compact HMAC-signed `cw2` payload for scanner tooling that can verify authenticity.
- Apple Wallet, Google Wallet, and local event QR images all use the same participant QR payload mode.

HTML composite tokens:
- `{wallet.membership_links}`: Membership Apple/Google buttons as HTML.
- `{wallet.participant_links}`: Event ticket Apple/Google buttons as HTML.
- `{wallet.donor_links}`: Donor card Apple/Google buttons as HTML.
- `{wallet.membership_qr}`: Membership QR code as an HTML `<img>` tag.
- `{wallet.participant_qr}`: Event ticket QR code as an HTML `<img>` tag.
- `{wallet.donor_qr}`: Donor QR code as an HTML `<img>` tag.
- `{wallet.contact_qr}`: Contact pass QR code as an HTML `<img>` tag.

Raw membership tokens:
- `{wallet.membership_apple_url}`: Apple Wallet URL for a membership card.
- `{wallet.membership_google_url}`: Google Wallet URL for a membership card.
- `{wallet.membership_qr_url}`: Raw QR image URL for a membership card.

Raw donor tokens:
- `{wallet.donor_apple_url}`: Apple Wallet URL for a donor card.
- `{wallet.donor_google_url}`: Google Wallet URL for a donor card.
- `{wallet.donor_qr_url}`: Raw QR image URL for a donor card.

Raw participant tokens:
- `{wallet.participant_apple_url}`: Apple Wallet URL for an event ticket.
- `{wallet.participant_google_url}`: Google Wallet URL for an event ticket.
- `{wallet.participant_qr_url}`: Raw QR image URL for an event ticket.

Raw contact pass token:
- `{wallet.contact_qr_url}`: Raw QR image URL for a contact pass or badge.

- The raw URL tokens are intended for custom MJML/HTML templates where you want to place Apple, Google, or QR assets independently.
- Event confirmation templates may URL-encode raw participant URL tokens when they are inserted directly inside an HTML attribute. In that context, prefer `{wallet.participant_qr}` unless you have confirmed the template keeps raw `{...}` token syntax intact in source mode.
- Event reminder templates must use the full participant token name, for example `{wallet.participant_qr}` or `{wallet.participant_qr_url}`. A malformed token such as `{wallet.participant_qr.}` is invalid Smarty syntax and will fail before token replacement.
- Participant tokens are intentionally empty in generic contact context to avoid resolving the wrong registration when a contact has multiple event participations.
- Tokens are only exposed in CiviCRM auto-complete when their underlying context and channel are enabled. For example, if Apple and Google are off, wallet-link tokens are hidden; if `membership` or `donor` are off, those token families are hidden as well.
- Scanning the QR Code on the pass allows check-in. Simple mode works with any PID-aware scanner. Signed mode stays compatible with PID extraction, and CiviScan session mode can additionally verify the HMAC signature server-side.

### Donor Cards
You can generate a pure Donor card for any Contact using the `{wallet.donor_links}` token.
This card is independent of memberships. By default, it automatically fetches and displays the last 3 completed Contributions (Donations) on the back of the Apple Pass or as text modules on the Google Pass, along with quick links to update their profile or make a new donation.

## Maintenance

### Development checks

The local quality gate checks maintained extension code with Civilint and
PHPStan level 5 against CiviCRM 6.16:

```bash
composer check
```

The command expects sibling checkouts named `civicrm-buildkit` and
`civicrm-standalone`. Set `CIVICRM_BUILDKIT` when buildkit is installed in a
different location. Generated Civix, DAO, APIv4, and upgrader files are not
rewritten by the formatting command.

To apply Civilint's mechanical fixes to maintained files:

```bash
composer civilint:fix
```

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

You can customize the QR Code content dynamically (for example, to inject another cryptographic signature format or a JWT) without forking the extension.

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
        // Example: replace the built-in payload with a JWT
        $payload = [
            'pid' => $entityId,
            'exp' => time() + 86400
        ];
        $secretKey = 'your-very-secret-key';

        $barcodeValue = \Firebase\JWT\JWT::encode($payload, $secretKey, 'HS256');
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

## Support the project

CiviWallet is independently developed and currently has no institutional or
corporate sponsor. If your organization benefits from this work, you can help
fund its maintenance, documentation, Apple and Google Wallet compatibility
testing, or future development.

To sponsor work on Wallet or request professional support, please
[visit Jonathan Dahan's website](https://www.jonathan.paris/en) or use the
[contact form](https://www.jonathan.paris/en/contact).

## License

CiviWallet is distributed under the
[GNU Affero General Public License v3.0 or later](https://civicrm.org/extensions/LICENSE.txt).
