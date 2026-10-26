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

namespace DumpLoader\Tests\Integration\Monitor;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\FileSize;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\StepStatus;
use DumpLoader\Monitor\MonitorAccess;
use DumpLoader\Monitor\MonitorKernel;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Tests\Integration\BootsTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\KernelInterface;

final class MonitorWebTest extends WebTestCase
{
    use BootsTestKernel;

    private const MOUNT_PATH = '/dump-loader/monitor';

    private const PROJECT_DIR = '/DumpLoaderBundle/monitor';

    private KernelBrowser $client;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $mountPath = $options['mount_path'] ?? self::MOUNT_PATH;

        return new MonitorKernel(
            'test',
            true,
            sys_get_temp_dir().self::PROJECT_DIR,
            \is_string($mountPath) ? $mountPath : self::MOUNT_PATH,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->grantAccess();

        $this->purgeJobs();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    #[Test]
    public function it_should_follow_the_active_job(): void
    {
        $job = $this->aRunningJobHoldingItsLock();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter('#dump-loader-progress')->attr('data-active'));
        self::assertSelectorTextContains('.badge-running', 'Running');
        self::assertSelectorTextContains('.card', $job->snapshot()->dumpName);
        self::assertCount(1, $crawler->filter('form[action="'.self::MOUNT_PATH.'/abort"]'));
    }

    #[Test]
    public function it_should_show_the_last_job_once_everything_is_at_rest(): void
    {
        $this->aCompletedJob();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('#dump-loader-progress')->attr('data-active'));
        self::assertCount(1, $crawler->filter('[data-terminal="1"]'));
        self::assertSelectorTextContains('.badge-completed', 'Completed');
    }

    #[Test]
    public function it_should_show_an_empty_history_without_any_job(): void
    {
        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('#dump-loader-progress')->attr('data-active'));
        self::assertSelectorTextContains('.card', 'No load job has run yet.');
        self::assertCount(0, $crawler->filter('form'));
    }

    #[Test]
    public function it_should_serve_the_progress_fragment_for_polling(): void
    {
        $job = $this->aRunningJobHoldingItsLock();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/progress');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.load-title h2', 'Load in progress');
        self::assertSelectorTextContains('.card', $job->snapshot()->dumpName);
        self::assertCount(0, $crawler->filter('[data-terminal="1"]'));
    }

    #[Test]
    public function it_should_time_every_step_already_done(): void
    {
        $this->aJobHalfwayThrough();

        $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertSelectorTextContains('.step-done .timeline-meta', '1 min 52 s');
        self::assertSelectorTextContains('.step-running .step-badge', 'Running');
        self::assertSelectorTextContains('.load-count', '2/2');
    }

    #[Test]
    public function it_should_count_the_time_of_a_running_step_that_reports_no_percentage(): void
    {
        $this->aJobHalfwayThrough();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/progress');

        $meta = $crawler->filter('.step-running .timeline-meta')->text();
        self::assertMatchesRegularExpression('/^\d+ (s|min \d{2} s|h \d{2} min)$/', $meta);
    }

    #[Test]
    public function it_should_show_the_percentage_a_running_step_reports_next_to_its_time(): void
    {
        $job = $this->aJobHalfwayThrough();
        $job->progress(Percentage::of(34));
        self::service(LoadJobRepository::class)->save($job);

        $this->client->request('GET', self::MOUNT_PATH.'/progress');

        self::assertSelectorTextContains('.step-running .timeline-meta', '34% · ');
    }

    #[Test]
    public function it_should_show_only_the_last_lines_of_the_log_under_the_running_step(): void
    {
        $this->aJobHalfwayThrough();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/progress');

        $tail = $crawler->filter('.step-running .journal-tail > div');
        self::assertCount(3, $tail);
        self::assertStringContainsString('UPDATE cm_authentication_log', $tail->last()->text());
        self::assertStringContainsString('Run migrations', $crawler->filter('.step-running .journal .log > div')->eq(4)->text());
        self::assertSelectorTextContains('.step-running .journal summary', 'Show the full log (8 lines)');
        self::assertCount(8, $crawler->filter('.step-running .journal .log > div'));
    }

    #[Test]
    public function it_should_set_the_log_after_the_timeline_once_no_step_runs(): void
    {
        $this->aCompletedJob();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/progress');

        self::assertCount(0, $crawler->filter('.timeline .journal'));
        self::assertCount(1, $crawler->filter('.load > .journal'));
    }

    #[Test]
    public function it_should_tell_how_long_a_running_job_has_run_and_that_the_page_follows_it(): void
    {
        $this->aJobHalfwayThrough();

        $this->client->request('GET', self::MOUNT_PATH.'/progress');

        self::assertSelectorTextContains('.load-foot', 'elapsed');
        self::assertSelectorTextContains('.load-foot', 'this page refreshes itself');
    }

    #[Test]
    public function it_should_tell_when_a_finished_job_ended_and_how_long_it_ran(): void
    {
        $clock = new MockClock('2026-09-19 10:00:00');
        $job = $this->aTimedJob($clock);
        $clock->sleep(8);
        $job->completeStep();
        $job->complete();
        self::service(LoadJobRepository::class)->save($job);

        $this->client->request('GET', self::MOUNT_PATH.'/progress');

        self::assertSelectorTextContains('.load-title h2', 'Load completed');
        self::assertSelectorTextContains('.load-foot', 'Ended at 10:02, after 2 min 00 s');
    }

    #[Test]
    public function it_should_serve_a_terminal_progress_fragment_once_the_job_is_over(): void
    {
        $this->aCompletedJob();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/progress');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-terminal="1"]'));
    }

    #[Test]
    public function it_should_warn_instead_of_following_a_job_while_a_job_file_is_unreadable(): void
    {
        $this->aCompletedJob();
        $this->anUnreadableJobFile();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('#dump-loader-progress')->attr('data-active'));
        self::assertSelectorTextContains('#dump-loader-progress .alert', 'cannot be followed');
        self::assertCount(0, $crawler->filter('#dump-loader-progress .card'));
        self::assertCount(1, $crawler->filter('.badge-completed'));
    }

    #[Test]
    public function it_should_serve_the_warning_to_the_polling_while_a_job_file_is_unreadable(): void
    {
        $this->anUnreadableJobFile();

        $this->client->request('GET', self::MOUNT_PATH.'/progress');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', '01J8Z9P0000000000000000001.json');
    }

    #[Test]
    public function it_should_raise_the_abort_signal(): void
    {
        $job = $this->aRunningJobHoldingItsLock();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');
        $token = (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', self::MOUNT_PATH.'/abort', [
            '_token' => $token,
            'id' => $job->id()->value,
        ]);

        self::assertResponseRedirects(self::MOUNT_PATH.'/');
        self::assertTrue(self::service(AbortSignal::class)->isRaised($job->id()));
    }

    #[Test]
    public function it_should_say_so_when_an_abort_carries_an_invalid_token(): void
    {
        $job = $this->aRunningJobHoldingItsLock();

        $this->client->request('POST', self::MOUNT_PATH.'/abort', [
            '_token' => 'forged',
            'id' => $job->id()->value,
        ]);

        self::assertResponseRedirects(self::MOUNT_PATH.'/');
        self::assertFalse(self::service(AbortSignal::class)->isRaised($job->id()));

        $this->client->followRedirect();
        self::assertSelectorTextContains('main > .alert', 'the load was not aborted');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownJobIds(): iterable
    {
        yield 'not an identifier' => ['not-an-ulid'];
        yield 'no such job' => ['01J8Z9P0000000000000000009'];
    }

    #[Test]
    #[DataProvider('unknownJobIds')]
    public function it_should_say_so_when_an_abort_names_an_unknown_job(string $id): void
    {
        $job = $this->aRunningJobHoldingItsLock();

        $this->client->request('POST', self::MOUNT_PATH.'/abort', [
            '_token' => $this->abortToken(),
            'id' => $id,
        ]);

        self::assertResponseRedirects(self::MOUNT_PATH.'/');
        self::assertFalse(self::service(AbortSignal::class)->isRaised($job->id()));

        $this->client->followRedirect();
        self::assertSelectorTextContains('main > .alert', 'no longer exists');
    }

    #[Test]
    public function it_should_say_so_when_an_abort_names_an_unreadable_job(): void
    {
        $this->aRunningJobHoldingItsLock();
        $token = $this->abortToken();
        $this->anUnreadableJobFile();

        $this->client->request('POST', self::MOUNT_PATH.'/abort', [
            '_token' => $token,
            'id' => '01J8Z9P0000000000000000001',
        ]);

        self::assertResponseRedirects(self::MOUNT_PATH.'/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main > .alert', 'cannot be read; nothing was aborted');
    }

    #[Test]
    public function it_should_quietly_go_back_to_the_monitor_when_an_abort_names_a_finished_job(): void
    {
        $this->aRunningJobHoldingItsLock();
        $finished = $this->aCompletedJob();

        $this->client->request('POST', self::MOUNT_PATH.'/abort', [
            '_token' => $this->abortToken(),
            'id' => $finished->id()->value,
        ]);

        self::assertResponseRedirects(self::MOUNT_PATH.'/');
        self::assertFalse(self::service(AbortSignal::class)->isRaised($finished->id()));

        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('main > .alert'));
    }

    #[Test]
    public function it_should_turn_a_visitor_without_access_away(): void
    {
        $this->aRunningJobHoldingItsLock();
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.alert', 'open it again from the dump loader dashboard');
        self::assertCount(0, $crawler->filter('#dump-loader-progress'));
        self::assertCount(0, $crawler->filter('form'));
    }

    #[Test]
    public function it_should_stop_the_polling_of_a_visitor_without_access(): void
    {
        $this->aRunningJobHoldingItsLock();
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->xmlHttpRequest('GET', self::MOUNT_PATH.'/progress');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $crawler->filter('[data-terminal="1"]'));
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function it_should_refuse_an_abort_from_a_visitor_without_access(): void
    {
        $job = $this->aRunningJobHoldingItsLock();
        $token = $this->abortToken();
        $this->client->getCookieJar()->expire(MonitorAccess::COOKIE, self::MOUNT_PATH);

        $this->client->request('POST', self::MOUNT_PATH.'/abort', [
            '_token' => $token,
            'id' => $job->id()->value,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(self::service(AbortSignal::class)->isRaised($job->id()));
    }

    #[Test]
    public function it_should_turn_a_forged_access_away(): void
    {
        $this->client->getCookieJar()->set(new BrowserKitCookie(MonitorAccess::COOKIE, '4102444800.'.str_repeat('0', 64), path: self::MOUNT_PATH));

        $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function it_should_show_a_job_lost_when_its_lock_was_released(): void
    {
        $this->aRunningJob();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('#dump-loader-progress')->attr('data-active'));
        self::assertSelectorTextContains('.badge-lost', 'Lost');
    }

    #[Test]
    public function it_should_generate_every_url_under_its_mount_path(): void
    {
        $this->aRunningJobHoldingItsLock();

        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        self::assertSame(self::MOUNT_PATH.'/progress', $crawler->filter('#dump-loader-progress')->attr('data-url'));
        self::assertSame(self::MOUNT_PATH.'/abort', $crawler->filter('form')->attr('action'));
    }

    #[Test]
    public function it_should_answer_under_another_mount_path(): void
    {
        self::ensureKernelShutdown();

        $this->client = self::createClient(['mount_path' => '/follow']);
        $this->grantAccess();

        $this->aRunningJobHoldingItsLock();

        $crawler = $this->client->request('GET', '/follow/');

        self::assertResponseIsSuccessful();
        self::assertSame('/follow/progress', $crawler->filter('#dump-loader-progress')->attr('data-url'));
        self::assertSame('/follow/abort', $crawler->filter('form')->attr('action'));
    }

    #[Test]
    public function it_should_carry_its_own_session_cookie(): void
    {
        $this->aRunningJobHoldingItsLock();

        $this->client->request('GET', self::MOUNT_PATH.'/');

        $cookies = $this->client->getResponse()->headers->getCookies();
        $options = self::getContainer()->getParameter('session.storage.options');

        self::assertIsArray($options);
        self::assertSame('DUMP_LOADER_MONITOR', $options['name'] ?? null);
        self::assertCount(1, $cookies);
        self::assertSame(self::MOUNT_PATH, $cookies[0]->getPath());
    }

    #[Test]
    public function it_should_boot_without_the_host_bundles(): void
    {
        $bundles = array_keys(self::$kernel?->getBundles() ?? []);

        self::assertContains('TwigBundle', $bundles);
        self::assertNotContains('DumpLoaderBundle', $bundles);
        self::assertNotContains('SecurityBundle', $bundles);
        self::assertNotContains('DoctrineBundle', $bundles);
        self::assertFalse(self::getContainer()->has('dump_loader.dashboard_query'));
        self::assertFalse(self::getContainer()->has('security.token_storage'));
        self::assertFalse(self::getContainer()->has('doctrine'));
    }

    private function grantAccess(): void
    {
        $access = self::service(MonitorAccess::class)->open()->headers->getCookies()[0];

        $this->client->getCookieJar()->set(BrowserKitCookie::fromString((string) $access));
    }

    private function abortToken(): string
    {
        $crawler = $this->client->request('GET', self::MOUNT_PATH.'/');

        return (string) $crawler->filter('form input[name="_token"]')->first()->attr('value');
    }

    private function aRunningJobHoldingItsLock(): LoadJob
    {
        $job = $this->aRunningJob();

        self::assertTrue(self::service(JobLock::class)->acquire($job->id()));

        return $job;
    }

    private function aRunningJob(): LoadJob
    {
        $job = $this->aRequestedJob();
        $job->start();
        $job->startStep('restore_database');

        self::service(LoadJobRepository::class)->save($job);

        return $job;
    }

    private function aCompletedJob(): LoadJob
    {
        $job = $this->aRequestedJob();
        $job->start();
        $job->startStep('restore_database');
        $job->completeStep();
        $job->complete();

        self::service(LoadJobRepository::class)->save($job);

        return $job;
    }

    private function aJobHalfwayThrough(): LoadJob
    {
        $job = $this->aTimedJob(new MockClock('2026-09-19 10:00:00'));

        self::service(LoadJobRepository::class)->save($job);
        self::assertTrue(self::service(JobLock::class)->acquire($job->id()));

        return $job;
    }

    private function aTimedJob(MockClock $clock): LoadJob
    {
        $job = LoadJob::request(
            id: LoadJobId::generate(),
            dump: Dump::fromPath('app_2026-10-12.sql.gz', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-19 09:00:00')),
            options: LoadOptions::none(),
            steps: [
                new PlannedStep(name: 'restore_database', label: 'step.restore_database.label', weight: 10, status: StepStatus::Todo),
                new PlannedStep(name: 'run_migrations', label: 'step.run_migrations.label', weight: 2, status: StepStatus::Todo),
            ],
            requester: Requester::named('an operator'),
            clock: $clock,
        );
        $job->start();
        $job->startStep('restore_database');
        $clock->sleep(112);
        $job->completeStep();
        $job->startStep('run_migrations');
        $job->log(LogTag::Run, 'Version20260831123639');
        $job->log(LogTag::Run, 'ALTER TABLE cm_authentication_log ADD user_class');
        $job->log(LogTag::Run, 'UPDATE cm_authentication_log l INNER JOIN as_assure_access a');

        return $job;
    }

    private function aRequestedJob(): LoadJob
    {
        return LoadJob::request(
            id: LoadJobId::generate(),
            dump: Dump::fromPath('simple.sql', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-19 09:00:00')),
            options: LoadOptions::none(),
            steps: [PlannedStep::fromDefinition(new StepDefinition(
                name: 'restore_database',
                label: 'step.restore_database.label',
                description: 'step.restore_database.description',
                rank: StepDefinition::RANK_RESTORE_DATABASE,
                weight: 100,
            ))],
            requester: Requester::named('an operator'),
            clock: new MockClock('2026-09-19 10:00:00'),
        );
    }

    private function anUnreadableJobFile(): void
    {
        $jobsDir = $this->jobsDir();

        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0o775, true);
        }

        file_put_contents($jobsDir.'/01J8Z9P0000000000000000001.json', '{"version": 2}');
    }

    private function purgeJobs(): void
    {
        foreach (glob($this->jobsDir().'/*') ?: [] as $path) {
            unlink($path);
        }
    }

    private function jobsDir(): string
    {
        $jobsDir = self::getContainer()->getParameter('dump_loader.jobs_dir');

        self::assertIsString($jobsDir);

        return $jobsDir;
    }
}
