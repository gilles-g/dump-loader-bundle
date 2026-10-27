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

use DumpLoader\LoadJob\Requester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class SecurityRequester implements CurrentRequester
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function current(): Requester
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (null === $user) {
            return Requester::unknown();
        }

        return Requester::named($user->getUserIdentifier());
    }
}
