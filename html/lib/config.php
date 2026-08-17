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
        'discord'       => config_discord_defaults(),
        'smtp'          => config_smtp_defaults(),
        'email'         => array('enabled' => false, 'subject' => 'Scanner notification'),
        'processing'    => config_seed_processing(),
        'ocr'           => config_seed_ocr(),
        'ftp'           => config_seed_ftp(),
        'sync'          => config_seed_sync(),
        'address_book'  => array(),
    );
}

/** true if an env var is set to a truthy value. */
function _cfg_envbool($k)
{
    $v = getenv($k);
    return $v !== false && in_array(strtolower(trim($v)), array('1', 'true', 'yes', 'on'), true);
}
function _cfg_env($k, $default = '')
{
    $v = getenv($k);
    return ($v === false || trim($v) === '') ? $default : trim($v);
}

// ---- Seed the migrated sections from environment variables (first run only) ----
function config_seed_processing()
{
    return array(
        'resolution'      => _cfg_env('RESOLUTION', '300'),
        'mode'            => _cfg_env('MODE', '24bit Color[Fast]'),
        'blank_threshold' => _cfg_env('REMOVE_BLANK_THRESHOLD', ''), // empty = disabled
        'jpeg'            => _cfg_envbool('USE_JPEG_COMPRESSION'),
    );
}
function config_seed_ocr()
{
    $s = _cfg_env('OCR_SERVER'); $p = _cfg_env('OCR_PORT'); $pa = _cfg_env('OCR_PATH');
    return array(
        'enabled'         => ($s !== '' && $p !== '' && $pa !== ''),
        'server'          => $s, 'port' => $p, 'path' => $pa,
        'remove_original' => _cfg_envbool('REMOVE_ORIGINAL_AFTER_OCR'),
    );
}
function config_seed_ftp()
{
    $h = _cfg_env('FTP_HOST'); $u = _cfg_env('FTP_USER');
    return array(
        'enabled' => ($h !== '' && $u !== ''),
        'host' => $h, 'user' => $u,
        'password' => (string) getenv('FTP_PASSWORD'),
        'path' => _cfg_env('FTP_PATH', '/scans/'),
    );
}
function config_seed_sync()
{
    $h = _cfg_env('SSH_HOST'); $u = _cfg_env('SSH_USER');
    return array(
        'enabled' => ($h !== '' && $u !== ''),
        'host' => $h, 'user' => $u,
        'password' => (string) getenv('SSH_PASSWORD'),
        'path' => _cfg_env('SSH_PATH'),
    );
}

// ---- Accessors (saved config merged over hardcoded fallbacks) ----
function config_processing($cfg = null)
{
    $cfg = $cfg ?? config_load();
    $p = $cfg['processing'] ?? array();
    return array_merge(array('resolution' => '300', 'mode' => '24bit Color[Fast]', 'blank_threshold' => '', 'jpeg' => false), is_array($p) ? $p : array());
}
function config_ocr($cfg = null)
{
    $cfg = $cfg ?? config_load();
    $o = $cfg['ocr'] ?? array();
    return array_merge(array('enabled' => false, 'server' => '', 'port' => '', 'path' => '', 'remove_original' => false), is_array($o) ? $o : array());
}
function config_ftp($cfg = null)
{
    $cfg = $cfg ?? config_load();
    $f = $cfg['ftp'] ?? array();
    return array_merge(array('enabled' => false, 'host' => '', 'user' => '', 'password' => '', 'path' => '/scans/'), is_array($f) ? $f : array());
}
function config_sync($cfg = null)
{
    $cfg = $cfg ?? config_load();
    $s = $cfg['sync'] ?? array();
    return array_merge(array('enabled' => false, 'host' => '', 'user' => '', 'password' => '', 'path' => ''), is_array($s) ? $s : array());
}

/** Global appearance for outgoing Discord webhook messages (notifications + delivery). */
function config_discord_defaults()
{
    return array(
        'username'   => 'Brother Scanner',
        'avatar_url' => 'https://cdn.jsdelivr.net/gh/selfhst/icons@main/png/brother.png',
    );
}

function config_discord($cfg = null)
{
    if ($cfg === null) {
        $cfg = config_load();
    }
    $d = isset($cfg['discord']) && is_array($cfg['discord']) ? $cfg['discord'] : array();
    return array_merge(config_discord_defaults(), $d);
}

/** Build a Discord webhook payload, applying the username/avatar appearance. */
function discord_payload($content, $appearance = null)
{
    $ap = ($appearance !== null) ? $appearance : config_discord();
    $payload = array('content' => $content);
    if (!empty($ap['username']))   $payload['username']   = $ap['username'];
    if (!empty($ap['avatar_url'])) $payload['avatar_url'] = $ap['avatar_url'];
    return $payload;
}

function config_smtp_defaults()
{
    return array(
        'host'     => '',
        'port'     => 587,
        'security' => 'starttls', // none | starttls | ssl
        'username' => '',
        'password' => '',
        'from'     => '',
    );
}

/** SMTP settings with all defaults filled in. */
function config_smtp($cfg = null)
{
    if ($cfg === null) {
        $cfg = config_load();
    }
    $smtp = isset($cfg['smtp']) && is_array($cfg['smtp']) ? $cfg['smtp'] : array();
    return array_merge(config_smtp_defaults(), $smtp);
}

/** Email notification settings. */
function config_email($cfg = null)
{
    if ($cfg === null) {
        $cfg = config_load();
    }
    $e = isset($cfg['email']) && is_array($cfg['email']) ? $cfg['email'] : array();
    return array_merge(array('enabled' => false, 'subject' => 'Scanner notification'), $e);
}

/** Address-book contacts. */
function config_contacts($cfg = null)
{
    if ($cfg === null) {
        $cfg = config_load();
    }
    return isset($cfg['address_book']) && is_array($cfg['address_book']) ? $cfg['address_book'] : array();
}

/** Load the config (falling back to the env-seeded default if absent/invalid). */
function config_load()
{
    if (is_file(CONFIG_FILE)) {
        $cfg = json_decode((string) @file_get_contents(CONFIG_FILE), true);
        if (is_array($cfg)) {
            // Upgrade path: a config.json written before a section existed (e.g.
            // processing/ocr/ftp/sync) inherits that section's env-seeded default,
            // while every section the file already defines wins. Top-level merge
            // only — the per-section accessors fill in any missing sub-keys.
            return array_merge(config_default(), $cfg);
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
