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

namespace DumpLoader\LoadJob;

final readonly class Progress
{
    private function __construct(
        public int $stepNumber,
        public int $stepCount,
        public Percentage $stepPercentage,
    ) {
    }

    public static function notStarted(int $stepCount): self
    {
        if ($stepCount < 0) {
            throw new \InvalidArgumentException(\sprintf('A step count cannot be negative, %d given.', $stepCount));
        }

        return new self(0, $stepCount, Percentage::zero());
    }

    public static function atStep(int $stepNumber, int $stepCount): self
    {
        if ($stepNumber < 1 || $stepNumber > $stepCount) {
            throw new \InvalidArgumentException(\sprintf('Step %d is outside the planned sequence of %d steps.', $stepNumber, $stepCount));
        }

        return new self($stepNumber, $stepCount, Percentage::zero());
    }

    public function withStepPercentage(Percentage $stepPercentage): self
    {
        return new self($this->stepNumber, $this->stepCount, $stepPercentage);
    }

    public function overall(): Percentage
    {
        if (0 === $this->stepNumber || 0 === $this->stepCount) {
            return Percentage::zero();
        }

        $done = ($this->stepNumber - 1) + ($this->stepPercentage->value / 100.0);

        return Percentage::of($done / $this->stepCount * 100.0);
    }

    /**
     * @return array{stepNumber: int, stepCount: int, stepPercentage: float}
     */
    public function toArray(): array
    {
        return [
            'stepNumber' => $this->stepNumber,
            'stepCount' => $this->stepCount,
            'stepPercentage' => $this->stepPercentage->value,
        ];
    }

    /**
     * @param array{stepNumber: int, stepCount: int, stepPercentage: float} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['stepNumber'],
            $data['stepCount'],
            Percentage::of($data['stepPercentage']),
        );
    }
}
