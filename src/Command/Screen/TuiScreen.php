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

namespace DumpLoader\Command\Screen;

use DumpLoader\Command\Labels;
use DumpLoader\Command\LoadRefused;
use DumpLoader\Dump\Dumps;
use DumpLoader\LoadJob\FollowedLoadJob;
use DumpLoader\LoadJob\LoadOptions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final class TuiScreen implements LoadDumpScreen
{
    private bool $cancelled = false;

    public function __construct(
        private readonly Labels $labels,
        private readonly ClockInterface $clock,
        private readonly TerminalInterface $terminal = new Terminal(),
        private readonly Keybindings $keybindings = new Keybindings(['toggle' => [Key::SPACE], 'abort' => ['ctrl+c']]),
    ) {
    }

    public function chooseDump(Dumps $dumps): string
    {
        $items = [];

        foreach ($dumps as $dump) {
            $items[] = [
                'value' => $dump->path,
                'label' => StringUtils::stripControlBytes($dump->name),
                'description' => \sprintf('%s, %s', $dump->size->humanized(), $dump->modifiedAt->format('Y-m-d H:i')),
            ];
        }

        return $this->select('Which dump do you want to load?', new SelectListWidget($items, maxVisible: 10));
    }

    public function chooseOptions(array $availableOptions, LoadOptions $defaultOptions): LoadOptions
    {
        if ([] === $availableOptions) {
            return LoadOptions::none();
        }

        $checklist = new OptionChecklist($availableOptions, $defaultOptions, $this->labels);
        $list = new SelectListWidget($checklist->items(), maxVisible: \count($availableOptions));
        $description = new TextWidget($checklist->descriptionOf($checklist->firstOption()));

        $list->onSelectionChange(static function (SelectionChangeEvent $event) use ($description, $checklist): void {
            $description->setText($checklist->descriptionOf($event->getValue()));
        });

        $tui = $this->question('Which options? Space ticks or unticks, Enter confirms, Esc cancels.', $list);
        $tui->add($description);
        $tui->addListener(function (InputEvent $event) use ($tui, $list, $checklist): void {
            $selected = $list->getSelectedItem();

            if (null === $selected || !$this->keybindings->matches($event->getData(), 'toggle')) {
                return;
            }

            $event->stopPropagation();
            $checklist->toggle($selected['value']);
            $list->setItems($checklist->items());
            $list->setSelectedIndex($checklist->positionOf($selected['value']));
            $tui->requestRender();
        });

        $this->run($tui);

        return $checklist->checked();
    }

    public function confirm(string $database): bool
    {
        $answer = $this->select(
            \sprintf('Every table of "%s" will be dropped before the restore. Continue?', StringUtils::stripControlBytes($database)),
            new SelectListWidget([
                ['value' => 'no', 'label' => 'No, keep the database as it is'],
                ['value' => 'yes', 'label' => 'Yes, drop every table and load the dump'],
            ]),
        );

        return 'yes' === $answer;
    }

    public function follow(FollowedLoadJob $job): void
    {
        $tui = new Tui(terminal: $this->terminal);
        $panel = new FollowPanel($job, $this->labels, $this->clock);

        $tui->addListener(function (InputEvent $event) use ($panel): void {
            if ($this->keybindings->matches($event->getData(), 'abort')) {
                $event->stopPropagation();
                $panel->abort();
            }
        });

        $tui->onTick(static function (TickEvent $event) use ($tui, $panel): void {
            $panel->tick($event->getDeltaTime());

            if ($panel->isOver()) {
                $tui->requestRender();
                $tui->processRender();
                $tui->stop();
            }
        });

        $panel->mountOn($tui);
        $tui->run();
    }

    private function select(string $question, SelectListWidget $list): string
    {
        $this->run($this->question($question, $list));

        return $list->getSelectedItem()['value'] ?? throw LoadRefused::cancelled();
    }

    private function question(string $question, FocusableInterface&AbstractWidget $widget): Tui
    {
        $this->cancelled = false;

        $tui = new Tui(terminal: $this->terminal);
        $tui->add(new TextWidget($question))->add($widget);
        $tui->setFocus($widget);
        $tui->addListener(static fn (SelectEvent $event) => $tui->stop());
        $tui->addListener(function (CancelEvent $event) use ($tui): void {
            $this->cancelled = true;
            $tui->stop();
        });

        return $tui;
    }

    private function run(Tui $tui): void
    {
        $tui->run();

        if ($this->cancelled) {
            throw LoadRefused::cancelled();
        }
    }
}
