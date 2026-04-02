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

    /**
     * Ensures that model classes do not depend on command classes.
     * Models function as pure data containers (DTOs/Value Objects) and must
     * not possess any logic or dependencies on higher layers.
     */
    #[TestRule]
    public function modelDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Models are data holders, must not reference Commands');
    }

    /**
     * Ensures that model classes do not depend on service classes.
     * This prevents circular dependencies and ensures the independence of
     * data structures from processing logic.
     */
    #[TestRule]
    public function modelDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Models are data holders, must not reference Services');
    }

    /**
     * Ensures that model classes do not depend on strategies.
     * Since strategies are interchangeable algorithms, the underlying data
     * models must not have knowledge of their implementation.
     */
    #[TestRule]
    public function modelDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Models are data holders, must not reference Strategies');
    }

    /**
     * Ensures that model classes do not depend on the metadata namespace.
     * TemporalMetadata is an exception as it is used as a pure value object
     * for time information within models.
     */
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

    /**
     * Ensures that model classes do not depend on regex utilities.
     * Regex operations belong in the processing or helper layer, not in
     * the definition of data structures.
     */
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

    /**
     * Ensures that exceptions do not depend on commands.
     * Exceptions are leaf classes and must have no knowledge of the
     * calling layers or business logic.
     */
    #[TestRule]
    public function exceptionDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    /**
     * Ensures that exceptions do not depend on services.
     * To guarantee reusability, exceptions must be free of service dependencies.
     */
    #[TestRule]
    public function exceptionDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    /**
     * Ensures that exceptions do not depend on strategies.
     */
    #[TestRule]
    public function exceptionDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    /**
     * Ensures that exceptions do not depend on the metadata namespace.
     */
    #[TestRule]
    public function exceptionDoesNotDependOnMetadata(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Metadata'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    /**
     * Ensures that exceptions do not depend on models.
     * This prevents error states from being too tightly coupled to data structures.
     */
    #[TestRule]
    public function exceptionDoesNotDependOnModel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    /**
     * Ensures that exceptions do not depend on helper classes.
     */
    #[TestRule]
    public function exceptionDoesNotDependOnHelper(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->because('Exceptions are leaf classes with no dependencies');
    }

    /**
     * Ensures that exceptions do not depend on regex utilities.
     */
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

    /**
     * Ensures that regex utilities do not depend on commands.
     * Regex classes are fundamental tools that must not know any business logic.
     */
    #[TestRule]
    public function regexDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    /**
     * Ensures that regex utilities do not depend on services.
     */
    #[TestRule]
    public function regexDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    /**
     * Ensures that regex utilities do not depend on strategies.
     */
    #[TestRule]
    public function regexDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    /**
     * Ensures that regex utilities do not depend on the metadata namespace.
     */
    #[TestRule]
    public function regexDoesNotDependOnMetadata(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Metadata'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    /**
     * Ensures that regex utilities do not depend on models.
     */
    #[TestRule]
    public function regexDoesNotDependOnModel(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->because('Regex utilities are leaf tools, only Exception allowed');
    }

    /**
     * Ensures that regex utilities do not depend on helper classes.
     */
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

    /**
     * Ensures that helper classes do not depend on commands.
     */
    #[TestRule]
    public function helperDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Helpers are shared utilities, must not reference Commands');
    }

    /**
     * Ensures that helper classes do not depend on services.
     */
    #[TestRule]
    public function helperDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Helpers are shared utilities, must not reference Services');
    }

    /**
     * Ensures that helper classes do not depend on strategies.
     */
    #[TestRule]
    public function helperDoesNotDependOnStrategy(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Helper'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->because('Helpers are shared utilities, must not reference Strategies');
    }

    /**
     * Ensures that helper classes do not depend on metadata classes.
     */
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

    /**
     * Ensures that strategies do not depend on commands.
     * Strategies are configured and injected by commands but must not
     * have any reference to concrete CLI commands themselves.
     */
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

    /**
     * Ensures that services do not depend on commands.
     * Services are agnostic to their use and should be usable by both
     * CLI commands and other interfaces.
     */
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
