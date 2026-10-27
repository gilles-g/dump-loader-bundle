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

final readonly class ExecutionLog
{
    public const int MAX_LINES = 2_000;

    /**
     * @param list<LogLine> $lines
     */
    private function __construct(
        public array $lines,
        public int $omittedLines,
    ) {
    }

    public static function empty(): self
    {
        return new self([], 0);
    }

    /**
     * @param list<LogLine> $lines
     */
    public static function fromLines(array $lines, int $omittedLines): self
    {
        if ($omittedLines < 0) {
            throw new \InvalidArgumentException(\sprintf('An omission count cannot be negative, %d given.', $omittedLines));
        }

        return new self($lines, $omittedLines);
    }

    public function last(): ?LogLine
    {
        return [] === $this->lines ? null : $this->lines[\count($this->lines) - 1];
    }

    public function append(LogLine $line): self
    {
        $lines = $this->lines;
        $lines[] = $line;

        $overflow = \count($lines) - self::MAX_LINES;

        if ($overflow <= 0) {
            return new self($lines, $this->omittedLines);
        }

        return new self(
            \array_slice($lines, $overflow),
            $this->omittedLines + $overflow,
        );
    }

    /**
     * @return array{lines: list<array{at: string, tag: string, message: string}>, omittedLines: int}
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(static fn (LogLine $line): array => $line->toArray(), $this->lines),
            'omittedLines' => $this->omittedLines,
        ];
    }
}
