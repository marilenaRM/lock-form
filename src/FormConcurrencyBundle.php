<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency;

use LockForm\FormConcurrency\Presence\PresenceController;
use LockForm\FormConcurrency\Presence\PresenceIdentity;
use LockForm\FormConcurrency\Presence\PresenceMarkup;
use LockForm\FormConcurrency\Presence\PresenceStore;
use LockForm\FormConcurrency\Presence\PresenceTypeExtension;
use LockForm\FormConcurrency\Presence\SecurityPresenceIdentity;
use LockForm\FormConcurrency\Test\BypassConflictDetector;
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
                ->booleanNode('test_bypass')->defaultFalse()->info('Accept every submission of a request with the FORM_CONCURRENCY_BYPASS server parameter. For the test environment only.')->end()
                ->arrayNode('presence')
                    ->info('A non-blocking "someone else is editing this record" indicator. Off by default.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('cache_pool')->defaultValue('cache.app')->cannotBeEmpty()->info('Where open tabs are kept.')->end()
                        ->scalarNode('identity')->defaultNull()->info('Service implementing PresenceIdentity; the logged-in user by default.')->end()
                        ->integerNode('interval')->defaultValue(15)->min(2)->info('Seconds between two heartbeats of a tab.')->end()
                        ->integerNode('ttl')->defaultValue(90)->min(5)->info('Seconds without a heartbeat before a tab counts as closed. Browsers slow the timers of background tabs down to one a minute.')->end()
                    ->end()
                    ->validate()
                        ->ifTrue(
                            /** @param array{ttl: int, interval: int} $presence */
                            static fn (array $presence): bool => $presence['ttl'] <= $presence['interval'],
                        )
                        ->thenInvalid('form_concurrency.presence.ttl must be longer than the interval, or every tab would drop out between two heartbeats.')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{enabled: bool, field_name: string, conflict_event: bool, test_bypass: bool, presence: array{enabled: bool, cache_pool: string, identity: ?string, interval: int, ttl: int}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(ConflictDetector::class, FingerprintConflictDetector::class);

        if ($config['test_bypass']) {
            $services->set(BypassConflictDetector::class)
                ->decorate(ConflictDetector::class)
                ->args([service('.inner'), service('request_stack')]);
        }

        $services
            ->set(ConcurrencyTypeExtension::class)
                ->args([
                    '%kernel.secret%',
                    service('translator')->nullOnInvalid(),
                    $config['enabled'],
                    $config['field_name'],
                    $config['conflict_event'] ? service('event_dispatcher') : null,
                    service(ConflictDetector::class),
                ])
                ->tag('form.type_extension');

        $presence = $config['presence'];
        if (!$presence['enabled']) {
            // Still registered so that the form options exist, but with nothing to render.
            $services->set(PresenceTypeExtension::class)->args([null])->tag('form.type_extension');

            return;
        }

        if (null === $presence['identity']) {
            $services->set(SecurityPresenceIdentity::class)->args([service('security.token_storage')->nullOnInvalid()]);
            $services->alias(PresenceIdentity::class, SecurityPresenceIdentity::class);
        } else {
            $services->alias(PresenceIdentity::class, $presence['identity']);
        }

        $services
            ->set(PresenceStore::class)
                ->args([service($presence['cache_pool']), $presence['ttl']])
            ->set(PresenceMarkup::class)
                ->args([service('request_stack'), service('router'), service(PresenceIdentity::class), '%kernel.secret%', $presence['interval']])
            ->set(PresenceController::class)
                ->args([service(PresenceStore::class), '%kernel.secret%', service('translator')->nullOnInvalid()])
                ->public()
                ->tag('controller.service_arguments')
            ->set(PresenceTypeExtension::class)
                ->args([service(PresenceMarkup::class)])
                ->tag('form.type_extension');
    }
}
