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

namespace DumpLoader\Step;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class RegisterConfiguredStepsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = $container->getParameter('dump_loader.steps');
        $factories = $this->factoryIdsByKey($container);

        foreach ($configured as $key => $options) {
            if (!isset($factories[$key])) {
                throw new InvalidConfigurationException(\sprintf('There is no step factory for "dump_loader.steps.%s". Known keys: %s.', $key, [] === $factories ? 'none' : implode(', ', array_keys($factories))));
            }

            $definition = (new Definition(Step::class, [$options]))
                ->setFactory([new Reference($factories[$key]), 'create'])
                ->addTag(Step::class);

            $container->setDefinition('dump_loader.step.configured.'.$key, $definition);
        }
    }

    /**
     * @return array<string, string>
     */
    private function factoryIdsByKey(ContainerBuilder $container): array
    {
        $factories = [];

        foreach (array_keys($container->findTaggedServiceIds(StepFactory::class)) as $id) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());

            if (!\is_string($class) || !is_subclass_of($class, StepFactory::class)) {
                continue;
            }

            $factories[$class::configurationKey()] = $id;
        }

        return $factories;
    }
}
