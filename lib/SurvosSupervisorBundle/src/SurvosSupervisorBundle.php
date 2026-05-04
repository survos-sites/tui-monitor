<?php

declare(strict_types=1);

namespace Survos\SupervisorBundle;

use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosSupervisorBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        self::applyConfigTree($definition->rootNode());
    }

    /**
     * Shared by the bundle's configure() and the standalone YAML loader so
     * `survos_supervisor:` (in config/packages) and `supervisor.yaml` (passed
     * via --config) validate against the same schema.
     */
    public static function applyConfigTree(NodeDefinition $root): void
    {
        $root
            ->children()
                ->integerNode('ring_buffer_lines')
                    ->defaultValue(5000)
                    ->min(1)
                ->end()
                ->booleanNode('follow_by_default')
                    ->defaultTrue()
                ->end()
                ->arrayNode('processes')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('cmd')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()->end()
                            ->end()
                            ->scalarNode('cwd')->defaultNull()->end()
                            ->arrayNode('env')
                                ->normalizeKeys(false)
                                ->scalarPrototype()->end()
                            ->end()
                            ->enumNode('restart')
                                ->values(['never', 'on-failure', 'always'])
                                ->defaultValue('never')
                            ->end()
                            ->arrayNode('backoff')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->floatNode('initial')->defaultValue(1.0)->min(0.0)->end()
                                    ->floatNode('max')->defaultValue(30.0)->min(0.0)->end()
                                    ->floatNode('multiplier')->defaultValue(2.0)->min(1.0)->end()
                                ->end()
                            ->end()
                            ->booleanNode('autostart')->defaultTrue()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('survos_supervisor.config', $config);

        $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure()
            ->load('Survos\\SupervisorBundle\\', '../src/')
            ->exclude([
                '../src/SurvosSupervisorBundle.php',
                '../src/Process/',
                '../src/Tui/',
            ])
        ;
    }
}
