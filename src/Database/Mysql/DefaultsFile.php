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

use DumpLoader\Database\TargetDatabase;

use function Symfony\Component\String\u;

final readonly class DefaultsFile
{
    public const PREFIX = 'defaults-extra-';

    public function __construct(
        private string $varDir,
        private bool $ssl = false,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(string): T $use
     *
     * @return T
     */
    public function withCredentials(TargetDatabase $database, callable $use): mixed
    {
        $file = $this->write($database);

        try {
            return $use($file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function write(TargetDatabase $database): string
    {
        $content = implode("\n", [
            '[client]',
            \sprintf('host = %s', self::quote('host', $database->host)),
            \sprintf('port = %d', $database->port),
            \sprintf('user = %s', self::quote('user', $database->user)),
            \sprintf('password = %s', self::quote('password', $database->password)),
            \sprintf('default-character-set = %s', self::quote('charset', $database->charset)),
            '',
            // MariaDB clients >= 11.4 require TLS unless told otherwise; MySQL clients do not read this group.
            '[client-mariadb]',
            $this->ssl ? 'ssl' : 'skip-ssl',
            '',
        ]);

        if (!is_dir($this->varDir) && !@mkdir($this->varDir, 0o770, true) && !is_dir($this->varDir)) {
            throw DefaultsFileNotWritable::in($this->varDir);
        }

        $file = $this->varDir.'/'.self::PREFIX.bin2hex(random_bytes(8));
        // Unlike tempnam(), mode "x" never falls back to the system temporary directory.
        $handle = @fopen($file, 'x');

        if (false === $handle) {
            throw DefaultsFileNotWritable::in($this->varDir);
        }

        chmod($file, 0o600);
        fwrite($handle, $content);
        fclose($handle);

        return $file;
    }

    private static function quote(string $field, string $value): string
    {
        if (u($value)->containsAny(["\n", "\r"])) {
            throw DefaultsFileNotWritable::lineBreakIn($field);
        }

        return '"'.u($value)->replace('\\', '\\\\')->replace('"', '\\"')->toString().'"';
    }
}
