<?php
/**
 * Scan delivery: send a COPY of the finished PDF to address-book recipients on
 * their channel(s) (email attachment / Telegram document / Discord webhook file),
 * with the caption mentioning the person. Distinct from notify.php (text alerts).
 *
 * Non-fatal by design — a failed delivery never blocks saving to storage.
 *
 * CLI (used by the scan scripts):  php deliver.php <pdf_path> [id1,id2,...]
 *   With ids: also deliver to those (non-default) recipients (Phase 2 UI picker).
 *   Without:  deliver to every enabled "default" recipient.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail.php';

// Per-channel attachment size caps (bytes). Over these we skip + warn.
if (!defined('DELIVER_MAX_EMAIL'))    define('DELIVER_MAX_EMAIL',    20 * 1024 * 1024);
if (!defined('DELIVER_MAX_TELEGRAM')) define('DELIVER_MAX_TELEGRAM', 50 * 1024 * 1024);
if (!defined('DELIVER_MAX_DISCORD'))  define('DELIVER_MAX_DISCORD',   8 * 1024 * 1024);

function deliver_result($channel, $ok, $error = '')
{
    return array('channel' => $channel, 'ok' => (bool) $ok, 'error' => $error);
}

function deliver_too_big($size, $cap)
{
    return round($size / 1048576, 1) . 'MB exceeds ' . round($cap / 1048576) . 'MB limit';
}

/** Bot token used to send Telegram documents — reused from the Notifications Telegram channel. */
function deliver_telegram_token($cfg)
{
    foreach (config_channels($cfg) as $c) {
        if (($c['type'] ?? '') === 'telegram' && trim($c['token'] ?? '') !== '') {
            return trim($c['token']);
        }
    }
    return '';
}

function deliver_email($smtp, $to, $name, $path, $filename)
{
    $size = @filesize($path);
    if ($size !== false && $size > DELIVER_MAX_EMAIL) {
        return deliver_result('email:' . $to, false, deliver_too_big($size, DELIVER_MAX_EMAIL));
    }
    $body = ($name !== '' ? "Hi $name,\n\n" : '') . "Here is a scan from your Brother scanner.\n\n" . $filename;
    $r = smtp_send_mail($smtp, $to, 'Scan: ' . $filename, $body, array(
        array('path' => $path, 'name' => $filename, 'type' => 'application/pdf'),
    ));
    return deliver_result('email:' . $to, !empty($r['ok']), $r['error'] ?? '');
}

function deliver_telegram($token, $chat_id, $caption, $path, $filename)
{
    if ($token === '')  return deliver_result('telegram', false, 'no bot token (configure Telegram in Notifications)');
    if ($chat_id === '') return deliver_result('telegram', false, 'no chat_id');
    $size = @filesize($path);
    if ($size !== false && $size > DELIVER_MAX_TELEGRAM) {
        return deliver_result('telegram', false, deliver_too_big($size, DELIVER_MAX_TELEGRAM));
    }
    $t = (strncmp($token, 'bot', 3) === 0) ? $token : 'bot' . $token;
    $ch = curl_init('https://api.telegram.org/' . $t . '/sendDocument');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array(
            'chat_id'  => $chat_id,
            'caption'  => $caption,
            'document' => new CURLFile($path, 'application/pdf', $filename),
        ),
        CURLOPT_TIMEOUT => 120,
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ok = ($code >= 200 && $code < 300);
    return deliver_result('telegram', $ok, $ok ? '' : ('HTTP ' . $code . ' ' . $err));
}

function deliver_discord($webhook, $content, $path, $filename)
{
    if ($webhook === '') return deliver_result('discord', false, 'no webhook');
    $size = @filesize($path);
    if ($size !== false && $size > DELIVER_MAX_DISCORD) {
        return deliver_result('discord', false, deliver_too_big($size, DELIVER_MAX_DISCORD));
    }
    $ch = curl_init($webhook);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array(
            'payload_json' => json_encode(array('content' => $content)),
            'file1'        => new CURLFile($path, 'application/pdf', $filename),
        ),
        CURLOPT_TIMEOUT => 120,
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ok = ($code >= 200 && $code < 300);
    return deliver_result('discord', $ok, $ok ? '' : ('HTTP ' . $code . ' ' . $err));
}

/**
 * Deliver $path to the selected recipients. $onlyIds = extra (non-default)
 * recipient ids to include; defaults are always included.
 */
function deliver_send($path, $onlyIds = null)
{
    if (!is_file($path)) return array(deliver_result('-', false, 'file not found: ' . $path));

    $cfg      = config_load();
    $smtp     = config_smtp($cfg);
    $tgToken  = deliver_telegram_token($cfg);
    $filename = basename($path);
    $extra    = is_array($onlyIds) ? $onlyIds : array();

    $results = array();
    foreach (config_contacts($cfg) as $c) {
        if (empty($c['enabled'])) continue;
        $isDefault = !empty($c['default']);
        if (!$isDefault && !in_array($c['id'] ?? '', $extra, true)) continue;

        $name = trim($c['name'] ?? '');
        $chn  = isset($c['channels']) && is_array($c['channels']) ? $c['channels'] : array();

        $em = $chn['email'] ?? array();
        if (!empty($em['on']) && trim($em['address'] ?? '') !== '') {
            $results[] = deliver_email($smtp, trim($em['address']), $name, $path, $filename);
        }
        $tg = $chn['telegram'] ?? array();
        if (!empty($tg['on']) && trim($tg['chat_id'] ?? '') !== '') {
            $who = trim($tg['username'] ?? '');
            $caption = 'Scan' . ($name !== '' ? ' for ' . $name : '') . ($who !== '' ? ' (' . $who . ')' : '') . ': ' . $filename;
            $results[] = deliver_telegram($tgToken, trim($tg['chat_id']), $caption, $path, $filename);
        }
        $dc = $chn['discord'] ?? array();
        if (!empty($dc['on']) && trim($dc['webhook'] ?? '') !== '') {
            $mention = trim($dc['mention'] ?? '');
            $content = ($mention !== '' ? '<@' . $mention . '> ' : '') . 'Scan' . ($name !== '' ? ' for ' . $name : '') . ': ' . $filename;
            $results[] = deliver_discord(trim($dc['webhook']), $content, $path, $filename);
        }
    }
    return $results;
}

// ---- CLI entry point (used by the scan scripts) ----
if (php_sapi_name() === 'cli' && isset($argv)) {
    $path = isset($argv[1]) ? $argv[1] : '';
    $ids  = isset($argv[2]) && $argv[2] !== '' ? array_filter(explode(',', $argv[2])) : null;
    if ($path === '') {
        fwrite(STDERR, "usage: php deliver.php <pdf_path> [id1,id2,...]\n");
        exit(2);
    }
    $res = deliver_send($path, $ids);
    if (empty($res)) {
        echo "deliver: no recipients\n";
    }
    foreach ($res as $r) {
        echo 'deliver ' . $r['channel'] . ': ' . ($r['ok'] ? 'ok' : ('FAILED ' . $r['error'])) . "\n";
    }
}
