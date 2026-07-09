<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Fixtures;

use MagicSunday\Renamer\Service\Output\DiffHighlighter;
use MagicSunday\Renamer\Service\Output\OutputDecisionLogRenderer;
use MagicSunday\Renamer\Service\Output\OutputEntryPresenter;
use MagicSunday\Renamer\Service\Output\OutputSkipReasonDecider;
use MagicSunday\Renamer\Service\Output\OutputSummaryRowBuilder;
use MagicSunday\Renamer\Service\Output\SkipReasonFormatter;
use MagicSunday\Renamer\Service\RenameOutputRenderer;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates fully wired RenameOutputRenderer instances for tests.
 *
 * The production container autowires the output module, but many unit and
 * integration tests instantiate the renderer manually to keep setup explicit.
 * This fixture keeps those tests aligned with the constructor-DI shape without
 * spreading the small output object graph across every test file.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputRendererFactory
{
    /**
     * Creates a RenameOutputRenderer with its concrete output collaborators.
     *
     * @param SymfonyStyle $io Console IO used by the renderer under test
     *
     * @return RenameOutputRenderer Fully wired renderer instance for tests
     */
    public static function create(SymfonyStyle $io): RenameOutputRenderer
    {
        $diffHighlighter = new DiffHighlighter();

        return new RenameOutputRenderer(
            $io,
            new OutputDecisionLogRenderer(),
            new OutputEntryPresenter(
                new OutputSkipReasonDecider(OutputSkipReasonRuleFactory::createDefaultRules()),
                new SkipReasonFormatter(),
                $diffHighlighter,
            ),
            new OutputSummaryRowBuilder(),
            $diffHighlighter,
        );
    }
}
