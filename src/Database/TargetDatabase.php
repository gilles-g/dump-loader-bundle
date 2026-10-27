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

namespace DumpLoader\Database;

use function Symfony\Component\String\u;

final readonly class TargetDatabase
{
    public const array SUPPORTED_SCHEMES = ['mysql', 'mysqli', 'pdo-mysql', 'mariadb'];

    private const int DEFAULT_PORT = 3306;

    private const string DEFAULT_CHARSET = 'utf8mb4';

    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
        public string $charset,
    ) {
    }

    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);

        if (!\is_array($parts) || !isset($parts['scheme'])) {
            throw InvalidDsn::unreadable();
        }

        $scheme = u($parts['scheme'])->lower()->toString();

        if (!\in_array($scheme, self::SUPPORTED_SCHEMES, true)) {
            throw InvalidDsn::unsupportedScheme($scheme);
        }

        if (!isset($parts['host']) || '' === $parts['host']) {
            throw InvalidDsn::missingHost();
        }

        $name = u($parts['path'] ?? '')->trimStart('/')->toString();

        if ('' === $name) {
            throw InvalidDsn::missingName();
        }

        /** @var array<string, mixed> $query */
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        if (isset($query['unix_socket'])) {
            throw InvalidDsn::unixSocket();
        }

        $charset = $query['charset'] ?? null;

        return new self(
            host: $parts['host'],
            port: $parts['port'] ?? self::DEFAULT_PORT,
            name: rawurldecode($name),
            user: rawurldecode($parts['user'] ?? ''),
            password: rawurldecode($parts['pass'] ?? ''),
            charset: \is_string($charset) && '' !== $charset ? $charset : self::DEFAULT_CHARSET,
        );
    }

    public function displayName(): string
    {
        return \sprintf('%s:%d/%s', $this->host, $this->port, $this->name);
    }
}
