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

namespace DumpLoader\Tests\Double;

use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;
use DumpLoader\Step\StepTracker;

final class RecordingStepTracker implements StepTracker
{
    /**
     * @var list<string>
     */
    public array $messages = [];

    private int $polls = 0;

    private function __construct(
        private readonly ?int $abortAfterPolls,
    ) {
    }

    public static function silent(): self
    {
        return new self(abortAfterPolls: null);
    }

    public static function abortingAfterPolls(int $polls): self
    {
        return new self(abortAfterPolls: $polls);
    }

    public function log(LogTag $tag, string $message): void
    {
        $this->messages[] = $message;
    }

    public function progress(Percentage $percentage): void
    {
    }

    public function abortRequested(): bool
    {
        if (null === $this->abortAfterPolls) {
            return false;
        }

        return ++$this->polls > $this->abortAfterPolls;
    }
}
