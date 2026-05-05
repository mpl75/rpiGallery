#!/usr/bin/env php
<?php
/**
 * Restore photos from Azure Backup.
 *
 * Usage:
 *   php restore.php list [PREFIX]                - list backed-up files
 *   php restore.php rehydrate [PREFIX]           - request rehydration (Archive -> Cool)
 *   php restore.php status [PREFIX]              - check rehydration progress
 *   php restore.php download TARGET_DIR [PREFIX] - download rehydrated files
 *   php restore.php archive [PREFIX]             - move rehydrated blobs back to Archive
 *
 * Full restore workflow:
 *   1. rehydrate           (returns immediately; Azure rehydrates in up to 15 hours)
 *   2. status              (poll periodically until all rehydrated)
 *   3. download TARGET_DIR (saves files with original directory structure under TARGET_DIR)
 */

require_once __DIR__ . '/classStorage/classStorage.php';

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

if (empty($config['azureBackup']['connectionString'])) {
    fwrite(STDERR, "ERROR: azureBackup.connectionString not configured in config.json\n");
    exit(1);
}

$storage = new Storage($config['azureBackup']['connectionString'], true);
$container = $config['azureBackup']['container'] ?? 'archiv';

$cmd = $argv[1] ?? '';
$arg2 = $argv[2] ?? '';
$arg3 = $argv[3] ?? '';

switch ($cmd) {
    case 'list':
        cmdList($storage, $container, $arg2);
        break;
    case 'rehydrate':
        cmdRehydrate($storage, $container, $arg2);
        break;
    case 'status':
        cmdStatus($storage, $container, $arg2);
        break;
    case 'download':
        if (!$arg2) {
            fwrite(STDERR, "ERROR: download requires TARGET_DIR\n");
            exit(1);
        }
        cmdDownload($storage, $container, $arg2, $arg3);
        break;
    case 'archive':
        cmdArchive($storage, $container, $arg2);
        break;
    default:
        echo "Restore from Azure Backup\n\n";
        echo "Usage:\n";
        echo "  php restore.php list [PREFIX]                - list backed-up files\n";
        echo "  php restore.php rehydrate [PREFIX]           - request rehydration\n";
        echo "  php restore.php status [PREFIX]              - check rehydration progress\n";
        echo "  php restore.php download TARGET_DIR [PREFIX] - download rehydrated files\n";
        echo "  php restore.php archive [PREFIX]             - move rehydrated blobs back to Archive\n\n";
        echo "Workflow: rehydrate -> wait (up to 15h) -> status -> download\n";
        exit($cmd === '' ? 0 : 1);
}

// Encode each path segment for Azure REST URL (spaces, diacritics).
function encodeBlobPath($path) {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function cmdList($s, $c, $prefix) {
    $blobs = $s->getListOfBlobsByPrefix($c, $prefix);
    foreach ($blobs as $b) {
        $name = $b['Name'];
        $size = $b['Properties']['Content-Length'] ?? '?';
        $tier = $b['Properties']['AccessTier'] ?? '?';
        $status = $b['Properties']['ArchiveStatus'] ?? '';
        printf("%-9s %12s  %s%s\n", $tier, $size, $name, $status ? "  ($status)" : '');
    }
    echo "\nTotal: " . count($blobs) . " files\n";
}

function cmdRehydrate($s, $c, $prefix) {
    $blobs = $s->getListOfBlobsByPrefix($c, $prefix);
    $requested = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($blobs as $b) {
        $name = $b['Name'];
        $tier = $b['Properties']['AccessTier'] ?? '';
        $status = $b['Properties']['ArchiveStatus'] ?? '';
        if ($tier !== 'Archive' || strpos($status, 'rehydrate-pending') !== false) {
            $skipped++;
            continue;
        }
        $r = $s->setBlobTier($c, encodeBlobPath($name), 'Cool');
        if (!empty($r['success'])) {
            $requested++;
            echo "REQUESTED: $name\n";
        } else {
            $failed++;
            echo "FAILED: $name (" . ($r['errorCode'] ?? 'unknown') . ")\n";
        }
    }
    echo "\nRequested: $requested, Skipped (not Archive or already pending): $skipped, Failed: $failed\n";
    if ($requested > 0) {
        echo "Rehydration takes up to 15 hours. Run 'status' to check progress.\n";
    }
}

function cmdStatus($s, $c, $prefix) {
    $blobs = $s->getListOfBlobsByPrefix($c, $prefix);
    $stats = ['Archive' => 0, 'rehydrating' => 0, 'Cool' => 0, 'Hot' => 0, 'other' => 0];
    foreach ($blobs as $b) {
        $tier = $b['Properties']['AccessTier'] ?? 'other';
        $status = $b['Properties']['ArchiveStatus'] ?? '';
        if (strpos($status, 'rehydrate-pending') !== false) {
            $stats['rehydrating']++;
        } elseif (isset($stats[$tier])) {
            $stats[$tier]++;
        } else {
            $stats['other']++;
        }
    }
    echo "Status (total " . count($blobs) . " files):\n";
    foreach ($stats as $k => $v) {
        if ($v > 0) printf("  %-12s %d\n", $k . ':', $v);
    }
}

function cmdArchive($s, $c, $prefix) {
    $blobs = $s->getListOfBlobsByPrefix($c, $prefix);
    $moved = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($blobs as $b) {
        $name = $b['Name'];
        $tier = $b['Properties']['AccessTier'] ?? '';
        if ($tier === 'Archive') {
            $skipped++;
            continue;
        }
        $r = $s->setBlobTier($c, encodeBlobPath($name), 'Archive');
        if (!empty($r['success'])) {
            $moved++;
            echo "ARCHIVED: $name\n";
        } else {
            $failed++;
            echo "FAILED: $name (" . ($r['errorCode'] ?? 'unknown') . ")\n";
        }
    }
    echo "\nArchived: $moved, Skipped (already Archive): $skipped, Failed: $failed\n";
}

function cmdDownload($s, $c, $targetDir, $prefix) {
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        fwrite(STDERR, "Cannot create $targetDir\n");
        exit(1);
    }
    $blobs = $s->getListOfBlobsByPrefix($c, $prefix);
    $downloaded = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($blobs as $b) {
        $name = $b['Name'];
        $tier = $b['Properties']['AccessTier'] ?? '';
        if ($tier === 'Archive') {
            echo "SKIP (still Archive): $name\n";
            $skipped++;
            continue;
        }
        $localPath = rtrim($targetDir, '/') . '/' . $name;
        $localDir = dirname($localPath);
        if (!is_dir($localDir)) mkdir($localDir, 0755, true);
        if (file_exists($localPath)) {
            echo "SKIP (exists): $name\n";
            $skipped++;
            continue;
        }
        echo "Downloading $name ... ";
        $r = $s->getBlob($c, encodeBlobPath($name), ['returnRichStatus' => true]);
        if (!empty($r['success']) && $r['content'] !== null) {
            file_put_contents($localPath, $r['content']);
            echo "OK\n";
            $downloaded++;
        } else {
            echo "FAILED (" . ($r['errorCode'] ?? 'unknown') . ")\n";
            $failed++;
        }
    }
    echo "\nDownloaded: $downloaded, Skipped: $skipped, Failed: $failed\n";
}
