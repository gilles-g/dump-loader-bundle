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

namespace DumpLoader\Requirements;

/**
 * @implements \IteratorAggregate<int, Requirement>
 */
final readonly class Requirements implements \IteratorAggregate, \Countable
{
    /**
     * @var list<Requirement>
     */
    private array $requirements;

    public function __construct(Requirement ...$requirements)
    {
        $this->requirements = array_values($requirements);
    }

    /**
     * @return \ArrayIterator<int, Requirement>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->requirements);
    }

    public function count(): int
    {
        return \count($this->requirements);
    }

    public function allSatisfied(): bool
    {
        return [] === $this->unsatisfied();
    }

    /**
     * @return list<Requirement>
     */
    public function unsatisfied(): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (Requirement $requirement): bool => !$requirement->satisfied,
        ));
    }
}
