<?php

/**
 * php-cs-fixer configuration.
 *
 * Non-risky PSR-12 baseline. Strict-types and other risky transforms are
 * intentionally deferred to later phases (see docs/ROADMAP.md). Run with:
 *   vendor/bin/php-cs-fixer fix --dry-run --diff   (check)
 *   vendor/bin/php-cs-fixer fix                     (apply)
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'trailing_comma_in_multiline' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);
