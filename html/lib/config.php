<?php
/**
 * Runtime configuration store.
 *
 * Persisted as JSON on the /config volume (kept OUTSIDE the web root so its
 * secrets are never served). Environment variables SEED it on first run; after
 * that the admin dashboard owns it and takes precedence.
 *
 * Shared by the web layer (dashboard) and the CLI (boot seeding + notify.php).
 */

if (!defined('CONFIG_DIR'))  define('CONFIG_DIR', '/config');
if (!defined('CONFIG_FILE')) define('CONFIG_FILE', CONFIG_DIR . '/config.json');

/** The initial config, seeded from environment variables. */
function config_default()
{
    $channels = array();

    // Seed a Telegram channel from the legacy TELEGRAM_TOKEN / TELEGRAM_CHATID.
    $tgToken = getenv('TELEGRAM_TOKEN');
    $tgChat  = getenv('TELEGRAM_CHATID');
    if ($tgToken !== false && $tgToken !== '' && $tgChat !== false && $tgChat !== '') {
        $channels[] = array(
            'id'      => 'telegram',
            'type'    => 'telegram',
            'name'    => 'Telegram',
            'enabled' => true,
            'token'   => trim($tgToken),
            'chat_id' => trim($tgChat),
        );
    }

    return array(
        'version'       => 1,
        'notifications' => array('channels' => $channels),
    );
}

/** Load the config (falling back to the env-seeded default if absent/invalid). */
function config_load()
{
    if (is_file(CONFIG_FILE)) {
        $cfg = json_decode((string) @file_get_contents(CONFIG_FILE), true);
        if (is_array($cfg)) {
            return $cfg;
        }
    }
    return config_default();
}

/** Persist the config atomically. Returns true on success. */
function config_save($cfg)
{
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0775, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = CONFIG_FILE . '.tmp';
    if (@file_put_contents($tmp, $json . "\n") === false) {
        return false;
    }
    @chmod($tmp, 0664);
    return @rename($tmp, CONFIG_FILE);
}

/** Write the seeded default if no config file exists yet. */
function config_seed_if_missing()
{
    if (is_file(CONFIG_FILE)) {
        return true;
    }
    return config_save(config_default());
}

/** Return the list of configured notification channels. */
function config_channels()
{
    $cfg = config_load();
    return isset($cfg['notifications']['channels']) && is_array($cfg['notifications']['channels'])
        ? $cfg['notifications']['channels']
        : array();
}
