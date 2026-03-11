<?php
/**
 * Gallery Crawler - generates thumbnails and fullsize previews
 * Runs as a background process, independent of the browser.
 * Controlled via crawler.pid (running), crawler.stop (stop request).
 * Progress written to crawler.json.
 */
set_time_limit(0);
putenv('LANG=en_US.UTF-8');
setlocale(LC_ALL, 'en_US.UTF-8');

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

$rootGallery    = rtrim($config['rootGallery'], '/');
$thumbsFolder   = rtrim($config['thumbnailsFolder'], '/');
$fullsizeFolder = rtrim($config['fullsizeFolder'], '/');
$thumbWidth     = $config['thumbnailWidth'];
$thumbHeight    = $config['thumbnailHeight'];
$thumbQuality   = $config['thumbnailQuality'];
$fullWidth      = $config['fullWidth'];
$fullHeight     = $config['fullHeight'];
$fullQuality    = $config['fullQuality'];

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// Build UID -> user info map
$uidMap = [];
foreach ($config['users'] as $u) {
    if (isset($u['uid'])) {
        $uidMap[$u['uid']] = ['name' => $u['name'], 'initials' => $u['initials']];
    }
}

$pidFile    = __DIR__ . '/crawler.pid';
$stopFile   = __DIR__ . '/crawler.stop';
$statusFile = __DIR__ . '/crawler.json';

// Check if already running
if (file_exists($pidFile)) {
    $oldPid = (int)file_get_contents($pidFile);
    if ($oldPid && posix_kill($oldPid, 0)) {
        echo "Crawler already running (PID $oldPid)\n";
        exit(1);
    }
    unlink($pidFile);
}

// Write PID
file_put_contents($pidFile, getmypid());

// Clean stop file if leftover
if (file_exists($stopFile)) unlink($stopFile);

function writeStatus($data) {
    global $statusFile;
    $data['updatedAt'] = date('Y-m-d H:i:s');
    file_put_contents($statusFile, json_encode($data, JSON_UNESCAPED_UNICODE));
}

function shouldStop() {
    global $stopFile;
    return file_exists($stopFile);
}

function cleanup() {
    global $pidFile, $stopFile;
    if (file_exists($pidFile)) unlink($pidFile);
    if (file_exists($stopFile)) unlink($stopFile);
}

register_shutdown_function('cleanup');

// Collect all folders recursively
function collectFolders($dir, $relative = '') {
    global $imageExts;
    $result = [];
    $entries = @scandir($dir);
    if (!$entries) return $result;

    $hasMedia = false;
    foreach ($entries as $entry) {
        if ($entry[0] === '.') continue;
        $fullPath = $dir . '/' . $entry;
        if (is_dir($fullPath)) {
            $subRel = $relative ? $relative . '/' . $entry : $entry;
            $result[] = $subRel;
            $result = array_merge($result, collectFolders($fullPath, $subRel));
        }
    }
    return $result;
}

// Sort folders: newest first (by folder name, works for date-named folders)
$allFolders = collectFolders($rootGallery);
rsort($allFolders, SORT_LOCALE_STRING);

// Add root folder at the end
$allFolders[] = '';

$totalFolders = count($allFolders);

writeStatus([
    'state' => 'running',
    'totalFolders' => $totalFolders,
    'processedFolders' => 0,
    'currentFolder' => '',
    'foldersWithNewFiles' => 0,
    'totalNewFiles' => 0,
]);

$processedFolders = 0;
$foldersWithNewFiles = 0;
$totalNewFiles = 0;

foreach ($allFolders as $relPath) {
    if (shouldStop()) {
        writeStatus([
            'state' => 'stopped',
            'totalFolders' => $totalFolders,
            'processedFolders' => $processedFolders,
            'currentFolder' => '',
            'foldersWithNewFiles' => $foldersWithNewFiles,
            'totalNewFiles' => $totalNewFiles,
        ]);
        exit(0);
    }

    $srcDir = $rootGallery . ($relPath ? '/' . $relPath : '');
    $thumbDir = $thumbsFolder . ($relPath ? '/' . $relPath : '');
    $fsDir = $fullsizeFolder . ($relPath ? '/' . $relPath : '');

    // Scan for media files
    $entries = @scandir($srcDir);
    if (!$entries) {
        $processedFolders++;
        continue;
    }

    $mediaFiles = [];
    foreach ($entries as $entry) {
        if ($entry[0] === '.') continue;
        if (is_dir($srcDir . '/' . $entry)) continue;
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, $imageExts)) {
            $mediaFiles[] = $entry;
        }
    }

    if (!$mediaFiles) {
        $processedFolders++;
        continue;
    }

    // Load existing data.json
    $dataFile = $thumbDir . '/data.json';
    $data = [];
    if (file_exists($dataFile)) {
        $data = json_decode(file_get_contents($dataFile), true) ?: [];
    }

    // Check what needs generating
    $toProcess = [];
    foreach ($mediaFiles as $name) {
        $srcMtime = filemtime($srcDir . '/' . $name);
        if (!isset($data[$name]) || ($data[$name]['mtime'] ?? 0) !== $srcMtime) {
            $toProcess[] = $name;
        }
    }

    // Remove entries for deleted files
    $dataChanged = false;
    foreach (array_keys($data) as $key) {
        if (!in_array($key, $mediaFiles)) {
            $oldMapped = $data[$key]['mappedName'] ?? $key;
            $t = $thumbDir . '/' . $oldMapped;
            if (file_exists($t)) unlink($t);
            $f = $fsDir . '/' . $oldMapped;
            if (file_exists($f)) unlink($f);
            unset($data[$key]);
            $dataChanged = true;
        }
    }

    if (!$toProcess && !$dataChanged) {
        if ($dataChanged) {
            file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $processedFolders++;
        continue;
    }

    if ($toProcess) {
        $foldersWithNewFiles++;
        $totalNewFiles += count($toProcess);

        if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
        if (!is_dir($fsDir)) mkdir($fsDir, 0755, true);

        // Build used names map
        $usedNames = [];
        foreach ($data as $k => $v) {
            if (isset($v['mappedName'])) $usedNames[$v['mappedName']] = true;
        }

        $filesInFolder = count($toProcess);
        $filesDone = 0;

        foreach ($toProcess as $name) {
            if (shouldStop()) {
                if ($dataChanged) {
                    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                writeStatus([
                    'state' => 'stopped',
                    'totalFolders' => $totalFolders,
                    'processedFolders' => $processedFolders,
                    'currentFolder' => '',
                    'foldersWithNewFiles' => $foldersWithNewFiles,
                    'totalNewFiles' => $totalNewFiles,
                ]);
                exit(0);
            }

            $srcFile = $srcDir . '/' . $name;
            $srcMtime = filemtime($srcFile);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            $exif = [];
            if (in_array($ext, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
                $rawExif = @exif_read_data($srcFile, 'ANY_TAG', false);
                if ($rawExif) {
                    $exif['DateTimeOriginal'] = $rawExif['DateTimeOriginal'] ?? null;
                    $exif['Camera'] = trim($rawExif['Model'] ?? '');
                    $exif['Width'] = $rawExif['COMPUTED']['Width'] ?? null;
                    $exif['Height'] = $rawExif['COMPUTED']['Height'] ?? null;
                    $exif['Orientation'] = $rawExif['Orientation'] ?? 1;
                }
            }

            $dateTaken = $exif['DateTimeOriginal'] ?? null;

            // Build mapped name
            $baseMapped = dateToFilename($dateTaken);
            if ($baseMapped) {
                $mapped = $baseMapped . '.jpg';
                $counter = 1;
                while (isset($usedNames[$mapped])) {
                    $mapped = $baseMapped . '_' . $counter . '.jpg';
                    $counter++;
                }
            } else {
                $mapped = $name;
            }
            $usedNames[$mapped] = true;

            // Generate thumbnail
            generateThumbnail($srcFile, $thumbDir . '/' . $mapped, $thumbWidth, $thumbHeight, $thumbQuality, $exif['Orientation'] ?? 1);

            // Generate fullsize
            generateThumbnail($srcFile, $fsDir . '/' . $mapped, $fullWidth, $fullHeight, $fullQuality, $exif['Orientation'] ?? 1);

            // File owner
            $owner = null;
            $fileUid = fileowner($srcFile);
            if ($fileUid !== false && isset($uidMap[$fileUid])) {
                $owner = $uidMap[$fileUid];
            }

            $data[$name] = [
                'mtime' => $srcMtime,
                'exif' => $exif,
                'dateTaken' => $dateTaken,
                'mappedName' => $mapped,
                'owner' => $owner,
            ];
            $dataChanged = true;
            $filesDone++;

            writeStatus([
                'state' => 'running',
                'totalFolders' => $totalFolders,
                'processedFolders' => $processedFolders,
                'currentFolder' => $relPath ?: '(root)',
                'currentFile' => "$filesDone / $filesInFolder",
                'foldersWithNewFiles' => $foldersWithNewFiles,
                'totalNewFiles' => $totalNewFiles,
            ]);
        }
    }

    if ($dataChanged) {
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    $processedFolders++;
}

writeStatus([
    'state' => 'done',
    'totalFolders' => $totalFolders,
    'processedFolders' => $processedFolders,
    'currentFolder' => '',
    'foldersWithNewFiles' => $foldersWithNewFiles,
    'totalNewFiles' => $totalNewFiles,
]);

// --- Helper functions ---

function dateToFilename($dt) {
    if (!$dt) return null;
    $ts = strtotime(str_replace(':', '-', substr($dt, 0, 10)) . substr($dt, 10));
    if (!$ts) { $ts = strtotime($dt); }
    if (!$ts) return null;
    return date('Y-m-d_H-i-s', $ts);
}

function generateThumbnail($src, $dst, $maxW, $maxH, $quality, $orientation = 1) {
    $info = @getimagesize($src);
    if (!$info) return false;

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $img = imagecreatefromjpeg($src); break;
        case 'image/png':  $img = imagecreatefrompng($src); break;
        case 'image/gif':  $img = imagecreatefromgif($src); break;
        case 'image/webp': $img = imagecreatefromwebp($src); break;
        default: return false;
    }

    switch ($orientation) {
        case 3: $img = imagerotate($img, 180, 0); break;
        case 6: $img = imagerotate($img, -90, 0); break;
        case 8: $img = imagerotate($img, 90, 0); break;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $ratio = min($maxW / $w, $maxH / $h);
    if ($ratio >= 1) { $newW = $w; $newH = $h; }
    else { $newW = (int) round($w * $ratio); $newH = (int) round($h * $ratio); }

    $thumb = imagecreatetruecolor($newW, $newH);
    if ($mime === 'image/png' || $mime === 'image/gif') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
    imagejpeg($thumb, $dst, $quality);
    imagedestroy($img);
    imagedestroy($thumb);
    return true;
}
