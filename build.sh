#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status.
set -e

echo "=== Building the distributable release ==="

# Directories.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${ROOT_DIR}"
DIST_DIR="${ROOT_DIR}/one.jonathandhn.wallet"
TMP_DIR="${ROOT_DIR}/dist_tmp"

# 1. Clean previous build directories.
echo "Cleaning previous build directories..."
rm -rf "${DIST_DIR}" "${TMP_DIR}"
mkdir -p "${TMP_DIR}"

# 2. Prepare production dependencies in dist_tmp.
echo "Installing production dependencies..."
cp "${ROOT_DIR}/composer.json" "${ROOT_DIR}/composer.lock" "${TMP_DIR}/"
if [ -d "${ROOT_DIR}/vendor" ]; then
    echo "Reusing the local vendor directory in offline mode..."
    cp -R "${ROOT_DIR}/vendor" "${TMP_DIR}/"
    COMPOSER_DISABLE_NETWORK=1 composer install --no-dev --no-interaction --prefer-dist --working-dir="${TMP_DIR}"
else
    composer install --no-dev --no-interaction --prefer-dist --working-dir="${TMP_DIR}"
fi

# 3. Copy PHP source files to scope into dist_tmp.
echo "Copying PHP sources for scoping..."
cp -r Civi "${TMP_DIR}/"
cp -r CRM "${TMP_DIR}/"
cp civi_wallet.php civi_wallet.civix.php debug_queue.php "${TMP_DIR}/"
cp scoper.inc.php "${TMP_DIR}/"

# 4. Run PHP-Scoper.
echo "Running PHP-Scoper..."
php -d error_reporting="E_ALL & ~E_DEPRECATED" -d memory_limit=-1 "${ROOT_DIR}/vendor/bin/php-scoper" add-prefix --config="${TMP_DIR}/scoper.inc.php" --output-dir="${DIST_DIR}" --force

# 4b. Repair the scoped Composer autoloader.
echo "Repairing the scoped Composer autoloader..."
php "${ROOT_DIR}/scripts/fix_scoped_autoload.php" "${DIST_DIR}"

# 5. Copy non-PHP resources and configuration.
echo "Copying resources and configuration..."
RESOURCES=(
    "info.xml"
    "settings"
    "sql"
    "templates"
    "xml"
    "managed"
    "resources"
    "mixin"
    "extern"
)

for item in "${RESOURCES[@]}"; do
    if [ -e "${ROOT_DIR}/${item}" ]; then
        echo " - Copying ${item}..."
        cp -R "${ROOT_DIR}/${item}" "${DIST_DIR}/"
    fi
done

# 6. Clean the temporary directory.
echo "Cleaning the temporary directory..."
rm -rf "${TMP_DIR}"

# 7. Create the ZIP archive.
ZIP_FILE="${ROOT_DIR}/one.jonathandhn.wallet.zip"
echo "Creating ZIP archive: ${ZIP_FILE}..."
rm -f "${ZIP_FILE}"
cd "${ROOT_DIR}"
zip -r "${ZIP_FILE}" "one.jonathandhn.wallet" -x "*.DS_Store" "*__MACOSX*"

echo "CiviWallet package created successfully: ${ZIP_FILE}"
