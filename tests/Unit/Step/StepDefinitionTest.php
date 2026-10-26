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

namespace DumpLoader\Tests\Unit\Step;

use DumpLoader\Step\StepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepDefinitionTest extends TestCase
{
    #[Test]
    public function it_should_expose_its_properties_without_getters(): void
    {
        $definition = new StepDefinition(
            name: 'restore_database',
            label: 'Restore the database',
            description: 'Loads the dump into the target database.',
            rank: StepDefinition::RANK_RESTORE_DATABASE,
            weight: 10,
        );

        self::assertSame('restore_database', $definition->name);
        self::assertSame('Restore the database', $definition->label);
        self::assertSame('Loads the dump into the target database.', $definition->description);
        self::assertSame(100, $definition->rank);
        self::assertSame(10, $definition->weight);
    }

    #[Test]
    public function it_should_ship_the_ranks_of_the_steps_delivered_by_the_bundle(): void
    {
        self::assertSame(50, StepDefinition::RANK_DROP_ALL_TABLES);
        self::assertSame(100, StepDefinition::RANK_RESTORE_DATABASE);
        self::assertSame(200, StepDefinition::RANK_RUN_MIGRATIONS);
        self::assertSame(300, StepDefinition::RANK_CLEAR_CACHES);
    }
}
