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

use DumpLoader\LoadJob\AbortLoadJob;
use DumpLoader\LoadJob\AbortLoadJobHandler;
use DumpLoader\LoadJob\InvalidTransition;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobNotFound;
use DumpLoader\LoadJob\UnreadableLoadJob;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class AbortLoadJobController
{
    public const CSRF_TOKEN_ID = 'dump_loader_abort';

    public function __construct(
        private AbortLoadJobHandler $abortLoadJobHandler,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token')))) {
            return $this->monitorWithError('flash.abort_invalid_token', $request);
        }

        try {
            $id = LoadJobId::fromString((string) $request->request->get('id'));
        } catch (\InvalidArgumentException) {
            return $this->monitorWithError('flash.abort_unknown_job', $request);
        }

        try {
            ($this->abortLoadJobHandler)(new AbortLoadJob($id));
        } catch (LoadJobNotFound) {
            return $this->monitorWithError('flash.abort_unknown_job', $request);
        } catch (UnreadableLoadJob) {
            return $this->monitorWithError('flash.abort_unreadable_job', $request);
        } catch (InvalidTransition) {
            return $this->monitor();
        }

        return $this->monitor();
    }

    private function monitorWithError(string $message, Request $request): RedirectResponse
    {
        if ($request->hasSession()) {
            $session = $request->getSession();

            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('dump_loader_error', $message);
            }
        }

        return $this->monitor();
    }

    private function monitor(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('dump_loader_monitor'));
    }
}
