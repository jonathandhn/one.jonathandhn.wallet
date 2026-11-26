<?php

namespace Civi\Wallet;

use RuntimeException;

/**
 * A provider configuration failure which should remain retryable indefinitely.
 */
final class ProviderConfigurationException extends RuntimeException {

}
