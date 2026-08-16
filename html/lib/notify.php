<?php
/**
 * Notification dispatcher.
 *
 * Reads channels/SMTP/address-book from the runtime config and fans a message
 * out to every enabled destination (Telegram, Discord webhooks, and email to
 * the address book via SMTP). New destination types are added here in one place.
 *
 * As a library:  require 'notify.php'; notify_send("hello");
 * As a CLI:      php notify.php "message"      (used by the scan scripts)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail.php';

function notify_http($url, $postfields, $headers = array())
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postfields,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('code' => $code, 'error' => $err, 'body' => $body);
}

function notify_telegram($ch, $message)
{
    $token = isset($ch['token']) ? trim($ch['token']) : '';
    $chat  = isset($ch['chat_id']) ? trim($ch['chat_id']) : '';
    if ($token === '' || $chat === '') {
        return array('ok' => false, 'channel' => 'telegram', 'error' => 'missing token/chat_id');
    }
    // The Telegram API path is /bot<token>/... ; tolerate a token that already
    // includes the "bot" prefix (the legacy TELEGRAM_TOKEN convention).
    $t = (strncmp($token, 'bot', 3) === 0) ? $token : 'bot' . $token;
    $r = notify_http('https://api.telegram.org/' . $t . '/sendMessage', array('chat_id' => $chat, 'text' => $message));
    return array('ok' => ($r['code'] >= 200 && $r['code'] < 300), 'channel' => 'telegram', 'code' => $r['code'], 'error' => $r['error']);
}

function notify_discord($ch, $message)
{
    $url = isset($ch['webhook_url']) ? trim($ch['webhook_url']) : '';
    if ($url === '') {
        return array('ok' => false, 'channel' => 'discord', 'error' => 'missing webhook_url');
    }
    $r = notify_http($url, json_encode(array('content' => $message)), array('Content-Type: application/json'));
    return array('ok' => ($r['code'] >= 200 && $r['code'] < 300), 'channel' => 'discord', 'code' => $r['code'], 'error' => $r['error']);
}

/** Send a plain-text email over SMTP (delegates to the shared mailer). */
function smtp_send($smtp, $to, $subject, $body)
{
    $r = smtp_send_mail($smtp, $to, $subject, $body);
    return array('ok' => !empty($r['ok']), 'channel' => 'email:' . $to, 'error' => $r['error'] ?? '');
}

/**
 * Broadcast a short text ALERT to every enabled notification channel. This is
 * distinct from scan delivery (deliver.php sends the actual file to the address
 * book) — notifications are just "a scan happened" pings.
 */
function notify_send($message)
{
    $results = array();
    foreach (config_channels() as $ch) {
        if (empty($ch['enabled'])) continue;
        switch (isset($ch['type']) ? $ch['type'] : '') {
            case 'telegram': $results[] = notify_telegram($ch, $message); break;
            case 'discord':  $results[] = notify_discord($ch, $message); break;
        }
    }
    return $results;
}

/**
 * Ad-hoc test send using explicitly provided params (NOT the saved config), so
 * the dashboard can test a channel before saving. Returns a single result array.
 */
function notify_test($type, $params, $message)
{
    switch ($type) {
        case 'telegram':
            return notify_telegram($params, $message);
        case 'discord':
            return notify_discord($params, $message);
        case 'email':
            return smtp_send(
                isset($params['smtp']) ? $params['smtp'] : array(),
                isset($params['to']) ? $params['to'] : '',
                isset($params['subject']) ? $params['subject'] : 'Scanner test',
                $message
            );
        default:
            return array('ok' => false, 'error' => 'unknown channel type');
    }
}

// ---- CLI entry point (used by the scan scripts) ----
if (php_sapi_name() === 'cli' && isset($argv)) {
    $msg = isset($argv[1]) ? $argv[1] : '';
    if ($msg === '') {
        fwrite(STDERR, "usage: php notify.php \"message\"\n");
        exit(2);
    }
    $res = notify_send($msg);
    if (empty($res)) {
        echo "notify: no enabled destinations\n";
    }
    foreach ($res as $r) {
        echo 'notify ' . ($r['channel'] ?? '?') . ': '
            . (!empty($r['ok']) ? 'ok' : ('FAILED ' . ($r['error'] ?? '') . (isset($r['code']) ? ' code=' . $r['code'] : '')))
            . "\n";
    }
}
