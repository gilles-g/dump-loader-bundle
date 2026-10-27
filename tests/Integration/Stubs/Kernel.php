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

namespace DumpLoader\Tests\Integration\Stubs;

use DumpLoader\DumpLoaderBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as HttpKernel;

final class Kernel extends HttpKernel
{
    public function __construct(
        string $environment,
        bool $debug,
        private readonly string $config = 'base',
    ) {
        parent::__construct($environment, $debug);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new DumpLoaderBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/../config/'.$this->config.'_config.yaml');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/DumpLoaderBundle/'.$this->config.'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/DumpLoaderBundle/'.$this->config.'/logs';
    }
}
