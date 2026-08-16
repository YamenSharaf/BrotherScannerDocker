<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/notify.php';

require_admin();

$flash = null; // array(type, msg)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $flash = array('err', 'Session expired, please try again.');
    } elseif (($_POST['action'] ?? '') === 'save') {
        $cfg = config_load();
        // Preserve any channels other than the two we manage here.
        $others = array();
        foreach (config_channels() as $c) {
            if (!in_array($c['id'] ?? '', array('telegram', 'discord'), true)) {
                $others[] = $c;
            }
        }
        $telegram = array(
            'id' => 'telegram', 'type' => 'telegram', 'name' => 'Telegram',
            'enabled' => isset($_POST['tg_enabled']),
            'token'   => trim($_POST['tg_token'] ?? ''),
            'chat_id' => trim($_POST['tg_chat'] ?? ''),
        );
        $discord = array(
            'id' => 'discord', 'type' => 'discord', 'name' => 'Discord',
            'enabled' => isset($_POST['dc_enabled']),
            'webhook_url' => trim($_POST['dc_webhook'] ?? ''),
        );
        $cfg['notifications']['channels'] = array_merge(array($telegram, $discord), $others);
        $flash = config_save($cfg)
            ? array('ok', 'Notification settings saved.')
            : array('err', 'Could not write the config file. Is /config writable?');
    } elseif (($_POST['action'] ?? '') === 'test') {
        $id = $_POST['channel'] ?? '';
        $res = notify_send("Test notification from your Brother scanner \xe2\x9c\x85", $id);
        if (empty($res)) {
            $flash = array('err', 'Nothing to test — that channel is not configured yet (save first).');
        } else {
            $r = $res[0];
            $flash = !empty($r['ok'])
                ? array('ok', 'Test sent to ' . htmlspecialchars($id) . ' successfully.')
                : array('err', 'Test to ' . htmlspecialchars($id) . ' failed (HTTP ' . ($r['code'] ?? '?') . ') ' . htmlspecialchars($r['error'] ?? ''));
        }
    }
}

// Current values for the form.
$ch = array();
foreach (config_channels() as $c) {
    $ch[$c['id'] ?? ''] = $c;
}
$tg = $ch['telegram'] ?? array('enabled' => false, 'token' => '', 'chat_id' => '');
$dc = $ch['discord'] ?? array('enabled' => false, 'webhook_url' => '');
$token = csrf_token();
$val = function ($a, $k) { return htmlspecialchars((string) ($a[$k] ?? '')); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f1115" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f5f6f8" media="(prefers-color-scheme: light)">
    <title>Scanner Admin</title>
    <link rel="icon" type="image/svg+xml" href="/assets/brother_logo.svg">
    <link rel="stylesheet" href="/assets/fontawesome.5.15.4/css/all.min.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <h1><i class="fas fa-sliders-h"></i> Scanner Admin</h1>
            <div class="right">
                <a class="btn ghost" href="/"><i class="fas fa-arrow-left"></i> Scanner</a>
                <a class="btn ghost" href="/admin/logout.php">Sign out</a>
            </div>
        </div>

        <?php if ($flash) { ?>
            <div class="flash <?php echo $flash[0]; ?>"><?php echo $flash[1]; ?></div>
        <?php } ?>

        <p class="desc" style="margin:-.4rem 0 1rem">Notifications are sent to every <strong>enabled</strong> channel when a scan completes.</p>

        <form method="post" action="/admin/">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="action" value="save">

            <!-- Telegram -->
            <div class="card">
                <div class="row" style="margin-bottom:.6rem">
                    <h2><span class="type-ico"><i class="fab fa-telegram-plane"></i></span> Telegram</h2>
                    <label class="switch">
                        <input type="checkbox" name="tg_enabled" <?php echo !empty($tg['enabled']) ? 'checked' : ''; ?>>
                        <span class="track"></span> Enabled
                    </label>
                </div>
                <div class="field">
                    <label>Bot token</label>
                    <input type="text" name="tg_token" value="<?php echo $val($tg, 'token'); ?>" placeholder="bot123456:ABC-DEF..." autocomplete="off" spellcheck="false">
                </div>
                <div class="field">
                    <label>Chat ID</label>
                    <input type="text" name="tg_chat" value="<?php echo $val($tg, 'chat_id'); ?>" placeholder="123456789" autocomplete="off">
                </div>
            </div>

            <!-- Discord -->
            <div class="card">
                <div class="row" style="margin-bottom:.6rem">
                    <h2><span class="type-ico"><i class="fab fa-discord"></i></span> Discord</h2>
                    <label class="switch">
                        <input type="checkbox" name="dc_enabled" <?php echo !empty($dc['enabled']) ? 'checked' : ''; ?>>
                        <span class="track"></span> Enabled
                    </label>
                </div>
                <div class="field">
                    <label>Webhook URL</label>
                    <input type="text" name="dc_webhook" value="<?php echo $val($dc, 'webhook_url'); ?>" placeholder="https://discord.com/api/webhooks/..." autocomplete="off" spellcheck="false">
                </div>
            </div>

            <div class="actions">
                <button class="btn primary" type="submit"><i class="fas fa-save"></i> Save changes</button>
            </div>
        </form>

        <!-- Per-channel test (uses the saved settings) -->
        <div class="actions" style="margin-top:.6rem">
            <form method="post" action="/admin/" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="action" value="test">
                <input type="hidden" name="channel" value="telegram">
                <button class="btn" type="submit"><i class="fas fa-paper-plane"></i> Test Telegram</button>
            </form>
            <form method="post" action="/admin/" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="action" value="test">
                <input type="hidden" name="channel" value="discord">
                <button class="btn" type="submit"><i class="fas fa-paper-plane"></i> Test Discord</button>
            </form>
        </div>
        <p class="desc" style="margin-top:.6rem">Tests use the last <em>saved</em> settings — save first if you just edited a field.</p>
    </div>
</body>
</html>
