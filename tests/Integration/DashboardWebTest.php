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

use DumpLoader\Dump\DumpSource;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\Requester;
use DumpLoader\Monitor\MonitorAccess;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepSequence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;

final class DashboardWebTest extends WebTestCase
{
    use BootsTestKernel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();

        $this->client = self::createClient();

        self::purgeDumpLoaderVarDir();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    #[Test]
    public function it_should_list_the_available_dumps(): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header h1', 'Load a dump');
        self::assertSelectorTextContains('header p', 'preprod');
        self::assertGreaterThan(0, $crawler->filter('select[name="dump"] option')->count());
    }

    #[Test]
    public function it_should_refuse_a_request_without_a_valid_token(): void
    {
        $this->client->request('POST', '/dump-loader/request', [
            '_token' => 'forged',
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'token was invalid');
        self::assertCount(0, self::service(LoadJobRepository::class)->history(10));
    }

    #[Test]
    public function it_should_refuse_a_request_without_confirmation(): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'simple.sql',
        ]);

        self::assertResponseRedirects('/dump-loader/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'must confirm');
        self::assertCount(0, self::service(LoadJobRepository::class)->history(10));
    }

    #[Test]
    public function it_should_run_the_job_and_show_it_in_the_history_once_confirmed(): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/monitor');

        $history = self::service(LoadJobRepository::class)->history(10);

        self::assertCount(1, $history);

        $snapshot = $history->first();

        self::assertNotNull($snapshot);
        self::assertSame('simple.sql', $snapshot->dumpName);
        self::assertContains($snapshot->status, [LoadJobStatus::Failed, LoadJobStatus::Completed]);

        $this->client->request('GET', '/dump-loader/');
        self::assertSelectorTextContains('table', 'simple.sql');
    }

    #[Test]
    public function it_should_show_an_unreadable_target_database_dsn_as_an_unmet_requirement(): void
    {
        self::ensureKernelShutdown();

        $this->client = self::createClient(['config' => 'invalid_dsn']);

        $crawler = $this->client->request('GET', '/dump-loader/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'Target database DSN');
        self::assertSelectorTextContains('header p', '—');
        self::assertSelectorTextContains('.card', 'fix the dump_loader.target_database DSN');
        self::assertCount(0, $crawler->filter('form[action="/dump-loader/request"]'));
    }

    #[Test]
    public function it_should_send_the_operator_to_the_configured_monitor_path(): void
    {
        self::ensureKernelShutdown();

        $this->client = self::createClient(['config' => 'monitor_path']);

        self::purgeDumpLoaderVarDir();

        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('form[action="/dump-loader/request"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/suivi');
        self::assertResponseHasCookie(MonitorAccess::COOKIE, '/suivi');
    }

    #[Test]
    public function it_should_link_to_the_monitor_instead_of_polling_while_a_job_is_active(): void
    {
        $this->aRunningJobHoldingItsLock();

        $crawler = $this->client->request('GET', '/dump-loader/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#dump-loader-progress'));
        self::assertCount(0, $crawler->filter('form[action="/dump-loader/request"]'));
        self::assertSelectorTextContains('.card', 'A load is running');
        self::assertSame('/dump-loader/open-monitor', $crawler->filter('.card a')->attr('href'));
    }

    #[Test]
    public function it_should_open_the_monitor_with_an_access_scoped_to_its_path(): void
    {
        $this->client->request('GET', '/dump-loader/open-monitor');

        self::assertResponseRedirects('/dump-loader/monitor');
        self::assertResponseHasCookie(MonitorAccess::COOKIE, '/dump-loader/monitor');

        $access = $this->client->getResponse()->headers->getCookies()[0];

        self::assertTrue($access->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $access->getSameSite());
    }

    #[Test]
    public function it_should_hand_the_monitor_access_over_with_the_redirect_to_the_monitor(): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/monitor');
        self::assertResponseHasCookie(MonitorAccess::COOKIE, '/dump-loader/monitor');
    }

    #[Test]
    public function it_should_hand_no_monitor_access_over_with_a_refused_request(): void
    {
        $this->client->request('POST', '/dump-loader/request', [
            '_token' => 'forged',
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/');
        self::assertResponseNotHasCookie(MonitorAccess::COOKIE, '/dump-loader/monitor');
    }

    #[Test]
    public function it_should_refuse_a_second_job_while_one_is_active(): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $active = $this->aRunningJobHoldingItsLock();

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'already running');

        $history = self::service(LoadJobRepository::class)->history(10);

        self::assertCount(1, $history);
        self::assertSame($active->id()->value, $history->first()?->id->value);
    }

    #[Test]
    public function it_should_show_a_job_lost_when_its_lock_was_released(): void
    {
        $this->aRunningJob();

        $crawler = $this->client->request('GET', '/dump-loader/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.badge-lost', 'Lost');
        self::assertCount(1, $crawler->filter('form[action="/dump-loader/request"]'));
    }

    #[Test]
    public function it_should_persist_a_lost_job_on_the_next_request(): void
    {
        $lost = $this->aRunningJob();

        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('form[action="/dump-loader/request"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/monitor');
        self::assertSame(LoadJobStatus::Lost, self::service(LoadJobRepository::class)->get($lost->id())->snapshot()->status);
    }

    #[Test]
    public function it_should_refuse_a_new_load_while_a_job_file_is_unreadable(): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('form[action="/dump-loader/request"] input[name="_token"]')->first()->attr('value');

        $this->anUnreadableJobFile();

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'simple.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'a load may still be running; nothing was loaded');
        self::assertCount(0, self::service(LoadJobRepository::class)->history(10));
    }

    #[Test]
    public function it_should_explain_why_no_load_can_start_while_a_job_file_is_unreadable(): void
    {
        $this->anUnreadableJobFile();

        $crawler = $this->client->request('GET', '/dump-loader/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action="/dump-loader/request"]'));
        self::assertSelectorTextContains('.card .alert', 'no new load can start');
        self::assertSelectorTextContains('.card .alert', '01J8Z9P0000000000000000001.json');
    }

    #[Test]
    public function it_should_refuse_a_request_naming_an_unknown_dump(): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('form[action="/dump-loader/request"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => 'nope.sql',
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'dump');
        self::assertCount(0, self::service(LoadJobRepository::class)->history(10));
    }

    #[DataProvider('dumpsThatCannotBeRestored')]
    #[Test]
    public function it_should_refuse_a_request_naming_a_dump_that_cannot_be_restored(string $dump): void
    {
        $crawler = $this->client->request('GET', '/dump-loader/');
        $token = (string) $crawler->filter('form[action="/dump-loader/request"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/dump-loader/request', [
            '_token' => $token,
            'dump' => $dump,
            'confirm' => '1',
        ]);

        self::assertResponseRedirects('/dump-loader/');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'cannot be restored');
        self::assertCount(0, self::service(LoadJobRepository::class)->history(10));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dumpsThatCannotBeRestored(): iterable
    {
        yield 'targeting another database' => ['mysqldump-shop-databases.sql'];
        yield 'truncated' => ['truncated.sql.gz'];
    }

    private function aRunningJobHoldingItsLock(): LoadJob
    {
        $job = $this->aRunningJob();

        self::assertTrue(self::service(JobLock::class)->acquire($job->id()));

        return $job;
    }

    private function aRunningJob(): LoadJob
    {
        $steps = array_map(
            static fn (Step $step): PlannedStep => PlannedStep::fromDefinition($step->definition()),
            self::service(StepSequence::class)->plan(LoadOptions::none()),
        );

        $job = LoadJob::request(
            id: LoadJobId::generate(),
            dump: self::service(DumpSource::class)->find('simple.sql'),
            options: LoadOptions::none(),
            steps: $steps,
            requester: Requester::named('an operator'),
            clock: new MockClock('2026-09-19 10:00:00'),
        );

        $job->start();

        self::service(LoadJobRepository::class)->save($job);

        return $job;
    }

    private function anUnreadableJobFile(): void
    {
        $jobsDir = self::getContainer()->getParameter('dump_loader.jobs_dir');

        self::assertIsString($jobsDir);

        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0o775, true);
        }

        file_put_contents($jobsDir.'/01J8Z9P0000000000000000001.json', '{"version": 2}');
    }
}
