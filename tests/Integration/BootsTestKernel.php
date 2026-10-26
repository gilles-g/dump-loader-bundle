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

namespace DumpLoader\Tests\Integration;

use DumpLoader\Tests\Integration\Stubs\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;

trait BootsTestKernel
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $environment = $options['environment'] ?? 'test';
        $debug = $options['debug'] ?? true;
        $config = $options['config'] ?? 'base';

        return new Kernel(
            \is_string($environment) ? $environment : 'test',
            \is_bool($debug) ? $debug : true,
            \is_string($config) ? $config : 'base',
        );
    }

    protected static function purgeDumpLoaderVarDir(): void
    {
        $varDir = self::getContainer()->getParameter('dump_loader.var_dir');

        self::assertIsString($varDir);

        if (!is_dir($varDir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($varDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        $service = self::getContainer()->get($id);

        self::assertInstanceOf($id, $service);

        return $service;
    }
}
