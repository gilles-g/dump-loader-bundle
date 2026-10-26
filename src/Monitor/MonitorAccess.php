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

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class MonitorAccess
{
    public const COOKIE = 'DUMP_LOADER_MONITOR_ACCESS';

    private const LIFETIME = 'PT8H';

    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
        private ClockInterface $clock,
        private string $monitorPath,
    ) {
    }

    public function open(): RedirectResponse
    {
        $expiresAt = $this->clock->now()->add(new \DateInterval(self::LIFETIME));
        $expiry = (string) $expiresAt->getTimestamp();

        $monitor = new RedirectResponse($this->monitorPath);
        $monitor->headers->setCookie(Cookie::create(
            self::COOKIE,
            $expiry.'.'.$this->signature($expiry),
            $expiresAt,
            $this->monitorPath,
            sameSite: Cookie::SAMESITE_STRICT,
        ));

        return $monitor;
    }

    public function isGrantedTo(Request $request): bool
    {
        if (1 !== preg_match('/^(\d+)\.([0-9a-f]{64})$/', (string) $request->cookies->get(self::COOKIE), $pass)) {
            return false;
        }

        return (int) $pass[1] > $this->clock->now()->getTimestamp()
            && hash_equals($this->signature($pass[1]), $pass[2]);
    }

    private function signature(string $expiry): string
    {
        return hash_hmac('sha256', self::COOKIE.'|'.$expiry, $this->secret);
    }
}
