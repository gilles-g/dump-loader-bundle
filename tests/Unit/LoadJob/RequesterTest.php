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

use DumpLoader\LoadJob\Requester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequesterTest extends TestCase
{
    #[Test]
    public function it_should_carry_the_name_it_was_given(): void
    {
        $requester = Requester::named('alice');

        self::assertTrue($requester->isKnown());
        self::assertSame('alice', $requester->name);
    }

    #[Test]
    public function it_should_be_unknown_without_a_name(): void
    {
        $requester = Requester::unknown();

        self::assertFalse($requester->isKnown());
        self::assertNull($requester->name);
    }

    #[DataProvider('blankNames')]
    #[Test]
    public function it_should_refuse_a_blank_name(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Requester::named($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankNames(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tabulation' => ["\t"];
    }
}
