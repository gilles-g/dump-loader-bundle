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

use DumpLoader\Dump\Dump;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadOptions;

final readonly class LoadContext
{
    public function __construct(
        public LoadJobId $id,
        public Dump $dump,
        public LoadOptions $options,
    ) {
    }
}
