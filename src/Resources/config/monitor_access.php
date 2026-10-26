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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use DumpLoader\Monitor\MonitorAccess;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('dump_loader.monitor_access', MonitorAccess::class)
        ->args([env('APP_SECRET'), service('clock'), param('dump_loader.monitor_path')])
    ;

    $services->alias(MonitorAccess::class, 'dump_loader.monitor_access');
};
