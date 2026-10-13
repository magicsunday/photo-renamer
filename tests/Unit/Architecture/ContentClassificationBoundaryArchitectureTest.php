<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Architecture;

use MagicSunday\Renamer\Service\HashSubGroupingServiceInterface;
use MagicSunday\Renamer\Service\Pipeline\SubgroupClassifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

use function file_get_contents;

/**
 * Protects the direct content-classification boundary from the legacy naming
 * adapter. Functional/differential suites verify the shared decision rules;
 * these guards prevent reintroducing synthetic rename models in the pipeline.
 */
#[CoversNothing]
final class ContentClassificationBoundaryArchitectureTest extends TestCase
{
    /**
     * The pipeline classifier must consume a content contract rather than the
     * legacy apply contract that requires Rename/FileDuplicate and a naming closure.
     *
     * @return void
     */
    #[Test]
    public function pipelineDoesNotRequireTheLegacyNamingContract(): void
    {
        $constructor = new ReflectionClass(SubgroupClassifier::class)->getConstructor();
        self::assertNotNull($constructor);
        $type = $constructor->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertNotSame(HashSubGroupingServiceInterface::class, $type->getName());
    }

    /**
     * Active source must no longer create artificial legacy models/target paths
     * just to obtain content membership; source names remain pairing hints only.
     *
     * @return void
     */
    #[Test]
    public function pipelineDoesNotCreateSyntheticRenameModels(): void
    {
        $path = new ReflectionClass(SubgroupClassifier::class)->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringNotContainsString('new FileDuplicate(', $source);
        self::assertStringNotContainsString('new Rename(', $source);
        self::assertStringNotContainsString('$targetPathnameResolver', $source);
    }
}
