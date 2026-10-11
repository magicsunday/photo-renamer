<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Exception;

use RuntimeException;

/**
 * Signals a planned pre-mutation stop while PHP still has room to report the
 * incomplete analysis and release run-scoped state. This is not a PHP/native
 * allocation or kernel-OOM handler and never certifies a partial plan as safe.
 */
final class AnalysisMemoryBudgetExceededException extends RuntimeException
{
}
