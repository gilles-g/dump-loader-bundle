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

namespace DumpLoader\Command;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class Labels
{
    private const DOMAIN = 'DumpLoaderBundle';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function translate(string $key): string
    {
        return $this->translator->trans($key, domain: self::DOMAIN);
    }
}
