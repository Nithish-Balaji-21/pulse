<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a production's offloaded CFT would exceed what that shift produced.
 *
 * Both save paths check this before writing anything. This exception is the backstop
 * for the window between that check and the write — a concurrent offload, or a future
 * caller that writes the production row without checking first. It is thrown inside the
 * save transaction so the production row and its loads roll back together, rather than
 * leaving cft_produced lowered against loads that were never adjusted.
 */
class PithDistributionException extends RuntimeException {}
