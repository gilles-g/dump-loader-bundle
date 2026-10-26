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

namespace DumpLoader\LoadJob;

/**
 * @implements \IteratorAggregate<int, LoadJobSnapshot>
 */
final readonly class LoadJobs implements \IteratorAggregate, \Countable
{
    /**
     * @var list<LoadJobSnapshot>
     */
    private array $snapshots;

    public function __construct(LoadJobSnapshot ...$snapshots)
    {
        $this->snapshots = array_values($snapshots);
    }

    /**
     * @return \ArrayIterator<int, LoadJobSnapshot>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->snapshots);
    }

    public function count(): int
    {
        return \count($this->snapshots);
    }

    public function isEmpty(): bool
    {
        return [] === $this->snapshots;
    }

    public function first(): ?LoadJobSnapshot
    {
        return $this->snapshots[0] ?? null;
    }

    public function firstActive(): ?LoadJobSnapshot
    {
        $first = $this->first();

        return null !== $first && $first->status->isActive() ? $first : null;
    }

    public function seenThrough(VanishedJobs $vanished): self
    {
        return new self(...array_map(
            $vanished->seenNow(...),
            $this->snapshots,
        ));
    }
}
