<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use MagicSunday\Renamer\Service\Output\OutputSkipReasonRuleInterface;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\CandidateOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\DefaultOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\FallbackOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\ReviewOutputSkipReasonRule;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonRules\WarningOutputSkipReasonRule;

/**
 * Creates the default ordered skip-reason rule set for tests.
 *
 * Production wiring injects the rule list through the Symfony container. Tests
 * that instantiate the output module manually still need the same concrete
 * policy stack, and this fixture keeps that setup centralized.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputSkipReasonRuleFactory
{
    /**
     * Returns the concrete skip-reason rules used by the output decider.
     *
     * The decider itself owns priority sorting, so the returned order only has
     * to contain the full rule set, not the final precedence.
     *
     * @return list<OutputSkipReasonRuleInterface> Concrete skip-reason rules for tests
     */
    public static function createDefaultRules(): array
    {
        return [
            new CandidateOutputSkipReasonRule(),
            new ReviewOutputSkipReasonRule(),
            new WarningOutputSkipReasonRule(),
            new FallbackOutputSkipReasonRule(),
            new DefaultOutputSkipReasonRule(),
        ];
    }
}
