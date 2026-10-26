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

namespace DumpLoader\Step;

use DumpLoader\LoadJob\LoadOptions;

final readonly class StepSequence
{
    /**
     * @var list<Step>
     */
    private array $steps;

    /**
     * @param iterable<Step> $steps
     */
    public function __construct(iterable $steps)
    {
        $this->steps = array_values([...$steps]);
    }

    /**
     * @return list<Step>
     */
    public function plan(LoadOptions $options): array
    {
        $planned = array_filter(
            $this->steps,
            static fn (Step $step): bool => !$step instanceof OptionalStep
                || $options->includes($step->definition()->name),
        );

        return $this->sortedByRank($planned);
    }

    public function named(string $name): Step
    {
        foreach ($this->steps as $step) {
            if ($step->definition()->name === $name) {
                return $step;
            }
        }

        throw UnknownStep::named($name);
    }

    /**
     * @return list<OptionalStep>
     */
    public function availableOptions(): array
    {
        $optional = array_filter($this->steps, static fn (Step $step): bool => $step instanceof OptionalStep);

        /** @var list<OptionalStep> $sorted */
        $sorted = $this->sortedByRank($optional);

        return $sorted;
    }

    /**
     * @param array<int, Step> $steps
     *
     * @return list<Step>
     */
    private function sortedByRank(array $steps): array
    {
        $sorted = array_values($steps);
        usort($sorted, static fn (Step $left, Step $right): int => $left->definition()->rank <=> $right->definition()->rank);

        return $sorted;
    }
}
