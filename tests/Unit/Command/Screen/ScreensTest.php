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

namespace DumpLoader\Tests\Unit\Command\Screen;

use DumpLoader\Command\Screen\ConsoleScreen;
use DumpLoader\Command\Screen\Screens;
use DumpLoader\Command\Screen\TuiScreen;
use DumpLoader\Tests\Double\EnglishLabels;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Tui\Tui;

final class ScreensTest extends TestCase
{
    /**
     * @var list<resource>
     */
    private array $terminals = [];

    protected function tearDown(): void
    {
        foreach ($this->terminals as $terminal) {
            fclose($terminal);
        }
    }

    #[Test]
    public function it_should_open_the_rich_terminal_ui_on_an_interactive_terminal(): void
    {
        if (!class_exists(Tui::class)) {
            self::markTestSkipped('symfony/tui is not installed.');
        }

        self::assertInstanceOf(TuiScreen::class, $this->screens()->open(new ArrayInput([]), $this->terminalOutput(), tuiAllowed: true));
    }

    #[Test]
    public function it_should_stay_on_the_console_when_the_ui_is_not_allowed(): void
    {
        self::assertInstanceOf(ConsoleScreen::class, $this->screens()->open(new ArrayInput([]), $this->terminalOutput(), tuiAllowed: false));
    }

    #[Test]
    public function it_should_stay_on_the_console_without_interaction(): void
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        self::assertInstanceOf(ConsoleScreen::class, $this->screens()->open($input, $this->terminalOutput(), tuiAllowed: true));
    }

    #[Test]
    public function it_should_stay_on_the_console_when_the_output_is_not_a_terminal(): void
    {
        self::assertInstanceOf(ConsoleScreen::class, $this->screens()->open(new ArrayInput([]), new BufferedOutput(), tuiAllowed: true));
        $memory = fopen('php://memory', 'w');
        self::assertIsResource($memory);

        self::assertInstanceOf(ConsoleScreen::class, $this->screens()->open(new ArrayInput([]), new StreamOutput($memory), tuiAllowed: true));
    }

    private function screens(): Screens
    {
        return new Screens(new MockClock(), EnglishLabels::create());
    }

    private function terminalOutput(): StreamOutput
    {
        $terminal = @fopen('/dev/ptmx', 'r+');

        if (false === $terminal || !stream_isatty($terminal)) {
            self::markTestSkipped('No pseudo-terminal is available.');
        }

        $this->terminals[] = $terminal;

        return new StreamOutput($terminal);
    }
}
