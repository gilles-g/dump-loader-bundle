<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\Tests\Unit\Execution;

use DumpLoader\Execution\NoInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NoInputTest extends TestCase
{
    #[Test]
    public function it_should_iterate_over_nothing(): void
    {
        self::assertSame([], iterator_to_array(new NoInput(), false));
    }
}
