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
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\StepDefinition;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final class OptionChecklist
{
    /**
     * @param non-empty-list<StepDefinition> $options
     */
    public function __construct(
        private readonly array $options,
        private LoadOptions $checked,
        private readonly Labels $labels,
    ) {
    }

    public function toggle(string $name): void
    {
        $this->checked = $this->checked->toggled($name);
    }

    public function checked(): LoadOptions
    {
        return $this->checked;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function items(): array
    {
        return array_map(
            fn (StepDefinition $option): array => [
                'value' => $option->name,
                'label' => \sprintf(
                    '[%s] %s',
                    $this->checked->includes($option->name) ? 'x' : ' ',
                    StringUtils::stripControlBytes($this->labels->translate($option->label)),
                ),
            ],
            $this->options,
        );
    }

    public function firstOption(): string
    {
        return $this->options[0]->name;
    }

    public function positionOf(string $name): int
    {
        foreach ($this->options as $position => $option) {
            if ($option->name === $name) {
                return $position;
            }
        }

        throw new \InvalidArgumentException(\sprintf('There is no option named "%s" in the checklist.', $name));
    }

    public function descriptionOf(string $name): string
    {
        $description = $this->options[$this->positionOf($name)]->description;

        return '' === $description ? '' : StringUtils::stripControlBytes($this->labels->translate($description));
    }
}
