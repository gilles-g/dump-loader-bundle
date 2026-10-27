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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use DumpLoader\Monitor\AbortLoadJobController;
use DumpLoader\Monitor\MonitorController;
use DumpLoader\Monitor\MonitorQuery;
use DumpLoader\Monitor\ProgressController;
use DumpLoader\Monitor\RequireMonitorAccess;

return static function (ContainerConfigurator $container): void {
    $container->import(__DIR__.'/../jobs.php');
    $container->import(__DIR__.'/../monitor_access.php');

    $services = $container->services();

    $services->set('dump_loader.require_monitor_access', RequireMonitorAccess::class)
        ->args([service('dump_loader.monitor_access'), service('twig')])
        ->tag('kernel.event_listener', ['event' => 'kernel.request'])
    ;

    $services->set('dump_loader.monitor_query', MonitorQuery::class)
        ->args([
            service('dump_loader.job_repository'),
            service('dump_loader.vanished_jobs'),
            param('dump_loader.retention'),
            service('clock'),
        ])
    ;

    $services->set('dump_loader.controller.monitor', MonitorController::class)
        ->args([service('dump_loader.monitor_query'), service('twig')])
        ->tag('controller.service_arguments')
        ->public()
    ;

    $services->set('dump_loader.controller.progress', ProgressController::class)
        ->args([service('dump_loader.monitor_query'), service('twig')])
        ->tag('controller.service_arguments')
        ->public()
    ;

    $services->set('dump_loader.controller.abort', AbortLoadJobController::class)
        ->args([
            service('dump_loader.handler.abort'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->tag('controller.service_arguments')
        ->public()
    ;

    $services->alias(MonitorQuery::class, 'dump_loader.monitor_query');
};
