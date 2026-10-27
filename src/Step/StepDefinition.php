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

namespace DumpLoader\Step;

final readonly class StepDefinition
{
    public const int RANK_DROP_ALL_TABLES = 50;
    public const int RANK_RESTORE_DATABASE = 100;
    public const int RANK_RUN_MIGRATIONS = 200;
    public const int RANK_CLEAR_CACHES = 300;
    public const int RANK_AFTER_LOAD = 400;

    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public int $rank,
        public int $weight,
    ) {
    }
}
