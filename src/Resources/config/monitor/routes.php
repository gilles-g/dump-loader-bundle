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

namespace Symfony\Component\Routing\Loader\Configurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('dump_loader_monitor', '/')
        ->controller('dump_loader.controller.monitor')
        ->methods(['GET'])
    ;

    $routes->add('dump_loader_progress', '/progress')
        ->controller('dump_loader.controller.progress')
        ->methods(['GET'])
    ;

    $routes->add('dump_loader_abort', '/abort')
        ->controller('dump_loader.controller.abort')
        ->methods(['POST'])
    ;
};
