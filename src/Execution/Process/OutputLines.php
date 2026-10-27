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

namespace DumpLoader\Execution\Process;

use function Symfony\Component\String\u;

final readonly class OutputLines
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public array $values = [],
    ) {
    }

    public static function fromBuffer(string $buffer): self
    {
        $values = [];

        foreach (u($buffer)->replace("\r\n", "\n")->split("\n") as $line) {
            $text = $line->trim()->toString();

            if ('' !== $text) {
                $values[] = $text;
            }
        }

        return new self(values: $values);
    }

    public function appendKeepingLast(self $other, int $limit): self
    {
        return new self(values: \array_slice([...$this->values, ...$other->values], -$limit));
    }
}
