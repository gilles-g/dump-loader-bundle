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

namespace DumpLoader\Monitor;

final readonly class MonitorSettings
{
    public const DEFAULT_VAR_DIR = 'var/dump-loader';

    public const DEFAULT_PENDING_GRACE_PERIOD = 60;

    public const DEFAULT_RETENTION = 50;

    public function __construct(
        public string $varDir,
        public int $pendingGracePeriod = self::DEFAULT_PENDING_GRACE_PERIOD,
        public int $retention = self::DEFAULT_RETENTION,
    ) {
        if ('' === $this->varDir) {
            throw new \InvalidArgumentException('Cannot follow the jobs without the "var_dir" the bundle writes them to.');
        }

        if ($this->pendingGracePeriod < 0) {
            throw new \InvalidArgumentException(\sprintf('A pending grace period cannot be negative, %d given.', $this->pendingGracePeriod));
        }

        if ($this->retention < 1) {
            throw new \InvalidArgumentException(\sprintf('Cannot keep fewer than one job in the history, %d given.', $this->retention));
        }
    }

    public static function defaultsFor(string $projectDir): self
    {
        return new self($projectDir.'/'.self::DEFAULT_VAR_DIR);
    }

    public function jobsDir(): string
    {
        return $this->varDir.'/jobs';
    }
}
