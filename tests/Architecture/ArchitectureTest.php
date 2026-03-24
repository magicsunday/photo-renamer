<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced by PHPat (runs as part of PHPStan).
 *
 * Layers: Command, Service, Strategy, Model, Helper, Metadata, Exception, Regex.
 * Models must not depend on Services. Helpers must not depend on Commands.
 *
 * @internal
 */
final class ArchitectureTest
{
    #[TestRule]
    public function modelDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Models hold data, Services contain logic');
    }

    #[TestRule]
    public function modelDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Models must not reference Commands');
    }

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
            ->because('Helpers are leaf utilities, must not reference Services');
    }

    #[TestRule]
    public function strategyDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Strategy'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Strategies are injected by Commands, not the reverse');
    }

    #[TestRule]
    public function serviceDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Services are command-agnostic; Commands orchestrate Services, not vice versa');
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
    public function exceptionDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Exception'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
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
    public function regexDoesNotDependOnService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Service'))
            ->because('Regex utilities are leaf-level tools with no service dependencies');
    }

    #[TestRule]
    public function regexDoesNotDependOnCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Regex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('MagicSunday\Renamer\Command'))
            ->because('Regex utilities are leaf-level tools with no command dependencies');
    }
}
