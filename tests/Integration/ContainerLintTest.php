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

namespace DumpLoader\Tests\Integration;

use DumpLoader\Command\LoadDumpCommand;
use DumpLoader\Database\DatabaseClient;
use DumpLoader\Database\RestorePolicy;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\Execution\Process\SynchronousLauncher;
use DumpLoader\LoadJob\DashboardQuery;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Requirements\CheckRequirements;
use DumpLoader\Security\CurrentRequester;
use DumpLoader\Security\UnknownRequester;
use DumpLoader\Step\ClearCaches;
use DumpLoader\Step\DropAllTables;
use DumpLoader\Step\RestoreDatabase;
use DumpLoader\Step\RunMigrations;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepSequence;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Routing\RouterInterface;

final class ContainerLintTest extends KernelTestCase
{
    use BootsTestKernel;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    #[Test]
    public function it_should_compile_the_container_and_expose_the_read_model(): void
    {
        self::assertInstanceOf(DashboardQuery::class, self::service(DashboardQuery::class));
    }

    #[Test]
    public function it_should_alias_every_port_to_its_implementation(): void
    {
        self::assertInstanceOf(DumpSource::class, self::service(DumpSource::class));
        self::assertInstanceOf(DatabaseClient::class, self::service(DatabaseClient::class));
        self::assertInstanceOf(RestorePolicy::class, self::service(RestorePolicy::class));
        self::assertInstanceOf(LoadJobRepository::class, self::service(LoadJobRepository::class));
        self::assertInstanceOf(StepSequence::class, self::service(StepSequence::class));
        self::assertInstanceOf(CheckRequirements::class, self::service(CheckRequirements::class));
    }

    #[Test]
    public function it_should_wire_the_synchronous_launcher_when_configured(): void
    {
        self::assertInstanceOf(SynchronousLauncher::class, self::service(LoadJobLauncher::class));
    }

    #[Test]
    public function it_should_fall_back_to_the_unknown_requester_without_security(): void
    {
        self::assertInstanceOf(UnknownRequester::class, self::service(CurrentRequester::class));
    }

    #[Test]
    public function it_should_register_the_four_steps_through_the_tag(): void
    {
        $sequence = self::service(StepSequence::class);

        self::assertInstanceOf(DropAllTables::class, $sequence->named(DropAllTables::NAME));
        self::assertInstanceOf(RestoreDatabase::class, $sequence->named(RestoreDatabase::NAME));
        self::assertInstanceOf(RunMigrations::class, $sequence->named(RunMigrations::NAME));
        self::assertInstanceOf(ClearCaches::class, $sequence->named(ClearCaches::NAME));
    }

    #[Test]
    public function it_should_plan_only_the_mandatory_steps_without_options(): void
    {
        self::assertSame(
            [DropAllTables::NAME, RestoreDatabase::NAME],
            array_map(
                static fn (Step $step): string => $step->definition()->name,
                self::service(StepSequence::class)->plan(LoadOptions::none()),
            ),
        );
    }

    #[Test]
    public function it_should_register_the_routes_under_the_host_prefix(): void
    {
        $routes = self::service(RouterInterface::class)->getRouteCollection();

        $expected = [
            'dump_loader_dashboard' => '/dump-loader/',
            'dump_loader_request' => '/dump-loader/request',
            'dump_loader_open_monitor' => '/dump-loader/open-monitor',
        ];

        foreach ($expected as $name => $path) {
            $route = $routes->get($name);

            self::assertNotNull($route, \sprintf('Route "%s" is missing.', $name));
            self::assertSame($path, $route->getPath());
        }

        self::assertSame(array_keys($expected), array_keys($routes->all()));
    }

    #[Test]
    public function it_should_register_the_load_command(): void
    {
        $command = (new Application(self::$kernel ?? self::bootKernel()))->find('dump-loader:load');

        self::assertInstanceOf(LazyCommand::class, $command);
        self::assertInstanceOf(LoadDumpCommand::class, $command->getCommand());
    }

    #[Test]
    public function it_should_read_the_dumps_from_the_configured_flysystem_storage(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel(['config' => 'flysystem']);

        $filesystem = self::getContainer()->get('dumps.storage');

        self::assertInstanceOf(FilesystemOperator::class, $filesystem);
        $filesystem->write('prod.sql', 'SELECT 1;');

        self::assertCount(1, self::service(DumpSource::class)->list());
    }
}
