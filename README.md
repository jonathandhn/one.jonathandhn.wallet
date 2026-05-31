# CiviWallet - Apple & Google Wallet for CiviCRM

Generate Apple Wallet (`.pkpass`) and Google Wallet passes for CiviCRM Event Participants.
This extension automatically generates wallet passes including QR codes, event details, venue location, and custom styling.

## Repository
[https://github.com/jonathandhn/org.jonathandhn.wallet](https://github.com/jonathandhn/org.jonathandhn.wallet)

## Features

- **Apple Wallet**: Generates signed `.pkpass` files used in Apple Wallet.
- **Google Wallet**: Creates "Event Ticket" objects via Google Wallet API and generates "Save to Android Pay" links.
- **QR Codes**: Encodes participant ID for check-in scanning.
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

- Participants receive a download link in their email confirmation (requires inserting the `{wallet_links}` token in Message Templates).
- Scanning the QR Code on the pass allows check-in. The recommended app is **[Civi Scan Webapp](https://github.com/jonathandhn/one.jonathandhn.scan)**, or standard QR lookup.

## Visibility

Wallet configuration fields are automatically hidden from public Event Info and Registration pages to keep the interface clean for participants. They are only visible to administrators in the backend.

## License

AGPL-3.0
