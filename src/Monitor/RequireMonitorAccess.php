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

namespace DumpLoader\Monitor;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;

final readonly class RequireMonitorAccess
{
    public function __construct(
        private MonitorAccess $monitorAccess,
        private Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $this->monitorAccess->isGrantedTo($request)) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render($request->isXmlHttpRequest() ? '@DumpLoader/_access_denied.html.twig' : '@DumpLoader/access_denied.html.twig'),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
