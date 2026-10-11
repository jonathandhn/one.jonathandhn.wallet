<?php

declare(strict_types = 1);

const WALLET_QA_ACTIONS = ['civilint', 'civilint-fix', 'phpstan'];

$projectRoot = dirname(__DIR__);
$action = $argv[1] ?? '';

if (!in_array($action, WALLET_QA_ACTIONS, TRUE)) {
  fwrite(STDERR, "Usage: php scripts/qa.php <civilint|civilint-fix|phpstan>\n");
  exit(2);
}

if ($action === 'phpstan') {
  $phpstan = $projectRoot . '/vendor/bin/phpstan';
  if (!is_file($phpstan)) {
    fwrite(STDERR, "PHPStan is missing. Run composer install in CiviWallet.\n");
    exit(2);
  }

  $civiRoot = dirname($projectRoot) . '/civicrm-standalone/core';
  $civiAutoload = $civiRoot . '/vendor/autoload.php';
  $civiVersionFile = $civiRoot . '/xml/version.xml';
  if (!is_file($civiAutoload) || !is_file($civiVersionFile)) {
    fwrite(
        STDERR,
        "CiviCRM source is missing. Expected the 6.16 checkout under ../civicrm-standalone/core.\n",
    );
    exit(2);
  }

  $versionXml = file_get_contents($civiVersionFile);
  if (!is_string($versionXml)
        || preg_match('/<version_no>([^<]+)<\/version_no>/', $versionXml, $matches) !== 1
    ) {
    fwrite(STDERR, "Unable to read the local CiviCRM version.\n");
    exit(2);
  }
  if (version_compare($matches[1], '6.16.0', '<')) {
    fwrite(STDERR, sprintf("CiviCRM %s is too old; Wallet requires 6.16.0 or newer.\n", $matches[1]));
    exit(2);
  }

  exit(walletRun([
    PHP_BINARY,
    $phpstan,
    'analyse',
    '--configuration=' . $projectRoot . '/phpstan.neon.dist',
    '--no-progress',
    '--debug',
    '--memory-limit=1G',
  ], $projectRoot));
}

$civilint = walletFindCivilint($projectRoot);
if ($civilint === NULL) {
  fwrite(
        STDERR,
        "Civilint is missing. Install civicrm-buildkit beside the repository or set CIVICRM_BUILDKIT.\n",
    );
  exit(2);
}

$phpFiles = [
  ...walletPhpFiles($projectRoot . '/Civi/Wallet'),
  $projectRoot . '/CRM/Wallet/BAO/Pass.php',
  ...walletPhpFiles($projectRoot . '/CRM/Wallet/Form'),
  ...walletPhpFiles($projectRoot . '/CRM/Wallet/Page'),
  ...walletPhpFiles($projectRoot . '/CRM/Wallet/PassType'),
  ...walletPhpFiles($projectRoot . '/CRM/Wallet/Utils'),
  ...walletPhpFiles($projectRoot . '/managed'),
  ...walletPhpFiles($projectRoot . '/settings'),
  $projectRoot . '/civi_wallet.php',
  $projectRoot . '/extern/api.php',
  $projectRoot . '/scripts/simulate-apple-wallet.php',
  __FILE__,
];
sort($phpFiles);

putenv('PHPRC=' . $projectRoot . '/tools/civilint-php.ini');
$command = [$civilint];
if ($action === 'civilint-fix') {
  $command[] = '--fix';
}
array_push($command, ...$phpFiles);
exit(walletRun($command, $projectRoot));

/**
 * @return list<string>
 */
function walletPhpFiles(string $directory): array {
  if (!is_dir($directory)) {
    return [];
  }

  $files = [];
  $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
  foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
      $files[] = $file->getPathname();
    }
  }
  sort($files);
  return $files;
}

function walletFindCivilint(string $projectRoot): ?string {
  $buildkit = getenv('CIVICRM_BUILDKIT');
  if (is_string($buildkit) && $buildkit !== '') {
    $candidate = rtrim($buildkit, DIRECTORY_SEPARATOR) . '/bin/civilint';
    if (is_executable($candidate)) {
      return $candidate;
    }
  }

  $candidate = dirname($projectRoot) . '/civicrm-buildkit/bin/civilint';
  if (is_executable($candidate)) {
    return $candidate;
  }

  $path = getenv('PATH');
  if (!is_string($path)) {
    return NULL;
  }
  foreach (explode(PATH_SEPARATOR, $path) as $directory) {
    $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . '/civilint';
    if (is_executable($candidate)) {
      return $candidate;
    }
  }

  return NULL;
}

/**
 * @param list<string> $command
 */
function walletRun(array $command, string $workingDirectory): int {
  $descriptorSpec = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
  ];
  $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
  if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start quality command.\n");
    return 2;
  }

  return proc_close($process);
}
