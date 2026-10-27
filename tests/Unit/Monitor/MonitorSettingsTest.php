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

namespace DumpLoader\Tests\Unit\Monitor;

use DumpLoader\Monitor\MonitorSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MonitorSettingsTest extends TestCase
{
    #[Test]
    public function it_should_default_to_the_settings_of_the_bundle(): void
    {
        $settings = MonitorSettings::defaultsFor('/app');

        self::assertSame('/app/var/dump-loader', $settings->varDir);
        self::assertSame(60, $settings->pendingGracePeriod);
        self::assertSame(50, $settings->retention);
    }

    #[Test]
    public function it_should_read_the_jobs_where_the_bundle_writes_them(): void
    {
        self::assertSame('/data/dump-loader/jobs', (new MonitorSettings('/data/dump-loader'))->jobsDir());
    }

    #[Test]
    public function it_should_refuse_an_empty_var_dir(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MonitorSettings('');
    }

    #[Test]
    public function it_should_refuse_a_negative_pending_grace_period(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MonitorSettings('/data/dump-loader', pendingGracePeriod: -1);
    }

    #[Test]
    public function it_should_refuse_a_retention_keeping_no_job(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MonitorSettings('/data/dump-loader', retention: 0);
    }
}
