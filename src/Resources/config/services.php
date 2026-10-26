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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use DumpLoader\Command\CheckRequirementsCommand;
use DumpLoader\Command\Labels;
use DumpLoader\Command\LoadDumpCommand;
use DumpLoader\Command\RunLoadJobCommand;
use DumpLoader\Command\Screen\Screens;
use DumpLoader\Controller\DashboardController;
use DumpLoader\Controller\OpenMonitorController;
use DumpLoader\Controller\RequestLoadJobController;
use DumpLoader\Database\DatabaseClient;
use DumpLoader\Database\Mysql\DefaultsFile;
use DumpLoader\Database\Mysql\MysqlBinary;
use DumpLoader\Database\Mysql\MysqlClient;
use DumpLoader\Database\Mysql\MysqlRestorePolicy;
use DumpLoader\Database\RestorePolicy;
use DumpLoader\Database\TargetDatabase;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\Flysystem\FlysystemDumpSource;
use DumpLoader\Dump\LocalDumpFiles;
use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\Execution\Process\DetachedProcessLauncher;
use DumpLoader\Execution\Process\SymfonyProcessRunner;
use DumpLoader\Execution\Process\SynchronousLauncher;
use DumpLoader\Execution\ProcessRunner;
use DumpLoader\LoadJob\DashboardQuery;
use DumpLoader\LoadJob\FollowLoadJobs;
use DumpLoader\LoadJob\ReconcileLostJobs;
use DumpLoader\LoadJob\RequestLoadJobHandler;
use DumpLoader\LoadJob\RunLoadJobHandler;
use DumpLoader\Monitor\MonitorSettings;
use DumpLoader\Monitor\MonitorSettingsFile;
use DumpLoader\Monitor\PublishMonitorSettings;
use DumpLoader\Requirements\CheckRequirements;
use DumpLoader\Security\CurrentRequester;
use DumpLoader\Security\SecurityRequester;
use DumpLoader\Security\UnknownRequester;
use DumpLoader\Step\ClearCaches;
use DumpLoader\Step\DropAllTables;
use DumpLoader\Step\RestoreDatabase;
use DumpLoader\Step\RunMigrations;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepSequence;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

return static function (ContainerConfigurator $container): void {
    $container->import(__DIR__.'/jobs.php');
    $container->import(__DIR__.'/monitor_access.php');

    $services = $container->services();

    $services->set('dump_loader.target_database_dsn', TargetDatabaseDsn::class)
        ->args([param('dump_loader.target_database.dsn')])
    ;

    $services->set('dump_loader.target_database', TargetDatabase::class)
        ->factory([service('dump_loader.target_database_dsn'), 'targetDatabase'])
    ;

    $services->set('dump_loader.mysql.binary', MysqlBinary::class)
        ->args([param('dump_loader.mysql.binary')])
    ;

    $services->set('dump_loader.dump_source.filesystem', Filesystem::class)
        ->args([inline_service(LocalFilesystemAdapter::class)->args([param('dump_loader.source.directory')])])
    ;

    $services->set('dump_loader.dump_source.flysystem', FlysystemDumpSource::class)
        ->args([service('dump_loader.dump_source.filesystem')])
    ;

    $services->set('dump_loader.dump_source', LocalDumpFiles::class)
        ->args([
            service('dump_loader.dump_source.flysystem'),
            param('dump_loader.source.directory'),
        ])
    ;

    $services->set('dump_loader.process_runner', SymfonyProcessRunner::class)
        ->args([service('clock')])
    ;

    $services->set('dump_loader.database_client', MysqlClient::class)
        ->args([
            service('dump_loader.process_runner'),
            inline_service(DefaultsFile::class)->args([param('dump_loader.var_dir'), param('dump_loader.mysql.ssl')]),
            service('dump_loader.mysql.binary'),
        ])
    ;

    $services->set('dump_loader.restore_policy', MysqlRestorePolicy::class);

    $services->set('dump_loader.step.drop_all_tables', DropAllTables::class)
        ->args([
            service('dump_loader.dump_source'),
            service('dump_loader.restore_policy'),
            service('dump_loader.database_client'),
            service('dump_loader.target_database_dsn'),
        ])
        ->tag(Step::class)
    ;

    $services->set('dump_loader.step.restore_database', RestoreDatabase::class)
        ->args([
            service('dump_loader.dump_source'),
            service('dump_loader.database_client'),
            service('dump_loader.target_database_dsn'),
        ])
        ->tag(Step::class)
    ;

    $services->set('dump_loader.step.run_migrations', RunMigrations::class)
        ->args([
            service('dump_loader.process_runner'),
            param('dump_loader.php_binary'),
            param('dump_loader.console'),
        ])
        ->tag(Step::class)
    ;

    $services->set('dump_loader.step.clear_caches', ClearCaches::class)
        ->args([
            service('dump_loader.process_runner'),
            param('dump_loader.php_binary'),
            param('dump_loader.console'),
        ])
        ->tag(Step::class)
    ;

    $services->set('dump_loader.step_sequence', StepSequence::class)
        ->args([tagged_iterator(Step::class)])
    ;

    $services->set('dump_loader.reconcile_lost_jobs', ReconcileLostJobs::class)
        ->args([
            service('dump_loader.job_repository'),
            service('dump_loader.vanished_jobs'),
            service('dump_loader.job_lock'),
        ])
    ;

    $services->set('dump_loader.launcher.detached', DetachedProcessLauncher::class)
        ->args([param('dump_loader.console'), param('dump_loader.jobs_dir'), param('dump_loader.php_binary')])
    ;

    $services->set('dump_loader.launcher.synchronous', SynchronousLauncher::class)
        ->args([service('dump_loader.handler.run')])
    ;

    $services->set('dump_loader.handler.request', RequestLoadJobHandler::class)
        ->args([
            service('dump_loader.dump_source'),
            service('dump_loader.restore_policy'),
            service('dump_loader.step_sequence'),
            service('dump_loader.job_repository'),
            service('dump_loader.reconcile_lost_jobs'),
            service('dump_loader.launcher'),
            service('lock.factory'),
            service('clock'),
            param('dump_loader.retention'),
        ])
    ;

    $services->set('dump_loader.handler.run', RunLoadJobHandler::class)
        ->args([
            service('dump_loader.job_repository'),
            service('dump_loader.step_sequence'),
            service('dump_loader.dump_source'),
            service('dump_loader.job_lock'),
            service('dump_loader.abort_signal'),
            service('clock'),
        ])
    ;

    $services->set('dump_loader.dashboard_query', DashboardQuery::class)
        ->args([
            service('dump_loader.dump_source'),
            service('dump_loader.step_sequence'),
            service('dump_loader.job_repository'),
            service('dump_loader.vanished_jobs'),
            service('dump_loader.target_database_dsn'),
            param('dump_loader.retention'),
        ])
    ;

    $services->set(UnknownRequester::class, UnknownRequester::class);

    $services->set(SecurityRequester::class, SecurityRequester::class)
        ->args([service('security.token_storage')])
    ;

    $services->set('dump_loader.check_requirements', CheckRequirements::class)
        ->args([
            service('dump_loader.dump_source'),
            service('dump_loader.target_database_dsn'),
            param('dump_loader.var_dir'),
            param('dump_loader.console'),
            service('dump_loader.mysql.binary'),
            param('dump_loader.php_binary'),
        ])
    ;

    $services->set('dump_loader.controller.dashboard', DashboardController::class)
        ->args([
            service('dump_loader.dashboard_query'),
            service('dump_loader.check_requirements'),
            service('twig'),
        ])
        ->tag('controller.service_arguments')
        ->public()
    ;

    $services->set('dump_loader.controller.request', RequestLoadJobController::class)
        ->args([
            service('dump_loader.handler.request'),
            service('dump_loader.requester'),
            service('security.csrf.token_manager'),
            service('router'),
            service('dump_loader.monitor_access'),
        ])
        ->tag('controller.service_arguments')
        ->public()
    ;

    $services->set('dump_loader.controller.open_monitor', OpenMonitorController::class)
        ->args([service('dump_loader.monitor_access')])
        ->tag('controller.service_arguments')
        ->public()
    ;

    $services->set('dump_loader.publish_monitor_settings', PublishMonitorSettings::class)
        ->args([
            inline_service(MonitorSettingsFile::class)->args([param('kernel.project_dir')]),
            inline_service(MonitorSettings::class)->args([
                param('dump_loader.var_dir'),
                param('dump_loader.pending_grace_period'),
                param('dump_loader.retention'),
            ]),
        ])
        ->tag('kernel.cache_warmer')
    ;

    $services->set('dump_loader.command.run', RunLoadJobCommand::class)
        ->args([service('dump_loader.handler.run')])
        ->tag('console.command')
    ;

    $services->set('dump_loader.command.check', CheckRequirementsCommand::class)
        ->args([service('dump_loader.check_requirements')])
        ->tag('console.command')
    ;

    $services->set('dump_loader.follow_load_jobs', FollowLoadJobs::class)
        ->args([
            service('dump_loader.job_repository'),
            service('dump_loader.vanished_jobs'),
            service('dump_loader.handler.abort'),
        ])
    ;

    $services->set('dump_loader.labels', Labels::class)
        ->args([service('translator')])
    ;

    $services->set('dump_loader.command.load', LoadDumpCommand::class)
        ->args([
            service('dump_loader.dashboard_query'),
            service('dump_loader.check_requirements'),
            service('dump_loader.handler.request'),
            service('dump_loader.follow_load_jobs'),
            inline_service(Screens::class)->args([service('clock'), service('dump_loader.labels')]),
            service('dump_loader.labels'),
        ])
        ->tag('console.command')
    ;

    $services->alias(DumpSource::class, 'dump_loader.dump_source');
    $services->alias(DatabaseClient::class, 'dump_loader.database_client');
    $services->alias(RestorePolicy::class, 'dump_loader.restore_policy');
    $services->alias(ProcessRunner::class, 'dump_loader.process_runner');
    $services->alias(LoadJobLauncher::class, 'dump_loader.launcher');
    $services->alias(TargetDatabase::class, 'dump_loader.target_database');
    $services->alias(StepSequence::class, 'dump_loader.step_sequence');
    $services->alias(CurrentRequester::class, 'dump_loader.requester');
    $services->alias(CheckRequirements::class, 'dump_loader.check_requirements');
    $services->alias(DashboardQuery::class, 'dump_loader.dashboard_query');
};
