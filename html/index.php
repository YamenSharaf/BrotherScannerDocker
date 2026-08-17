<?php
include 'settings.php';
require_once __DIR__ . '/lib/config.php';

// Recipients the user can add for THIS scan (in addition to the "Every scan"
// defaults): Active, not-default, and with at least one delivery channel on.
// Only names + channel icons are exposed here — never the actual addresses,
// since the scan GUI is unauthenticated.
$pickable = array();
$hasDelivery = false; // any enabled recipient (default or not) that can receive files
foreach (config_contacts() as $c) {
    if (empty($c['enabled'])) {
        continue;
    }
    $cch = isset($c['channels']) && is_array($c['channels']) ? $c['channels'] : array();
    $chans = array();
    if (!empty($cch['email']['on']))    { $chans[] = 'fas fa-envelope'; }
    if (!empty($cch['telegram']['on'])) { $chans[] = 'fab fa-telegram-plane'; }
    if (!empty($cch['discord']['on']))  { $chans[] = 'fab fa-discord'; }
    if (empty($chans)) {
        continue;
    }
    $hasDelivery = true;
    if (!empty($c['default'])) {
        continue; // defaults always receive scans — not shown in the opt-in picker
    }
    $pickable[] = array(
        'id'    => $c['id'] ?? '',
        'name'  => trim($c['name'] ?? '') !== '' ? trim($c['name']) : 'Recipient',
        'chans' => $chans,
    );
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f1115" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f5f6f8" media="(prefers-color-scheme: light)">
    <title><?php echo htmlspecialchars($MODEL); ?> · Scanner</title>
    <link rel="icon" type="image/svg+xml" href="/assets/brother_logo.svg">
    <!-- iOS home-screen icon: SVG is ignored by iOS; drop a 180x180 PNG at
         /assets/brother_logo_180.png and it will be used automatically. -->
    <link rel="apple-touch-icon" href="/assets/brother_logo_180.png">
    <link rel="stylesheet" href="/assets/fontawesome.5.15.4/css/all.min.css">
    <style>
        /* ---- Theme tokens (light is the base; dark overrides) ---- */
        :root {
            --bg: #eef0f4;
            --bg-accent: #e2e6ee;
            --surface: #ffffff;
            --surface-2: #f4f5f8;
            --border: #e2e5ec;
            --text: #1b1e27;
            --text-dim: #6b7280;
            --primary: #2f6bff;
            --primary-strong: #1f52d6;
            --primary-contrast: #ffffff;
            --primary-soft: rgba(47, 107, 255, .12);
            --ok: #1aa06d;
            --warn: #d9820a;
            --busy: #2f6bff;
            --ring: rgba(47, 107, 255, .18);
            --shadow: 0 10px 30px rgba(20, 27, 45, .10), 0 2px 6px rgba(20, 27, 45, .06);
            --shadow-sm: 0 1px 2px rgba(20, 27, 45, .08);
        }

        :root[data-theme="dark"],
        html[data-theme="dark"] {
            color-scheme: dark;
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --bg: #0f1115;
                --bg-accent: #151922;
                --surface: #191d26;
                --surface-2: #20252f;
                --border: #2a303c;
                --text: #e7eaf0;
                --text-dim: #9aa2b1;
                --primary: #5b8bff;
                --primary-strong: #3f6fe6;
                --primary-contrast: #0b0e14;
                --primary-soft: rgba(91, 139, 255, .16);
                --ok: #34c48b;
                --warn: #eaa13a;
                --busy: #5b8bff;
                --ring: rgba(91, 139, 255, .22);
                --shadow: 0 14px 40px rgba(0, 0, 0, .45), 0 2px 8px rgba(0, 0, 0, .35);
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, .4);
            }
        }

        :root[data-theme="dark"] {
            --bg: #0f1115;
            --bg-accent: #151922;
            --surface: #191d26;
            --surface-2: #20252f;
            --border: #2a303c;
            --text: #e7eaf0;
            --text-dim: #9aa2b1;
            --primary: #5b8bff;
            --primary-strong: #3f6fe6;
            --primary-contrast: #0b0e14;
            --primary-soft: rgba(91, 139, 255, .16);
            --ok: #34c48b;
            --warn: #eaa13a;
            --busy: #5b8bff;
            --ring: rgba(91, 139, 255, .22);
            --shadow: 0 14px 40px rgba(0, 0, 0, .45), 0 2px 8px rgba(0, 0, 0, .35);
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, .4);
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: radial-gradient(120% 100% at 50% 0%, var(--bg-accent), var(--bg) 60%);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
        }

        /* ---- Top bar ---- */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            padding: max(.9rem, env(safe-area-inset-top)) 1rem .9rem;
            padding-left: max(1rem, env(safe-area-inset-left));
            padding-right: max(1rem, env(safe-area-inset-right));
        }

        .brand {
            display: flex;
            align-items: center;
            gap: .6rem;
            min-width: 0;
        }

        .brand .dot {
            width: 9px; height: 9px; border-radius: 50%;
            background: var(--text-dim);
            box-shadow: 0 0 0 4px var(--surface-2);
            flex: none;
            transition: background .3s;
        }
        .brand .dot.live { background: var(--ok); }

        .brand .name {
            font-weight: 650;
            font-size: 1rem;
            letter-spacing: .01em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .brand .sub { color: var(--text-dim); font-size: .78rem; margin-top: -1px; }

        .iconbtn {
            appearance: none;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            width: 42px; height: 42px;
            border-radius: 12px;
            display: grid; place-items: center;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: transform .12s, background .2s, border-color .2s;
            flex: none;
        }
        .iconbtn:active { transform: scale(.94); }

        /* ---- Main ---- */
        main {
            flex: 1;
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            padding: .5rem 1rem calc(1.5rem + env(safe-area-inset-bottom));
            padding-left: max(1rem, env(safe-area-inset-left));
            padding-right: max(1rem, env(safe-area-inset-right));
            display: flex;
            flex-direction: column;
        }

        /* ---- Status hero ---- */
        .hero {
            text-align: center;
            padding: 1.6rem 1rem 1.2rem;
        }
        .halo {
            --c: var(--text-dim);
            width: 132px; height: 132px;
            margin: 0 auto 1.2rem;
            border-radius: 50%;
            display: grid; place-items: center;
            color: var(--c);
            background: var(--surface);
            border: 1px solid var(--border);
            box-shadow: var(--shadow), 0 0 0 0 var(--ring);
            transition: color .3s, box-shadow .4s;
            position: relative;
        }
        .halo::after {
            content: "";
            position: absolute; inset: -6px;
            border-radius: 50%;
            border: 2px solid var(--c);
            opacity: .18;
            transition: opacity .3s;
        }
        .halo i { font-size: 3.2rem; }
        /* Idle state shows the Brother logo in the halo; active states show an
           animated status icon. The ring/halo styling is shared. */
        .halo-logo { display: none; width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .halo-icon { display: block; }
        .state-idle .halo-logo { display: block; }
        .state-idle .halo-icon { display: none; }
        .state-scan .halo { --c: var(--busy); box-shadow: var(--shadow), 0 0 0 8px var(--ring); }
        .state-ocr  .halo { --c: var(--busy); box-shadow: var(--shadow), 0 0 0 8px var(--ring); }
        .state-waiting .halo { --c: var(--warn); }
        .state-done .halo { --c: var(--ok); box-shadow: var(--shadow), 0 0 0 8px var(--ring); }
        .state-idle .halo { --c: var(--ok); }

        .state-title { font-size: 1.35rem; font-weight: 700; margin: 0; }
        .state-sub { color: var(--text-dim); font-size: .92rem; margin: .35rem 0 0; min-height: 1.2em; }

        /* ---- Feature chips ---- */
        .chips {
            display: flex; flex-wrap: wrap; gap: .4rem;
            justify-content: center;
            margin: 1rem 0 .2rem;
        }
        .chip {
            display: inline-flex; align-items: center; gap: .4rem;
            font-size: .76rem; font-weight: 600;
            color: var(--text-dim);
            background: var(--surface-2);
            border: 1px solid var(--border);
            padding: .32rem .6rem;
            border-radius: 999px;
        }
        .chip i { font-size: .72rem; color: var(--ok); }

        /* ---- Resolution selector ---- */
        .reso { margin-top: 1.3rem; }
        .reso-head {
            display: flex; align-items: center; justify-content: center; gap: .4rem;
            font-size: .78rem; font-weight: 600; letter-spacing: .02em;
            color: var(--text-dim); text-transform: uppercase;
            margin: 0 0 .6rem;
        }
        .reso-head i { color: var(--text-dim); font-size: .78rem; }
        .reso-unit { text-transform: none; letter-spacing: 0; opacity: .7; font-weight: 500; }
        .reso-opts { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; }
        .reso-pill {
            flex: 0 0 auto;
            appearance: none; cursor: pointer;
            min-width: 3.1rem;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-dim);
            border-radius: 999px;
            padding: .42rem .7rem;
            font-size: .85rem; font-weight: 650;
            box-shadow: var(--shadow-sm);
            transition: transform .12s, background .2s, border-color .2s, color .2s;
            -webkit-tap-highlight-color: transparent;
        }
        .reso-pill:hover { border-color: var(--primary); }
        .reso-pill:active { transform: scale(.94); }
        .reso-pill.active {
            background: var(--primary);
            border-color: transparent;
            color: var(--primary-contrast);
            box-shadow: var(--shadow);
        }

        /* ---- Per-scan recipient picker ---- */
        .recipients { margin-top: 1.3rem; }
        .recip-opts { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; }
        .recip {
            display: inline-flex; align-items: center; gap: .45rem;
            border: 1px solid var(--border); background: var(--surface); color: var(--text-dim);
            border-radius: 999px; padding: .42rem .8rem; font-size: .85rem; font-weight: 600;
            cursor: pointer; box-shadow: var(--shadow-sm);
            transition: transform .12s, background .2s, border-color .2s, color .2s;
            -webkit-tap-highlight-color: transparent;
        }
        .recip:active { transform: scale(.96); }
        .recip input { display: none; }
        .recip .recip-chans { display: inline-flex; gap: .25rem; opacity: .65; font-size: .72rem; }
        .recip.checked { background: var(--primary); border-color: transparent; color: var(--primary-contrast); box-shadow: var(--shadow); }
        .recip.checked .recip-chans { opacity: .9; }

        /* ---- "Deliver only" (skip local save) toggle ---- */
        .skip-save-row { display: flex; justify-content: center; }
        .skip-pill {
            display: inline-flex; align-items: center; gap: .45rem;
            border: 1px solid var(--border); background: var(--surface); color: var(--text-dim);
            border-radius: 999px; padding: .45rem .9rem; font-size: .84rem; font-weight: 600;
            cursor: pointer; box-shadow: var(--shadow-sm);
            transition: transform .12s, background .2s, border-color .2s, color .2s;
            -webkit-tap-highlight-color: transparent;
        }
        .skip-pill:active { transform: scale(.97); }
        .skip-pill input { display: none; }
        .skip-pill.on { background: var(--warn); border-color: transparent; color: #fff; box-shadow: var(--shadow); }

        /* ---- Duplex / single-sided toggle ---- */
        .duplex-row { display: flex; justify-content: center; margin-top: 1.3rem; }
        .dup-pill {
            display: inline-flex; align-items: center; gap: .45rem;
            border: 1px solid var(--border); background: var(--surface); color: var(--text-dim);
            border-radius: 999px; padding: .45rem .9rem; font-size: .84rem; font-weight: 600;
            cursor: pointer; box-shadow: var(--shadow-sm);
            transition: transform .12s, background .2s, border-color .2s, color .2s;
            -webkit-tap-highlight-color: transparent;
        }
        .dup-pill:active { transform: scale(.97); }
        .dup-pill input { display: none; }
        .dup-pill.on { background: var(--primary); border-color: transparent; color: var(--primary-contrast); box-shadow: var(--shadow); }

        /* ---- Action buttons ---- */
        .actions {
            display: flex; flex-direction: column; gap: .7rem;
            margin-top: 1.4rem;
        }
        .btn {
            appearance: none;
            display: flex; align-items: center; gap: .85rem;
            width: 100%;
            text-align: left;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            border-radius: 16px;
            padding: 1rem 1.1rem;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: transform .12s, box-shadow .2s, border-color .2s, background .2s, opacity .2s;
            -webkit-tap-highlight-color: transparent;
        }
        .btn .b-ico {
            width: 44px; height: 44px; flex: none;
            border-radius: 12px;
            display: grid; place-items: center;
            background: var(--surface-2);
            color: var(--text-dim);
            font-size: 1.1rem;
            transition: background .2s, color .2s;
        }
        .btn .b-text { min-width: 0; }
        .btn .b-label { font-weight: 650; display: block; }
        .btn .b-hint { color: var(--text-dim); font-size: .8rem; margin-top: .1rem; }
        .btn .b-go { margin-left: auto; color: var(--text-dim); flex: none; opacity: .6; }
        .btn:active { transform: scale(.985); }
        .btn:hover { border-color: var(--primary); }

        /* Primary (blue) treatment — the front button by default, and the rear
           button while waiting for backs (see below). */
        .btn.primary,
        .state-waiting .btn.rear {
            background: linear-gradient(180deg, var(--primary), var(--primary-strong));
            border-color: transparent;
            color: var(--primary-contrast);
            box-shadow: var(--shadow);
        }
        .btn.primary .b-ico,
        .state-waiting .btn.rear .b-ico { background: rgba(255, 255, 255, .18); color: var(--primary-contrast); }
        .btn.primary .b-hint, .btn.primary .b-go,
        .state-waiting .btn.rear .b-hint, .state-waiting .btn.rear .b-go { color: rgba(255, 255, 255, .8); opacity: 1; }
        .btn.primary:hover,
        .state-waiting .btn.rear:hover { border-color: transparent; }

        /* While waiting for rear pages, the rear button becomes the primary
           action, so the front button steps back to the neutral style. */
        .state-waiting .btn.primary {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text);
            box-shadow: var(--shadow-sm);
        }
        .state-waiting .btn.primary .b-ico { background: var(--surface-2); color: var(--text-dim); }
        .state-waiting .btn.primary .b-hint { color: var(--text-dim); opacity: 1; }
        .state-waiting .btn.primary .b-go { color: var(--text-dim); opacity: .6; }

        .btn.busy {
            opacity: .55;
            pointer-events: none;
        }

        /* ---- Recent scans sheet ---- */
        .scrim {
            position: fixed; inset: 0;
            background: rgba(8, 10, 16, .5);
            opacity: 0; pointer-events: none;
            transition: opacity .25s;
            z-index: 40;
        }
        .scrim.open { opacity: 1; pointer-events: auto; }

        .sheet {
            position: fixed; left: 0; right: 0; bottom: 0;
            z-index: 50;
            background: var(--surface);
            border-top-left-radius: 22px;
            border-top-right-radius: 22px;
            box-shadow: 0 -12px 40px rgba(0, 0, 0, .3);
            transform: translateY(100%);
            transition: transform .3s cubic-bezier(.22, .61, .36, 1);
            max-height: 82dvh;
            display: flex; flex-direction: column;
            padding-bottom: env(safe-area-inset-bottom);
        }
        .sheet.open { transform: translateY(0); }
        .sheet-grip { width: 40px; height: 4px; border-radius: 2px; background: var(--border); margin: .7rem auto .2rem; }
        .sheet-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: .3rem 1.1rem .6rem;
            border-bottom: 1px solid var(--border);
        }
        .sheet-head h2 { font-size: 1.05rem; margin: 0; }
        .sheet-body { overflow-y: auto; -webkit-overflow-scrolling: touch; padding: .4rem 0 1rem; }

        .empty { text-align: center; color: var(--text-dim); padding: 2.5rem 1rem; }

        /* File list rows (list.php markup) */
        .file-row {
            display: flex; align-items: center; gap: .8rem;
            padding: .8rem 1.1rem;
            color: inherit; text-decoration: none;
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }
        .file-row:active { background: var(--surface-2); }
        .file-row .f-ico { color: var(--primary); font-size: 1.3rem; flex: none; }
        .file-row .f-main { min-width: 0; flex: 1; }
        .file-row .f-name { font-weight: 600; font-size: .92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-row .f-meta { color: var(--text-dim); font-size: .78rem; margin-top: .1rem; }
        .file-row .f-dl { color: var(--text-dim); flex: none; }

        .toast {
            position: fixed; left: 50%; bottom: calc(1.2rem + env(safe-area-inset-bottom));
            transform: translate(-50%, 1.5rem);
            background: var(--text); color: var(--bg);
            padding: .7rem 1.1rem; border-radius: 999px;
            font-size: .85rem; font-weight: 600;
            box-shadow: var(--shadow);
            opacity: 0; pointer-events: none;
            transition: opacity .25s, transform .25s;
            z-index: 60; max-width: 90vw;
        }
        .toast.show { opacity: 1; transform: translate(-50%, 0); }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }
    </style>
</head>

<body class="state-idle">

    <header class="topbar">
        <div class="brand">
            <span class="dot" id="liveDot" title="connection"></span>
            <div style="min-width:0">
                <div class="name"><?php echo htmlspecialchars($MODEL); ?></div>
                <div class="sub">Network scanner</div>
            </div>
        </div>
        <div style="display:flex; gap:.5rem">
            <?php if (env_str('ADMIN_PASSWORD') !== '' || env_str('ADMIN_PASSWORD_HASH') !== '') { ?>
            <a class="iconbtn" href="/admin/" title="Admin" aria-label="Admin">
                <i class="fas fa-sliders-h"></i>
            </a>
            <?php } ?>
            <button class="iconbtn" id="btnFiles" title="Recent scans" aria-label="Recent scans">
                <i class="far fa-folder-open"></i>
            </button>
            <button class="iconbtn" id="btnTheme" title="Theme" aria-label="Toggle theme">
                <i class="fas fa-adjust"></i>
            </button>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="halo" id="halo">
                <img src="/assets/brother_logo.svg" alt="" class="halo-logo">
                <i class="halo-icon far fa-smile" id="haloIcon"></i>
            </div>
            <h1 class="state-title" id="stateTitle">Ready to scan</h1>
            <p class="state-sub" id="stateSub">Pick an action below</p>
        </section>

        <?php
        $activeFeatures = array();
        if ($FEATURES['ocr'])           { $activeFeatures[] = array('fa-brain', 'OCR'); }
        if ($FEATURES['blank_removal']) { $activeFeatures[] = array('fa-eraser', 'Blank-page removal'); }
        if ($FEATURES['ftp'])           { $activeFeatures[] = array('fa-upload', 'FTP upload'); }
        if ($FEATURES['telegram'])      { $activeFeatures[] = array('fa-paper-plane', 'Telegram'); }
        if ($FEATURES['jpeg'])          { $activeFeatures[] = array('fa-file-image', 'JPEG compression'); }
        ?>
        <div class="chips">
            <?php foreach ($activeFeatures as $f) { ?>
                <span class="chip"><i class="fas <?php echo $f[0]; ?>"></i><?php echo htmlspecialchars($f[1]); ?></span>
            <?php } ?>
        </div>

        <div class="reso">
            <div class="reso-head"><i class="fas fa-tachometer-alt"></i> Resolution <span class="reso-unit">dpi</span></div>
            <div class="reso-opts" id="resoOpts" data-default="<?php echo htmlspecialchars($RESOLUTION); ?>">
                <?php foreach ($RESOLUTIONS as $r) { ?>
                    <button type="button" class="reso-pill" data-dpi="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></button>
                <?php } ?>
            </div>
        </div>

        <div class="reso">
            <div class="reso-head"><i class="fas fa-palette"></i> Color mode</div>
            <div class="reso-opts" id="modeOpts" data-default="<?php echo htmlspecialchars($MODE); ?>">
                <?php foreach ($MODES as $m) { ?>
                    <button type="button" class="reso-pill" data-mode="<?php echo htmlspecialchars($m); ?>"><?php echo htmlspecialchars($MODE_LABELS[$m] ?? $m); ?></button>
                <?php } ?>
            </div>
        </div>

        <div class="reso duplex-row">
            <label class="dup-pill on" id="duplexPill">
                <input type="checkbox" id="duplex" checked>
                <i class="fas fa-copy"></i> <span class="dup-text">Double-sided</span>
            </label>
        </div>

        <?php if (!empty($pickable)) { ?>
        <div class="reso recipients">
            <div class="reso-head"><i class="fas fa-paper-plane"></i> Send a copy to</div>
            <div class="recip-opts" id="recipOpts">
                <?php foreach ($pickable as $r) { ?>
                    <label class="recip">
                        <input type="checkbox" value="<?php echo htmlspecialchars($r['id']); ?>">
                        <span class="recip-name"><?php echo htmlspecialchars($r['name']); ?></span>
                        <span class="recip-chans"><?php foreach ($r['chans'] as $ic) { ?><i class="<?php echo $ic; ?>"></i><?php } ?></span>
                    </label>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <?php if ($hasDelivery) { ?>
        <div class="recipients skip-save-row">
            <label class="skip-pill" id="skipSavePill">
                <input type="checkbox" id="skipSave">
                <i class="fas fa-user-secret"></i> Deliver only — don't keep a local copy
            </label>
        </div>
        <?php } ?>

        <div class="actions">
            <?php foreach ($BUTTONS as $key => $b) {
                if (!$b['enabled']) { continue; }
                $cls = 'btn';
                if ($b['primary']) { $cls .= ' primary'; }
                if ($key === 'email') { $cls .= ' rear'; }
            ?>
                <button class="<?php echo $cls; ?> trigger-scan" data-trigger="<?php echo $key; ?>">
                    <span class="b-ico"><i class="<?php echo $b['icon']; ?>"></i></span>
                    <span class="b-text">
                        <span class="b-label"><?php echo htmlspecialchars($b['label']); ?></span>
                        <?php if (!empty($b['hint'])) { ?>
                            <span class="b-hint"><?php echo htmlspecialchars($b['hint']); ?></span>
                        <?php } ?>
                    </span>
                    <i class="fas fa-chevron-right b-go"></i>
                </button>
            <?php } ?>
        </div>
    </main>

    <!-- Recent scans bottom sheet -->
    <div class="scrim" id="scrim"></div>
    <div class="sheet" id="sheet" role="dialog" aria-modal="true" aria-label="Recent scans">
        <div class="sheet-grip"></div>
        <div class="sheet-head">
            <h2>Recent scans</h2>
            <button class="iconbtn" id="btnClose" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="sheet-body" id="sheetBody">
            <div class="empty">Loading…</div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        (function () {
            "use strict";

            /* ---------- Theme: auto (default) / light / dark ---------- */
            var order = ["auto", "light", "dark"];
            var icons = { auto: "fa-adjust", light: "fa-sun", dark: "fa-moon" };
            var root = document.documentElement;
            var themeIcon = document.querySelector("#btnTheme i");

            function applyTheme(mode) {
                if (mode === "auto") root.removeAttribute("data-theme");
                else root.setAttribute("data-theme", mode);
                themeIcon.className = "fas " + icons[mode];
            }
            var saved = localStorage.getItem("scanner-theme") || "auto";
            applyTheme(saved);
            document.getElementById("btnTheme").addEventListener("click", function () {
                saved = order[(order.indexOf(saved) + 1) % order.length];
                localStorage.setItem("scanner-theme", saved);
                applyTheme(saved);
                toast("Theme: " + saved);
            });

            /* ---------- Toast ---------- */
            var toastEl = document.getElementById("toast");
            var toastTimer;
            function toast(msg) {
                toastEl.textContent = msg;
                toastEl.classList.add("show");
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function () { toastEl.classList.remove("show"); }, 2200);
            }

            /* ---------- Resolution selector ---------- */
            var resoOpts = document.getElementById("resoOpts");
            var pills = resoOpts ? Array.prototype.slice.call(resoOpts.querySelectorAll(".reso-pill")) : [];
            var available = pills.map(function (b) { return b.getAttribute("data-dpi"); });
            var defaultReso = resoOpts ? resoOpts.getAttribute("data-default") : "300";
            var selectedReso = localStorage.getItem("scanner-resolution");
            if (available.indexOf(selectedReso) === -1) {
                selectedReso = available.indexOf(defaultReso) !== -1 ? defaultReso : (available[0] || "300");
            }
            function markReso() {
                pills.forEach(function (b) { b.classList.toggle("active", b.getAttribute("data-dpi") === selectedReso); });
            }
            markReso();
            if (resoOpts) {
                resoOpts.addEventListener("click", function (e) {
                    var b = e.target.closest(".reso-pill");
                    if (!b) return;
                    selectedReso = b.getAttribute("data-dpi");
                    localStorage.setItem("scanner-resolution", selectedReso);
                    markReso();
                });
            }

            /* ---------- Colour mode selector ---------- */
            var modeOpts = document.getElementById("modeOpts");
            var modePills = modeOpts ? Array.prototype.slice.call(modeOpts.querySelectorAll(".reso-pill")) : [];
            var availableModes = modePills.map(function (b) { return b.getAttribute("data-mode"); });
            var defaultMode = modeOpts ? modeOpts.getAttribute("data-default") : "";
            var selectedMode = localStorage.getItem("scanner-mode");
            if (availableModes.indexOf(selectedMode) === -1) {
                selectedMode = availableModes.indexOf(defaultMode) !== -1 ? defaultMode : (availableModes[0] || "");
            }
            function markMode() {
                modePills.forEach(function (b) { b.classList.toggle("active", b.getAttribute("data-mode") === selectedMode); });
            }
            markMode();
            if (modeOpts) {
                modeOpts.addEventListener("click", function (e) {
                    var b = e.target.closest(".reso-pill");
                    if (!b) return;
                    selectedMode = b.getAttribute("data-mode");
                    localStorage.setItem("scanner-mode", selectedMode);
                    markMode();
                });
            }

            /* ---------- Per-scan recipient picker ---------- */
            document.querySelectorAll("#recipOpts input").forEach(function (cb) {
                cb.addEventListener("change", function () {
                    cb.closest(".recip").classList.toggle("checked", cb.checked);
                });
            });
            function selectedRecipients() {
                return Array.prototype.slice
                    .call(document.querySelectorAll("#recipOpts input:checked"))
                    .map(function (cb) { return cb.value; })
                    .join(",");
            }

            var skipSave = document.getElementById("skipSave");
            if (skipSave) {
                skipSave.addEventListener("change", function () {
                    document.getElementById("skipSavePill").classList.toggle("on", skipSave.checked);
                });
            }

            /* ---------- Duplex / single-sided toggle ---------- */
            var duplex = document.getElementById("duplex");
            var dupPill = document.getElementById("duplexPill");
            var rearBtn = document.querySelector('.trigger-scan[data-trigger="email"]');
            var frontBtn = document.querySelector('.trigger-scan[data-trigger="file"]');
            var frontLabel = frontBtn ? frontBtn.querySelector(".b-label") : null;
            var frontHint = frontBtn ? frontBtn.querySelector(".b-hint") : null;
            var frontLabelDuplex = frontLabel ? frontLabel.textContent : "";
            var frontHintDuplex = frontHint ? frontHint.textContent : "";
            function applyDuplex(on) {
                if (dupPill) {
                    dupPill.classList.toggle("on", on);
                    var t = dupPill.querySelector(".dup-text"); if (t) { t.textContent = on ? "Double-sided" : "Single-sided"; }
                    var i = dupPill.querySelector("i"); if (i) { i.className = on ? "fas fa-copy" : "fas fa-file"; }
                }
                if (rearBtn) { rearBtn.style.display = on ? "" : "none"; }
                if (frontLabel) { frontLabel.textContent = on ? frontLabelDuplex : "Scan document"; }
                if (frontHint) { frontHint.textContent = on ? frontHintDuplex : "Single-sided — processed right away"; }
            }
            if (duplex) {
                var savedDup = localStorage.getItem("scanner-duplex");
                duplex.checked = (savedDup === null) ? true : (savedDup === "1");
                applyDuplex(duplex.checked);
                duplex.addEventListener("change", function () {
                    localStorage.setItem("scanner-duplex", duplex.checked ? "1" : "0");
                    applyDuplex(duplex.checked);
                });
            }

            /* ---------- Status polling ---------- */
            var STATES = {
                idle:           { cls: "state-idle",    icon: "far fa-smile",           title: "Ready to scan",          sub: "Pick an action below" },
                scanning_front: { cls: "state-scan",    icon: "fas fa-spinner fa-spin", title: "Scanning front pages…",  sub: "Feeding pages through the scanner" },
                waiting:        { cls: "state-waiting", icon: "fas fa-hourglass-half",  title: "Waiting for rear pages", sub: "Add the backs, or wait to finalize the document" },
                scanning_rear:  { cls: "state-scan",    icon: "fas fa-spinner fa-spin", title: "Scanning rear pages…",   sub: "Feeding pages through the scanner" },
                processing:     { cls: "state-ocr",     icon: "fas fa-cog fa-spin",     title: "Processing…",            sub: "Assembling and cleaning up the PDF" },
                delivering:     { cls: "state-ocr",     icon: "fas fa-paper-plane",     title: "Delivering…",            sub: "Sending copies to recipients" },
                ocr:            { cls: "state-ocr",     icon: "fas fa-brain",           title: "Running OCR…",           sub: "Recognizing text on the server" },
                done:           { cls: "state-done",    icon: "fas fa-check-circle",    title: "Saved",                  sub: "Your document is ready" },
                sent:           { cls: "state-done",    icon: "fas fa-paper-plane",     title: "Sent",                   sub: "Delivered to recipients — not saved locally" }
            };
            var BUSY_STATES = { scanning_front: 1, scanning_rear: 1, processing: 1, delivering: 1 };
            var haloIcon = document.getElementById("haloIcon");
            var stateTitle = document.getElementById("stateTitle");
            var stateSub = document.getElementById("stateSub");
            var liveDot = document.getElementById("liveDot");
            var current = "idle";

            function setState(name) {
                var s = STATES[name] || STATES.idle;
                if (name !== current) {
                    current = name;
                    document.body.className = s.cls;
                    haloIcon.className = "halo-icon " + s.icon;
                    stateTitle.textContent = s.title;
                    stateSub.textContent = s.sub;
                }
                // Lock the buttons while actively scanning or processing;
                // otherwise (waiting / ocr / done / idle) they stay live.
                document.querySelectorAll(".trigger-scan").forEach(function (b) {
                    b.classList.toggle("busy", !!BUSY_STATES[name]);
                });
            }

            function poll() {
                fetch("/active.php", { cache: "no-store" })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        liveDot.classList.add("live");
                        setState(d.state || "idle");
                    })
                    .catch(function () { liveDot.classList.remove("live"); });
            }
            poll();
            setInterval(poll, 1200);

            /* ---------- Trigger a scan ---------- */
            document.querySelectorAll(".trigger-scan").forEach(function (btn) {
                btn.addEventListener("click", function () {
                    if (btn.classList.contains("busy")) return;
                    var target = btn.getAttribute("data-trigger");
                    var label = btn.querySelector(".b-label").textContent;
                    var body = new URLSearchParams({
                        target: target,
                        resolution: selectedReso,
                        mode: selectedMode,
                        recipients: selectedRecipients(),
                        skip_save: (skipSave && skipSave.checked) ? "1" : "",
                        simplex: (duplex && !duplex.checked) ? "1" : ""
                    });
                    fetch("/scan.php", { method: "POST", body: body })
                        .then(function () { toast(label + " started"); })
                        .catch(function () { toast("Could not reach scanner"); });
                    setState(target === "email" ? "scanning_rear" : "scanning_front");
                });
            });

            /* ---------- Recent scans sheet ---------- */
            var scrim = document.getElementById("scrim");
            var sheet = document.getElementById("sheet");
            var sheetBody = document.getElementById("sheetBody");

            function openSheet() {
                scrim.classList.add("open");
                sheet.classList.add("open");
                sheetBody.innerHTML = '<div class="empty">Loading…</div>';
                fetch("/list.php", { cache: "no-store" })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        sheetBody.innerHTML = html.trim() ? html : '<div class="empty">No scans yet</div>';
                    })
                    .catch(function () { sheetBody.innerHTML = '<div class="empty">Could not load files</div>'; });
            }
            function closeSheet() {
                scrim.classList.remove("open");
                sheet.classList.remove("open");
            }
            document.getElementById("btnFiles").addEventListener("click", openSheet);
            document.getElementById("btnClose").addEventListener("click", closeSheet);
            scrim.addEventListener("click", closeSheet);
        })();
    </script>
</body>

</html>
