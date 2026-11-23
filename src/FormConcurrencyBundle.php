<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use LockForm\FormConcurrency\Type\ConcurrencyTypeExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class FormConcurrencyBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('field_name')->defaultValue('_concurrency_token')->cannotBeEmpty()->end()
                ->booleanNode('conflict_event')->defaultFalse()->info('Dispatch a ConcurrencyConflictEvent on every conflict.')->end()
            ->end();
    }

    /**
     * @param array{enabled: bool, field_name: string, conflict_event: bool} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(ConcurrencyTypeExtension::class)
                ->args([
                    '%kernel.secret%',
                    service('translator')->nullOnInvalid(),
                    $config['enabled'],
                    $config['field_name'],
                    $config['conflict_event'] ? service('event_dispatcher') : null,
                ])
                ->tag('form.type_extension');
    }
}
