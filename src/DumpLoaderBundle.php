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

namespace DumpLoader;

use DumpLoader\Execution\ShellCommand;
use DumpLoader\Monitor\MonitorSettings;
use DumpLoader\Security\ChooseRequesterPass;
use DumpLoader\Step\ClearCaches;
use DumpLoader\Step\DropAllTables;
use DumpLoader\Step\RegisterConfiguredStepsPass;
use DumpLoader\Step\RestoreDatabase;
use DumpLoader\Step\RunConsoleCommand;
use DumpLoader\Step\RunMigrations;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepFactory;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Process\PhpExecutableFinder;

final class DumpLoaderBundle extends AbstractBundle
{
    public const EXECUTION_DETACHED = 'detached';

    public const EXECUTION_SYNCHRONOUS = 'synchronous';

    private const BUILT_IN_STEPS = [
        DropAllTables::NAME,
        RestoreDatabase::NAME,
        RunMigrations::NAME,
        ClearCaches::NAME,
    ];

    public function getPath(): string
    {
        return __DIR__;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(Step::class)->addTag(Step::class);
        $container->registerForAutoconfiguration(StepFactory::class)->addTag(StepFactory::class);
        $container->addCompilerPass(new ChooseRequesterPass());
        $container->addCompilerPass(new RegisterConfiguredStepsPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('source')
                    ->isRequired()
                    ->children()
                        ->scalarNode('directory')->defaultNull()->end()
                        ->scalarNode('flysystem')->defaultNull()->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $source): bool => (null === $source['directory']) === (null === $source['flysystem']))
                        ->thenInvalid('Configure exactly one of "dump_loader.source.directory" or "dump_loader.source.flysystem".')
                    ->end()
                ->end()
                ->arrayNode('target_database')
                    ->isRequired()
                    ->children()
                        ->scalarNode('dsn')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('mysql')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('binary')->defaultValue('mysql')->cannotBeEmpty()->end()
                        ->booleanNode('ssl')->defaultFalse()->end()
                    ->end()
                ->end()
                ->scalarNode('console')->defaultValue('%kernel.project_dir%/bin/console')->cannotBeEmpty()->end()
                ->scalarNode('php_binary')->defaultNull()->end()
                ->scalarNode('var_dir')->defaultValue('%kernel.project_dir%/'.MonitorSettings::DEFAULT_VAR_DIR)->cannotBeEmpty()->end()
                ->scalarNode('monitor_path')->defaultValue('/dump-loader/monitor')->cannotBeEmpty()->end()
                ->enumNode('execution')
                    ->values([self::EXECUTION_DETACHED, self::EXECUTION_SYNCHRONOUS])
                    ->defaultValue(self::EXECUTION_DETACHED)
                ->end()
                ->integerNode('pending_grace_period')->min(0)->defaultValue(MonitorSettings::DEFAULT_PENDING_GRACE_PERIOD)->end()
                ->integerNode('retention')->min(1)->defaultValue(MonitorSettings::DEFAULT_RETENTION)->end()
                ->arrayNode('after_load')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->defaultNull()->end()
                            ->scalarNode('description')->defaultValue('')->end()
                            ->booleanNode('enabled_by_default')->defaultTrue()->end()
                            ->arrayNode('command')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $commands): bool => [] !== array_filter(
                            array_keys($commands),
                            static fn (string $name): bool => 1 !== preg_match('/^[a-z0-9_]+$/', $name),
                        ))
                        ->thenInvalid('Each key under "dump_loader.after_load" must only contain lowercase letters, digits and underscores.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $commands): bool => [] !== array_intersect(array_keys($commands), self::BUILT_IN_STEPS))
                        ->thenInvalid('The keys under "dump_loader.after_load" must not reuse the name of a built-in step ('.implode(', ', self::BUILT_IN_STEPS).').')
                    ->end()
                ->end()
                ->arrayNode('steps')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('key')
                    ->variablePrototype()->end()
                    ->validate()
                        ->ifTrue(static fn (array $steps): bool => [] !== array_filter($steps, static fn (mixed $options): bool => !\is_array($options)))
                        ->thenInvalid('Each entry under "dump_loader.steps" must be a map of options.')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{
     *     source: array{directory: string|null, flysystem: string|null},
     *     target_database: array{dsn: string},
     *     mysql: array{binary: string, ssl: bool},
     *     console: string,
     *     php_binary: string|null,
     *     var_dir: string,
     *     monitor_path: string,
     *     execution: string,
     *     pending_grace_period: int,
     *     retention: int,
     *     after_load: array<string, array{label: string|null, description: string, enabled_by_default: bool, command: list<string>}>,
     *     steps: array<string, array<string, mixed>>,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('dump_loader.config', $config);
        $builder->setParameter('dump_loader.var_dir', $config['var_dir']);
        $builder->setParameter('dump_loader.jobs_dir', $config['var_dir'].'/jobs');
        $builder->setParameter('dump_loader.console', $config['console']);
        $builder->setParameter('dump_loader.php_binary', $config['php_binary'] ?? $this->locatePhpBinary());
        $builder->setParameter('dump_loader.mysql.binary', $config['mysql']['binary']);
        $builder->setParameter('dump_loader.mysql.ssl', $config['mysql']['ssl']);
        $builder->setParameter('dump_loader.target_database.dsn', $config['target_database']['dsn']);
        $builder->setParameter('dump_loader.source.directory', $config['source']['directory'] ?? '');
        $builder->setParameter('dump_loader.pending_grace_period', $config['pending_grace_period']);
        $builder->setParameter('dump_loader.retention', $config['retention']);
        $builder->setParameter('dump_loader.steps', $config['steps']);
        $builder->setParameter('dump_loader.monitor_path', $config['monitor_path']);

        $loader = new PhpFileLoader($builder, new FileLocator(__DIR__.'/Resources/config'));
        $loader->load('services.php');

        if (null !== $config['source']['flysystem']) {
            $builder->removeDefinition('dump_loader.dump_source.filesystem');
            $builder->setAlias('dump_loader.dump_source.filesystem', $config['source']['flysystem']);
            $builder->removeDefinition('dump_loader.dump_source');
            $builder->setAlias('dump_loader.dump_source', 'dump_loader.dump_source.flysystem');
        }

        $this->registerAfterLoadCommands($config['after_load'], $builder);

        $builder->setAlias(
            'dump_loader.launcher',
            self::EXECUTION_SYNCHRONOUS === $config['execution']
                ? 'dump_loader.launcher.synchronous'
                : 'dump_loader.launcher.detached',
        );
    }

    /**
     * @param array<string, array{label: string|null, description: string, enabled_by_default: bool, command: list<string>}> $commands
     */
    private function registerAfterLoadCommands(array $commands, ContainerBuilder $builder): void
    {
        $rank = StepDefinition::RANK_AFTER_LOAD;

        foreach ($commands as $name => $command) {
            $definition = new Definition(RunConsoleCommand::class, [
                new Reference('dump_loader.process_runner'),
                new Definition(StepDefinition::class, [
                    $name,
                    $command['label'] ?? $name,
                    $command['description'],
                    $rank,
                    1,
                ]),
                new Definition(ShellCommand::class, [
                    ['%dump_loader.php_binary%', '%dump_loader.console%', ...$command['command']],
                ]),
                $command['enabled_by_default'],
            ]);

            $builder->setDefinition('dump_loader.step.after_load.'.$name, $definition->addTag(Step::class));

            $rank += 10;
        }
    }

    private function locatePhpBinary(): string
    {
        $binary = (new PhpExecutableFinder())->find();

        return false === $binary ? \PHP_BINARY : $binary;
    }
}
