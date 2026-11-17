<?php

declare(strict_types=1);

/*
| D99: every env() key the config reads is listed in .env.example (set, or commented with
| its default), so a production .env can be written from it without reading config/.
*/

test('.env.example lists every env key used in config/', function () {
    $example = (string) file_get_contents(dirname(__DIR__, 2).'/.env.example');
    $missing = [];

    foreach (glob(dirname(__DIR__, 2).'/config/*.php') ?: [] as $file) {
        preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $key) {
            if (preg_match('/^#?\s*'.preg_quote($key, '/').'=/m', $example) !== 1) {
                $missing[] = basename($file).': '.$key;
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
