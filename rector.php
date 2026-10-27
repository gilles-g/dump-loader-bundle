<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\MethodCall\RemoveNullNamedArgOnNullDefaultParamRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([__DIR__])
    ->withSkip([
        __DIR__.'/.github',
        __DIR__.'/vendor',
        NarrowObjectReturnTypeRector::class => [__DIR__.'/src/Controller'],
        RemoveNullNamedArgOnNullDefaultParamRector::class => [__DIR__.'/tests'],
    ])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPhpSets(php84: true)
    ->withImportNames(importShortClasses: false)
    ->withPreparedSets(
        deadCode: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
;
