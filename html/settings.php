<?php
/**
 * Central configuration for the web GUI + API.
 *
 * Included by index.php (GUI) and scan.php (API). Configuration comes from the
 * $ENV array that runScanner.sh writes into config.php at boot.
 *
 * Why not getenv()? lighttpd's FastCGI config forwards only a small allowlist
 * of environment variables to php-cgi (bin-copy-environment: PATH, SHELL,
 * USER), so the container's `-e` variables never reach the web layer. The boot
 * script has the full environment, so it captures what the GUI needs into
 * config.php (which is executed, never served as text — nothing is exposed —
 * and deliberately omits secrets like passwords/tokens). getenv() is kept as a
 * fallback so the pages still render when run directly (e.g. `php index.php`
 * for local testing).
 */

@include __DIR__ . '/config.php'; // provides $UID and $ENV (written at boot)
if (!isset($UID)) {
    $UID = 1000;
}
$ENV = (isset($ENV) && is_array($ENV)) ? $ENV : array();

/** Read a config value (from $ENV, falling back to getenv). */
function env_str($key, $default = '')
{
    global $ENV;
    if (array_key_exists($key, $ENV)) {
        $v = $ENV[$key];
    } else {
        $v = getenv($key);          // fallback for direct-CLI / non-lighttpd use
        if ($v === false) {
            return $default;
        }
    }
    $v = trim((string) $v);
    return $v === '' ? $default : $v;
}

/** Read an env var as a boolean (1/true/yes/on, case-insensitive). */
function env_bool($key)
{
    return in_array(strtolower(env_str($key)), array('1', 'true', 'yes', 'on'), true);
}

$MODEL      = env_str('MODEL', 'Scanner');
$NAME       = env_str('NAME', 'Scanner');
$RESOLUTION = env_str('RESOLUTION', '300');

/**
 * Post-processing capabilities that are actually configured. Surfaced in the
 * GUI as read-only "chips" so the user can see what will happen to a scan —
 * honest status rather than fake buttons.
 */
$FEATURES = array(
    'ocr'           => env_str('OCR_SERVER') !== '' && env_str('OCR_PORT') !== '' && env_str('OCR_PATH') !== '',
    'ftp'           => env_str('FTP_HOST') !== '' && env_str('FTP_USER') !== '',
    // Detected via TELEGRAM_CHATID: the secret TELEGRAM_TOKEN is intentionally
    // not written into config.php, so we use the chat id as the presence proxy.
    'telegram'      => env_str('TELEGRAM_CHATID') !== '',
    'blank_removal' => env_str('REMOVE_BLANK_THRESHOLD') !== '',
    'jpeg'          => env_bool('USE_JPEG_COMPRESSION'),
);

/**
 * The four fixed Brother brscan-skey button types. The backend scripts must be
 * named scanto<key>.sh and scan.php dispatches on <key>, so these keys are not
 * free-form. Labels are fixed honest defaults (edit them here if you want to
 * reword or localize); only visibility is configurable via env.
 *
 *   - file  = scan the FRONT pages (starts a document; opens the ~120s window)
 *   - email = scan the REAR pages  (completes a double-sided document)
 *   - image = unimplemented stub  -> hidden unless ENABLE_GUI_SCANTOIMAGE
 *   - ocr   = unimplemented stub  -> hidden unless ENABLE_GUI_SCANTOOCR
 *
 * file/email are always shown (they are the working duplex pair). image/ocr
 * default to hidden because they do nothing out of the box; enable them once
 * you have mounted a working scanto{image,ocr}.sh of your own.
 */
$BUTTONS = array(
    'file' => array(
        'label'   => 'Scan front pages',
        'hint'    => 'Start a document — single-sided, or the fronts of a double-sided one',
        'icon'    => 'fas fa-file-alt',
        'primary' => true,
        'enabled' => true,
    ),
    'email' => array(
        'label'   => 'Scan rear pages',
        'hint'    => 'Add the backs of the stack you just scanned — pages interleave automatically',
        'icon'    => 'fas fa-clone',
        'primary' => false,
        'enabled' => true,
    ),
    'image' => array(
        'label'   => 'Scan to image',
        'hint'    => '',
        'icon'    => 'fas fa-image',
        'primary' => false,
        'enabled' => env_bool('ENABLE_GUI_SCANTOIMAGE'),
    ),
    'ocr' => array(
        'label'   => 'Scan to OCR',
        'hint'    => '',
        'icon'    => 'fas fa-brain',
        'primary' => false,
        'enabled' => env_bool('ENABLE_GUI_SCANTOOCR'),
    ),
);

/** Targets scan.php will accept (the four real scripts, regardless of GUI visibility). */
$SCAN_TARGETS = array('file', 'email', 'image', 'ocr');
