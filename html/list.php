<?php
// Returns an HTML fragment of recent scans, injected into the "Recent scans"
// bottom sheet by index.php. One <a class="file-row"> per PDF, newest first.

$directory = '/scans';
$files = @scandir($directory);
if ($files === false) {
    $files = array();
}
$files = array_diff($files, array('.', '..'));

$filesWithMtime = array();
foreach ($files as $file) {
    $filePath = $directory . '/' . $file;
    if (is_file($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf') {
        $filesWithMtime[$file] = array(
            'mtime' => filemtime($filePath),
            'size'  => filesize($filePath),
        );
    }
}

// Newest first
uasort($filesWithMtime, function ($a, $b) {
    return $b['mtime'] <=> $a['mtime'];
});

/** Human-readable byte size. */
function human_size($bytes)
{
    $units = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? $n : number_format($n, 1)) . ' ' . $units[$i];
}

if (empty($filesWithMtime)) {
    echo '<div class="empty">No scans yet</div>';
    return;
}

foreach ($filesWithMtime as $file => $attr) {
    $href = '/download.php?file=' . rawurlencode($file);
    $when = date('D j M · H:i', $attr['mtime']);
    $size = human_size($attr['size']);
    ?>
    <a class="file-row" href="<?php echo htmlspecialchars($href); ?>" target="_blank" rel="noopener">
        <i class="far fa-file-pdf f-ico"></i>
        <span class="f-main">
            <span class="f-name"><?php echo htmlspecialchars($file); ?></span>
            <span class="f-meta"><?php echo htmlspecialchars($when . '  ·  ' . $size); ?></span>
        </span>
        <i class="fas fa-download f-dl"></i>
    </a>
    <?php
}
