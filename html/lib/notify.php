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
 * Send a plain-text email over SMTP using libcurl (no MTA/PHPMailer needed).
 * $smtp = host, port, security (none|starttls|ssl), username, password, from.
 */
function smtp_send($smtp, $to, $subject, $body)
{
    $smtp = array_merge(config_smtp_defaults(), is_array($smtp) ? $smtp : array());
    $host = trim($smtp['host']);
    $to   = trim($to);
    if ($host === '') return array('ok' => false, 'channel' => 'email', 'error' => 'SMTP host not set');
    if ($to === '')   return array('ok' => false, 'channel' => 'email', 'error' => 'no recipient');

    $port = (int) $smtp['port'];
    $sec  = $smtp['security'];
    $user = $smtp['username'];
    $pass = $smtp['password'];
    $from = $smtp['from'] !== '' ? $smtp['from'] : $user;
    if ($from === '') $from = 'scanner@localhost';

    $scheme = ($sec === 'ssl') ? 'smtps' : 'smtp';

    $eol = "\r\n";
    $payload =
        'Date: ' . date('r') . $eol .
        'From: ' . $from . $eol .
        'To: ' . $to . $eol .
        'Subject: ' . $subject . $eol .
        'MIME-Version: 1.0' . $eol .
        'Content-Type: text/plain; charset=UTF-8' . $eol . $eol .
        $body . $eol;

    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $payload);
    rewind($fp);

    $ch = curl_init();
    $opts = array(
        CURLOPT_URL            => $scheme . '://' . $host . ':' . $port,
        CURLOPT_MAIL_FROM      => '<' . $from . '>',
        CURLOPT_MAIL_RCPT      => array('<' . $to . '>'),
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fp,
        CURLOPT_INFILESIZE     => strlen($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    );
    if ($sec === 'starttls') {
        $opts[CURLOPT_USE_SSL] = CURLUSESSL_ALL; // upgrade the plain connection
    }
    if ($user !== '') {
        $opts[CURLOPT_USERNAME] = $user;
        $opts[CURLOPT_PASSWORD] = $pass;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    return array('ok' => ($err === ''), 'channel' => 'email:' . $to, 'error' => $err);
}

/**
 * Broadcast $message to every enabled destination (channels + email contacts).
 * Returns a list of per-destination result arrays.
 */
function notify_send($message)
{
    $cfg = config_load();
    $results = array();

    foreach (config_channels() as $ch) {
        if (empty($ch['enabled'])) continue;
        switch (isset($ch['type']) ? $ch['type'] : '') {
            case 'telegram': $results[] = notify_telegram($ch, $message); break;
            case 'discord':  $results[] = notify_discord($ch, $message); break;
        }
    }

    $email = config_email($cfg);
    if (!empty($email['enabled'])) {
        $smtp = config_smtp($cfg);
        if ($smtp['host'] !== '') {
            $subject = $email['subject'] !== '' ? $email['subject'] : 'Scanner notification';
            foreach (config_contacts($cfg) as $c) {
                if (empty($c['enabled']) || empty($c['email'])) continue;
                $results[] = smtp_send($smtp, $c['email'], $subject, $message);
            }
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
