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

namespace DumpLoader\Database\Mysql;

use DumpLoader\Dump\DumpNotRestorable;

final class DumpTargetsExplicitDatabase extends \RuntimeException implements DumpNotRestorable
{
    public static function inHeader(string $statement): self
    {
        return new self(\sprintf('The dump starts with a "%s" statement and would write outside the configured target database: produce it with mysqldump on a single database, without --databases.', $statement));
    }
}
