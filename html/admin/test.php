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

    default:
        echo json_encode(array('ok' => false, 'message' => 'Unknown test type.'));
        exit;
}

$ok = !empty($r['ok']);
$message = $ok
    ? 'Sent successfully.'
    : ('Failed: ' . ($r['error'] ?? '') . (isset($r['code']) && $r['code'] ? ' (HTTP ' . $r['code'] . ')' : ''));

echo json_encode(array('ok' => $ok, 'message' => $message));
