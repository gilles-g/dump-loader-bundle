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

namespace DumpLoader\Tests\Double;

use DumpLoader\Step\OptionalStep;

final class FakeOptionalStep extends FakeStep implements OptionalStep
{
    private bool $enabledByDefault = false;

    public function onByDefault(): self
    {
        $this->enabledByDefault = true;

        return $this;
    }

    public function enabledByDefault(): bool
    {
        return $this->enabledByDefault;
    }
}
