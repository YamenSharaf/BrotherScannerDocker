<?php
// Reports the current scanner phase to the GUI. The scan scripts write a small
// state file at each phase (scanning_front -> waiting -> scanning_rear ->
// processing -> ocr -> done); we just read it here.
//
// This replaces the old "guess from process names" approach, which was wrong in
// two ways: killing the front-scan subshell orphans its `sleep`, so `pgrep
// sleep` stayed true and the UI was stuck on "waiting"; and the conversion
// phase (gm/gs/pdftk) matched none of scanimage/sleep/curl, so there was no
// "processing" state and no clear completion.

$statefile = '/tmp/scanner.state';
$state = 'idle';

if (is_readable($statefile)) {
    $s = trim((string) @file_get_contents($statefile));
    $age = time() - (int) @filemtime($statefile);
    if ($s !== '') {
        if ($s === 'done' || $s === 'sent') {
            // Brief terminal confirmation ("Saved"/"Sent"), then fall back to idle.
            $state = ($age <= 8) ? $s : 'idle';
        } elseif ($age > 1200) {
            // Safety net: a job that never wrote a terminal state (e.g. crashed)
            // shouldn't leave the UI stuck. After 20 min, treat as idle.
            $state = 'idle';
        } else {
            $state = $s;
        }
    }
}

// `state` is authoritative; the booleans are kept for backwards compatibility
// with any external API consumers of this endpoint.
$result = array(
    'state'   => $state,
    'scan'    => ($state === 'scanning_front' || $state === 'scanning_rear'),
    'waiting' => ($state === 'waiting'),
    'ocr'     => ($state === 'ocr'),
);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
