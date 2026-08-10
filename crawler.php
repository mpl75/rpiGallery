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

// Initialize Azure backup if configured.
$azureStorage = null;
$azureContainer = null;
$azureTier = 'Archive';
$azureMaxSize = 0;
$backupIndex = false;
if (!empty($config['azureBackup']['connectionString'])) {
    require_once __DIR__ . '/classStorage/classStorage.php';
    $azureStorage = new Storage($config['azureBackup']['connectionString'], true);
    $azureContainer = $config['azureBackup']['container'] ?? 'archiv';
    $azureTier = $config['azureBackup']['tier'] ?? 'Archive';
    $azureMaxSize = (int)($config['azureBackup']['maxSizeMB'] ?? 0) * 1048576;
    $backupIndex = $config['azureBackup']['backupIndex'] ?? true;
    // Idempotent: returns ContainerAlreadyExists error if it exists, that is fine
    $azureStorage->createContainer($azureContainer);
}

// Everything below formats local wall-clock times: video dates, album names, logs.
// Pin the zone explicitly instead of inheriting whatever a library left behind --
// classStorage still sets the process default to GMT when it loads, which is what made
// videos come out 2 hours early. Safe to override since classStorage formats its own
// Azure dates with gmdate(); the signatures no longer depend on the default.
date_default_timezone_set($config['timezone'] ?? 'Europe/Prague');

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

// --- Move detection ---
// A photo moved between albums (merging day-albums into one multi-day album) keeps its
// name, size and mtime -- rename() preserves both, and so do Finder/SMB copies. That
// triple is the identity used to recognise "this is the same file, just elsewhere", so
// the existing thumbnail, fullsize preview and Azure backup can be reused instead of
// regenerated and re-uploaded.

function moveKey($name, $size, $mtime) {
    return $name . '|' . (int)$size . '|' . (int)$mtime;
}

// Cheap content fingerprint (first 64 kB). Stored in data.json and compared on adoption
// so a coincidental name+size+mtime match cannot attach the wrong thumbnail.
function headHash($file) {
    $fh = @fopen($file, 'rb');
    if (!$fh) return null;
    $buf = fread($fh, 65536);
    fclose($fh);
    return $buf === false ? null : md5($buf);
}

function dataJsonFiles() {
    global $thumbsFolder;
    if (!is_dir($thumbsFolder)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($thumbsFolder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if ($f->getFilename() === 'data.json') $out[] = $f->getPathname();
    }
    return $out;
}

// Index every data.json entry whose source file is gone -- those are the only candidates
// for a move. Two lookups are kept: by name+size+mtime (a plain move) and by content
// fingerprint (a move that also renamed the file, e.g. around a name collision).
// Keys claimed by more than one entry are ambiguous and dropped -- regenerating is
// cheaper than attaching the wrong thumbnail.
function buildMoveIndex() {
    global $thumbsFolder, $rootGallery;
    $records = [];
    $byName = [];
    $byContent = [];
    $nameDup = [];
    $contentDup = [];
    $id = 0;

    foreach (dataJsonFiles() as $djPath) {
        $rel = trim(substr(dirname($djPath), strlen($thumbsFolder)), '/');
        $data = json_decode(@file_get_contents($djPath), true);
        if (!is_array($data)) continue;
        foreach ($data as $name => $entry) {
            if ($name === '' || $name[0] === '_' || !is_array($entry)) continue;
            if (file_exists($rootGallery . ($rel ? '/' . $rel : '') . '/' . $name)) continue;

            $records[++$id] = ['rel' => $rel, 'name' => $name, 'entry' => $entry];

            $nk = moveKey($name, $entry['filesize'] ?? -1, $entry['mtime'] ?? 0);
            if (isset($byName[$nk])) $nameDup[$nk] = true; else $byName[$nk] = $id;

            if (!empty($entry['hash']) && !empty($entry['filesize'])) {
                $ck = $entry['hash'] . '|' . (int)$entry['filesize'];
                if (isset($byContent[$ck])) $contentDup[$ck] = true; else $byContent[$ck] = $id;
            }
        }
    }
    foreach (array_keys($nameDup) as $k) unset($byName[$k]);
    foreach (array_keys($contentDup) as $k) unset($byContent[$k]);

    return ['records' => $records, 'byName' => $byName, 'byContent' => $byContent];
}

// Which indexed entry does this file come from? Name+size+mtime first (with the
// size-less fallback for entries predating 'filesize'), then the content fingerprint.
// A name match whose stored fingerprint disagrees is rejected, not adopted.
// Returns a record id for claimMove(), or null.
function findMoveCandidate($index, $hash, $name, $size, $mtime) {
    foreach ([moveKey($name, $size, $mtime), moveKey($name, -1, $mtime)] as $nk) {
        if (!isset($index['byName'][$nk])) continue;
        $id = $index['byName'][$nk];
        if (!isset($index['records'][$id])) continue;
        $stored = $index['records'][$id]['entry']['hash'] ?? null;
        if ($stored && $hash !== null && $stored !== $hash) continue;
        return $id;
    }
    if ($hash !== null) {
        $ck = $hash . '|' . (int)$size;
        if (isset($index['byContent'][$ck]) && isset($index['records'][$index['byContent'][$ck]])) {
            return $index['byContent'][$ck];
        }
    }
    return null;
}

// Consume a candidate so no second folder can adopt the same thumbnail
function claimMove(&$index, $id) {
    unset($index['records'][$id]);
}

// Reserve a thumbnail filename in the destination folder, suffixing on collision the
// same way the generator does (two photos can share an EXIF second).
function reserveMappedName($mapped, &$usedNames) {
    if (!isset($usedNames[$mapped])) { $usedNames[$mapped] = true; return $mapped; }
    $base = pathinfo($mapped, PATHINFO_FILENAME);
    $ext  = pathinfo($mapped, PATHINFO_EXTENSION);
    $i = 1;
    do {
        $cand = $base . '_' . $i . ($ext ? '.' . $ext : '');
        $i++;
    } while (isset($usedNames[$cand]));
    $usedNames[$cand] = true;
    return $cand;
}

function moveLog($status, $item, $detail = '') {
    $line = sprintf("%s  %-4s  %s%s\n", date('Y-m-d H:i:s'), $status, $item, $detail ? "  $detail" : '');
    @file_put_contents(__DIR__ . '/moves.log', $line, FILE_APPEND);
}

// --- Orphan sweep ---
// Drops thumbnails, fullsize previews and data.json entries whose source file is gone.
// The in-loop cleanup only reaches folders that still hold media, so an album emptied
// out completely (merged elsewhere, or deleted) would otherwise keep its thumbnails
// forever. Also removes stray files in the thumbnail/fullsize dirs that no data.json
// entry points at. Runs only after a full, uninterrupted pass.
function sweepOrphans() {
    global $thumbsFolder, $fullsizeFolder, $rootGallery;
    $removedFiles = 0;
    $removedFolders = 0;

    // Safety: never sweep against an archive that looks unmounted or unreadable.
    if (!is_dir($rootGallery)) return [0, 0];
    if (!array_diff(@scandir($rootGallery) ?: [], ['.', '..'])) return [0, 0];

    foreach (dataJsonFiles() as $djPath) {
        $rel = trim(substr(dirname($djPath), strlen($thumbsFolder)), '/');
        $srcDir   = $rootGallery . ($rel ? '/' . $rel : '');
        $thumbDir = dirname($djPath);
        $fsDir    = $fullsizeFolder . ($rel ? '/' . $rel : '');

        $data = json_decode(@file_get_contents($djPath), true);
        if (!is_array($data)) continue;

        $changed = false;
        foreach ($data as $name => $entry) {
            if ($name === '' || $name[0] === '_' || !is_array($entry)) continue;
            if (file_exists($srcDir . '/' . $name)) continue;
            $mapped = $entry['mappedName'] ?? $name;
            if (file_exists($thumbDir . '/' . $mapped)) { @unlink($thumbDir . '/' . $mapped); $removedFiles++; }
            if (file_exists($fsDir . '/' . $mapped))    { @unlink($fsDir . '/' . $mapped);    $removedFiles++; }
            unset($data[$name]);
            $changed = true;
            moveLog('DROP', ($rel ? $rel . '/' : '') . $name, '(zdroj neexistuje)');
        }

        $hasMedia = false;
        foreach ($data as $k => $v) {
            if ($k !== '' && $k[0] !== '_') { $hasMedia = true; break; }
        }

        // Source album gone entirely -> drop its thumbnail/fullsize folders too
        if (!$hasMedia && !is_dir($srcDir)) {
            @unlink($djPath);
            removeIfEmpty($thumbDir);
            removeIfEmpty($fsDir);
            $removedFolders++;
            moveLog('DROP', $rel ?: '(root)', '(cela slozka)');
            continue;
        }

        if ($changed) {
            file_put_contents($djPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Stray files nothing points at (left over from earlier renames/regenerations)
        if ($hasMedia) {
            $keep = ['data.json' => true];
            foreach ($data as $k => $v) {
                if ($k === '' || $k[0] === '_' || !is_array($v)) continue;
                $keep[$v['mappedName'] ?? $k] = true;
            }
            foreach ([$thumbDir, $fsDir] as $dir) {
                if (!is_dir($dir)) continue;
                foreach (@scandir($dir) ?: [] as $f) {
                    if ($f[0] === '.' || isset($keep[$f])) continue;
                    if (is_dir($dir . '/' . $f)) continue;
                    @unlink($dir . '/' . $f);
                    $removedFiles++;
                    moveLog('DROP', ($rel ? $rel . '/' : '') . $f, '(osirely soubor)');
                }
            }
        }
    }

    return [$removedFiles, $removedFolders];
}

// Auto-file anything dropped into the Inbox before scanning (so new albums are included)
processInbox();

// Index of files that vanished from their album -- candidates for adoption below.
// Built after the inbox pass so freshly filed photos are already in place.
$moveIndex = buildMoveIndex();

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
$adoptedFiles = 0;

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
    $displayName = null;
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

    $dataChanged = false;

    // Thumbnail names already taken in this folder (shared by the adoption pass below
    // and the generator further down)
    $usedNames = [];
    foreach ($data as $k => $v) {
        if (isset($v['mappedName'])) $usedNames[$v['mappedName']] = true;
    }

    // Adopt files that moved here from another album: relocate the existing thumbnail,
    // fullsize preview and backup record instead of regenerating and re-uploading.
    $carryBackup = [];
    if ($toProcess && $moveIndex['records']) {
        $stillToProcess = [];
        foreach ($toProcess as $name) {
            if (isset($data[$name])) { $stillToProcess[] = $name; continue; }

            $srcFile  = $srcDir . '/' . $name;
            $srcSize  = filesize($srcFile);
            $srcMtime = filemtime($srcFile);
            $srcHash  = headHash($srcFile);
            $id = findMoveCandidate($moveIndex, $srcHash, $name, $srcSize, $srcMtime);
            if ($id === null) { $stillToProcess[] = $name; continue; }

            $cand     = $moveIndex['records'][$id];
            $oldRel   = $cand['rel'];
            $oldEntry = $cand['entry'];

            // Backup record travels with the file: the blob keeps its original path
            // (an Archive-tier blob cannot be moved server-side without rehydration),
            // so remember where it actually lives. Entries predating backupBlob get it
            // reconstructed from the folder they are leaving.
            $backup = array_intersect_key($oldEntry, ['backedUp' => 1, 'backupSkipped' => 1, 'backupBlob' => 1]);
            if (!empty($oldEntry['backedUp']) && empty($backup['backupBlob'])) {
                $backup['backupBlob'] = ($oldRel ? $oldRel . '/' : '') . $cand['name'];
            }

            $oldMapped = $oldEntry['mappedName'] ?? $cand['name'];
            $oldThumb  = $thumbsFolder   . ($oldRel ? '/' . $oldRel : '') . '/' . $oldMapped;
            $oldFs     = $fullsizeFolder . ($oldRel ? '/' . $oldRel : '') . '/' . $oldMapped;

            // Thumbnail missing or unmovable -> regenerate, but keep the backup record
            // so the file is not uploaded to Azure a second time.
            if (!file_exists($oldThumb)) {
                claimMove($moveIndex, $id);
                if ($backup) $carryBackup[$name] = $backup;
                $stillToProcess[] = $name;
                continue;
            }

            if (!is_dir($thumbDir)) mkdirShared($thumbDir);
            $newMapped = reserveMappedName($oldMapped, $usedNames);
            if (!@rename($oldThumb, $thumbDir . '/' . $newMapped)) {
                unset($usedNames[$newMapped]);
                claimMove($moveIndex, $id);
                if ($backup) $carryBackup[$name] = $backup;
                $stillToProcess[] = $name;
                moveLog('FAIL', ($relPath ? $relPath . '/' : '') . $name, '(presun nahledu selhal)');
                continue;
            }

            if (file_exists($oldFs)) {
                if (!is_dir($fsDir)) mkdirShared($fsDir);
                @rename($oldFs, $fsDir . '/' . $newMapped);
            }

            $entry = $oldEntry;
            $entry['mtime']      = $srcMtime;
            $entry['filesize']   = $srcSize;
            $entry['mappedName'] = $newMapped;
            if (!empty($backup['backupBlob'])) $entry['backupBlob'] = $backup['backupBlob'];
            $data[$name] = $entry;
            $dataChanged = true;
            $adoptedFiles++;
            claimMove($moveIndex, $id);
            moveLog('MOVE', ($oldRel ? $oldRel . '/' : '') . $cand['name'], '-> ' . ($relPath ?: '(root)'));
        }
        $toProcess = $stillToProcess;
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
    foreach (array_keys($data) as $key) {
        if (!in_array($key, $mediaFiles)) {
            // The file may have moved to an album not scanned yet (folders are walked
            // newest-first, so the destination often comes later). Drop the entry but
            // keep the thumbnail for the adoption pass; the orphan sweep collects
            // whatever nobody claims.
            if (findMoveCandidate($moveIndex, $data[$key]['hash'] ?? null, $key, $data[$key]['filesize'] ?? -1, $data[$key]['mtime'] ?? 0) !== null) {
                unset($data[$key]);
                $dataChanged = true;
                continue;
            }
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
                            // creation_time is UTC. getTimestamp() is an absolute point in
                            // time -- converting the object's zone first would change
                            // nothing; the local wall clock appears when $ts is formatted.
                            $ts = (new DateTime($creationTime, new DateTimeZone('UTC')))->getTimestamp();
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
            // Backfill the move-detection fingerprint for entries written before it existed
            if (empty($data[$name]['hash'])) $data[$name]['hash'] = headHash($srcFile);
            $dataChanged = true;
        }
    }

    if ($toProcess) {
        $foldersWithNewFiles++;
        $totalNewFiles += count($toProcess);

        if (!is_dir($thumbDir)) mkdirShared($thumbDir);
        if (!is_dir($fsDir)) mkdirShared($fsDir);

        // $usedNames was built above, before the adoption pass

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
                            // creation_time is UTC. getTimestamp() is an absolute point in
                            // time -- converting the object's zone first would change
                            // nothing; the local wall clock appears when $ts is formatted.
                            $ts = (new DateTime($creationTime, new DateTimeZone('UTC')))->getTimestamp();
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
                'hash' => headHash($srcFile),
            ];
            // Recognised as a moved file whose thumbnail could not be relocated: keep the
            // backup record so it is not uploaded to Azure again
            if (isset($carryBackup[$name])) {
                $data[$name] = array_merge($data[$name], $carryBackup[$name]);
            }
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
                        $data[$name]['backupBlob'] = $relBlob;
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
            $data[$name]['backupBlob'] = $relBlob;
            $dataChanged = true;
            file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    if ($dataChanged) {
        file_put_contents($dataFile, json_encode(['_version' => $currentVersion] + ($displayName ? ['_displayName' => $displayName] : []) + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // Mirror the folder index to Azure under _index/ so a RAID loss does not take the
    // blob-path -> album mapping with it. Kept out of the Archive tier: these are tiny,
    // must stay readable, and an archived blob cannot be overwritten.
    if ($azureStorage && $backupIndex && $dataChanged) {
        $indexBlob = encodeBlobPath('_index/' . ($relPath ? $relPath . '/' : '') . 'data.json');
        $r = $azureStorage->uploadContent($azureContainer, $indexBlob, file_get_contents($dataFile), 'application/json');
        if (empty($r['success'])) {
            backupLog('FAIL', '_index/' . ($relPath ? $relPath . '/' : '') . 'data.json', '(' . ($r['errorCode'] ?? 'unknown') . ', http ' . ($r['httpCode'] ?? '?') . ')');
        }
    }

    $processedFolders++;
}

// Only after a complete pass: a stopped run may not have adopted moved files yet, and
// sweeping then would throw away thumbnails that are about to find their new album.
writeStatus([
    'state' => 'running',
    'totalFolders' => $totalFolders,
    'processedFolders' => $processedFolders,
    'currentFolder' => 'úklid',
    'foldersWithNewFiles' => $foldersWithNewFiles,
    'totalNewFiles' => $totalNewFiles,
    'adoptedFiles' => $adoptedFiles,
]);

[$sweptFiles, $sweptFolders] = sweepOrphans();

writeStatus([
    'state' => 'done',
    'totalFolders' => $totalFolders,
    'processedFolders' => $processedFolders,
    'currentFolder' => '',
    'foldersWithNewFiles' => $foldersWithNewFiles,
    'totalNewFiles' => $totalNewFiles,
    'adoptedFiles' => $adoptedFiles,
    'sweptFiles' => $sweptFiles,
    'sweptFolders' => $sweptFolders,
]);

if ($adoptedFiles || $sweptFiles || $sweptFolders) {
    moveLog('====', "prevzato $adoptedFiles, smazano $sweptFiles souboru, $sweptFolders slozek");
}

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

// A filename carrying a date is the most reliable source for a video: it is already
// local wall-clock time, so it needs no timezone conversion at all (unlike the UTC
// creation_time from ffprobe). Checked before falling back to the container metadata.
function videoDateFromFilename($filename) {
    $patterns = [
        // Gallery's own convention, written by dateToFilename(): 2026-08-03_20-03-40.mp4
        '/(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/',
        // Android: VID_20251013_130938378.mp4 -> local time 2025-10-13 13:09:38
        '/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/',
    ];
    foreach ($patterns as $re) {
        if (!preg_match($re, $filename, $m)) continue;
        if ($m[1] < 2000 || $m[1] > 2099) continue;
        $ts = strtotime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]");
        if ($ts) return $ts;
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
