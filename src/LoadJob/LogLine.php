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

final readonly class LogLine
{
    public function __construct(
        public \DateTimeImmutable $at,
        public LogTag $tag,
        public string $message,
    ) {
    }

    /**
     * @return array{at: string, tag: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'at' => SnapshotDate::format($this->at),
            'tag' => $this->tag->value,
            'message' => $this->message,
        ];
    }

    /**
     * @param array{at: string, tag: string, message: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            at: SnapshotDate::parse($data['at']),
            tag: LogTag::from($data['tag']),
            message: $data['message'],
        );
    }
}
