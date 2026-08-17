<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/config.php';

require_admin();

$flash = null;              // array(type, msg)
$active = 'notifications';  // which tab to show after a POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $active = $_POST['active_tab'] ?? 'notifications';
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $flash = array('err', 'Session expired, please try again.');
    } else {
        $cfg = config_load();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_notifications') {
            $others = array();
            foreach (config_channels($cfg) as $c) {
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
            // Global appearance for all Discord webhook messages (alerts + delivery).
            $cfg['discord'] = array(
                'username'   => trim($_POST['dc_username'] ?? ''),
                'avatar_url' => trim($_POST['dc_avatar'] ?? ''),
            );
            $flash = config_save($cfg) ? array('ok', 'Notification settings saved.') : array('err', 'Could not write config (/config not writable?).');

        } elseif ($action === 'save_smtp') {
            $cfg['smtp'] = array(
                'host'     => trim($_POST['smtp_host'] ?? ''),
                'port'     => (int) ($_POST['smtp_port'] ?? 587),
                'security' => in_array($_POST['smtp_security'] ?? '', array('none', 'starttls', 'ssl'), true) ? $_POST['smtp_security'] : 'starttls',
                'username' => trim($_POST['smtp_user'] ?? ''),
                'password' => (string) ($_POST['smtp_pass'] ?? ''),
                'from'     => trim($_POST['smtp_from'] ?? ''),
            );
            $flash = config_save($cfg) ? array('ok', 'SMTP settings saved.') : array('err', 'Could not write config (/config not writable?).');

        } elseif ($action === 'save_addressbook') {
            $contacts = array();
            foreach (($_POST['c'] ?? array()) as $id => $row) {
                $cid = preg_replace('/[^a-z0-9]/', '', (string) $id);
                if ($cid === '') continue;
                $contacts[] = array(
                    'id'       => $cid,
                    'name'     => trim($row['name'] ?? ''),
                    'enabled'  => !empty($row['enabled']),
                    'default'  => !empty($row['default']),
                    'channels' => array(
                        'email'    => array('address' => trim($row['email'] ?? ''), 'on' => !empty($row['email_on'])),
                        'telegram' => array('chat_id' => trim($row['tg_chat'] ?? ''), 'username' => trim($row['tg_user'] ?? ''), 'on' => !empty($row['tg_on'])),
                        'discord'  => array('webhook' => trim($row['dc_webhook'] ?? ''), 'mention' => trim($row['dc_mention'] ?? ''), 'on' => !empty($row['dc_on'])),
                    ),
                );
            }
            $cfg['address_book'] = $contacts;
            $flash = config_save($cfg) ? array('ok', 'Recipients saved.') : array('err', 'Could not write config (/config not writable?).');

        } elseif ($action === 'ab_add') {
            $name  = trim($_POST['new_name'] ?? '');
            $email = trim($_POST['new_email'] ?? '');
            if ($name === '' && $email === '') {
                $flash = array('err', 'Enter a name or an email address.');
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash = array('err', 'Enter a valid email address.');
            } else {
                $cfg['address_book'][] = array(
                    'id'       => bin2hex(random_bytes(4)),
                    'name'     => $name,
                    'enabled'  => true,
                    'default'  => true,
                    'channels' => array(
                        'email'    => array('address' => $email, 'on' => $email !== ''),
                        'telegram' => array('chat_id' => '', 'username' => '', 'on' => false),
                        'discord'  => array('webhook' => '', 'mention' => '', 'on' => false),
                    ),
                );
                $flash = config_save($cfg) ? array('ok', 'Recipient added.') : array('err', 'Could not write config.');
            }

        } elseif ($action === 'ab_remove') {
            $id = $_POST['id'] ?? '';
            $cfg['address_book'] = array_values(array_filter(config_contacts($cfg), function ($c) use ($id) {
                return ($c['id'] ?? '') !== $id;
            }));
            $flash = config_save($cfg) ? array('ok', 'Contact removed.') : array('err', 'Could not write config.');
        }
    }
}

// ---- Load current values for rendering ----
$cfg   = config_load();
$chmap = array();
foreach (config_channels($cfg) as $c) { $chmap[$c['id'] ?? ''] = $c; }
$tg    = $chmap['telegram'] ?? array('enabled' => false, 'token' => '', 'chat_id' => '');
$dc    = $chmap['discord']  ?? array('enabled' => false, 'webhook_url' => '');
$smtp  = config_smtp($cfg);
$dcApp = config_discord($cfg);
$contacts = config_contacts($cfg);
$token = csrf_token();
$h = function ($s) { return htmlspecialchars((string) $s); };
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
<body data-csrf="<?php echo $h($token); ?>">
    <div class="wrap">
        <div class="topbar">
            <h1><i class="fas fa-sliders-h"></i> Scanner Admin</h1>
            <div class="right">
                <a class="btn ghost" href="/"><i class="fas fa-arrow-left"></i> Scanner</a>
                <a class="btn ghost" href="/admin/logout.php">Sign out</a>
            </div>
        </div>

        <?php if ($flash) { ?><div class="flash <?php echo $flash[0]; ?>"><?php echo $flash[1]; ?></div><?php } ?>

        <div class="tabs" role="tablist">
            <button class="tab" data-tab="notifications"><i class="fas fa-bell"></i> Notifications</button>
            <button class="tab" data-tab="smtp"><i class="fas fa-server"></i> SMTP</button>
            <button class="tab" data-tab="addressbook"><i class="fas fa-address-book"></i> Address Book</button>
        </div>

        <!-- ============ NOTIFICATIONS ============ -->
        <section class="tabpane" id="tab-notifications">
            <p class="desc">Sent to every <strong>enabled</strong> channel when a scan completes. Tests use the values in the fields — no need to save first.</p>
            <form method="post" action="/admin/">
                <input type="hidden" name="csrf" value="<?php echo $h($token); ?>">
                <input type="hidden" name="action" value="save_notifications">
                <input type="hidden" name="active_tab" value="notifications">

                <div class="card">
                    <div class="row" style="margin-bottom:.6rem">
                        <h2><span class="type-ico"><i class="fab fa-telegram-plane"></i></span> Telegram</h2>
                        <label class="switch"><input type="checkbox" name="tg_enabled" <?php echo !empty($tg['enabled']) ? 'checked' : ''; ?>><span class="track"></span> Enabled</label>
                    </div>
                    <div class="field"><label>Bot token</label><input type="text" name="tg_token" value="<?php echo $h($tg['token'] ?? ''); ?>" placeholder="bot123456:ABC-DEF..." autocomplete="off" spellcheck="false"></div>
                    <div class="field"><label>Chat ID</label><input type="text" name="tg_chat" value="<?php echo $h($tg['chat_id'] ?? ''); ?>" placeholder="123456789" autocomplete="off"></div>
                    <div class="test-row"><button type="button" class="btn test-btn" data-type="telegram"><i class="fas fa-paper-plane"></i> Send test</button><span class="test-result"></span></div>
                </div>

                <div class="card">
                    <div class="row" style="margin-bottom:.6rem">
                        <h2><span class="type-ico"><i class="fab fa-discord"></i></span> Discord</h2>
                        <label class="switch"><input type="checkbox" name="dc_enabled" <?php echo !empty($dc['enabled']) ? 'checked' : ''; ?>><span class="track"></span> Enabled</label>
                    </div>
                    <div class="field"><label>Webhook URL</label><input type="text" name="dc_webhook" value="<?php echo $h($dc['webhook_url'] ?? ''); ?>" placeholder="https://discord.com/api/webhooks/..." autocomplete="off" spellcheck="false"></div>
                    <div class="grid2">
                        <div class="field"><label>Username</label><input type="text" name="dc_username" value="<?php echo $h($dcApp['username']); ?>" placeholder="Brother Scanner" autocomplete="off"></div>
                        <div class="field"><label>Avatar URL</label><input type="text" name="dc_avatar" value="<?php echo $h($dcApp['avatar_url']); ?>" placeholder="https://…/brother.png" autocomplete="off" spellcheck="false"></div>
                    </div>
                    <p class="desc" style="margin:-.2rem 0 .6rem">Applies to all Discord webhook messages — alerts and scan delivery.</p>
                    <div class="test-row"><button type="button" class="btn test-btn" data-type="discord"><i class="fas fa-paper-plane"></i> Send test</button><span class="test-result"></span></div>
                </div>

                <div class="actions"><button class="btn primary" type="submit"><i class="fas fa-save"></i> Save notifications</button></div>
            </form>
        </section>

        <!-- ============ SMTP ============ -->
        <section class="tabpane" id="tab-smtp">
            <p class="desc">Outgoing mail server used to email scans to your Address Book. Use “Send test” to verify — no save required.</p>
            <form method="post" action="/admin/">
                <input type="hidden" name="csrf" value="<?php echo $h($token); ?>">
                <input type="hidden" name="action" value="save_smtp">
                <input type="hidden" name="active_tab" value="smtp">
                <div class="card">
                    <h2 style="margin-bottom:.9rem"><span class="type-ico"><i class="fas fa-server"></i></span> SMTP server</h2>
                    <div class="field"><label>Host</label><input type="text" name="smtp_host" value="<?php echo $h($smtp['host']); ?>" placeholder="smtp.gmail.com" autocomplete="off" spellcheck="false"></div>
                    <div class="grid2">
                        <div class="field"><label>Port</label><input type="text" name="smtp_port" value="<?php echo $h($smtp['port']); ?>" placeholder="587" autocomplete="off"></div>
                        <div class="field"><label>Security</label>
                            <select name="smtp_security">
                                <?php foreach (array('none' => 'None', 'starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS') as $k => $lbl) { ?>
                                    <option value="<?php echo $k; ?>" <?php echo $smtp['security'] === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="field"><label>Username</label><input type="text" name="smtp_user" value="<?php echo $h($smtp['username']); ?>" autocomplete="off" spellcheck="false"></div>
                    <div class="field"><label>Password</label><input type="password" name="smtp_pass" value="<?php echo $h($smtp['password']); ?>" autocomplete="new-password"></div>
                    <div class="field"><label>From address</label><input type="text" name="smtp_from" value="<?php echo $h($smtp['from']); ?>" placeholder="scanner@yourdomain.com" autocomplete="off" spellcheck="false"></div>
                </div>
                <div class="card">
                    <h2 style="margin-bottom:.7rem"><span class="type-ico"><i class="fas fa-vial"></i></span> Send a test email</h2>
                    <div class="field"><label>To</label><input type="text" name="email_to" id="email_to" placeholder="you@example.com" autocomplete="off"></div>
                    <div class="test-row"><button type="button" class="btn test-btn" data-type="email"><i class="fas fa-paper-plane"></i> Send test email</button><span class="test-result"></span></div>
                </div>
                <div class="actions"><button class="btn primary" type="submit"><i class="fas fa-save"></i> Save SMTP</button></div>
            </form>
        </section>

        <!-- ============ ADDRESS BOOK ============ -->
        <section class="tabpane" id="tab-addressbook">
            <p class="desc">People who receive a <strong>copy of the scan</strong>. Each <em>default</em> recipient gets every completed scan on the channels you enable below. (Telegram delivery uses the bot token from the Notifications tab.)</p>
            <form method="post" action="/admin/">
                <input type="hidden" name="csrf" value="<?php echo $h($token); ?>">
                <input type="hidden" name="action" value="save_addressbook">
                <input type="hidden" name="active_tab" value="addressbook">
                <input type="hidden" name="id" id="rm_id" value="">

                <?php if (empty($contacts)) { ?>
                    <div class="card"><p class="desc" style="margin:.2rem 0">No recipients yet — add one below.</p></div>
                <?php } foreach ($contacts as $c) {
                    $id = $c['id'] ?? '';
                    $ch = isset($c['channels']) && is_array($c['channels']) ? $c['channels'] : array();
                    $em = $ch['email'] ?? array(); $tg = $ch['telegram'] ?? array(); $dc = $ch['discord'] ?? array();
                    $n = function ($f) use ($id, $h) { return 'c[' . $h($id) . '][' . $f . ']'; };
                ?>
                    <div class="card contact-card">
                        <div class="row" style="margin-bottom:.7rem">
                            <input type="text" name="<?php echo $n('name'); ?>" value="<?php echo $h($c['name'] ?? ''); ?>" placeholder="Name" class="c-name-lg">
                            <button type="button" class="btn ghost c-remove" data-id="<?php echo $h($id); ?>" title="Remove recipient"><i class="fas fa-trash"></i></button>
                        </div>
                        <div class="flags">
                            <label class="switch"><input type="checkbox" name="<?php echo $n('enabled'); ?>" <?php echo !empty($c['enabled']) ? 'checked' : ''; ?>><span class="track"></span> Active</label>
                            <label class="switch"><input type="checkbox" name="<?php echo $n('default'); ?>" <?php echo !empty($c['default']) ? 'checked' : ''; ?>><span class="track"></span> Every scan</label>
                        </div>

                        <div class="chan-row">
                            <label class="switch tight"><input type="checkbox" name="<?php echo $n('email_on'); ?>" <?php echo !empty($em['on']) ? 'checked' : ''; ?>><span class="track"></span></label>
                            <span class="chan-ico"><i class="fas fa-envelope"></i></span>
                            <input type="text" name="<?php echo $n('email'); ?>" value="<?php echo $h($em['address'] ?? ''); ?>" placeholder="email@example.com" class="grow">
                        </div>
                        <div class="chan-row">
                            <label class="switch tight"><input type="checkbox" name="<?php echo $n('tg_on'); ?>" <?php echo !empty($tg['on']) ? 'checked' : ''; ?>><span class="track"></span></label>
                            <span class="chan-ico"><i class="fab fa-telegram-plane"></i></span>
                            <input type="text" name="<?php echo $n('tg_chat'); ?>" value="<?php echo $h($tg['chat_id'] ?? ''); ?>" placeholder="chat id" class="grow">
                            <input type="text" name="<?php echo $n('tg_user'); ?>" value="<?php echo $h($tg['username'] ?? ''); ?>" placeholder="@user (caption)" class="grow">
                        </div>
                        <div class="chan-row">
                            <label class="switch tight"><input type="checkbox" name="<?php echo $n('dc_on'); ?>" <?php echo !empty($dc['on']) ? 'checked' : ''; ?>><span class="track"></span></label>
                            <span class="chan-ico"><i class="fab fa-discord"></i></span>
                            <input type="text" name="<?php echo $n('dc_webhook'); ?>" value="<?php echo $h($dc['webhook'] ?? ''); ?>" placeholder="channel webhook URL" class="grow">
                            <input type="text" name="<?php echo $n('dc_mention'); ?>" value="<?php echo $h($dc['mention'] ?? ''); ?>" placeholder="user id (mention)" class="grow">
                        </div>
                    </div>
                <?php } ?>

                <div class="actions"><button class="btn primary" type="submit"><i class="fas fa-save"></i> Save recipients</button></div>
            </form>

            <form method="post" action="/admin/" class="card">
                <input type="hidden" name="csrf" value="<?php echo $h($token); ?>">
                <input type="hidden" name="action" value="ab_add">
                <input type="hidden" name="active_tab" value="addressbook">
                <h2 style="margin-bottom:.7rem"><i class="fas fa-user-plus"></i> Add recipient</h2>
                <div class="grid2">
                    <div class="field"><label>Name</label><input type="text" name="new_name" placeholder="Alex" autocomplete="off"></div>
                    <div class="field"><label>Email (optional)</label><input type="text" name="new_email" placeholder="alex@example.com" autocomplete="off"></div>
                </div>
                <div class="actions"><button class="btn" type="submit"><i class="fas fa-plus"></i> Add</button></div>
            </form>
        </section>
    </div>

    <script>
        (function () {
            var CSRF = document.body.getAttribute('data-csrf');
            var tabs = Array.prototype.slice.call(document.querySelectorAll('.tab'));
            var panes = Array.prototype.slice.call(document.querySelectorAll('.tabpane'));
            function show(name) {
                tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === name); });
                panes.forEach(function (p) { p.classList.toggle('active', p.id === 'tab-' + name); });
                try { location.hash = name; } catch (e) {}
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab')); }); });
            var initial = <?php echo json_encode($active); ?>;
            var hash = (location.hash || '').replace('#', '');
            show((hash && document.getElementById('tab-' + hash)) ? hash : initial);

            // Inline "Send test" — posts the current (unsaved) field values.
            var FIELDS = {
                telegram: { token: 'tg_token', chat_id: 'tg_chat' },
                discord:  { webhook_url: 'dc_webhook', username: 'dc_username', avatar_url: 'dc_avatar' },
                email:    { to: 'email_to', host: 'smtp_host', port: 'smtp_port', security: 'smtp_security', username: 'smtp_user', password: 'smtp_pass', from: 'smtp_from' }
            };
            function val(name) { var el = document.querySelector('[name="' + name + '"]'); return el ? el.value : ''; }
            // Remove a contact: set the shared form's action + id, then submit.
            document.querySelectorAll('.c-remove').forEach(function (b) {
                b.addEventListener('click', function () {
                    var form = b.closest('form');
                    form.elements['action'].value = 'ab_remove';
                    form.elements['id'].value = b.getAttribute('data-id');
                    form.submit();
                });
            });

            document.querySelectorAll('.test-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var type = btn.getAttribute('data-type');
                    var out = btn.parentNode.querySelector('.test-result');
                    var body = new URLSearchParams({ csrf: CSRF, type: type });
                    var map = FIELDS[type] || {};
                    Object.keys(map).forEach(function (param) { body.set(param, val(map[param])); });
                    out.className = 'test-result muted'; out.textContent = 'Sending…';
                    btn.disabled = true;
                    fetch('/admin/test.php', { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (d) { out.className = 'test-result ' + (d.ok ? 'good' : 'bad'); out.textContent = d.message; })
                        .catch(function () { out.className = 'test-result bad'; out.textContent = 'Request failed.'; })
                        .then(function () { btn.disabled = false; });
                });
            });
        })();
    </script>
</body>
</html>
