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

namespace DumpLoader\Tests\Integration;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\FileSize;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\ClearCaches;
use DumpLoader\Step\LoadContext;
use DumpLoader\Step\RunConsoleCommand;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepSequence;
use DumpLoader\Step\StepTracker;
use DumpLoader\Tests\Double\RecordingProcessRunner;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AfterLoadCommandsTest extends KernelTestCase
{
    use BootsTestKernel;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ADMIN_PASSWORD'] = '$2y$13$hash';
        self::ensureKernelShutdown();
        self::bootKernel(['config' => 'after_load']);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        unset($_ENV['ADMIN_PASSWORD']);
        parent::tearDown();
    }

    #[Test]
    public function it_should_offer_one_option_per_configured_command_in_declaration_order(): void
    {
        $options = self::service(StepSequence::class)->availableOptions();

        $afterLoad = array_values(array_filter(
            $options,
            static fn (Step $step): bool => $step instanceof RunConsoleCommand,
        ));

        self::assertCount(3, $afterLoad);
        self::assertSame('reset_admin_password', $afterLoad[0]->definition()->name);
        self::assertSame('Reset the admin password', $afterLoad[0]->definition()->label);
        self::assertTrue($afterLoad[0]->enabledByDefault());
        self::assertSame('anonymize_emails', $afterLoad[1]->definition()->name);
        self::assertSame('anonymize_emails', $afterLoad[1]->definition()->label);
        self::assertFalse($afterLoad[1]->enabledByDefault());
        self::assertGreaterThan($afterLoad[0]->definition()->rank, $afterLoad[1]->definition()->rank);
    }

    #[Test]
    public function it_should_plan_the_configured_commands_after_the_built_in_steps(): void
    {
        $planned = self::service(StepSequence::class)->plan(
            LoadOptions::fromNames([ClearCaches::NAME, 'reset_admin_password', 'anonymize_emails', 'delete_test_accounts']),
        );

        self::assertSame(
            ['drop_all_tables', 'restore_database', ClearCaches::NAME, 'reset_admin_password', 'anonymize_emails', 'delete_test_accounts'],
            array_map(static fn (Step $step): string => $step->definition()->name, $planned),
        );
    }

    #[Test]
    public function it_should_run_the_configured_command_through_the_console_with_resolved_environment_variables(): void
    {
        $runner = self::getContainer()->get('dump_loader.process_runner');

        self::assertInstanceOf(RecordingProcessRunner::class, $runner);

        self::service(StepSequence::class)
            ->named('reset_admin_password')
            ->run(self::context(), $this->createStub(StepTracker::class));

        $argv = $runner->lastCommand()->argv;

        self::assertSame('dbal:run-sql', $argv[2]);
        self::assertSame('UPDATE `admin` SET `password` = "$2y$13$hash" WHERE `id` = 3282;', $argv[3]);
        self::assertStringEndsWith('/bin/console', $argv[1]);
    }

    #[Test]
    public function it_should_keep_an_escaped_percent_sign_in_the_arguments(): void
    {
        $runner = self::getContainer()->get('dump_loader.process_runner');

        self::assertInstanceOf(RecordingProcessRunner::class, $runner);

        self::service(StepSequence::class)
            ->named('delete_test_accounts')
            ->run(self::context(), $this->createStub(StepTracker::class));

        self::assertSame(
            'DELETE FROM `admin` WHERE `email` LIKE "%@example.com";',
            $runner->lastCommand()->argv[3],
        );
    }

    #[Test]
    public function it_should_rank_the_configured_commands_after_the_caches_have_been_cleared(): void
    {
        $definition = self::service(StepSequence::class)->named('reset_admin_password')->definition();

        self::assertSame(StepDefinition::RANK_AFTER_LOAD, $definition->rank);
        self::assertGreaterThan(StepDefinition::RANK_CLEAR_CACHES, $definition->rank);
    }

    private static function context(): LoadContext
    {
        return new LoadContext(
            LoadJobId::generate(),
            Dump::fromPath('2026-09-18.sql', FileSize::fromBytes(10), new \DateTimeImmutable('2026-09-18 10:00:00')),
            LoadOptions::fromNames(['reset_admin_password']),
        );
    }
}
