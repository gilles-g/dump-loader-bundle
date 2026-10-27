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

namespace DumpLoader\Tests\Unit\Dump;

use DumpLoader\Dump\FileSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileSizeTest extends TestCase
{
    #[Test]
    public function it_should_hold_a_byte_count(): void
    {
        self::assertSame(1259, FileSize::fromBytes(1259)->bytes);
    }

    #[Test]
    public function it_should_have_a_zero_value(): void
    {
        self::assertSame(0, FileSize::zero()->bytes);
    }

    #[Test]
    public function it_should_reject_a_negative_byte_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileSize::fromBytes(-1);
    }
}
