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
            ->end();
    }

    /**
     * @param array{enabled: bool, field_name: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(ConcurrencyTypeExtension::class)
                ->args(['%kernel.secret%', service('translator')->nullOnInvalid(), $config['enabled'], $config['field_name']])
                ->tag('form.type_extension');
    }
}
