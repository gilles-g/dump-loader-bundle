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

namespace DumpLoader\Tests\Integration\Monitor;

use DumpLoader\Monitor\MonitorSettings;
use DumpLoader\Monitor\MonitorSettingsFile;
use DumpLoader\Monitor\MonitorSettingsNotPublishable;
use DumpLoader\Monitor\PublishMonitorSettings;
use DumpLoader\Monitor\UnreadableMonitorSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class MonitorSettingsFileTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/dump-loader-monitor-settings-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    #[Test]
    public function it_should_read_the_defaults_when_nothing_was_published(): void
    {
        $settings = (new MonitorSettingsFile($this->projectDir))->read();

        self::assertEquals(MonitorSettings::defaultsFor($this->projectDir), $settings);
    }

    #[Test]
    public function it_should_read_what_was_published(): void
    {
        $file = new MonitorSettingsFile($this->projectDir);

        $file->publish(new MonitorSettings('/data/dump-loader', pendingGracePeriod: 300, retention: 7));

        self::assertEquals(new MonitorSettings('/data/dump-loader', 300, 7), $file->read());
    }

    #[Test]
    public function it_should_replace_a_previous_publication_without_leaving_a_draft(): void
    {
        $file = new MonitorSettingsFile($this->projectDir);

        $file->publish(new MonitorSettings('/first'));
        $file->publish(new MonitorSettings('/second'));

        self::assertSame('/second', $file->read()->varDir);
        self::assertSame(['dump-loader.monitor.json'], array_values(array_diff(scandir($this->projectDir.'/var') ?: [], ['.', '..'])));
    }

    #[Test]
    public function it_should_refuse_to_publish_where_it_cannot_write(): void
    {
        $blocker = $this->projectDir.'/blocker';
        touch($blocker);

        $this->expectException(MonitorSettingsNotPublishable::class);
        $this->expectExceptionMessage('blocker/var/dump-loader.monitor.json');

        (new MonitorSettingsFile($blocker))->publish(new MonitorSettings('/data'));
    }

    #[Test]
    public function it_should_refuse_a_published_file_that_is_not_json(): void
    {
        $this->writePublished('{not json');

        $this->expectException(UnreadableMonitorSettings::class);
        $this->expectExceptionMessage('dump-loader.monitor.json');

        (new MonitorSettingsFile($this->projectDir))->read();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompletePublications(): iterable
    {
        yield 'not an object' => ['"text"'];
        yield 'missing setting' => ['{"varDir":"/data","retention":5}'];
        yield 'wrong type' => ['{"varDir":"/data","pendingGracePeriod":"60","retention":5}'];
    }

    #[Test]
    #[DataProvider('incompletePublications')]
    public function it_should_refuse_a_published_file_with_a_missing_or_mistyped_setting(string $contents): void
    {
        $this->writePublished($contents);

        $this->expectException(UnreadableMonitorSettings::class);
        $this->expectExceptionMessage('missing or has the wrong type');

        (new MonitorSettingsFile($this->projectDir))->read();
    }

    #[Test]
    public function it_should_publish_the_settings_when_the_cache_is_warmed_up(): void
    {
        $file = new MonitorSettingsFile($this->projectDir);
        $warmer = new PublishMonitorSettings($file, new MonitorSettings('/data', 120, 9));

        self::assertSame([], $warmer->warmUp($this->projectDir.'/cache'));
        self::assertEquals(new MonitorSettings('/data', 120, 9), $file->read());
    }

    #[Test]
    public function it_should_not_be_an_optional_warmer(): void
    {
        $warmer = new PublishMonitorSettings(new MonitorSettingsFile($this->projectDir), new MonitorSettings('/data'));

        self::assertFalse($warmer->isOptional());
    }

    private function writePublished(string $contents): void
    {
        mkdir($this->projectDir.'/var');
        file_put_contents($this->projectDir.'/'.MonitorSettingsFile::PATH, $contents);
    }
}
