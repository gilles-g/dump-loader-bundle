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

namespace DumpLoader\Tests\Unit\Command;

use DumpLoader\Command\LoadJobReport;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\Requester;
use DumpLoader\Tests\Double\EnglishLabels;
use DumpLoader\Tests\Unit\LoadJob\JobFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LoadJobReportTest extends TestCase
{
    use JobFixtures;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-18 08:00:00');
    }

    #[Test]
    public function it_should_report_a_completed_job_with_its_duration_and_steps(): void
    {
        $job = $this->aJob(LoadOptions::fromNames(['clear_caches']));
        $job->start();
        $this->clock->sleep(75);
        $job->startStep('restore_database');
        $job->completeStep();
        $job->complete();

        $report = new LoadJobReport($job->snapshot(), EnglishLabels::create());
        $display = $this->display($report);

        self::assertTrue($report->isSuccessful());
        self::assertStringContainsString('Load job 01J8Z9P0000000000000000000', $display);
        self::assertStringContainsString('completed', $display);
        self::assertStringContainsString('clear_caches', $display);
        self::assertStringContainsString('1 min', $display);
        self::assertMatchesRegularExpression('/Restore database\s+Done/', $display);
        self::assertMatchesRegularExpression('/Clear caches\s+To do/', $display);
        self::assertStringContainsString('The dump is loaded.', $display);
    }

    #[Test]
    public function it_should_report_a_job_that_failed_before_starting(): void
    {
        $job = $this->aJob();
        $job->fail('The launcher could not start the job.');

        $report = new LoadJobReport($job->snapshot(), EnglishLabels::create());
        $display = $this->display($report);

        self::assertFalse($report->isSuccessful());
        self::assertMatchesRegularExpression('/Duration\s+-/', $display);
        self::assertStringContainsString('none', $display);
        self::assertStringContainsString('The launcher could not start the job.', $display);
    }

    #[Test]
    public function it_should_report_a_lost_job(): void
    {
        $job = $this->aJob();
        $job->start();
        $job->markLost();

        self::assertStringContainsString('The load job was lost: its process vanished.', $this->display(new LoadJobReport($job->snapshot(), EnglishLabels::create())));
    }

    #[Test]
    public function it_should_point_to_the_monitor_for_a_job_still_running(): void
    {
        $job = $this->aJob();
        $job->start();

        $report = new LoadJobReport($job->snapshot(), EnglishLabels::create());

        self::assertFalse($report->isSuccessful());
        self::assertStringContainsString('follow it in the monitor', $this->display($report));
    }

    private function aJob(?LoadOptions $options = null): LoadJob
    {
        return LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: $options ?? LoadOptions::none(),
            steps: $this->plannedSteps(),
            requester: Requester::unknown(),
            clock: $this->clock,
        );
    }

    private function display(LoadJobReport $report): string
    {
        $output = new BufferedOutput();
        $report->writeTo(new SymfonyStyle(new ArrayInput([]), $output));

        return $output->fetch();
    }
}
