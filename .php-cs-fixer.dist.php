<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->append([
        __DIR__.'/bin/fit-repair',
        __DIR__.'/bin/fit-repair-corpus',
        __DIR__.'/bin/fit-plan-local-time-zones',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setFinder($finder)
;
