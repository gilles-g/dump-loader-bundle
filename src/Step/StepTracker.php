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

namespace DumpLoader\Step;

use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;

interface StepTracker
{
    public function log(LogTag $tag, string $message): void;

    public function progress(Percentage $percentage): void;

    public function abortRequested(): bool;
}
