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
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\Requester;
use DumpLoader\Monitor\MonitorAccess;
use DumpLoader\Monitor\MonitorKernel;
use DumpLoader\Monitor\MonitorSettings;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Tests\Integration\BootsTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;
use Symfony\Component\Clock\MockClock;

final class MonitorFollowsTheBundleTest extends KernelTestCase
{
    use BootsTestKernel;

    private const MOUNT_PATH = '/dump-loader/monitor';

    private const PROJECT_DIR = '/DumpLoaderBundle/monitor-follows-the-bundle';

    /**
     * @var list<MonitorKernel>
     */
    private array $monitorKernels = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        self::purgeDumpLoaderVarDir();
    }

    protected function tearDown(): void
    {
        foreach ($this->monitorKernels as $monitorKernel) {
            $monitorKernel->shutdown();
        }

        self::ensureKernelShutdown();
        parent::tearDown();
    }

    #[Test]
    public function it_should_follow_and_abort_a_job_the_bundle_wrote_to_its_own_var_dir(): void
    {
        $job = $this->aRunningJobHoldingItsLock();
        $monitor = $this->browse(new MonitorKernel('test', true, self::projectDir(), self::MOUNT_PATH, new MonitorSettings($this->bundleVarDir())));

        $crawler = $monitor->request('GET', self::MOUNT_PATH.'/');

        self::assertSame(200, $monitor->getResponse()->getStatusCode());
        self::assertSame('1', $crawler->filter('#dump-loader-progress')->attr('data-active'));
        self::assertStringContainsString($job->snapshot()->dumpName, $crawler->filter('.card')->text());

        $monitor->request('POST', self::MOUNT_PATH.'/abort', [
            '_token' => (string) $crawler->filter('form input[name="_token"]')->attr('value'),
            'id' => $job->id()->value,
        ]);

        self::assertTrue($monitor->getResponse()->isRedirect(self::MOUNT_PATH.'/'));
        self::assertTrue(self::service(AbortSignal::class)->isRaised($job->id()));
    }

    #[Test]
    public function it_should_miss_the_jobs_of_a_bundle_whose_var_dir_it_was_not_given(): void
    {
        $this->aRunningJobHoldingItsLock();
        $monitor = $this->browse(new MonitorKernel('test', true, self::projectDir(), self::MOUNT_PATH));

        $crawler = $monitor->request('GET', self::MOUNT_PATH.'/');

        self::assertSame(200, $monitor->getResponse()->getStatusCode());
        self::assertSame('0', $crawler->filter('#dump-loader-progress')->attr('data-active'));
        self::assertStringContainsString('No load job has run yet.', $crawler->filter('.card')->text());
    }

    #[Test]
    public function it_should_judge_and_list_the_jobs_with_the_settings_it_was_given(): void
    {
        $monitor = $this->browse(new MonitorKernel('test', true, self::projectDir(), self::MOUNT_PATH, new MonitorSettings($this->bundleVarDir(), pendingGracePeriod: 300, retention: 7)));
        $container = $monitor->getContainer();

        self::assertSame($this->bundleVarDir().'/jobs', $container->getParameter('dump_loader.jobs_dir'));
        self::assertSame(300, $container->getParameter('dump_loader.pending_grace_period'));
        self::assertSame(7, $container->getParameter('dump_loader.retention'));
    }

    #[Test]
    public function it_should_compile_one_container_per_settings(): void
    {
        self::assertNotSame(
            (new MonitorKernel('test', true, self::projectDir(), self::MOUNT_PATH))->getCacheDir(),
            (new MonitorKernel('test', true, self::projectDir(), self::MOUNT_PATH, new MonitorSettings('/data/dump-loader')))->getCacheDir(),
        );
    }

    private static function projectDir(): string
    {
        return sys_get_temp_dir().self::PROJECT_DIR;
    }

    private function browse(MonitorKernel $monitorKernel): KernelBrowser
    {
        $monitorKernel->boot();
        $this->monitorKernels[] = $monitorKernel;

        $monitor = new KernelBrowser($monitorKernel);
        $monitor->disableReboot();
        $monitor->getCookieJar()->set(BrowserKitCookie::fromString((string) self::service(MonitorAccess::class)->open()->headers->getCookies()[0]));

        return $monitor;
    }

    private function bundleVarDir(): string
    {
        $varDir = self::getContainer()->getParameter('dump_loader.var_dir');

        self::assertIsString($varDir);

        return $varDir;
    }

    private function aRunningJobHoldingItsLock(): LoadJob
    {
        $job = LoadJob::request(
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
        $job->start();
        $job->startStep('restore_database');

        self::service(LoadJobRepository::class)->save($job);
        self::assertTrue(self::service(JobLock::class)->acquire($job->id()));

        return $job;
    }
}
