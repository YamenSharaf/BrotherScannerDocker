<?php
/**
 * Ad-hoc "send test" endpoint for the dashboard. Authenticated + CSRF-checked.
 * Takes the CURRENT (unsaved) field values from the form and dispatches one test
 * message, returning JSON so the UI can show an inline result per service.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/notify.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'message' => 'Not authenticated.'));
    exit;
}
if (!csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'Session expired — reload the page.'));
    exit;
}

$type    = $_POST['type'] ?? '';
$message = "Test notification from your Brother scanner \xe2\x9c\x85";

/** Plain TCP reachability check (no auth). Returns array(ok, error). */
function tcp_check($host, $port, $timeout = 6)
{
    $host = trim((string) $host);
    $port = (int) $port;
    if ($host === '' || $port <= 0) {
        return array('ok' => false, 'error' => 'Host and port are required.');
    }
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return array('ok' => true);
    }
    return array('ok' => false, 'error' => ($errstr !== '' ? $errstr : 'Connection failed') . ($errno ? " (errno $errno)" : ''));
}

switch ($type) {
    case 'telegram':
        $r = notify_test('telegram', array(
            'token'   => $_POST['token'] ?? '',
            'chat_id' => $_POST['chat_id'] ?? '',
        ), $message);
        break;

    case 'discord':
        $r = notify_test('discord', array(
            'webhook_url' => $_POST['webhook_url'] ?? '',
            'username'    => $_POST['username'] ?? '',
            'avatar_url'  => $_POST['avatar_url'] ?? '',
        ), $message);
        break;

    case 'email':
        $r = notify_test('email', array(
            'to'      => $_POST['to'] ?? '',
            'subject' => 'Brother scanner — test email',
            'smtp'    => array(
                'host'     => $_POST['host'] ?? '',
                'port'     => $_POST['port'] ?? 587,
                'security' => $_POST['security'] ?? 'starttls',
                'username' => $_POST['username'] ?? '',
                'password' => $_POST['password'] ?? '',
                'from'     => $_POST['from'] ?? '',
            ),
        ), 'This is a test email from your Brother scanner.');
        break;

    case 'ocr':
        $r = tcp_check($_POST['server'] ?? '', $_POST['port'] ?? '');
        $r['label'] = 'reachable';
        break;

    case 'ftp':
        $host = trim($_POST['host'] ?? '');
        $user = trim($_POST['user'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        if ($host === '') {
            $r = array('ok' => false, 'error' => 'Host is required.');
        } elseif (function_exists('ftp_connect')) {
            // Real login test when the PHP ftp extension is available.
            $conn = @ftp_connect($host, 21, 8);
            if (!$conn) {
                $r = array('ok' => false, 'error' => 'Could not connect to FTP host.');
            } elseif (!@ftp_login($conn, $user, $pass)) {
                @ftp_close($conn);
                $r = array('ok' => false, 'error' => 'Login rejected (check username/password).');
            } else {
                @ftp_close($conn);
                $r = array('ok' => true, 'label' => 'connected');
            }
        } else {
            // No ftp extension in the image — fall back to a port reachability check.
            $r = tcp_check($host, 21);
            $r['label'] = 'reachable';
        }
        break;

    case 'ssh':
        // The pipeline uses password SSH (sshpass); a real auth test needs a
        // shell round-trip. Keep it dependency-free: verify the SSH port answers.
        $r = tcp_check($_POST['host'] ?? '', 22);
        $r['label'] = 'reachable';
        break;

    default:
        echo json_encode(array('ok' => false, 'message' => 'Unknown test type.'));
        exit;
}

$ok = !empty($r['ok']);
$label = $r['label'] ?? 'sent';
if ($ok) {
    $message = $label === 'reachable' ? 'Reachable.' : ($label === 'connected' ? 'Connected successfully.' : 'Sent successfully.');
} else {
    $message = 'Failed: ' . ($r['error'] ?? '') . (isset($r['code']) && $r['code'] ? ' (HTTP ' . $r['code'] . ')' : '');
}

echo json_encode(array('ok' => $ok, 'message' => $message));
