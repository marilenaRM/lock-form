<?php

declare(strict_types=1);

use LockForm\FormConcurrency\Presence\PresenceController;
use LockForm\FormConcurrency\Presence\PresenceMarkup;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Only needed with form_concurrency.presence.enabled.
return static function (RoutingConfigurator $routes): void {
    $routes->add(PresenceMarkup::ROUTE, '/_form_concurrency/presence')
        ->controller(PresenceController::class)
        ->methods(['POST']);
};
