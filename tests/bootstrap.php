<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * A minimal PSR-4 autoloader for this package and for the two pattern packages
 * it builds on, so the suite runs without `composer install`. When
 * vendor/autoload.php is present, Composer has registered first and wins.
 *
 * Two details worth knowing:
 *
 *  - `Units\Hasher\Tests\` is a LONGER prefix than `Units\Hasher\`, and the map
 *    is scanned in insertion order. The test namespace has to come first, or
 *    `Units\Hasher\Tests\*` resolves against `src/` and nothing loads.
 *  - `Patterns\` is shared by two packages, so it carries two roots. Composer
 *    does the same thing when two packages declare the same prefix.
 */

spl_autoload_register(static function (string $class): void {
    /** @var array<string, list<string>> $prefixes */
    $prefixes = [
        'Units\\Hasher\\Tests\\' => [__DIR__ . '/'],
        'Units\\Hasher\\'        => [__DIR__ . '/../src/'],
        'Patterns\\'             => [
            __DIR__ . '/../../unit/src/',
            __DIR__ . '/../../value-object/src/',
        ],
    ];

    foreach ($prefixes as $prefix => $roots) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        foreach ($roots as $root) {
            if (is_file($root . $relative)) {
                require $root . $relative;

                return;
            }
        }

        return;
    }
});
