#!/usr/bin/env bash

set -Eeuo pipefail

readonly ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PACKAGE_NAME="one.jonathandhn.wallet"
readonly OUTPUT_ZIP="${ROOT_DIR}/${PACKAGE_NAME}.zip"
readonly WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PACKAGE_NAME}.build.XXXXXX")"
readonly SOURCE_DIR="${WORK_DIR}/source"
readonly PACKAGE_DIR="${WORK_DIR}/package/${PACKAGE_NAME}"
readonly TEMP_ZIP="${WORK_DIR}/${PACKAGE_NAME}.zip"

cleanup() {
  rm -rf -- "${WORK_DIR}"
}
trap cleanup EXIT INT TERM

fail() {
  printf 'Build failed: %s\n' "$1" >&2
  exit 1
}

for command in composer php zip unzip; do
  command -v "${command}" >/dev/null 2>&1 || fail "missing command: ${command}"
done

readonly SCOPER="${ROOT_DIR}/vendor/bin/php-scoper"
[[ -x "${SCOPER}" ]] || fail "run composer install before building"

readonly VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "${ROOT_DIR}/info.xml")"
[[ -n "${VERSION}" ]] || fail "info.xml does not contain a version"
if [[ -n "${CI_COMMIT_TAG:-}" && "${CI_COMMIT_TAG#v}" != "${VERSION}" ]]; then
  fail "tag ${CI_COMMIT_TAG} does not match info.xml version ${VERSION}"
fi

printf 'Preparing production dependencies\n'
mkdir -p "${SOURCE_DIR}" "$(dirname -- "${PACKAGE_DIR}")"
cp "${ROOT_DIR}/composer.json" "${ROOT_DIR}/composer.lock" "${SOURCE_DIR}/"
cp -R "${ROOT_DIR}/vendor" "${SOURCE_DIR}/vendor"
COMPOSER_DISABLE_NETWORK=1 composer install \
  --working-dir="${SOURCE_DIR}" \
  --no-dev \
  --classmap-authoritative \
  --no-interaction \
  --no-progress \
  --no-plugins \
  --quiet

for directory in Civi CRM; do
  cp -R "${ROOT_DIR}/${directory}" "${SOURCE_DIR}/"
done
for file in civi_wallet.php civi_wallet.civix.php debug_queue.php scoper.inc.php; do
  cp "${ROOT_DIR}/${file}" "${SOURCE_DIR}/"
done

printf 'Scoping PHP dependencies\n'
php -d error_reporting='E_ALL & ~E_DEPRECATED' -d memory_limit=-1 \
  "${SCOPER}" add-prefix \
  --config="${SOURCE_DIR}/scoper.inc.php" \
  --output-dir="${PACKAGE_DIR}" \
  --force \
  --no-interaction \
  --quiet
php "${ROOT_DIR}/scripts/fix_scoped_autoload.php" "${PACKAGE_DIR}" >/dev/null

# PHP-Scoper only copies PHP files. PKPass requires Apple's public WWDR
# intermediate certificate at runtime to sign passes.
readonly WWDR_CERTIFICATE="vendor/pkpass/pkpass/src/Certificate/AppleWWDRCA.pem"
mkdir -p "${PACKAGE_DIR}/$(dirname -- "${WWDR_CERTIFICATE}")"
cp "${SOURCE_DIR}/${WWDR_CERTIFICATE}" "${PACKAGE_DIR}/${WWDR_CERTIFICATE}"

mkdir -p "${PACKAGE_DIR}/licenses"
while IFS= read -r license_file; do
  relative_path="${license_file#${SOURCE_DIR}/vendor/}"
  mkdir -p "${PACKAGE_DIR}/licenses/$(dirname -- "${relative_path}")"
  cp "${license_file}" "${PACKAGE_DIR}/licenses/${relative_path}"
done < <(find "${SOURCE_DIR}/vendor" -type f \( -name LICENSE -o -name NOTICE -o -name COPYING \) -print)

php -r '
  $packageDir = $argv[1];
  require $packageDir . "/vendor/autoload.php";
  $requiredClasses = [
    "Civi\\Wallet\\Vendor\\PKPass\\PKPass",
    "Civi\\Wallet\\Vendor\\Google\\Client",
    "Civi\\Wallet\\Vendor\\Google\\Service\\Walletobjects",
    "Civi\\Wallet\\Vendor\\Firebase\\JWT\\JWT",
    "Civi\\Wallet\\Vendor\\chillerlan\\QRCode\\QRCode",
  ];
  foreach ($requiredClasses as $requiredClass) {
    if (!class_exists($requiredClass)) {
      fwrite(STDERR, "Build failed: scoped class is not autoloadable: {$requiredClass}\n");
      exit(1);
    }
  }
' "${PACKAGE_DIR}"

readonly RUNTIME_RESOURCES=(
  info.xml
  l10n
  settings
  schema
  templates
  xml
  managed
  resources
  mixin
  extern
  README.md
)
for item in "${RUNTIME_RESOURCES[@]}"; do
  [[ -e "${ROOT_DIR}/${item}" ]] || fail "missing runtime resource: ${item}"
  cp -R "${ROOT_DIR}/${item}" "${PACKAGE_DIR}/"
done
mkdir -p "${PACKAGE_DIR}/scripts"
cp "${ROOT_DIR}/scripts/simulate-apple-wallet.php" "${PACKAGE_DIR}/scripts/"

readonly REQUIRED_PACKAGE_FILES=(
  info.xml
  civi_wallet.php
  CRM/Wallet/BAO/Pass.php
  Civi/Wallet/ParticipantTokenSubscriber.php
  schema/WalletPass.entityType.php
  schema/WalletDevice.entityType.php
  settings/Wallet.setting.php
  templates/CRM/Wallet/Form/Config.tpl
  l10n/fr_FR.mo
  scripts/simulate-apple-wallet.php
  vendor/autoload.php
  vendor/pkpass/pkpass/src/Certificate/AppleWWDRCA.pem
)
for file in "${REQUIRED_PACKAGE_FILES[@]}"; do
  [[ -f "${PACKAGE_DIR}/${file}" ]] || fail "missing packaged file: ${file}"
done

printf 'Packaging %s %s\n' "${PACKAGE_NAME}" "${VERSION}"
(
  cd "${WORK_DIR}/package"
  zip -qr "${TEMP_ZIP}" "${PACKAGE_NAME}" -x '*.DS_Store' '*__MACOSX*'
)
unzip -tq "${TEMP_ZIP}" >/dev/null || fail "ZIP integrity check failed"
if ! unzip -Z1 "${TEMP_ZIP}" | awk -F/ -v root="${PACKAGE_NAME}" '$1 != root {exit 1}'; then
  fail "ZIP contains a path outside ${PACKAGE_NAME}/"
fi
if unzip -Z1 "${TEMP_ZIP}" | grep -Eiq '\.(p12|p8|key)$|/(client_secret_[^/]*\.json|service-account\.json|auth\.json)$'; then
  fail "ZIP contains a private certificate or credential file"
fi

mv -f -- "${TEMP_ZIP}" "${OUTPUT_ZIP}"
if command -v sha256sum >/dev/null 2>&1; then
  readonly CHECKSUM="$(sha256sum "${OUTPUT_ZIP}" | awk '{print $1}')"
else
  readonly CHECKSUM="$(shasum -a 256 "${OUTPUT_ZIP}" | awk '{print $1}')"
fi

printf 'Artifact: %s\n' "${OUTPUT_ZIP}"
printf 'SHA-256: %s\n' "${CHECKSUM}"
