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

namespace DumpLoader\Tests\Double;

use DumpLoader\Step\Step;
use DumpLoader\Step\StepFactory;

final readonly class NamedStepFactory implements StepFactory
{
    public static function configurationKey(): string
    {
        return 'named';
    }

    public function create(array $options): Step
    {
        $name = $options['name'] ?? 'named_step';

        return FakeStep::named(\is_string($name) ? $name : 'named_step', 500);
    }
}
