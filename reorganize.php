<?php
/**
 * Reorganize year folders into monthly subfolders (YYYY-MM).
 * Moves album directories (e.g. "2023-01-05 Pečené křepelky") into
 * month groups (e.g. "2023-01/2023-01-05 Pečené křepelky").
 * Operates on all 3 trees (source, thumbnails, fullsize) simultaneously.
 * No thumbnail regeneration needed - data.json travels inside each album.
 *
 * Usage:
 *   php reorganize.php                  # dry-run (default)
 *   php reorganize.php --execute        # actually move folders
 */

$execute = in_array('--execute', $argv);

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
if (!$config) {
    echo "ERROR: Cannot read config.json\n";
    exit(1);
}

$rootGallery    = rtrim($config['rootGallery'], '/');
$thumbsFolder   = rtrim($config['thumbnailsFolder'], '/');
$fullsizeFolder = rtrim($config['fullsizeFolder'], '/');

// Years to reorganize
$years = range(2020, 2025);

$totalMoved = 0;
$errors = [];

foreach ($years as $year) {
    $srcYear   = $rootGallery . '/' . $year;
    $thumbYear = $thumbsFolder . '/' . $year;
    $fsYear    = $fullsizeFolder . '/' . $year;

    if (!is_dir($srcYear)) {
        echo "SKIP: $year - source folder does not exist\n";
        continue;
    }

    // Scan for album subdirectories (named like "2023-01-05 Description")
    $entries = @scandir($srcYear);
    if (!$entries) continue;

    $albums = [];
    foreach ($entries as $entry) {
        if ($entry[0] === '.') continue;
        if (!is_dir($srcYear . '/' . $entry)) continue;
        // Skip entries that already look like month folders (YYYY-MM with no day)
        if (preg_match('/^\d{4}-\d{2}$/', $entry)) continue;
        $albums[] = $entry;
    }

    if (!$albums) {
        echo "SKIP: $year - no album folders to reorganize\n";
        continue;
    }

    // Group albums by month (from folder name prefix YYYY-MM-DD)
    $byMonth = [];
    $skipped = [];
    foreach ($albums as $album) {
        if (preg_match('/^(\d{4}-\d{2})-\d{2}/', $album, $m)) {
            $month = $m[1]; // e.g. "2023-07"
            $byMonth[$month][] = $album;
        } else {
            $skipped[] = $album;
        }
    }

    ksort($byMonth);

    echo "\n=== $year ===\n";
    $albumCount = array_sum(array_map('count', $byMonth));
    echo "  Albums to organize: $albumCount\n";
    foreach ($byMonth as $month => $monthAlbums) {
        echo "  $month: " . count($monthAlbums) . " albums\n";
    }
    if ($skipped) {
        echo "  Skipped (no date prefix): " . implode(', ', $skipped) . "\n";
    }

    if (!$execute) {
        $totalMoved += $albumCount;
        continue;
    }

    // Execute moves
    foreach ($byMonth as $month => $monthAlbums) {
        // Create month directory in source tree (thumbs/fullsize created only if they exist)
        $srcMonthDir   = $srcYear . '/' . $month;
        $thumbMonthDir = $thumbYear . '/' . $month;
        $fsMonthDir    = $fsYear . '/' . $month;

        if (!is_dir($srcMonthDir)) mkdir($srcMonthDir, 0755, true);

        foreach ($monthAlbums as $album) {
            $ok = true;

            // 1. Move source album
            $from = $srcYear . '/' . $album;
            $to   = $srcMonthDir . '/' . $album;
            if (is_dir($from)) {
                if (!rename($from, $to)) {
                    $errors[] = "Failed to move source: $year/$album";
                    $ok = false;
                }
            }

            // 2. Move thumbnail album (may not exist if crawler hasn't processed it)
            $from = $thumbYear . '/' . $album;
            $to   = $thumbMonthDir . '/' . $album;
            if (is_dir($from)) {
                if (!is_dir($thumbMonthDir)) mkdir($thumbMonthDir, 0755, true);
                if (!rename($from, $to)) {
                    $errors[] = "Failed to move thumbnail: $year/$album";
                    $ok = false;
                }
            }

            // 3. Move fullsize album (may not exist if crawler hasn't processed it)
            $from = $fsYear . '/' . $album;
            $to   = $fsMonthDir . '/' . $album;
            if (is_dir($from)) {
                if (!is_dir($fsMonthDir)) mkdir($fsMonthDir, 0755, true);
                if (!rename($from, $to)) {
                    $errors[] = "Failed to move fullsize: $year/$album";
                    $ok = false;
                }
            }

            if ($ok) {
                echo "  Moved: $album -> $month/$album\n";
                $totalMoved++;
            }
        }
    }
}

echo "\n--- Summary ---\n";
if ($execute) {
    echo "Moved: $totalMoved albums\n";
} else {
    echo "DRY RUN - would move $totalMoved albums\n";
    echo "Run with --execute to apply changes.\n";
}

if ($errors) {
    echo "\nERRORS:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
