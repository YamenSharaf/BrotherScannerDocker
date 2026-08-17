<?php
/**
 * Emits `export VAR='value'` lines for the processing/integration settings that
 * now live in config.json, using the exact variable names the scan pipeline's
 * bash already consumes (RESOLUTION, MODE, USE_JPEG_COMPRESSION,
 * REMOVE_BLANK_THRESHOLD, OCR_*, FTP_*, SSH_*).
 *
 * The scan scripts run `eval "$(php lib/scanenv.php)"` after sourcing env.txt, so
 * the dashboard config overrides the boot environment with zero changes to the
 * existing bash logic. Disabled features emit empty values so the existing bash
 * guards (`[ -z "$OCR_SERVER" ]`, etc.) simply skip them.
 */
require __DIR__ . '/config.php';

$cfg  = config_load();
$p    = config_processing($cfg);
$o    = config_ocr($cfg);
$f    = config_ftp($cfg);
$s    = config_sync($cfg);

function sh_export($k, $v)
{
    $v = str_replace("'", "'\\''", (string) $v); // safe single-quote escaping
    echo "export $k='" . $v . "'\n";
}

sh_export('RESOLUTION', $p['resolution']);
sh_export('MODE', $p['mode']);
sh_export('USE_JPEG_COMPRESSION', !empty($p['jpeg']) ? 'true' : '');
sh_export('REMOVE_BLANK_THRESHOLD', $p['blank_threshold']); // empty disables blank removal

$ocrOn = !empty($o['enabled']);
sh_export('OCR_SERVER', $ocrOn ? $o['server'] : '');
sh_export('OCR_PORT',   $ocrOn ? $o['port'] : '');
sh_export('OCR_PATH',   $ocrOn ? $o['path'] : '');
sh_export('REMOVE_ORIGINAL_AFTER_OCR', !empty($o['remove_original']) ? 'true' : 'false');

$ftpOn = !empty($f['enabled']);
sh_export('FTP_HOST',     $ftpOn ? $f['host'] : '');
sh_export('FTP_USER',     $ftpOn ? $f['user'] : '');
sh_export('FTP_PASSWORD', $ftpOn ? $f['password'] : '');
sh_export('FTP_PATH',     $f['path']);

$syncOn = !empty($s['enabled']);
sh_export('SSH_HOST',     $syncOn ? $s['host'] : '');
sh_export('SSH_USER',     $syncOn ? $s['user'] : '');
sh_export('SSH_PASSWORD', $syncOn ? $s['password'] : '');
sh_export('SSH_PATH',     $s['path']);
