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

use DumpLoader\Command\Screen\OptionChecklist;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Tests\Double\EnglishLabels;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionChecklistTest extends TestCase
{
    #[Test]
    public function it_should_tick_the_default_options_and_translate_the_labels(): void
    {
        self::assertSame(
            [
                ['value' => 'run_migrations', 'label' => '[x] Run migrations'],
                ['value' => 'clear_caches', 'label' => '[ ] Clear caches'],
            ],
            $this->checklist()->items(),
        );
    }

    #[Test]
    public function it_should_untick_a_ticked_option(): void
    {
        $checklist = $this->checklist();
        $checklist->toggle('run_migrations');

        self::assertSame([], $checklist->checked()->names);
        self::assertSame('[ ] Run migrations', $checklist->items()[0]['label']);
    }

    #[Test]
    public function it_should_describe_an_option_only_when_it_has_a_description(): void
    {
        $checklist = $this->checklist();

        self::assertSame('Runs doctrine:migrations:migrate after the restore.', $checklist->descriptionOf($checklist->firstOption()));
        self::assertSame('', $checklist->descriptionOf('clear_caches'));
    }

    #[Test]
    public function it_should_refuse_an_option_it_does_not_list(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('There is no option named "nope" in the checklist.'));

        $this->checklist()->positionOf('nope');
    }

    private function checklist(): OptionChecklist
    {
        return new OptionChecklist(
            [
                new StepDefinition('run_migrations', 'step.run_migrations.label', 'step.run_migrations.description', 200, 1),
                new StepDefinition('clear_caches', 'step.clear_caches.label', '', 300, 1),
            ],
            LoadOptions::fromNames(['run_migrations']),
            EnglishLabels::create(),
        );
    }
}
