<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/database']);

return (new Config())
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
    ])
    ->setRiskyAllowed(true);
