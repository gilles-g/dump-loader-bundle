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

namespace DumpLoader\Step;

interface StepFactory
{
    public static function configurationKey(): string;

    /**
     * @param array<string, mixed> $options
     */
    public function create(array $options): Step;
}
