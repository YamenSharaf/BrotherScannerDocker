<?php
/**
 * SMTP mailer (pure php-curl, no MTA/PHPMailer). Sends a plain-text email,
 * optionally with file attachments. Shared by notify.php (text alerts) and
 * deliver.php (scan delivery).
 */
require_once __DIR__ . '/config.php';

/**
 * @param array $attachments list of ['path'=>..., 'name'=>..., 'type'=>...]
 * @return array ['ok'=>bool, 'error'=>string]
 */
function smtp_send_mail($smtp, $to, $subject, $body, $attachments = array())
{
    $smtp = array_merge(config_smtp_defaults(), is_array($smtp) ? $smtp : array());
    $host = trim($smtp['host']);
    $to   = trim($to);
    if ($host === '') return array('ok' => false, 'error' => 'SMTP host not set');
    if ($to === '')   return array('ok' => false, 'error' => 'no recipient');

    $port = (int) $smtp['port'];
    $sec  = $smtp['security'];
    $user = $smtp['username'];
    $pass = $smtp['password'];
    $from = $smtp['from'] !== '' ? $smtp['from'] : $user;
    if ($from === '') $from = 'scanner@localhost';

    $eol = "\r\n";
    $head = 'Date: ' . date('r') . $eol
          . 'From: ' . $from . $eol
          . 'To: ' . $to . $eol
          . 'Subject: ' . $subject . $eol
          . 'MIME-Version: 1.0' . $eol;

    if (empty($attachments)) {
        $payload = $head
            . 'Content-Type: text/plain; charset=UTF-8' . $eol . $eol
            . $body . $eol;
    } else {
        $boundary = 'bnd_' . bin2hex(random_bytes(10));
        $payload = $head
            . 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol . $eol
            . '--' . $boundary . $eol
            . 'Content-Type: text/plain; charset=UTF-8' . $eol . $eol
            . $body . $eol . $eol;
        foreach ($attachments as $att) {
            $data = @file_get_contents($att['path']);
            if ($data === false) continue;
            $name  = isset($att['name']) ? $att['name'] : basename($att['path']);
            $ctype = isset($att['type']) ? $att['type'] : 'application/octet-stream';
            $payload .= '--' . $boundary . $eol
                . 'Content-Type: ' . $ctype . '; name="' . $name . '"' . $eol
                . 'Content-Transfer-Encoding: base64' . $eol
                . 'Content-Disposition: attachment; filename="' . $name . '"' . $eol . $eol
                . chunk_split(base64_encode($data)) . $eol;
        }
        $payload .= '--' . $boundary . '--' . $eol;
    }

    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $payload);
    rewind($fp);

    $ch = curl_init();
    $opts = array(
        CURLOPT_URL            => (($sec === 'ssl') ? 'smtps' : 'smtp') . '://' . $host . ':' . $port,
        CURLOPT_MAIL_FROM      => '<' . $from . '>',
        CURLOPT_MAIL_RCPT      => array('<' . $to . '>'),
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fp,
        CURLOPT_INFILESIZE     => strlen($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 10,
    );
    if ($sec === 'starttls') {
        $opts[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
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

    return array('ok' => ($err === ''), 'error' => $err);
}
