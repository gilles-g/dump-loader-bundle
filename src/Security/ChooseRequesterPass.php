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

namespace DumpLoader\Security;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ChooseRequesterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->has('security.token_storage')) {
            $container->setAlias('dump_loader.requester', SecurityRequester::class);

            return;
        }

        $container->removeDefinition(SecurityRequester::class);
        $container->setAlias('dump_loader.requester', UnknownRequester::class);
    }
}
