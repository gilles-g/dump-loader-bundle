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

namespace DumpLoader\Tests\Double;

use DumpLoader\Step\LoadContext;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepTracker;

class FakeStep implements Step
{
    /**
     * @var list<string>
     */
    public array $ranFor = [];

    private ?\Throwable $failure = null;

    final private function __construct(
        private readonly StepDefinition $definition,
    ) {
    }

    public static function named(string $name, int $rank, int $weight = 10): static
    {
        return new static(new StepDefinition($name, ucfirst($name), '', $rank, $weight));
    }

    public function failingWith(\Throwable $failure): static
    {
        $this->failure = $failure;

        return $this;
    }

    public function definition(): StepDefinition
    {
        return $this->definition;
    }

    public function run(LoadContext $context, StepTracker $tracker): void
    {
        $this->ranFor[] = $context->id->value;

        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
