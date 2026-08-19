<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Imported by the host application under a `when@dev:` guard — see the README.
 *
 * Keeping the import in the application's hands, scoped to the dev environment, is gate 2 of
 * the safety model: a production router never even learns these paths exist. It costs the
 * installer one small file, and buys a guarantee that no amount of misconfiguration inside
 * this bundle can undo.
 */
return static function (RoutingConfigurator $routes): void {
    $routes
        ->add('zestly_dev_login_discovery', '%zestly_dev_login.path_prefix%')
        ->controller('zestly_dev_login.controller.discovery')
        ->methods(['GET']);

    $routes
        ->add('zestly_dev_login', '%zestly_dev_login.path_prefix%/{identifier}')
        ->controller('zestly_dev_login.controller.login')
        ->methods(['GET']);
};
