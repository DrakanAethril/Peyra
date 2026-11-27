<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    // Doctrine writes these and nobody edits them by hand; reformatting them would only guarantee
    // that every freshly generated migration fails the check.
    ->exclude('migrations')
    // Gitignored conception and reference material, not application code.
    ->exclude('design')
    ->notPath([
        // Both are rewritten by Symfony Flex / the running container, not by us.
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    // @Symfony only, and no risky rules: this is a formatter, it must never change behaviour.
    // Anything that could is PHPStan's job (see phpstan.dist.neon).
    ->setRules([
        '@Symfony' => true,
        // Off on purpose: @Symfony's vertical mode pads a short @param halfway across the screen to
        // match a long array shape next to it. Docblock padding stays the author's call.
        'phpdoc_align' => false,
    ])
    ->setRiskyAllowed(false)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setFinder($finder)
;
