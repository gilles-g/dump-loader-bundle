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

namespace DumpLoader\Controller;

use DumpLoader\LoadJob\DashboardQuery;
use DumpLoader\Requirements\CheckRequirements;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class DashboardController
{
    public function __construct(
        private DashboardQuery $dashboardQuery,
        private CheckRequirements $checkRequirements,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('@DumpLoader/dashboard.html.twig', [
            'dashboard' => $this->dashboardQuery->current(),
            'requirements' => ($this->checkRequirements)(),
        ]));
    }
}
