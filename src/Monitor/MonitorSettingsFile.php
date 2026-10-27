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

final readonly class MonitorSettingsFile
{
    public const string PATH = 'var/dump-loader.monitor.json';

    public function __construct(
        private string $projectDir,
    ) {
    }

    public function publish(MonitorSettings $settings): void
    {
        $path = $this->path();
        $directory = \dirname($path);
        $draft = $path.'.'.bin2hex(random_bytes(8));
        $contents = json_encode([
            'varDir' => $settings->varDir,
            'pendingGracePeriod' => $settings->pendingGracePeriod,
            'retention' => $settings->retention,
        ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $published = (is_dir($directory) || @mkdir($directory, 0o775, true) || is_dir($directory))
            && false !== @file_put_contents($draft, $contents)
            && @rename($draft, $path);

        if (!$published) {
            throw MonitorSettingsNotPublishable::in($path);
        }
    }

    public function read(): MonitorSettings
    {
        $path = $this->path();

        if (!is_file($path)) {
            return MonitorSettings::defaultsFor($this->projectDir);
        }

        try {
            $decoded = json_decode((string) @file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw UnreadableMonitorSettings::at($path, $exception->getMessage(), $exception);
        }

        $published = \is_array($decoded) ? $decoded : [];
        $varDir = $published['varDir'] ?? null;
        $pendingGracePeriod = $published['pendingGracePeriod'] ?? null;
        $retention = $published['retention'] ?? null;

        if (!\is_string($varDir) || !\is_int($pendingGracePeriod) || !\is_int($retention)) {
            throw UnreadableMonitorSettings::at($path, 'a setting is missing or has the wrong type.');
        }

        return new MonitorSettings($varDir, $pendingGracePeriod, $retention);
    }

    private function path(): string
    {
        return $this->projectDir.'/'.self::PATH;
    }
}
