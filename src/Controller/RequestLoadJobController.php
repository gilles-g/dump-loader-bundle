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

use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\DumpNotRestorable;
use DumpLoader\LoadJob\LoadJobAlreadyRunning;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\RequestLoadJob;
use DumpLoader\LoadJob\RequestLoadJobHandler;
use DumpLoader\LoadJob\UnreadableLoadJob;
use DumpLoader\Monitor\MonitorAccess;
use DumpLoader\Security\CurrentRequester;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class RequestLoadJobController
{
    public const CSRF_TOKEN_ID = 'dump_loader_request';

    public function __construct(
        private RequestLoadJobHandler $requestLoadJobHandler,
        private CurrentRequester $currentRequester,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private MonitorAccess $monitorAccess,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token')))) {
            return $this->back('flash.invalid_token', $request);
        }

        if (!$request->request->getBoolean('confirm')) {
            return $this->back('flash.confirmation_required', $request);
        }

        try {
            ($this->requestLoadJobHandler)(new RequestLoadJob(
                dumpPath: (string) $request->request->get('dump'),
                options: LoadOptions::fromNames($this->checkedOptions($request)),
                requester: $this->currentRequester->current(),
            ));
        } catch (LoadJobAlreadyRunning) {
            return $this->back('flash.already_running', $request);
        } catch (UnreadableLoadJob) {
            return $this->back('flash.unreadable_job', $request);
        } catch (DumpNotFound) {
            return $this->back('flash.unknown_dump', $request);
        } catch (DumpNotRestorable) {
            return $this->back('flash.dump_not_restorable', $request);
        }

        return $this->monitorAccess->open();
    }

    /**
     * @return list<string>
     */
    private function checkedOptions(Request $request): array
    {
        return array_values(array_filter(
            $request->request->all('options'),
            \is_string(...),
        ));
    }

    private function back(string $message, Request $request): RedirectResponse
    {
        if ($request->hasSession()) {
            $session = $request->getSession();

            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('dump_loader_error', $message);
            }
        }

        return new RedirectResponse($this->urlGenerator->generate('dump_loader_dashboard'));
    }
}
