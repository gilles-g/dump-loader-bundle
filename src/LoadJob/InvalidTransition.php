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

namespace DumpLoader\LoadJob;

final class InvalidTransition extends \LogicException
{
    public static function from(LoadJobStatus $status, string $transition): self
    {
        return new self(\sprintf('A %s load job cannot %s.', $status->value, $transition));
    }

    public static function noStepRunning(string $transition): self
    {
        return new self(\sprintf('No step is running, nothing to %s.', $transition));
    }

    public static function unplannedStep(string $name): self
    {
        return new self(\sprintf('Step "%s" is not part of this load job.', $name));
    }

    public static function stepAlreadyReached(string $name): self
    {
        return new self(\sprintf('Step "%s" has already been started.', $name));
    }
}
