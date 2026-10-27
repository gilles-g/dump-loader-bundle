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

use Symfony\Component\Uid\Ulid;

final readonly class LoadJobId
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self((new Ulid())->toBase32());
    }

    public static function fromString(string $value): self
    {
        if (!Ulid::isValid($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid load job identifier.', $value));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
