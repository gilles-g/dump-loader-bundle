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

namespace DumpLoader\Database\Mysql;

use DumpLoader\Database\RestorePolicy;
use DumpLoader\Dump\SqlInput;

final readonly class MysqlRestorePolicy implements RestorePolicy
{
    private const HEADER_WINDOW = 8192;

    private const EXPLICIT_DATABASE = '/^[\t ]*(CREATE\s+DATABASE|USE)\b/mi';

    public function ensureRestorable(SqlInput $input): void
    {
        $statement = [];

        if (1 === preg_match(self::EXPLICIT_DATABASE, $input->header(self::HEADER_WINDOW), $statement)) {
            throw DumpTargetsExplicitDatabase::inHeader($statement[1]);
        }
    }
}
