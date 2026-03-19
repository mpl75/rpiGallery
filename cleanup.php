#!/usr/bin/env php
<?php
/**
 * Gallery Cleanup - applies virtual changes to the real filesystem:
 *   1. Deletes photos hidden for more than 30 days (source + thumbnail + fullsize)
 *   2. Renames folders on disk to match _displayName from data.json
 *
 * Run manually: php cleanup.php          (dry run - only shows what would happen)
 *               php cleanup.php --apply  (actually deletes and renames)
 * After running with --apply, trigger a crawler update so it refreshes data.json.
 */

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$rootGallery    = rtrim($config['rootGallery'], '/');
$thumbsFolder   = rtrim($config['thumbnailsFolder'], '/');
$fullsizeFolder = rtrim($config['fullsizeFolder'], '/');

$dryRun = !in_array('--apply', $argv);
if ($dryRun) echo "=== DRY RUN (nic se nesmazne ani neprejmenuje, pouzij --apply pro ostry beh) ===\n\n";

$deletedCount = 0;
$renamedCount = 0;
$threshold = date('Y-m-d', strtotime('-30 days'));

// Recursively scan thumbnails folder for data.json files
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($thumbsFolder, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $file) {
    if ($file->getFilename() !== 'data.json') continue;

    $djPath = $file->getPathname();
    $relDir = substr(dirname($djPath), strlen($thumbsFolder) + 1);
    $srcDir = $rootGallery . '/' . $relDir;
    $thumbDir = dirname($djPath);
    $fsDir = $fullsizeFolder . '/' . $relDir;

    $data = json_decode(file_get_contents($djPath), true) ?: [];

    // 1. Delete photos hidden for > 30 days
    foreach ($data as $filename => $entry) {
        if ($filename[0] === '_') continue; // skip meta keys
        $hiddenDate = $entry['hidden'] ?? null;
        if (!$hiddenDate || $hiddenDate > $threshold) continue;

        $srcFile = $srcDir . '/' . $filename;
        $mapped = $entry['mappedName'] ?? $filename;
        $thumbFile = $thumbDir . '/' . $mapped;
        $fsFile = $fsDir . '/' . $mapped;

        echo "SMAZAT: $relDir/$filename (skryto $hiddenDate)\n";
        if (!$dryRun) {
            if (file_exists($srcFile)) unlink($srcFile);
            if (file_exists($thumbFile)) unlink($thumbFile);
            if (file_exists($fsFile)) unlink($fsFile);
        }
        $deletedCount++;
    }

    // 2. Rename folder if _displayName differs from actual folder name
    $displayName = $data['_displayName'] ?? null;
    if (!$displayName) continue;

    $currentName = basename($srcDir);
    $parentDir = dirname($srcDir);
    $newSrcDir = $parentDir . '/' . $displayName;
    $newThumbDir = dirname($thumbDir) . '/' . $displayName;
    $newFsDir = dirname($fsDir) . '/' . $displayName;

    $srcNeedsRename = ($currentName !== $displayName) && is_dir($srcDir);
    $thumbNeedsRename = is_dir($thumbDir) && $thumbDir !== $newThumbDir && !is_dir($newThumbDir);
    $fsNeedsRename = is_dir($fsDir) && $fsDir !== $newFsDir && !is_dir($newFsDir);

    if (!$srcNeedsRename && !$thumbNeedsRename && !$fsNeedsRename) continue;

    if ($srcNeedsRename && is_dir($newSrcDir)) {
        echo "VAROVANI: Nelze prejmenovat '$relDir' -> '$displayName' (cilova slozka uz existuje)\n";
        continue;
    }

    $parts = [];
    if ($srcNeedsRename) $parts[] = 'zdroj';
    if ($thumbNeedsRename) $parts[] = 'nahledy';
    if ($fsNeedsRename) $parts[] = 'fullsize';
    echo "PREJMENOVAT (" . implode(', ', $parts) . "): $relDir -> $displayName\n";

    if (!$dryRun) {
        if ($srcNeedsRename) rename($srcDir, $newSrcDir);
        if ($thumbNeedsRename) rename($thumbDir, $newThumbDir);
        if ($fsNeedsRename) rename($fsDir, $newFsDir);
        // Remove _displayName from data.json -- no longer needed after rename
        $newDjPath = ($thumbNeedsRename ? $newThumbDir : $thumbDir) . '/data.json';
        unset($data['_displayName']);
        file_put_contents($newDjPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    $renamedCount++;
}

echo "\n";
echo "Smazano fotek: $deletedCount\n";
echo "Prejmenovano slozek: $renamedCount\n";
if ($dryRun) echo "\n(Dry run - nic se nezmenilo. Spust s --apply pro ostry beh.)\n";
if (!$dryRun && $deletedCount) {
    echo "\nSpust crawler pro aktualizaci data.json.\n";
}
