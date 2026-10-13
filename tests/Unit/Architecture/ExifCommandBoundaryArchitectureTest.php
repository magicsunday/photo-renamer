<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Architecture;

use MagicSunday\Renamer\Command\AbstractRenameCommand;
use MagicSunday\Renamer\Command\RenameByExifDateCommand;
use MagicSunday\Renamer\Service\CanonicalScorerInterface;
use MagicSunday\Renamer\Service\DuplicateDetectionServiceInterface;
use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\PerceptualHash\PerceptualHashCalculatorInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Guards the EXIF command's workflow boundary. Its asset-group pipeline does
 * not execute the legacy duplicate path, and pipeline-owned analysis setup
 * must not require the CLI to depend on those internal collaborators.
 * Functional command/plan tests separately protect runtime behavior.
 */
#[CoversNothing]
final class ExifCommandBoundaryArchitectureTest extends TestCase
{
    /**
     * EXIF must not receive legacy duplicate detection merely to satisfy an
     * inherited constructor; only simple commands retain that execution path.
     *
     * @return void
     */
    #[Test]
    public function exifConstructorDoesNotRequireLegacyDuplicateDetection(): void
    {
        self::assertFalse(new ReflectionClass(RenameByExifDateCommand::class)->isSubclassOf(AbstractRenameCommand::class));
        self::assertNotContains(DuplicateDetectionServiceInterface::class, $this->constructorTypeNames());
    }

    /**
     * Scoring and content/signal setup belong to the analysis workflow rather
     * than the Console command. This protects the intended dependency reduction
     * without requiring a particular number of parameters or a new framework.
     *
     * @return void
     */
    #[Test]
    public function exifConstructorDoesNotRequirePipelineSetupInternals(): void
    {
        $types = $this->constructorTypeNames();
        self::assertNotContains(CanonicalScorerInterface::class, $types);
        self::assertNotContains(HashSubGroupingServiceInterface::class, $types);
        self::assertNotContains(PerceptualHashCalculatorInterface::class, $types);
    }

    /**
     * Reads actual autoloaded constructor types rather than class-name text,
     * keeping guards independent of formatting, comments and parameter order.
     *
     * @return list<string> Named constructor types currently required by EXIF
     */
    private function constructorTypeNames(): array
    {
        $constructor = new ReflectionClass(RenameByExifDateCommand::class)->getConstructor();
        self::assertNotNull($constructor);
        $types = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                $types[] = $type->getName();
            }
        }

        return $types;
    }
}
