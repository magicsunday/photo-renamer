<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Architecture;

use MagicSunday\Renamer\Metadata\TemporalMetadata;
use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced by PHPat (runs as part of PHPStan).
 *
 * Layer hierarchy (top → bottom):
 *   Command → Service → Strategy, Metadata
 *   All layers may use: Model, Helper, Exception, Regex, Constants
 *
 * Leaf layers (Model, Helper, Exception, Regex) must not depend upward.
 *
 * @internal
 */
final class ArchitectureTest
{
    // =========================================================================
    // Model: DTOs and value objects — no upward dependencies
    // =========================================================================

    #[TestRule]
    public function modelDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Models are data holders, must not reference Commands');
    }

    #[TestRule]
    public function modelDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Models are data holders, must not reference Services');
    }

    #[TestRule]
    public function modelDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Models are data holders, must not reference Strategies');
    }

    #[TestRule]
    public function modelDoesNotDependOnMetadata(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Metadata'))
            ->excluding(Selector::classname(TemporalMetadata::class))
            ->because('Models are data holders, must not reference Metadata extraction (TemporalMetadata allowed as VO)');
    }

    #[TestRule]
    public function modelDoesNotDependOnRegex(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->because('Models are data holders, must not reference Regex utilities');
    }

    // =========================================================================
    // Exception: leaf classes — no dependencies on any layer
    // =========================================================================

    #[TestRule]
    public function exceptionDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    #[TestRule]
    public function exceptionDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    #[TestRule]
    public function exceptionDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    #[TestRule]
    public function exceptionDoesNotDependOnMetadata(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Metadata'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    #[TestRule]
    public function exceptionDoesNotDependOnModel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    #[TestRule]
    public function exceptionDoesNotDependOnHelper(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    #[TestRule]
    public function exceptionDoesNotDependOnRegex(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    // =========================================================================
    // Regex: leaf utilities — only Exception allowed
    // =========================================================================

    #[TestRule]
    public function regexDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    #[TestRule]
    public function regexDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    #[TestRule]
    public function regexDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    #[TestRule]
    public function regexDoesNotDependOnMetadata(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Metadata'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    #[TestRule]
    public function regexDoesNotDependOnModel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    #[TestRule]
    public function regexDoesNotDependOnHelper(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    // =========================================================================
    // Helper: shared utilities — may use Model, Exception, Regex, Constants
    // =========================================================================

    #[TestRule]
    public function helperDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Helpers are shared utilities, must not reference Commands');
    }

    #[TestRule]
    public function helperDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Helpers are shared utilities, must not reference Services');
    }

    #[TestRule]
    public function helperDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Helpers are shared utilities, must not reference Strategies');
    }

    #[TestRule]
    public function helperDoesNotDependOnMetadata(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Metadata'))
            ->because('Helpers are shared utilities, must not reference Metadata');
    }

    // =========================================================================
    // Strategy: may use Service interfaces, Metadata, Helper, Exception, Regex
    // =========================================================================

    #[TestRule]
    public function strategyDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Strategies are injected by Commands, not the reverse');
    }

    // =========================================================================
    // Service: may use Strategy, Metadata, Model, Helper, Exception, Regex
    // =========================================================================

    #[TestRule]
    public function serviceDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Services are command-agnostic; Commands orchestrate Services, not vice versa');
    }
}
