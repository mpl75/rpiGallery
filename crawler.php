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
$sharedGroup    = $config['group'] ?? null;
$inboxFolder    = $config['inboxFolder'] ?? '';
$archiveNesting = $config['archiveNesting'] ?? ['Y', 'Y-m'];
$thumbWidth     = $config['thumbnailWidth'];
$thumbHeight    = $config['thumbnailHeight'];
$thumbQuality   = $config['thumbnailQuality'];
$fullWidth      = $config['fullWidth'];
$fullHeight     = $config['fullHeight'];
$fullQuality    = $config['fullQuality'];

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$videoExts = $config['videoExtensions'] ?? [];
$allExts = array_merge($imageExts, $videoExts);

// Initialize Azure backup if configured. Note: classStorage sets timezone to GMT
// at file load (required for Azure auth signatures); leave it that way thereafter.
$azureStorage = null;
$azureContainer = null;
$azureTier = 'Archive';
$azureMaxSize = 0;
if (!empty($config['azureBackup']['connectionString'])) {
    require_once __DIR__ . '/classStorage/classStorage.php';
    $azureStorage = new Storage($config['azureBackup']['connectionString'], true);
    $azureContainer = $config['azureBackup']['container'] ?? 'archiv';
    $azureTier = $config['azureBackup']['tier'] ?? 'Archive';
    $azureMaxSize = (int)($config['azureBackup']['maxSizeMB'] ?? 0) * 1048576;
    // Idempotent: returns ContainerAlreadyExists error if it exists, that is fine
    $azureStorage->createContainer($azureContainer);
}

function backupContentType($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'mp4'  => 'video/mp4',
    ][$ext] ?? 'application/octet-stream';
}

// Encode each path segment so spaces and diacritics survive HTTP transport to Azure.
function encodeBlobPath($path) {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function backupLog($status, $blob, $detail = '') {
    $line = sprintf("%s  %-4s  %s%s\n", gmdate('Y-m-d H:i:s'), $status, $blob, $detail ? "  $detail" : '');
    @file_put_contents(__DIR__ . '/backup.log', $line, FILE_APPEND);
}

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

// Auto-file anything dropped into the Inbox before scanning (so new albums are included)
processInbox();

// Sort folders: newest first (by folder name, works for date-named folders)
$allFolders = collectFolders($rootGallery);
// Never treat the Inbox itself as a gallery album
if ($inboxFolder) {
    $allFolders = array_values(array_filter($allFolders, function ($f) use ($inboxFolder) {
        return $f !== $inboxFolder && strpos($f, $inboxFolder . '/') !== 0;
    }));
}
rsort($allFolders, SORT_LOCALE_STRING);

// Add root folder at the end
$allFolders[] = '';

$totalFolders = count($allFolders);

if ($azureStorage) {
    backupLog('====', '----- crawler START (' . $totalFolders . ' folders to scan) -----');
}

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
        if ($azureStorage) {
            backupLog('====', '----- crawler STOPPED -----');
        }
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
        if (in_array($ext, $allExts)) {
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
    $dataVersion = 0;
    if (file_exists($dataFile)) {
        $raw = json_decode(file_get_contents($dataFile), true) ?: [];
        $dataVersion = $raw['_version'] ?? 0;
        $displayName = $raw['_displayName'] ?? null;
        unset($raw['_version'], $raw['_displayName']);
        $data = $raw;
    }

    $currentVersion = $config['dataVersion'] ?? 1;
    $needsMetadataRefresh = ($dataVersion < $currentVersion);

    // Check what needs generating
    $toProcess = [];
    foreach ($mediaFiles as $name) {
        $srcMtime = filemtime($srcDir . '/' . $name);
        if (!isset($data[$name]) || ($data[$name]['mtime'] ?? 0) !== $srcMtime) {
            $toProcess[] = $name;
        }
    }

    // Files that need metadata refresh only (thumbnails exist)
    $toRefresh = [];
    if ($needsMetadataRefresh) {
        foreach ($mediaFiles as $name) {
            if (isset($data[$name]) && !in_array($name, $toProcess)) {
                $toRefresh[] = $name;
            }
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

    // Decide if backup pass is needed: any media file in this folder lacks both 'backedUp' and 'backupSkipped'
    $needsBackup = false;
    if ($azureStorage) {
        foreach ($mediaFiles as $name) {
            if (isset($data[$name]) && empty($data[$name]['backedUp']) && empty($data[$name]['backupSkipped'])) {
                $needsBackup = true;
                break;
            }
        }
    }

    if (!$toProcess && !$toRefresh && !$dataChanged && !$needsBackup) {
        $processedFolders++;
        continue;
    }

    // Metadata refresh: re-read EXIF/owner, keep existing thumbnails
    if ($toRefresh) {
        foreach ($toRefresh as $name) {
            if (shouldStop()) break;
            $srcFile = $srcDir . '/' . $name;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $isVideo = in_array($ext, $videoExts);
            $entry = $data[$name];
            $exif = $entry['exif'] ?? [];

            if ($isVideo) {
                $exif['type'] = 'video';
                // Re-extract video date: filename > Apple tag > creation_time
                $ts = videoDateFromFilename($name);
                if (!$ts) {
                    $probe = shell_exec('ffprobe -v quiet -print_format json -show_format ' . escapeshellarg($srcFile) . ' 2>/dev/null');
                    if ($probe) {
                        $meta = json_decode($probe, true);
                        $tags = $meta['format']['tags'] ?? [];
                        $appleDate = $tags['com.apple.quicktime.creationdate'] ?? null;
                        $creationTime = $tags['creation_time'] ?? null;
                        if ($appleDate) {
                            $ts = strtotime($appleDate);
                        } else if ($creationTime) {
                            $utc = new DateTime($creationTime, new DateTimeZone('UTC'));
                            $utc->setTimezone(new DateTimeZone('Europe/Prague'));
                            $ts = $utc->getTimestamp();
                        }
                    }
                }
                if ($ts) {
                    $exif['DateTimeOriginal'] = date('Y:m:d H:i:s', $ts);
                    $data[$name]['dateTaken'] = $exif['DateTimeOriginal'];
                }
            } else if (in_array($ext, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
                $rawExif = @exif_read_data($srcFile, 'ANY_TAG', false);
                if ($rawExif) {
                    $cam = trim($rawExif['Model'] ?? '');
                    $exif['Camera'] = $config['cameraAliases'][$cam] ?? $cam;
                    $gps = extractGps($rawExif);
                    if ($gps) $exif['gps'] = $gps;
                    else unset($exif['gps']);
                }
            }

            $owner = null;
            $fileUid = fileowner($srcFile);
            if ($fileUid !== false && isset($uidMap[$fileUid])) {
                $owner = $uidMap[$fileUid];
            }

            $data[$name]['exif'] = $exif;
            $data[$name]['owner'] = $owner;
            $data[$name]['type'] = $isVideo ? 'video' : 'image';
            $data[$name]['filesize'] = filesize($srcFile);
            $dataChanged = true;
        }
    }

    if ($toProcess) {
        $foldersWithNewFiles++;
        $totalNewFiles += count($toProcess);

        if (!is_dir($thumbDir)) mkdirShared($thumbDir);
        if (!is_dir($fsDir)) mkdirShared($fsDir);

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
                    file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                writeStatus([
                    'state' => 'stopped',
                    'totalFolders' => $totalFolders,
                    'processedFolders' => $processedFolders,
                    'currentFolder' => '',
                    'foldersWithNewFiles' => $foldersWithNewFiles,
                    'totalNewFiles' => $totalNewFiles,
                ]);
                if ($azureStorage) {
                    backupLog('====', '----- crawler STOPPED -----');
                }
                exit(0);
            }

            $srcFile = $srcDir . '/' . $name;
            $srcMtime = filemtime($srcFile);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $isVideo = in_array($ext, $videoExts);

            $exif = [];
            $dateTaken = null;

            if ($isVideo) {
                // Extract video date: filename (local time) > Apple tag > creation_time (UTC)
                $ts = videoDateFromFilename($name);
                if (!$ts) {
                    $probe = shell_exec('ffprobe -v quiet -print_format json -show_format ' . escapeshellarg($srcFile) . ' 2>/dev/null');
                    if ($probe) {
                        $meta = json_decode($probe, true);
                        $tags = $meta['format']['tags'] ?? [];
                        $appleDate = $tags['com.apple.quicktime.creationdate'] ?? null;
                        $creationTime = $tags['creation_time'] ?? null;
                        if ($appleDate) {
                            $ts = strtotime($appleDate);
                        } else if ($creationTime) {
                            $utc = new DateTime($creationTime, new DateTimeZone('UTC'));
                            $utc->setTimezone(new DateTimeZone('Europe/Prague'));
                            $ts = $utc->getTimestamp();
                        }
                    }
                }
                if ($ts) {
                    $exif['DateTimeOriginal'] = date('Y:m:d H:i:s', $ts);
                    $dateTaken = $exif['DateTimeOriginal'];
                }
                $exif['type'] = 'video';
            } else {
                // Image EXIF
                if (in_array($ext, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
                    $rawExif = @exif_read_data($srcFile, 'ANY_TAG', false);
                    if ($rawExif) {
                        $exif['DateTimeOriginal'] = $rawExif['DateTimeOriginal'] ?? null;
                        $cam = trim($rawExif['Model'] ?? '');
                        $exif['Camera'] = $config['cameraAliases'][$cam] ?? $cam;
                        $exif['Width'] = $rawExif['COMPUTED']['Width'] ?? null;
                        $exif['Height'] = $rawExif['COMPUTED']['Height'] ?? null;
                        $exif['Orientation'] = $rawExif['Orientation'] ?? 1;
                        $gps = extractGps($rawExif);
                        if ($gps) $exif['gps'] = $gps;
                    }
                }
                $dateTaken = $exif['DateTimeOriginal'] ?? null;
            }

            // Build mapped name
            $baseMapped = dateToFilename($dateTaken);
            $mappedExt = $isVideo ? $ext : 'jpg';
            if ($baseMapped) {
                $mapped = $baseMapped . '.' . $mappedExt;
                $counter = 1;
                while (isset($usedNames[$mapped])) {
                    $mapped = $baseMapped . '_' . $counter . '.' . $mappedExt;
                    $counter++;
                }
            } else {
                $mapped = $name;
            }
            $usedNames[$mapped] = true;

            if ($isVideo) {
                // Generate thumbnail from video frame
                $thumbFile = $thumbDir . '/' . pathinfo($mapped, PATHINFO_FILENAME) . '.jpg';
                if (!file_exists($thumbFile)) {
                    shell_exec('ffmpeg -y -i ' . escapeshellarg($srcFile) . ' -ss 1 -frames:v 1 -vf scale=' . $thumbWidth . ':-2 -q:v 3 ' . escapeshellarg($thumbFile) . ' 2>/dev/null');
                }
                $mapped = pathinfo($mapped, PATHINFO_FILENAME) . '.jpg'; // thumbnail is jpg
            } else {
                // Generate thumbnail
                generateThumbnail($srcFile, $thumbDir . '/' . $mapped, $thumbWidth, $thumbHeight, $thumbQuality, $exif['Orientation'] ?? 1);

                // Generate fullsize
                generateThumbnail($srcFile, $fsDir . '/' . $mapped, $fullWidth, $fullHeight, $fullQuality, $exif['Orientation'] ?? 1);
            }

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
                'type' => $isVideo ? 'video' : 'image',
                'filesize' => filesize($srcFile),
            ];
            $dataChanged = true;
            $filesDone++;

            // Save after each file so progress survives a crash
            file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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

    // Azure backup: upload any media files in this folder that aren't yet backed up.
    // Crash-resilient: data.json saved after each file. Failures are logged and retried next run.
    if ($azureStorage) {
        foreach ($mediaFiles as $name) {
            if (shouldStop()) break;
            if (!isset($data[$name])) continue;
            if (!empty($data[$name]['backedUp']) || !empty($data[$name]['backupSkipped'])) continue;

            $srcFile = $srcDir . '/' . $name;
            if (!file_exists($srcFile)) continue;
            $size = filesize($srcFile);
            $relBlob = ($relPath ? $relPath . '/' : '') . $name;

            // Honor max-size limit (e.g. skip videos over 1 GB)
            if ($azureMaxSize > 0 && $size > $azureMaxSize) {
                backupLog('SKIP', $relBlob, '(too large, ' . round($size / 1048576, 1) . ' MB)');
                $data[$name]['backupSkipped'] = 'size:' . $size;
                $dataChanged = true;
                file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                continue;
            }

            $blobPath = encodeBlobPath($relBlob);
            $t0 = microtime(true);
            $result = $azureStorage->uploadFile($azureContainer, $blobPath, $srcFile, backupContentType($name));

            if (empty($result['success'])) {
                // 409 Conflict means the blob already exists. Verify size matches before
                // accepting it as backed up (guards against truncated/wrong-version blobs).
                if (($result['httpCode'] ?? 0) === 409) {
                    $head = $azureStorage->getBlobProperties($azureContainer, $blobPath);
                    $existingSize = (int)($head['headers']['Content-Length'] ?? 0);
                    if (!empty($head['success']) && $existingSize === $size) {
                        backupLog('SKIP', $relBlob, '(already in Azure, ' . round($size / 1048576, 1) . ' MB)');
                        $data[$name]['backedUp'] = gmdate('Y-m-d\TH:i:s\Z');
                        $dataChanged = true;
                        file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        continue;
                    }
                    backupLog('FAIL', $relBlob, '(409 conflict, blob ' . $existingSize . ' B vs local ' . $size . ' B)');
                    continue;
                }
                backupLog('FAIL', $relBlob, '(' . ($result['errorCode'] ?? 'unknown') . ', http ' . ($result['httpCode'] ?? '?') . ')');
                continue;
            }

            $azureStorage->setBlobTier($azureContainer, $blobPath, $azureTier);
            $elapsed = round(microtime(true) - $t0, 1);
            backupLog('OK', $relBlob, '(' . round($size / 1048576, 1) . ' MB, ' . $elapsed . 's)');
            $data[$name]['backedUp'] = gmdate('Y-m-d\TH:i:s\Z');
            $dataChanged = true;
            file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    if ($dataChanged) {
        file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

if ($azureStorage) {
    backupLog('====', '----- crawler DONE -----');
}

// --- Helper functions ---

function exifGpsToDecimal($coord, $ref) {
    if (!$coord || !$ref) return null;
    $deg = count($coord) > 0 ? evalRational($coord[0]) : 0;
    $min = count($coord) > 1 ? evalRational($coord[1]) : 0;
    $sec = count($coord) > 2 ? evalRational($coord[2]) : 0;
    $dec = $deg + $min / 60 + $sec / 3600;
    if ($ref === 'S' || $ref === 'W') $dec = -$dec;
    return round($dec, 6);
}

function evalRational($val) {
    if (is_numeric($val)) return (float)$val;
    $parts = explode('/', (string)$val);
    if (count($parts) === 2 && $parts[1] != 0) return (float)$parts[0] / (float)$parts[1];
    return (float)$parts[0];
}

function extractGps($rawExif) {
    if (!$rawExif) return null;
    $lat = exifGpsToDecimal($rawExif['GPSLatitude'] ?? null, $rawExif['GPSLatitudeRef'] ?? null);
    $lon = exifGpsToDecimal($rawExif['GPSLongitude'] ?? null, $rawExif['GPSLongitudeRef'] ?? null);
    if ($lat !== null && $lon !== null && ($lat != 0 || $lon != 0)) return ['lat' => $lat, 'lon' => $lon];
    return null;
}

function videoDateFromFilename($filename) {
    // Android: VID_20251013_130938378.mp4 -> local time 2025-10-13 13:09:38
    if (preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $filename, $m)) {
        $dt = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
        $ts = strtotime($dt);
        if ($ts && $m[1] >= 2000 && $m[1] <= 2099) return $ts;
    }
    return null;
}

function dateToFilename($dt) {
    if (!$dt) return null;
    $ts = strtotime(str_replace(':', '-', substr($dt, 0, 10)) . substr($dt, 10));
    if (!$ts) { $ts = strtotime($dt); }
    if (!$ts) return null;
    return date('Y-m-d_H-i-s', $ts);
}

function mkdirShared($path) {
    global $sharedGroup;
    mkdir($path, 0775, true);
    if ($sharedGroup) {
        // Set group and setgid on all newly created directories in the path
        $parts = explode('/', $path);
        $current = '';
        foreach ($parts as $part) {
            $current .= $part . '/';
            if (is_dir($current)) {
                @chgrp($current, $sharedGroup);
                @chmod($current, 02775);
            }
        }
    }
}

// --- Inbox auto-filing ---
// Drains rootGallery/<inboxFolder> into the dated archive structure. Runs as the crawler
// user (www-data), never root: it creates destination album dirs (owned www-data, group =
// shared group inherited via setgid) and moves media in with rename() (preserving each
// file's original owner, e.g. michal/sarka). Group of moved files is left as-is (a non-owner
// cannot chgrp) — fine for viewing/managing, since folder perms are what grant shared access.
function processInbox() {
    global $rootGallery, $inboxFolder, $allExts;
    if (!$inboxFolder) return;
    $inboxPath = $rootGallery . '/' . $inboxFolder;
    if (!is_dir($inboxPath)) return;

    $entries = @scandir($inboxPath);
    if (!$entries) return;

    foreach ($entries as $entry) {
        if ($entry[0] === '.') continue;
        $src = $inboxPath . '/' . $entry;

        if (is_dir($src)) {
            // Subfolder = one album, named after the subfolder.
            $ts = inboxDate($src, $entry);
            if (!$ts) { inboxLog('SKIP', $entry, '(nelze urcit datum)'); continue; }
            $albumName = preg_match('/^\d{4}-\d{2}-\d{2}/', $entry) ? $entry : date('Y-m-d', $ts) . ' ' . $entry;

            if (is_writable($src)) {
                // Preferred: drain files into a fresh www-data-owned dir (correct shared group).
                $dest = archiveAlbumDir($ts, $albumName);
                [$m, $s] = drainMedia($src, $dest);
                removeIfEmpty($src);
                inboxLog('OK', $entry, "-> $albumName ($m souboru" . ($s ? ", $s preskoceno" : '') . ')');
            } else {
                // Foreign folder moved in wholesale: cannot write inside it, so move the whole
                // folder (works via parent perms). Files land + are viewable; the album dir keeps
                // its source group (www-data cannot chgrp a dir it does not own).
                $dest = archiveParentDir($ts) . '/' . $albumName;
                if (file_exists($dest)) { inboxLog('SKIP', $entry, "(cil uz existuje: $albumName)"); continue; }
                if (@rename($src, $dest)) inboxLog('MOVE', $entry, "-> $albumName (cela slozka, prava zdroje)");
                else inboxLog('FAIL', $entry, '(presun slozky selhal)');
            }
        } else {
            // Loose media file in the inbox root: merge into an existing album for that date
            // (e.g. "2026-07-24 Kempování") if one exists; otherwise a bare date album.
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, $allExts)) continue;
            $ts = mediaTimestamp($src, $entry, $ext);
            if (!$ts) { inboxLog('SKIP', $entry, '(nelze urcit datum)'); continue; }
            $dateStr = date('Y-m-d', $ts);
            $dest = findExistingAlbum($ts, $dateStr) ?? archiveAlbumDir($ts, $dateStr);
            $albumName = basename($dest);
            $target = $dest . '/' . $entry;
            if (file_exists($target)) { inboxLog('SKIP', $entry, '(cil uz existuje)'); continue; }
            if (@rename($src, $target)) inboxLog('OK', $entry, "-> $albumName");
            else inboxLog('FAIL', $entry, '(presun selhal)');
        }
    }
}

// Build (and create if missing) the parent path from archiveNesting date-format segments.
function archiveParentDir($ts) {
    global $rootGallery, $archiveNesting;
    $parent = $rootGallery;
    foreach ($archiveNesting as $fmt) $parent .= '/' . date($fmt, $ts);
    if (!is_dir($parent)) mkdirShared($parent);
    return $parent;
}

function archiveAlbumDir($ts, $albumName) {
    $dest = archiveParentDir($ts) . '/' . $albumName;
    if (!is_dir($dest)) mkdirShared($dest);
    return $dest;
}

// Find an existing album in this date's parent whose name starts with the date
// ("2026-07-24", "2026-07-24 Kempování", "2026-07-24a" — but not "2026-07-240...").
// Returns the first writable match (alphabetical), or null if none usable. Does not create.
function findExistingAlbum($ts, $dateStr) {
    global $rootGallery, $archiveNesting;
    $parent = $rootGallery;
    foreach ($archiveNesting as $fmt) $parent .= '/' . date($fmt, $ts);
    if (!is_dir($parent)) return null;

    $matches = [];
    foreach (@scandir($parent) ?: [] as $d) {
        if ($d[0] === '.') continue;
        if (!is_dir($parent . '/' . $d)) continue;
        if ($d === $dateStr || (strpos($d, $dateStr) === 0 && !ctype_digit(substr($d, strlen($dateStr), 1)))) {
            $matches[] = $d;
        }
    }
    if (!$matches) return null;
    sort($matches, SORT_LOCALE_STRING);
    foreach ($matches as $d) {
        if (is_writable($parent . '/' . $d)) return $parent . '/' . $d;
    }
    return null; // matches exist but none writable -> caller falls back to a bare date album
}

function drainMedia($srcDir, $destDir) {
    global $allExts;
    $moved = 0; $skipped = 0;
    foreach (@scandir($srcDir) ?: [] as $f) {
        if ($f[0] === '.') continue;
        $sp = $srcDir . '/' . $f;
        if (is_dir($sp)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allExts)) continue;
        $target = $destDir . '/' . $f;
        if (file_exists($target)) { $skipped++; continue; }
        if (@rename($sp, $target)) $moved++; else $skipped++;
    }
    return [$moved, $skipped];
}

function removeIfEmpty($dir) {
    foreach (array_diff(@scandir($dir) ?: [], ['.', '..']) as $f) {
        if ($f === '.DS_Store' || $f === 'Thumbs.db') @unlink($dir . '/' . $f);
    }
    if (!array_diff(@scandir($dir) ?: [], ['.', '..'])) @rmdir($dir);
}

// Album date: leading yyyy-mm-dd in the folder name, else earliest media date inside.
function inboxDate($srcDir, $name) {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $name, $m)) {
        $ts = strtotime("$m[1]-$m[2]-$m[3]");
        if ($ts) return $ts;
    }
    global $allExts;
    $earliest = null;
    foreach (@scandir($srcDir) ?: [] as $f) {
        if ($f[0] === '.') continue;
        $sp = $srcDir . '/' . $f;
        if (is_dir($sp)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allExts)) continue;
        $ts = mediaTimestamp($sp, $f, $ext);
        if ($ts && ($earliest === null || $ts < $earliest)) $earliest = $ts;
    }
    return $earliest;
}

function mediaTimestamp($file, $name, $ext) {
    global $videoExts;
    if (in_array($ext, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
        $ex = @exif_read_data($file, 'EXIF', false);
        if ($ex && !empty($ex['DateTimeOriginal'])) {
            $ts = strtotime(str_replace(':', '-', substr($ex['DateTimeOriginal'], 0, 10)) . substr($ex['DateTimeOriginal'], 10));
            if ($ts) return $ts;
        }
    }
    if (in_array($ext, $videoExts)) {
        $ts = videoDateFromFilename($name);
        if ($ts) return $ts;
    }
    return @filemtime($file) ?: null;
}

function inboxLog($status, $item, $detail = '') {
    $line = sprintf("%s  %-4s  %s%s\n", date('Y-m-d H:i:s'), $status, $item, $detail ? "  $detail" : '');
    @file_put_contents(__DIR__ . '/inbox.log', $line, FILE_APPEND);
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
