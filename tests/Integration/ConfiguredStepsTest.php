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

use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepSequence;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConfiguredStepsTest extends KernelTestCase
{
    use BootsTestKernel;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        self::bootKernel(['config' => 'steps']);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    #[Test]
    public function it_should_run_the_step_its_factory_builds_from_the_configured_options(): void
    {
        $planned = self::service(StepSequence::class)->plan(LoadOptions::none());

        self::assertSame(
            ['drop_all_tables', 'restore_database', 'anonymize_database'],
            array_map(static fn (Step $step): string => $step->definition()->name, $planned),
        );
    }
}
