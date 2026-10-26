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

use DumpLoader\Step\RegisterConfiguredStepsPass;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepFactory;
use DumpLoader\Tests\Double\FakeStep;
use DumpLoader\Tests\Double\NamedStepFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RegisterConfiguredStepsPassTest extends TestCase
{
    #[Test]
    public function it_should_register_a_step_built_by_the_factory_of_its_key(): void
    {
        $container = $this->container(['named' => ['name' => 'anonymize']]);

        (new RegisterConfiguredStepsPass())->process($container);

        $definition = $container->getDefinition('dump_loader.step.configured.named');
        self::assertTrue($definition->hasTag(Step::class));
        self::assertSame([['name' => 'anonymize']], $definition->getArguments());
        self::assertSame('create', $definition->getFactory()[1] ?? null);
    }

    #[Test]
    public function it_should_register_nothing_when_no_step_is_configured(): void
    {
        $container = $this->container([]);

        (new RegisterConfiguredStepsPass())->process($container);

        self::assertSame([], $container->findTaggedServiceIds(Step::class));
    }

    #[Test]
    public function it_should_refuse_a_key_no_factory_handles(): void
    {
        $container = $this->container(['anonimization' => ['seed' => 'x']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('There is no step factory for "dump_loader.steps.anonimization". Known keys: named.');

        (new RegisterConfiguredStepsPass())->process($container);
    }

    #[Test]
    public function it_should_name_no_known_key_when_no_factory_is_installed(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('dump_loader.steps', ['named' => []]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Known keys: none.');

        (new RegisterConfiguredStepsPass())->process($container);
    }

    #[Test]
    public function it_should_ignore_a_tagged_service_that_is_not_a_factory(): void
    {
        $container = $this->container(['named' => []]);
        $container->register('not_a_factory', FakeStep::class)->addTag(StepFactory::class);

        (new RegisterConfiguredStepsPass())->process($container);

        self::assertTrue($container->hasDefinition('dump_loader.step.configured.named'));
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     */
    private function container(array $steps): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('dump_loader.steps', $steps);
        $container->register('named_step_factory', NamedStepFactory::class)->addTag(StepFactory::class);

        return $container;
    }
}
