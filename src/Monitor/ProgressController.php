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

namespace DumpLoader\Monitor;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class ProgressController
{
    public function __construct(
        private MonitorQuery $monitorQuery,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        $monitor = $this->monitorQuery->progress();

        return new Response($this->twig->render('@DumpLoader/_progress.html.twig', [
            'job' => $monitor->followedJob,
            'unreadableJobDetail' => $monitor->unreadableJobDetail,
            'seenAt' => $monitor->seenAt,
        ]));
    }
}
