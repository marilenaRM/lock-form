<?php

declare(strict_types=1);

namespace LockForm\FormConcurrency\Tests\Integration\App;

use LockForm\FormConcurrency\FormConcurrencyBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new FormConcurrencyBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'form' => ['enabled' => true, 'csrf_protection' => false],
            'csrf_protection' => false,
            'default_locale' => 'fr',
            'translator' => ['enabled' => true, 'fallbacks' => ['en']],
        ]);

        // The "conflict_event" environment turns the option on, the default one keeps the default configuration.
        if ('conflict_event' === $this->environment) {
            $container->extension('form_concurrency', ['conflict_event' => true]);
        }

        // Nothing in this kernel injects the form factory, so the container would otherwise remove it.
        $container->services()->alias('test.form.factory', 'form.factory')->public();
        $container->services()->alias('test.event_dispatcher', 'event_dispatcher')->public();

        // The "presence" environment turns the presence indicator on; every other one keeps the defaults.
        if ('presence' === $this->environment) {
            // A plain service, not a framework pool: those are reset between two requests of the same kernel.
            $container->services()->set('test.presence_pool', ArrayAdapter::class);
            $container->extension('form_concurrency', ['presence' => [
                'cache_pool' => 'test.presence_pool',
                'identity' => HeaderPresenceIdentity::class,
            ]]);
            $container->services()->set(HeaderPresenceIdentity::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ('presence' === $this->environment) {
            $routes->import('@FormConcurrencyBundle/config/routes.php');
        }
    }
}
