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

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final readonly class PublishMonitorSettings implements CacheWarmerInterface
{
    public function __construct(
        private MonitorSettingsFile $file,
        private MonitorSettings $settings,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->file->publish($this->settings);

        return [];
    }
}
