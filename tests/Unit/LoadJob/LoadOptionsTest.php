<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader-bundle package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\LoadJob\LoadOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoadOptionsTest extends TestCase
{
    #[Test]
    public function it_should_add_an_option_it_does_not_include_yet(): void
    {
        self::assertSame(['run_migrations', 'clear_caches'], LoadOptions::fromNames(['run_migrations'])->toggled('clear_caches')->names);
    }

    #[Test]
    public function it_should_remove_an_option_it_already_includes(): void
    {
        self::assertSame(['clear_caches'], LoadOptions::fromNames(['run_migrations', 'clear_caches'])->toggled('run_migrations')->names);
    }
}
