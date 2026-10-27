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

namespace DumpLoader\Execution;

/**
 * @implements \IteratorAggregate<int, string>
 */
final readonly class NoInput implements \IteratorAggregate
{
    /**
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        yield from [];
    }
}
