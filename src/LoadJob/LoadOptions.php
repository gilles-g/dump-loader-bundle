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

final readonly class LoadOptions
{
    /**
     * @param list<string> $names
     */
    private function __construct(
        public array $names,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $names
     */
    public static function fromNames(array $names): self
    {
        return new self(array_values(array_unique(array_filter($names, static fn (string $name): bool => '' !== $name))));
    }

    public function toggled(string $name): self
    {
        if ($this->includes($name)) {
            return new self(array_values(array_filter($this->names, static fn (string $included): bool => $included !== $name)));
        }

        return self::fromNames([...$this->names, $name]);
    }

    public function includes(string $name): bool
    {
        return \in_array($name, $this->names, true);
    }
}
