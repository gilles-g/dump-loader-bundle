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

namespace Symfony\Component\Routing\Loader\Configurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('dump_loader_dashboard', '/')
        ->controller('dump_loader.controller.dashboard')
        ->methods(['GET'])
    ;

    $routes->add('dump_loader_request', '/request')
        ->controller('dump_loader.controller.request')
        ->methods(['POST'])
    ;

    $routes->add('dump_loader_open_monitor', '/open-monitor')
        ->controller('dump_loader.controller.open_monitor')
        ->methods(['GET'])
    ;
};
