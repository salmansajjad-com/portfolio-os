<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to alter a distribution run that has already been
 * approved or voided. Approved figures are what partners were actually paid, so
 * corrections are new adjusting entries rather than edits.
 */
class LockedDistributionException extends RuntimeException {}
