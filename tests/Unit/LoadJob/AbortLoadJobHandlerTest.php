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

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\LoadJob\AbortLoadJob;
use DumpLoader\LoadJob\AbortLoadJobHandler;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\InvalidTransition;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobNotFound;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\Requester;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AbortLoadJobHandlerTest extends TestCase
{
    use JobFixtures;

    #[Test]
    public function it_should_raise_the_abort_signal_without_touching_the_job(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $jobs->save($this->pendingJob());

        $signal = $this->createMock(AbortSignal::class);
        $signal->expects(self::once())->method('request')->with($this->aJobId());

        (new AbortLoadJobHandler($jobs, $signal))(new AbortLoadJob($this->aJobId()));

        self::assertSame($this->pendingJob()->snapshot()->status, $jobs->get($this->aJobId())->snapshot()->status);
    }

    #[Test]
    public function it_should_refuse_to_abort_a_finished_job(): void
    {
        $jobs = new InMemoryLoadJobRepository();

        $job = $this->pendingJob();
        $job->start();
        $job->complete();
        $jobs->save($job);

        $signal = $this->createMock(AbortSignal::class);
        $signal->expects(self::never())->method('request');

        $this->expectException(InvalidTransition::class);

        (new AbortLoadJobHandler($jobs, $signal))(new AbortLoadJob($this->aJobId()));
    }

    #[Test]
    public function it_should_refuse_an_unknown_job(): void
    {
        $this->expectException(LoadJobNotFound::class);

        (new AbortLoadJobHandler(new InMemoryLoadJobRepository(), $this->createStub(AbortSignal::class)))(
            new AbortLoadJob($this->aJobId()),
        );
    }

    private function pendingJob(): LoadJob
    {
        return LoadJob::request(
            $this->aJobId(),
            $this->aDump(),
            LoadOptions::none(),
            $this->plannedSteps(),
            Requester::unknown(),
            new MockClock('2026-09-18 08:00:00'),
        );
    }
}
