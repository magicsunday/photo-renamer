<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Output;

use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\Collection\AssetGroupCollection;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use MagicSunday\Renamer\Model\Execution\ExecutionPlan;
use MagicSunday\Renamer\Service\Output\OutputDecisionLogRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

use function str_contains;
use function substr_count;

/**
 * Verifies the dedicated renderer for grouped decision-log output.
 *
 * The tests focus on the shared console formatting contract: the section header
 * should appear once, groups without log entries should stay hidden, and both
 * the asset-group and execution-plan views should render the same grouped style.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(OutputDecisionLogRenderer::class)]
#[UsesClass(AssetGroup::class)]
#[UsesClass(AssetGroupCollection::class)]
#[UsesClass(ExecutionPlan::class)]
#[UsesClass(ExecutionGroup::class)]
#[UsesClass(ConsoleText::class)]
final class OutputDecisionLogRendererTest extends TestCase
{
    /**
     * Verifies that the asset-group view renders only non-empty groups under one
     * shared "Decision Log" heading.
     */
    #[Test]
    public function renderAssetGroupDecisionLogOutputsOnlyGroupsWithEntries(): void
    {
        $renderer   = new OutputDecisionLogRenderer();
        $output     = new BufferedOutput();
        $io         = new SymfonyStyle(new ArrayInput([]), $output);
        $collection = new AssetGroupCollection();

        $groupWithLog = new AssetGroup('group-a');
        $groupWithLog->addDecision('Canonical selected: a.heic');
        $groupWithLog->addDecision('Companion paired: a.mov');

        $groupWithoutLog = new AssetGroup('group-b');

        $collection->set('group-a', $groupWithLog);
        $collection->set('group-b', $groupWithoutLog);

        $renderer->renderAssetGroupDecisionLog(
            $collection,
            $io,
        );

        $buffer = $output->fetch();

        self::assertStringContainsString('Decision Log', $buffer);
        self::assertStringContainsString('group-a', $buffer);
        self::assertStringContainsString('Canonical selected: a.heic', $buffer);
        self::assertStringContainsString('Companion paired: a.mov', $buffer);
        self::assertStringNotContainsString('group-b', $buffer);
    }

    /**
     * Verifies that the execution-plan view uses the same grouped formatting and
     * stays silent when no plan group carries any decision-log entries.
     */
    #[Test]
    public function renderExecutionPlanDecisionLogOutputsNothingWhenEmpty(): void
    {
        $renderer = new OutputDecisionLogRenderer();
        $output   = new BufferedOutput();
        $io       = new SymfonyStyle(new ArrayInput([]), $output);

        $renderer->renderExecutionPlanDecisionLog(
            new ExecutionPlan([
                new ExecutionGroup('group-a', false, null, [], []),
            ]),
            $io,
        );

        self::assertSame('', $output->fetch());
    }

    /**
     * Decision keys and metadata-derived explanations cross the same trust
     * boundary in both pipeline views. Literal tags and escaped controls must
     * survive rendering without allowing a counterfeit decision-log row.
     *
     * @return void
     */
    #[Test]
    public function bothDecisionViewsNeutralizeKeysAndDiagnosticEntries(): void
    {
        $payload    = "<info>FORGED</info>\x1B[2J\r\n";
        $collection = new AssetGroupCollection();
        $group      = new AssetGroup($payload);
        $group->addDecision($payload);

        $collection->set($payload, $group);
        $renderer = new OutputDecisionLogRenderer();

        foreach ([false, true] as $executionView) {
            $output = new BufferedOutput();
            $io     = new SymfonyStyle(new ArrayInput([]), $output);

            if ($executionView) {
                $renderer->renderExecutionPlanDecisionLog(new ExecutionPlan([new ExecutionGroup($payload, false, null, [], [$payload])]), $io);
            } else {
                $renderer->renderAssetGroupDecisionLog($collection, $io);
            }

            $captured = $output->fetch();
            self::assertTrue(str_contains($captured, 'Decision Log'));
            self::assertSame(2, substr_count($captured, '<info>FORGED</info>\\u001b[2J\\r\\n'));
            self::assertFalse(str_contains($captured, "\x1B"));
            self::assertFalse(str_contains($captured, "\r"));
        }
    }
}
