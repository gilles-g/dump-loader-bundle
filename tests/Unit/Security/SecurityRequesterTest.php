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

namespace DumpLoader\Tests\Unit\Security;

use DumpLoader\Security\SecurityRequester;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityRequesterTest extends TestCase
{
    #[Test]
    public function it_should_name_the_requester_after_the_logged_in_user(): void
    {
        $user = $this->createStub(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('alice@example.com');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $requester = (new SecurityRequester($this->tokenStorage($token)))->current();

        self::assertTrue($requester->isKnown());
        self::assertSame('alice@example.com', $requester->name);
    }

    #[Test]
    public function it_should_fall_back_to_the_unknown_requester_without_a_token(): void
    {
        self::assertFalse((new SecurityRequester($this->tokenStorage(null)))->current()->isKnown());
    }

    #[Test]
    public function it_should_fall_back_to_the_unknown_requester_when_the_token_carries_no_user(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertFalse((new SecurityRequester($this->tokenStorage($token)))->current()->isKnown());
    }

    private function tokenStorage(?TokenInterface $token): TokenStorageInterface
    {
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        return $tokenStorage;
    }
}
