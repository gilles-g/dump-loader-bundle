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

namespace DumpLoader\Controller;

use DumpLoader\Monitor\MonitorAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;

final readonly class OpenMonitorController
{
    public function __construct(
        private MonitorAccess $monitorAccess,
    ) {
    }

    public function __invoke(): RedirectResponse
    {
        return $this->monitorAccess->open();
    }
}
