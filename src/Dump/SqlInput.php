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

namespace DumpLoader\Dump;

use DumpLoader\Execution\ShellCommand;

interface SqlInput
{
    public function header(int $window): string;

    public function feed(ShellCommand $command): ShellCommand;
}
