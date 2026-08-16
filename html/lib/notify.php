<?php
/**
 * Notification dispatcher.
 *
 * Reads the channel list from the runtime config and fans a message out to every
 * enabled channel. New channel types are added here in one place; callers (the
 * scan scripts, the dashboard "test" button) never change.
 *
 * As a library:  require 'notify.php'; notify_send("hello");
 * As a CLI:      php notify.php "message" [channelId]
 *                  - with a channelId, sends to that one channel even if disabled
 *                    (used by the dashboard's per-channel "Send test").
 */

require_once __DIR__ . '/config.php';

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

/**
 * Dispatch $message. With $onlyChannelId set, sends to exactly that channel
 * (ignoring its enabled flag — for tests); otherwise to all enabled channels.
 * Returns a list of per-channel result arrays.
 */
function notify_send($message, $onlyChannelId = null)
{
    $results = array();
    foreach (config_channels() as $ch) {
        $id = isset($ch['id']) ? $ch['id'] : '';
        if ($onlyChannelId !== null) {
            if ($id !== $onlyChannelId) {
                continue;
            }
        } elseif (empty($ch['enabled'])) {
            continue;
        }
        switch (isset($ch['type']) ? $ch['type'] : '') {
            case 'telegram':
                $results[] = notify_telegram($ch, $message);
                break;
            case 'discord':
                $results[] = notify_discord($ch, $message);
                break;
        }
    }
    return $results;
}

// ---- CLI entry point (used by the scan scripts) ----
if (php_sapi_name() === 'cli' && isset($argv)) {
    $msg  = isset($argv[1]) ? $argv[1] : '';
    $only = isset($argv[2]) ? $argv[2] : null;
    if ($msg === '') {
        fwrite(STDERR, "usage: php notify.php \"message\" [channelId]\n");
        exit(2);
    }
    $res = notify_send($msg, $only);
    if (empty($res)) {
        echo "notify: no matching/enabled channels\n";
    }
    foreach ($res as $r) {
        echo 'notify ' . ($r['channel'] ?? '?') . ': '
            . (!empty($r['ok']) ? 'ok' : ('FAILED code=' . ($r['code'] ?? '') . ' ' . ($r['error'] ?? '')))
            . "\n";
    }
}
