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

namespace DumpLoader\Dump;

/**
 * @implements \IteratorAggregate<int, Dump>
 */
final readonly class Dumps implements \IteratorAggregate, \Countable
{
    /**
     * @var list<Dump>
     */
    private array $dumps;

    public function __construct(Dump ...$dumps)
    {
        $this->dumps = array_values($dumps);
    }

    /**
     * @return \ArrayIterator<int, Dump>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->dumps);
    }

    public function count(): int
    {
        return \count($this->dumps);
    }

    public function isEmpty(): bool
    {
        return [] === $this->dumps;
    }
}
