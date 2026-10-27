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

use DumpLoader\LoadJob\AbortLoadJobHandler;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\File\FileAbortSignal;
use DumpLoader\LoadJob\File\FileLoadJobRepository;
use DumpLoader\LoadJob\File\FlockJobLock;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\VanishedJobs;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('dump_loader.job_repository', FileLoadJobRepository::class)
        ->args([param('dump_loader.jobs_dir'), service('clock')])
    ;

    $services->set('dump_loader.abort_signal', FileAbortSignal::class)
        ->args([param('dump_loader.jobs_dir')])
    ;

    $services->set('dump_loader.job_lock', FlockJobLock::class)
        ->args([param('dump_loader.jobs_dir')])
    ;

    $services->set('dump_loader.vanished_jobs', VanishedJobs::class)
        ->args([
            service('dump_loader.job_lock'),
            service('clock'),
            param('dump_loader.pending_grace_period'),
        ])
    ;

    $services->set('dump_loader.handler.abort', AbortLoadJobHandler::class)
        ->args([service('dump_loader.job_repository'), service('dump_loader.abort_signal')])
    ;

    $services->alias(LoadJobRepository::class, 'dump_loader.job_repository');
    $services->alias(AbortSignal::class, 'dump_loader.abort_signal');
    $services->alias(JobLock::class, 'dump_loader.job_lock');
};
