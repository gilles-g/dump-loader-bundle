<?php

declare(strict_types=1);

if (!file_exists(__DIR__.'/src')) {
    exit(0);
}

$header = <<<'EOF'
    This file is part of the gilles-g/dump-loader package.

    (c) gilles-g <ggauthier@spiriit.com>

    For the full copyright and license information, please view the LICENSE
    file that was distributed with this source code.
    EOF;

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'header_comment' => ['header' => $header],
        'php_unit_method_casing' => ['case' => 'snake_case'],
    ])
    ->setFinder($finder)
;
