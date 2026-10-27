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

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\FileSize;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\StepStatus;

trait JobFixtures
{
    private function aDump(): Dump
    {
        return Dump::fromPath(
            'dumps/prod-2026-09-18.sql.gz',
            FileSize::fromBytes(1_048_576),
            new \DateTimeImmutable('2026-09-18 08:00:00'),
        );
    }

    private function aJobId(): LoadJobId
    {
        return LoadJobId::fromString('01J8Z9P0000000000000000000');
    }

    /**
     * @return list<PlannedStep>
     */
    private function plannedSteps(): array
    {
        return [
            new PlannedStep(
                name: 'restore_database',
                label: 'Restore database',
                weight: 10,
                status: StepStatus::Todo,
            ),
            new PlannedStep(
                name: 'clear_caches',
                label: 'Clear caches',
                weight: 1,
                status: StepStatus::Todo,
            ),
        ];
    }
}
