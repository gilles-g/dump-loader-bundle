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

use Symfony\Component\Process\ExecutableFinder;

use function Symfony\Component\String\u;

final readonly class MysqlBinary
{
    private const string FALLBACK = 'mariadb';

    public function __construct(
        private string $binary = 'mysql',
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
    ) {
    }

    public function locate(): string
    {
        if (u($this->binary)->containsAny(\DIRECTORY_SEPARATOR)) {
            if (!is_executable($this->binary)) {
                throw MysqlBinaryNotFound::at($this->binary);
            }

            return $this->binary;
        }

        $found = $this->executableFinder->find($this->binary) ?? $this->executableFinder->find(self::FALLBACK);

        if (null === $found) {
            throw MysqlBinaryNotFound::named($this->binary, self::FALLBACK);
        }

        return $found;
    }
}
