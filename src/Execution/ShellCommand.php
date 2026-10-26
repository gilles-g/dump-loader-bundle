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

namespace DumpLoader\Execution;

final readonly class ShellCommand
{
    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     * @param iterable<string>      $input
     */
    public function __construct(
        public array $argv,
        public array $env = [],
        public ?string $cwd = null,
        public iterable $input = new NoInput(),
    ) {
    }

    /**
     * @param iterable<string> $input
     */
    public function withInput(iterable $input): self
    {
        return new self(argv: $this->argv, env: $this->env, cwd: $this->cwd, input: $input);
    }

    public function display(): string
    {
        $maskedEnv = array_map(static fn (string $name): string => $name.'=***', array_keys($this->env));

        return implode(' ', [...$maskedEnv, ...$this->argv]);
    }
}
