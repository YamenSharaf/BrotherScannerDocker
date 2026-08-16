<?php
// Boot-time seeding: create /config/config.json from the environment on first
// run. Invoked by runScanner.sh via php-cli. No-op if the file already exists.
require __DIR__ . '/config.php';

if (config_seed_if_missing()) {
    echo 'config store ready at ' . CONFIG_FILE . "\n";
} else {
    fwrite(STDERR, "warning: could not seed config at " . CONFIG_FILE . "\n");
}
