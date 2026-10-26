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

namespace DumpLoader\Tests\Unit\Security;

use DumpLoader\Security\ChooseRequesterPass;
use DumpLoader\Security\SecurityRequester;
use DumpLoader\Security\UnknownRequester;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ChooseRequesterPassTest extends TestCase
{
    #[Test]
    public function it_should_pick_the_security_requester_when_a_token_storage_is_available(): void
    {
        $container = $this->container();
        $container->register('security.token_storage', \stdClass::class);

        (new ChooseRequesterPass())->process($container);

        self::assertSame(SecurityRequester::class, (string) $container->getAlias('dump_loader.requester'));
        self::assertTrue($container->hasDefinition(SecurityRequester::class));
    }

    #[Test]
    public function it_should_fall_back_to_the_unknown_requester_without_security(): void
    {
        $container = $this->container();

        (new ChooseRequesterPass())->process($container);

        self::assertSame(UnknownRequester::class, (string) $container->getAlias('dump_loader.requester'));
        self::assertFalse($container->hasDefinition(SecurityRequester::class));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(SecurityRequester::class, new Definition(SecurityRequester::class));
        $container->setDefinition(UnknownRequester::class, new Definition(UnknownRequester::class));

        return $container;
    }
}
