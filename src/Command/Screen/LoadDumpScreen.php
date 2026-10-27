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

namespace DumpLoader\Command\Screen;

use DumpLoader\Dump\Dumps;
use DumpLoader\LoadJob\FollowedLoadJob;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\StepDefinition;

interface LoadDumpScreen
{
    public function chooseDump(Dumps $dumps): string;

    /**
     * @param list<StepDefinition> $availableOptions
     */
    public function chooseOptions(array $availableOptions, LoadOptions $defaultOptions): LoadOptions;

    public function confirm(string $database): bool;

    public function follow(FollowedLoadJob $job): void;
}
