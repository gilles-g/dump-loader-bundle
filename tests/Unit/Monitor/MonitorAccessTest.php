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

namespace DumpLoader\Tests\Unit\Monitor;

use DumpLoader\Monitor\MonitorAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class MonitorAccessTest extends TestCase
{
    private const SECRET = 'app-secret';

    #[Test]
    public function it_should_open_the_monitor_with_an_access_limited_to_its_path(): void
    {
        $monitor = (new MonitorAccess(self::SECRET, new MockClock('2026-09-23 10:00:00 UTC'), '/dump-loader/monitor'))->open();
        $access = $this->accessOf($monitor);

        self::assertSame('/dump-loader/monitor', $monitor->getTargetUrl());
        self::assertSame('/dump-loader/monitor', $access->getPath());
        self::assertTrue($access->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $access->getSameSite());
        self::assertSame((new \DateTimeImmutable('2026-09-23 18:00:00 UTC'))->getTimestamp(), $access->getExpiresTime());
    }

    #[Test]
    public function it_should_grant_the_access_it_opened_the_monitor_with(): void
    {
        $clock = new MockClock('2026-09-23 10:00:00');
        $monitorAccess = new MonitorAccess(self::SECRET, $clock, '/dump-loader/monitor');

        $request = $this->carrying((string) $this->accessOf($monitorAccess->open())->getValue());
        $clock->modify('+7 hours 59 minutes');

        self::assertTrue($monitorAccess->isGrantedTo($request));
    }

    #[Test]
    public function it_should_stop_granting_the_access_once_it_has_expired(): void
    {
        $clock = new MockClock('2026-09-23 10:00:00');
        $monitorAccess = new MonitorAccess(self::SECRET, $clock, '/dump-loader/monitor');

        $request = $this->carrying((string) $this->accessOf($monitorAccess->open())->getValue());
        $clock->modify('+8 hours');

        self::assertFalse($monitorAccess->isGrantedTo($request));
    }

    #[Test]
    public function it_should_refuse_an_access_signed_with_another_secret(): void
    {
        $clock = new MockClock('2026-09-23 10:00:00');
        $foreign = (new MonitorAccess('another-secret', $clock, '/dump-loader/monitor'))->open();

        $request = $this->carrying((string) $this->accessOf($foreign)->getValue());

        self::assertFalse((new MonitorAccess(self::SECRET, $clock, '/dump-loader/monitor'))->isGrantedTo($request));
    }

    #[Test]
    public function it_should_refuse_an_access_whose_expiry_was_pushed_back(): void
    {
        $monitorAccess = new MonitorAccess(self::SECRET, new MockClock('2026-09-23 10:00:00'), '/dump-loader/monitor');
        $access = (string) $this->accessOf($monitorAccess->open())->getValue();

        $request = $this->carrying((string) preg_replace('/^\d+/', '4102444800', $access));

        self::assertFalse($monitorAccess->isGrantedTo($request));
    }

    #[Test]
    public function it_should_refuse_a_request_carrying_no_access(): void
    {
        $monitorAccess = new MonitorAccess(self::SECRET, new MockClock('2026-09-23 10:00:00'), '/dump-loader/monitor');

        self::assertFalse($monitorAccess->isGrantedTo(new Request()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAccesses(): iterable
    {
        yield 'empty' => [''];
        yield 'no signature' => ['4102444800'];
        yield 'not a signature' => ['4102444800.forged'];
        yield 'not an expiry' => ['tomorrow.'.str_repeat('a', 64)];
    }

    #[Test]
    #[DataProvider('malformedAccesses')]
    public function it_should_refuse_a_malformed_access(string $access): void
    {
        $monitorAccess = new MonitorAccess(self::SECRET, new MockClock('2026-09-23 10:00:00'), '/dump-loader/monitor');

        self::assertFalse($monitorAccess->isGrantedTo($this->carrying($access)));
    }

    private function accessOf(RedirectResponse $monitor): Cookie
    {
        $cookies = $monitor->headers->getCookies();

        self::assertCount(1, $cookies);
        self::assertSame(MonitorAccess::COOKIE, $cookies[0]->getName());

        return $cookies[0];
    }

    private function carrying(string $access): Request
    {
        return new Request(cookies: [MonitorAccess::COOKIE => $access]);
    }
}
