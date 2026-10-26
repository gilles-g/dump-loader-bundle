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

namespace DumpLoader\Command\Screen;

use DumpLoader\Command\Labels;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Tui\Tui;

final readonly class Screens
{
    public function __construct(
        private ClockInterface $clock,
        private Labels $labels,
    ) {
    }

    public function open(InputInterface $input, OutputInterface $output, bool $tuiAllowed): LoadDumpScreen
    {
        if ($tuiAllowed && $input->isInteractive() && class_exists(Tui::class) && $this->isTerminal($output)) {
            return new TuiScreen($this->labels, $this->clock);
        }

        return new ConsoleScreen(new SymfonyStyle($input, $output), $this->clock, $this->labels);
    }

    private function isTerminal(OutputInterface $output): bool
    {
        return $output instanceof StreamOutput && stream_isatty($output->getStream());
    }
}
