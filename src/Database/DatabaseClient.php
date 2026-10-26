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

namespace DumpLoader\Database;

use DumpLoader\Dump\SqlInput;
use DumpLoader\Step\StepTracker;

interface DatabaseClient
{
    public function restore(TargetDatabase $database, SqlInput $input, StepTracker $tracker): void;

    public function dropAllTables(TargetDatabase $database, StepTracker $tracker): void;
}
