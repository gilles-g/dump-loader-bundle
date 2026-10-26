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

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class MonitorKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly MonitorSettings $settings;

    public function __construct(
        string $environment,
        bool $debug,
        private readonly string $projectDir,
        private readonly string $mountPath,
        ?MonitorSettings $settings = null,
    ) {
        $this->settings = $settings ?? (new MonitorSettingsFile($projectDir))->read();

        parent::__construct($environment, $debug);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle()];
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getCacheDir(): string
    {
        return $this->projectDir.'/var/cache/'.$this->environment.'/dump-loader-monitor/'.substr(hash('xxh128', serialize([$this->mountPath, $this->settings])), 0, 8);
    }

    public function getLogDir(): string
    {
        return $this->projectDir.'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->parameters()
            ->set('dump_loader.jobs_dir', $this->settings->jobsDir())
            ->set('dump_loader.pending_grace_period', $this->settings->pendingGracePeriod)
            ->set('dump_loader.retention', $this->settings->retention)
            ->set('dump_loader.monitor_path', $this->mountPath)
        ;

        $container->extension('framework', [
            'secret' => '%env(APP_SECRET)%',
            'test' => 'test' === $this->environment,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'csrf_protection' => true,
            'router' => ['utf8' => true],
            'session' => [
                'name' => 'DUMP_LOADER_MONITOR',
                'cookie_path' => $this->mountPath,
                'storage_factory_id' => 'test' === $this->environment ? 'session.storage.factory.mock_file' : 'session.storage.factory.native',
            ],
            'default_locale' => 'en',
            'set_locale_from_accept_language' => true,
            'enabled_locales' => ['en', 'fr'],
            'translator' => ['paths' => [__DIR__.'/../Resources/translations']],
        ]);

        $container->extension('twig', [
            'paths' => [__DIR__.'/../Resources/views' => 'DumpLoader'],
            'strict_variables' => true,
        ]);

        $container->import(__DIR__.'/../Resources/config/monitor/services.php');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes
            ->import(__DIR__.'/../Resources/config/monitor/routes.php')
            ->prefix($this->mountPath)
        ;
    }
}
