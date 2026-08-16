<?php
require_once __DIR__ . '/../lib/auth.php';

$error = '';

if (!admin_enabled()) {
    // No password configured — the dashboard is effectively disabled.
    http_response_code(503);
    $error = 'not_configured';
} elseif (is_admin()) {
    header('Location: /admin/');
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired, please try again.';
    } elseif (admin_login($_POST['password'] ?? '')) {
        header('Location: /admin/');
        exit;
    } else {
        $error = 'Incorrect password.';
    }
}
$token = admin_enabled() ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f1115" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f5f6f8" media="(prefers-color-scheme: light)">
    <title>Admin · Sign in</title>
    <link rel="icon" type="image/svg+xml" href="/assets/brother_logo.svg">
    <link rel="stylesheet" href="/assets/fontawesome.5.15.4/css/all.min.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <div class="wrap login">
        <img class="logo" src="/assets/brother_logo.svg" alt="">
        <h1>Scanner Admin</h1>
        <?php if ($error === 'not_configured') { ?>
            <div class="card">
                <div class="flash err">Admin dashboard is not configured.</div>
                <p class="desc">Set an <code>ADMIN_PASSWORD</code> (or <code>ADMIN_PASSWORD_HASH</code>)
                environment variable and restart the container to enable it.</p>
            </div>
        <?php } else { ?>
            <div class="card">
                <?php if ($error) { ?><div class="flash err"><?php echo htmlspecialchars($error); ?></div><?php } ?>
                <form method="post" action="/admin/login.php">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($token); ?>">
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" autofocus autocomplete="current-password">
                    </div>
                    <div class="actions">
                        <button class="btn primary" type="submit" style="width:100%">Sign in</button>
                    </div>
                </form>
            </div>
            <p class="desc" style="text-align:center"><a href="/">← Back to scanner</a></p>
        <?php } ?>
    </div>
</body>
</html>
