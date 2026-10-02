<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Fresh database schema for every test-suite run (SQLite by default, MySQL in CI).
// Each test then runs inside a transaction rolled back by DAMADoctrineTestBundle.
if ('test' === ($_SERVER['APP_ENV'] ?? null) && !getenv('MZIAN_SKIP_SCHEMA_RESET')) {
    $console = escapeshellarg(dirname(__DIR__).'/bin/console');
    foreach (['doctrine:schema:drop --force --full-database', 'doctrine:schema:create'] as $command) {
        exec(sprintf('php %s %s --env=test --no-interaction -q 2>&1', $console, $command), $output, $code);
        if (0 !== $code) {
            fwrite(STDERR, "Test database setup failed ({$command}):\n".implode("\n", $output)."\n");
            exit(1);
        }
    }
}
