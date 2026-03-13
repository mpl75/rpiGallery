<?php
set_time_limit(0);
putenv('LANG=en_US.UTF-8');
setlocale(LC_ALL, 'en_US.UTF-8');

session_start();

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

$rootGallery    = rtrim($config['rootGallery'], '/');
$thumbsFolder   = rtrim($config['thumbnailsFolder'], '/');
$thumbsUrl      = rtrim($config['thumbnailsUrl'], '/');
$fullsizeFolder = rtrim($config['fullsizeFolder'], '/');
$fullsizeUrl    = rtrim($config['fullsizeUrl'], '/');

$mapyApiKey = $config['mapyApiKey'] ?? '';
$thumbInfo = $config['thumbnailInfo'] ?? ['date' => true, 'camera' => true, 'owner' => false];
$showOwnerBadge = $config['showOwnerBadge'] ?? true;

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$videoExts = $config['videoExtensions'] ?? [];
$allExts = array_merge($imageExts, $videoExts);

// --- Shares ---
$sharesFile = __DIR__ . '/shares.json';
function loadShares() {
    global $sharesFile;
    if (!file_exists($sharesFile)) return [];
    $shares = json_decode(file_get_contents($sharesFile), true) ?: [];
    // Purge expired
    $now = time();
    $cleaned = array_filter($shares, fn($s) => $s['expires'] > $now);
    if (count($cleaned) !== count($shares)) {
        file_put_contents($sharesFile, json_encode($cleaned, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    return $cleaned;
}

// --- Handle shared access: /gallery/s/HASH ---
$path = isset($_GET['path']) ? $_GET['path'] : '';
$isSharedAccess = false;
$sharedPath = null;

if (preg_match('#^s/([a-f0-9]+)(/.*)?$#', $path, $m)) {
    $shareHash = $m[1];
    $shares = loadShares();
    if (isset($shares[$shareHash])) {
        $isSharedAccess = true;
        $sharedPath = $shares[$shareHash]['path'];
        // Allow subpath within shared folder
        $subPath = isset($m[2]) ? trim($m[2], '/') : '';
        $_GET['path'] = $sharedPath . ($subPath ? '/' . $subPath : '');
        // Skip auth
    } else {
        http_response_code(404);
        echo "Odkaz vypršel nebo neexistuje.";
        exit;
    }
}

// --- Crawler control API (AJAX) ---
if (isset($_GET['action']) && !empty($_SESSION['authenticated'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'crawler-start':
            if (empty($_SESSION['admin'])) { echo json_encode(['ok' => false]); exit; }
            $pidFile = __DIR__ . '/crawler.pid';
            if (file_exists($pidFile)) {
                $oldPid = (int)file_get_contents($pidFile);
                if ($oldPid && posix_kill($oldPid, 0)) {
                    echo json_encode(['ok' => false, 'msg' => 'Crawler already running']);
                    exit;
                }
            }
            $stopFile = __DIR__ . '/crawler.stop';
            if (file_exists($stopFile)) unlink($stopFile);
            exec('nohup php ' . escapeshellarg(__DIR__ . '/crawler.php') . ' > /dev/null 2>&1 &');
            usleep(300000);
            echo json_encode(['ok' => true]);
            exit;

        case 'crawler-stop':
            if (empty($_SESSION['admin'])) { echo json_encode(['ok' => false]); exit; }
            file_put_contents(__DIR__ . '/crawler.stop', '1');
            echo json_encode(['ok' => true]);
            exit;

        case 'share-create':
            $sharePath = $_GET['folder'] ?? '';
            $sharePath = str_replace('..', '', trim($sharePath, '/'));
            $days = (int)($_GET['days'] ?? 30);
            if ($days < 1) $days = 30;
            $hash = bin2hex(random_bytes(16));
            $shares = loadShares();
            $shares[$hash] = [
                'path' => $sharePath,
                'expires' => time() + ($days * 86400),
                'created' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($sharesFile, json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $url = 'https://' . $_SERVER['HTTP_HOST'] . '/gallery/s/' . $hash;
            echo json_encode(['ok' => true, 'url' => $url, 'days' => $days]);
            exit;

        case 'crawler-status':
            $statusFile = __DIR__ . '/crawler.json';
            $pidFile = __DIR__ . '/crawler.pid';
            $running = false;
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                $running = $pid && posix_kill($pid, 0);
            }
            $status = [];
            if (file_exists($statusFile)) {
                $status = json_decode(file_get_contents($statusFile), true) ?: [];
            }
            $status['running'] = $running;
            echo json_encode($status, JSON_UNESCAPED_UNICODE);
            exit;
    }
}

// --- Authentication ---
// Auth token: HMAC-signed cookie, independent of PHP sessions
function makeAuthToken($user, $admin, $config) {
    $secret = $config['users'][0]['password']; // use first bcrypt hash as HMAC key
    $payload = $user . '|' . ($admin ? '1' : '0');
    $sig = hash_hmac('sha256', $payload, $secret);
    return base64_encode($payload . '|' . $sig);
}

function verifyAuthToken($token, $config) {
    $decoded = base64_decode($token, true);
    if (!$decoded) return null;
    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return null;
    [$user, $admin, $sig] = $parts;
    $secret = $config['users'][0]['password'];
    $expected = hash_hmac('sha256', $user . '|' . $admin, $secret);
    if (!hash_equals($expected, $sig)) return null;
    return ['user' => $user, 'admin' => $admin === '1'];
}

if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('auth', '', time() - 3600, '/', '', true, true);
    header('Location: /gallery');
    exit;
}

if (isset($_POST['login_user'], $_POST['login_pass'])) {
    $authenticated = false;
    foreach ($config['users'] as $u) {
        if ($_POST['login_user'] === $u['user'] && password_verify($_POST['login_pass'], $u['password'])) {
            $authenticated = true;
            break;
        }
    }
    if ($authenticated) {
        $_SESSION['authenticated'] = true;
        $_SESSION['admin'] = !empty($u['admin']);
        $token = makeAuthToken($u['user'], !empty($u['admin']), $config);
        setcookie('auth', $token, time() + 365 * 86400, '/', '', true, true);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $loginError = true;
    }
}

// Restore auth from cookie if session expired
if (empty($_SESSION['authenticated']) && isset($_COOKIE['auth'])) {
    $authData = verifyAuthToken($_COOKIE['auth'], $config);
    if ($authData) {
        $_SESSION['authenticated'] = true;
        $_SESSION['admin'] = $authData['admin'];
    }
}

if (!$isSharedAccess && empty($_SESSION['authenticated'])) {
    showLoginForm($loginError ?? false);
    exit;
}

// --- Path handling ---
$path = isset($_GET['path']) ? $_GET['path'] : '';
$path = str_replace('..', '', $path);
$path = trim($path, '/');

function encodePath($p) {
    if (!$p) return '';
    return implode('/', array_map('rawurlencode', explode('/', $p)));
}

function formatDate($dt) {
    if (!$dt) return '';
    $ts = strtotime(str_replace(':', '-', substr($dt, 0, 10)) . substr($dt, 10));
    if (!$ts) { $ts = strtotime($dt); }
    if (!$ts) return $dt;
    return (int)date('j', $ts) . '. ' . (int)date('n', $ts) . '. ' . date('Y', $ts) . ' ' . date('H:i:s', $ts);
}

$fullPath = $rootGallery . ($path ? '/' . $path : '');
$baseUrl = '/gallery';
$shareBaseUrl = $isSharedAccess ? '/gallery/s/' . $shareHash : null;

// If path points to a file, serve the fullsize preview or video
if (is_file($fullPath)) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

    // Serve video original directly
    if (in_array($ext, $videoExts)) {
        session_write_close(); // Release session lock for concurrent range requests
        $mimeTypes = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        $size = filesize($fullPath);

        // Support range requests for video seeking
        if (isset($_SERVER['HTTP_RANGE'])) {
            preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
            $start = (int)$matches[1];
            $end = $matches[2] !== '' ? (int)$matches[2] : $size - 1;
            $length = $end - $start + 1;
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $start-$end/$size");
            header("Content-Length: $length");
            header("Content-Type: $mime");
            header('Accept-Ranges: bytes');
            $fh = fopen($fullPath, 'rb');
            fseek($fh, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($fh)) {
                $chunk = min($remaining, 65536);
                echo fread($fh, $chunk);
                $remaining -= $chunk;
                flush();
            }
            fclose($fh);
        } else {
            header("Content-Type: $mime");
            header("Content-Length: $size");
            header('Accept-Ranges: bytes');
            header('Cache-Control: public, max-age=86400');
            readfile($fullPath);
        }
        exit;
    }

    if (!in_array($ext, $imageExts)) {
        http_response_code(403);
        exit;
    }

    $dir = dirname($path);
    $fileName = basename($path);
    $previewDir = $fullsizeFolder . ($dir ? '/' . $dir : '');

    $djPath = $thumbsFolder . ($dir ? '/' . $dir : '') . '/data.json';
    $djData = file_exists($djPath) ? (json_decode(file_get_contents($djPath), true) ?: []) : [];
    unset($djData['_version']);
    $mappedName = $djData[$fileName]['mappedName'] ?? $fileName;

    $previewFile = $previewDir . '/' . $mappedName;
    if (!file_exists($previewFile)) {
        header('Content-Type: image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($previewFile));
    header('Cache-Control: public, max-age=86400');
    readfile($previewFile);
    exit;
}

if (!is_dir($fullPath)) {
    http_response_code(404);
    echo "Složka nenalezena: " . htmlspecialchars($path);
    exit;
}

$thumbPath = $thumbsFolder . ($path ? '/' . $path : '');
$continuousView = isset($_GET['view']) && $_GET['view'] === 'continuous';

// Scan directory
$folders = [];
$mediaFiles = [];

$entries = scandir($fullPath);
foreach ($entries as $entry) {
    if ($entry[0] === '.') continue;
    $entryPath = $fullPath . '/' . $entry;

    if (is_dir($entryPath)) {
        $folders[] = $entry;
    } else {
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, $allExts)) {
            $mediaFiles[] = $entry;
        }
    }
}

sort($folders, SORT_LOCALE_STRING);

// Load data.json (read-only, crawler maintains it)
$dataFile = $thumbPath . '/data.json';
$data = [];
if (file_exists($dataFile)) {
    $data = json_decode(file_get_contents($dataFile), true) ?: [];
    unset($data['_version']);
}

// Sort by date taken
usort($mediaFiles, function ($a, $b) use ($data) {
    $dateA = $data[$a]['dateTaken'] ?? '9999';
    $dateB = $data[$b]['dateTaken'] ?? '9999';
    return strcmp($dateA, $dateB);
});

// Continuous view: recursively load all nested albums
$continuousAlbums = [];
if ($continuousView && $folders && !$mediaFiles) {
    function scanAlbums($srcDir, $relPath, &$results, $thumbsFolder, $allExts) {
        $entries = @scandir($srcDir);
        if (!$entries) return;

        $subDirs = [];
        $mediaFiles = [];
        foreach ($entries as $e) {
            if ($e[0] === '.') continue;
            if (is_dir($srcDir . '/' . $e)) {
                $subDirs[] = $e;
            } else {
                $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
                if (in_array($ext, $allExts)) {
                    $mediaFiles[] = $e;
                }
            }
        }

        if ($mediaFiles) {
            // This is a leaf album - load its data and add
            $thumbPath = $thumbsFolder . '/' . $relPath;
            $dataFile = $thumbPath . '/data.json';
            $data = [];
            if (file_exists($dataFile)) {
                $data = json_decode(file_get_contents($dataFile), true) ?: [];
                unset($data['_version']);
            }

            usort($mediaFiles, function ($a, $b) use ($data) {
                $dateA = $data[$a]['dateTaken'] ?? '9999';
                $dateB = $data[$b]['dateTaken'] ?? '9999';
                return strcmp($dateA, $dateB);
            });

            $results[] = [
                'name' => basename($relPath),
                'relPath' => $relPath,
                'media' => $mediaFiles,
                'data' => $data,
            ];
        }

        if ($subDirs) {
            sort($subDirs, SORT_LOCALE_STRING);
            foreach ($subDirs as $sub) {
                scanAlbums($srcDir . '/' . $sub, $relPath . '/' . $sub, $results, $thumbsFolder, $allExts);
            }
        }
    }

    foreach ($folders as $folder) {
        scanAlbums(
            $fullPath . '/' . $folder,
            ($path ? $path . '/' : '') . $folder,
            $continuousAlbums,
            $thumbsFolder,
            $allExts
        );
    }
}

// Build breadcrumb
$breadcrumbs = [];
$breadcrumbs[] = ['name' => 'Galerie', 'url' => $baseUrl];
if ($path) {
    $parts = explode('/', $path);
    $cumulative = '';
    foreach ($parts as $part) {
        $cumulative .= ($cumulative ? '/' : '') . $part;
        $breadcrumbs[] = ['name' => $part, 'url' => $baseUrl . '/' . encodePath($cumulative)];
    }
}

// Find sibling folders (prev/next)
$prevFolder = null;
$nextFolder = null;
if ($path) {
    $currentName = basename($path);
    $parentPath = dirname($path);
    if ($parentPath === '.') $parentPath = '';
    $parentFullPath = $rootGallery . ($parentPath ? '/' . $parentPath : '');

    $siblings = [];
    $parentEntries = @scandir($parentFullPath);
    if ($parentEntries) {
        foreach ($parentEntries as $e) {
            if ($e[0] === '.') continue;
            if (is_dir($parentFullPath . '/' . $e)) {
                $siblings[] = $e;
            }
        }
        sort($siblings, SORT_LOCALE_STRING);
        $idx = array_search($currentName, $siblings);
        if ($idx !== false) {
            $viewSuffix = $continuousView ? '?view=continuous' : '';
            if ($idx > 0) {
                $prevName = $siblings[$idx - 1];
                $prevFolder = [
                    'name' => $prevName,
                    'url' => $baseUrl . '/' . encodePath($parentPath ? $parentPath . '/' . $prevName : $prevName) . $viewSuffix,
                ];
            }
            if ($idx < count($siblings) - 1) {
                $nextName = $siblings[$idx + 1];
                $nextFolder = [
                    'name' => $nextName,
                    'url' => $baseUrl . '/' . encodePath($parentPath ? $parentPath . '/' . $nextName : $nextName) . $viewSuffix,
                ];
            }
        }
    }
}

// Check crawler status
$crawlerRunning = false;
$pidFile = __DIR__ . '/crawler.pid';
if (file_exists($pidFile)) {
    $pid = (int)file_get_contents($pidFile);
    $crawlerRunning = $pid && posix_kill($pid, 0);
}

// Count pending files in this folder
$pendingCount = 0;
foreach ($mediaFiles as $name) {
    if (!isset($data[$name])) $pendingCount++;
}

// --- Functions ---
function showLoginForm($error = false) {
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Galerie — Přihlášení</title>
    <link rel="icon" type="image/svg+xml" href="/rpiGallery/favicon.svg">
    <link rel="manifest" href="/rpiGallery/manifest.json">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="/rpiGallery/gallery.css">
</head>
<body>
<div class="login-wrap">
    <form method="post" class="login-form">
        <h2>Galerie</h2>
        <?php if ($error): ?>
            <div class="login-error">Nesprávné přihlašovací údaje</div>
        <?php endif; ?>
        <input type="text" name="login_user" placeholder="E-mail" autocomplete="username" required>
        <input type="password" name="login_pass" placeholder="Heslo" autocomplete="current-password" required>
        <label class="remember-label"><input type="checkbox" name="remember" value="1" checked> Pamatuj si mě</label>
        <button type="submit">Přihlásit</button>
    </form>
</div>
</body>
</html>
<?php
}

// --- Render page ---
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Galerie<?= $path ? ' — ' . htmlspecialchars($path) : '' ?></title>
    <link rel="icon" type="image/svg+xml" href="/rpiGallery/favicon.svg">
    <link rel="manifest" href="/rpiGallery/manifest.json">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="/rpiGallery/gallery.css">
<?php if ($mapyApiKey): ?>
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4/dist/maplibre-gl.css">
    <script src="https://unpkg.com/maplibre-gl@4/dist/maplibre-gl.js"></script>
<?php endif; ?>
</head>
<body>

<nav class="breadcrumb">
    <?php foreach ($breadcrumbs as $i => $bc): ?>
        <?php if ($i > 0): ?> / <?php endif; ?>
        <?php if ($i < count($breadcrumbs) - 1): ?>
            <a href="<?= htmlspecialchars($bc['url']) ?>"><?= htmlspecialchars($bc['name']) ?></a>
        <?php else: ?>
            <span class="current"><?= htmlspecialchars($bc['name']) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
<?php if (!$isSharedAccess): ?>
    <span class="nav-right">
        <span id="crawler-status" class="crawler-status-inline"></span>
        <?php if (!empty($_SESSION['admin'])): ?>
        <button id="btn-start" onclick="crawlerAction('start')" class="nav-link-btn">Aktualizovat</button>
        <button id="btn-stop" onclick="crawlerAction('stop')" class="nav-link-btn nav-link-btn-stop" style="display:none">Zastavit</button>
        <?php endif; ?>
        <a href="?logout" class="logout-link">Odhlásit</a>
    </span>
<?php endif; ?>
</nav>

<div class="toolbar">
    <?php if ($folders && !$mediaFiles): ?>
    <div class="view-toggle">
        <a href="<?= htmlspecialchars($baseUrl . '/' . encodePath($path)) ?>" class="view-toggle-option<?= !$continuousView ? ' active' : '' ?>">Složky</a>
        <a href="<?= htmlspecialchars($baseUrl . '/' . encodePath($path)) ?>?view=continuous" class="view-toggle-option<?= $continuousView ? ' active' : '' ?>">Přehled</a>
    </div>
    <?php endif; ?>
    <span class="toolbar-right">
        <?php if ($mapyApiKey): ?>
            <button onclick="openMap()" class="btn-share" id="btn-map" style="display:none">Mapa</button>
        <?php endif; ?>
<?php if (!$isSharedAccess): ?>
        <?php if ($path && $mediaFiles): ?>
            <button onclick="shareFolder()" class="btn-share">Sdílet</button>
        <?php endif; ?>
<?php endif; ?>
    </span>
<?php if (!$isSharedAccess): ?>
<!-- Share dialog -->
<div id="share-dialog" class="share-dialog" style="display:none">
    <div class="share-dialog-content">
        <p>Sdílet složku <strong><?= htmlspecialchars(basename($path)) ?></strong></p>
        <div class="share-days">
            <button onclick="createShare(7)" class="share-days-btn">7 dní</button>
            <button onclick="createShare(30)" class="share-days-btn">30 dní</button>
            <button onclick="createShare(90)" class="share-days-btn">90 dní</button>
        </div>
        <div id="share-result" style="display:none">
            <input type="text" id="share-url" readonly>
            <button onclick="copyShareUrl()">Kopírovat</button>
        </div>
        <button onclick="document.getElementById('share-dialog').style.display='none'" class="share-close">Zavřít</button>
    </div>
</div>
<?php endif; ?>
</div>

<?php if ($continuousView && $continuousAlbums): ?>
<?php
    $lbIndex = 0;
    foreach ($continuousAlbums as $album):
        $albumUrl = $baseUrl . '/' . encodePath($album['relPath']);
?>
<section class="continuous-album">
<?php
        $albumDisplayName = (($pos = strpos($album['name'], ' ')) !== false) ? substr($album['name'], $pos + 1) : $album['name'];
        $albumDate = substr($album['name'], 0, 10);
?>
    <a href="<?= htmlspecialchars($albumUrl) ?>" class="album-header"><?= htmlspecialchars($albumDisplayName) ?><span class="album-date"><?= htmlspecialchars($albumDate) ?></span></a>
    <div class="images">
    <?php foreach ($album['media'] as $name):
        $entry = $album['data'][$name] ?? null;
        if (!$entry) continue;
        $exif = $entry['exif'] ?? [];
        $dateTaken = $entry['dateTaken'] ?? '';
        $camera = $exif['Camera'] ?? '';
        $owner = $entry['owner'] ?? null;
        $isVideo = ($entry['type'] ?? 'image') === 'video';
        $fullUrl = $baseUrl . '/' . encodePath($album['relPath'] . '/' . $name);
        $mapped = $entry['mappedName'] ?? $name;
        $thumbUrl = $thumbsUrl . '/' . encodePath($album['relPath'] . '/' . $mapped);
    ?>
        <div class="image-card" data-lb-index="<?= $lbIndex ?>" data-full="<?= htmlspecialchars($fullUrl) ?>" data-type="<?= $isVideo ? 'video' : 'image' ?>" data-exif="<?= htmlspecialchars(json_encode($exif, JSON_UNESCAPED_UNICODE)) ?>"<?php if ($owner): ?> data-owner="<?= htmlspecialchars($owner['name']) ?>"<?php endif; ?><?php if (!empty($exif['gps'])): ?> data-gps="<?= $exif['gps']['lat'] ?>,<?= $exif['gps']['lon'] ?>"<?php endif; ?><?php if (!empty($entry['filesize'])): ?> data-filesize="<?= $entry['filesize'] ?>"<?php endif; ?>>
            <div class="thumb-wrap" onclick="openLightbox(<?= $lbIndex ?>)">
                <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy">
            </div>
            <?php if ($isVideo): ?>
                <span class="video-badge">&#9654;</span>
            <?php endif; ?>
            <?php if ($showOwnerBadge && $owner): ?>
                <span class="owner-badge"><?= htmlspecialchars($owner['initials']) ?></span>
            <?php endif; ?>
            <div class="image-info">
                <?php if (!empty($thumbInfo['date']) && $dateTaken): ?>
                    <span class="image-date"><?= htmlspecialchars(formatDate($dateTaken)) ?></span>
                <?php endif; ?>
                <?php if (!empty($thumbInfo['camera']) && $camera): ?>
                    <span class="image-camera"><?= htmlspecialchars($camera) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php
        $lbIndex++;
    endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<?php elseif ($folders && !$continuousView): ?>
<section class="folders">
    <?php foreach ($folders as $folder): ?>
        <a class="folder" href="<?= $baseUrl . '/' . encodePath($path ? $path . '/' . $folder : $folder) ?>">
            <div class="folder-icon">📁</div>
            <div class="folder-name"><?= htmlspecialchars($folder) ?></div>
        </a>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($pendingCount > 0 && !$mediaFiles): ?>
    <p class="empty">Složka čeká na zpracování crawlerem.</p>
<?php endif; ?>

<?php if ($mediaFiles): ?>
<section class="images">
    <?php
    $lbIndex = 0;
    foreach ($mediaFiles as $name):
        $entry = $data[$name] ?? null;
        if (!$entry) {
    ?>
        <div class="image-card image-card-pending">
            <div class="thumb-wrap">
                <div class="thumb-pending">Čeká na zpracování</div>
            </div>
            <div class="image-info">
                <span class="image-name"><?= htmlspecialchars($name) ?></span>
            </div>
        </div>
    <?php
            continue;
        }
        $exif = $entry['exif'] ?? [];
        $dateTaken = $entry['dateTaken'] ?? '';
        $camera = $exif['Camera'] ?? '';
        $owner = $entry['owner'] ?? null;
        $isVideo = ($entry['type'] ?? 'image') === 'video';
        $fullUrl = ($shareBaseUrl ?? $baseUrl) . '/' . encodePath($isSharedAccess ? $name : ($path ? $path . '/' . $name : $name));
        $mapped = $entry['mappedName'] ?? $name;
        $thumbUrl = $thumbsUrl . '/' . encodePath($path ? $path . '/' . $mapped : $mapped);
    ?>
        <div class="image-card" data-lb-index="<?= $lbIndex ?>" data-full="<?= htmlspecialchars($fullUrl) ?>" data-type="<?= $isVideo ? 'video' : 'image' ?>" data-exif="<?= htmlspecialchars(json_encode($exif, JSON_UNESCAPED_UNICODE)) ?>"<?php if ($owner): ?> data-owner="<?= htmlspecialchars($owner['name']) ?>"<?php endif; ?><?php if (!empty($exif['gps'])): ?> data-gps="<?= $exif['gps']['lat'] ?>,<?= $exif['gps']['lon'] ?>"<?php endif; ?><?php if (!empty($entry['filesize'])): ?> data-filesize="<?= $entry['filesize'] ?>"<?php endif; ?>>
            <div class="thumb-wrap" onclick="openLightbox(<?= $lbIndex ?>)">
                <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy">
            </div>
            <?php if ($isVideo): ?>
                <span class="video-badge">&#9654;</span>
            <?php endif; ?>
            <?php if ($showOwnerBadge && $owner): ?>
                <span class="owner-badge"><?= htmlspecialchars($owner['initials']) ?></span>
            <?php endif; ?>
            <div class="image-info">
                <?php if (!empty($thumbInfo['date']) && $dateTaken): ?>
                    <span class="image-date"><?= htmlspecialchars(formatDate($dateTaken)) ?></span>
                <?php endif; ?>
                <?php if (!empty($thumbInfo['camera']) && $camera): ?>
                    <span class="image-camera"><?= htmlspecialchars($camera) ?></span>
                <?php endif; ?>
                <?php if (!empty($thumbInfo['owner']) && $owner): ?>
                    <span class="image-camera"><?= htmlspecialchars($owner['name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php
        $lbIndex++;
    endforeach; ?>
</section>
<?php endif; ?>

<?php if (!$isSharedAccess && ($prevFolder || $nextFolder)): ?>
<nav class="folder-nav">
    <?php if ($prevFolder): ?>
        <a href="<?= htmlspecialchars($prevFolder['url']) ?>" class="folder-nav-link folder-nav-prev">&laquo; <?= htmlspecialchars($prevFolder['name']) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <?php if ($nextFolder): ?>
        <a href="<?= htmlspecialchars($nextFolder['url']) ?>" class="folder-nav-link folder-nav-next"><?= htmlspecialchars($nextFolder['name']) ?> &raquo;</a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<?php if (!$folders && !$mediaFiles): ?>
    <p class="empty">Složka je prázdná.</p>
<?php endif; ?>

<?php if ($mapyApiKey): ?>
<!-- Map overlay -->
<div id="map-overlay" class="map-overlay" style="display:none">
    <button class="map-close" onclick="closeMap()">&times;</button>
    <div id="map-container"></div>
</div>
<?php endif; ?>

<!-- Lightbox -->
<div id="lightbox" class="lightbox" onclick="closeLightbox(event)">
    <button class="lb-close" onclick="closeLightbox()">&times;</button>
    <button class="lb-prev" onclick="navigateLightbox(event, -1)">&#10094;</button>
    <button class="lb-next" onclick="navigateLightbox(event, 1)">&#10095;</button>
    <div class="lb-content" onclick="event.stopPropagation()">
        <img id="lb-img" src="" alt="">
        <video id="lb-video" controls preload="metadata" style="display:none"></video>
        <div id="lb-video-loading" class="lb-video-loading" style="display:none"></div>
        <div id="lb-exif" class="lb-exif"></div>
    </div>
</div>
<?php if ($mapyApiKey): ?>
<div id="lb-minimap-wrap" class="lb-minimap-wrap" style="display:none;cursor:pointer" onclick="closeLightbox();openMap()"><div id="lb-minimap" class="lb-minimap"></div></div>
<?php endif; ?>

<script>
// Lightbox
const cards = document.querySelectorAll('.image-card[data-lb-index]');
let currentIdx = 0;
let touchStartX = 0;

function openLightbox(idx) {
    currentIdx = idx;
    showImage();
    document.getElementById('lightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox(e) {
    if (e && e.target !== document.getElementById('lightbox') && !e.target.classList.contains('lb-close')) return;
    document.getElementById('lightbox').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('lb-img').src = '';
    const vid = document.getElementById('lb-video');
    vid.pause();
    vid.removeAttribute('src');
    vid.load();
    vid.style.display = 'none';
    document.getElementById('lb-img').style.display = '';
    document.getElementById('lb-video-loading').style.display = 'none';
    const mmWrap = document.getElementById('lb-minimap-wrap');
    if (mmWrap) mmWrap.style.display = 'none';
}

const prevFolderUrl = <?= json_encode(!$isSharedAccess && $prevFolder ? $prevFolder['url'] : null) ?>;
const nextFolderUrl = <?= json_encode(!$isSharedAccess && $nextFolder ? $nextFolder['url'] : null) ?>;

function navigateLightbox(e, dir) {
    if (e) e.stopPropagation();
    currentIdx += dir;
    if (currentIdx < 0) {
        if (prevFolderUrl) { window.location.href = prevFolderUrl; return; }
        currentIdx = cards.length - 1;
    }
    if (currentIdx >= cards.length) {
        if (nextFolderUrl) { window.location.href = nextFolderUrl; return; }
        currentIdx = 0;
    }
    showImage();
}

function showImage() {
    const card = cards[currentIdx];
    const img = document.getElementById('lb-img');
    const vid = document.getElementById('lb-video');
    const loading = document.getElementById('lb-video-loading');
    const isVideo = card.dataset.type === 'video';

    // Release previous video connection
    vid.pause();
    vid.removeAttribute('src');
    vid.load();

    if (isVideo) {
        const thumbSrc = card.querySelector('img')?.src || '';
        const filesize = parseInt(card.dataset.filesize || '0');
        // Show thumbnail as poster while video loads
        img.src = thumbSrc;
        img.style.display = '';
        loading.style.display = '';
        loading.textContent = '';
        vid.style.display = 'none';
        vid.poster = thumbSrc;
        vid.preload = 'auto';
        vid.src = card.dataset.full;

        function onProgress() {
            if (vid.buffered.length > 0 && vid.duration) {
                const pct = Math.round((vid.buffered.end(0) / vid.duration) * 100);
                loading.textContent = pct + '%';
            } else if (filesize) {
                loading.textContent = Math.round(filesize / 1048576) + ' MB';
            }
        }
        vid.addEventListener('progress', onProgress);

        function onCanPlay() {
            vid.removeEventListener('canplay', onCanPlay);
            vid.removeEventListener('progress', onProgress);
            img.style.display = 'none';
            img.src = '';
            loading.style.display = 'none';
            vid.style.display = '';
            vid.play().catch(() => {});
        }
        vid.addEventListener('canplay', onCanPlay);
    } else {
        vid.style.display = 'none';
        loading.style.display = 'none';
        img.src = card.dataset.full;
        img.style.display = '';
    }

    const exif = JSON.parse(card.dataset.exif);
    const el = document.getElementById('lb-exif');
    let h = '';
    if (exif.DateTimeOriginal) {
        let d = exif.DateTimeOriginal;
        try {
            let ts = new Date(d.replace(/^(\d{4}):(\d{2}):(\d{2})/, '$1-$2-$3'));
            if (!isNaN(ts)) {
                h += '<span>' + ts.getDate() + '. ' + (ts.getMonth()+1) + '. ' + ts.getFullYear() + ' ' +
                    String(ts.getHours()).padStart(2,'0') + ':' + String(ts.getMinutes()).padStart(2,'0') + ':' +
                    String(ts.getSeconds()).padStart(2,'0') + '</span>';
            } else {
                h += '<span>' + d + '</span>';
            }
        } catch(e) { h += '<span>' + d + '</span>'; }
    }
    if (exif.Camera) h += '<span>' + exif.Camera + '</span>';
    const owner = card.dataset.owner;
    if (owner) h += '<span>' + owner + '</span>';
    const fs = card.dataset.filesize;
    if (fs && card.dataset.type === 'video') {
        const mb = (parseInt(fs) / 1048576).toFixed(0);
        h += '<span>' + mb + ' MB</span>';
    }
    el.innerHTML = h;

    // Minimap
    updateMinimap(card.dataset.gps);
}

// Keyboard
document.addEventListener('keydown', function(e) {
    if (!document.getElementById('lightbox').classList.contains('active')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') navigateLightbox(null, -1);
    if (e.key === 'ArrowRight') navigateLightbox(null, 1);
});

// Touch swipe
const lb = document.getElementById('lightbox');
lb.addEventListener('touchstart', function(e) {
    touchStartX = e.changedTouches[0].screenX;
}, {passive: true});

lb.addEventListener('touchend', function(e) {
    const diff = touchStartX - e.changedTouches[0].screenX;
    if (Math.abs(diff) > 50) {
        navigateLightbox(null, diff > 0 ? 1 : -1);
    }
}, {passive: true});

// Crawler control
let crawlerPolling = null;

function crawlerAction(action) {
    fetch('?action=crawler-' + action)
        .then(r => r.json())
        .then(d => { updateCrawlerStatus(); });
}

function updateCrawlerStatus() {
    fetch('?action=crawler-status')
        .then(r => r.json())
        .then(s => {
            const statusEl = document.getElementById('crawler-status');
            const btnStart = document.getElementById('btn-start');
            const btnStop = document.getElementById('btn-stop');
            if (!btnStart) return;

            if (s.running) {
                let text = 'Crawler: ';
                if (s.processedFolders && s.totalFolders) {
                    text += s.processedFolders + '/' + s.totalFolders;
                } else {
                    text += 'běží';
                }
                statusEl.textContent = text;
                btnStart.style.display = 'none';
                btnStop.style.display = '';
                if (!crawlerPolling) {
                    crawlerPolling = setInterval(updateCrawlerStatus, 3000);
                }
            } else {
                statusEl.textContent = '';
                btnStart.style.display = '';
                btnStop.style.display = 'none';
                if (crawlerPolling) {
                    clearInterval(crawlerPolling);
                    crawlerPolling = null;
                }
            }
        });
}

// Check on load
updateCrawlerStatus();

// Share
function shareFolder() {
    document.getElementById('share-dialog').style.display = 'flex';
    document.getElementById('share-result').style.display = 'none';
}

function createShare(days) {
    fetch('?action=share-create&folder=<?= rawurlencode($path) ?>&days=' + days)
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                document.getElementById('share-url').value = d.url;
                document.getElementById('share-result').style.display = 'flex';
            }
        });
}

function copyShareUrl() {
    const input = document.getElementById('share-url');
    input.select();
    navigator.clipboard.writeText(input.value);
}

<?php if ($mapyApiKey): ?>
// Minimap in lightbox
let minimapInstance = null;
let minimapMarker = null;
const minimapApiKey = <?= json_encode($mapyApiKey) ?>;

function updateMinimap(gpsStr) {
    const wrap = document.getElementById('lb-minimap-wrap');
    if (!wrap) return;

    if (!gpsStr) {
        wrap.style.display = 'none';
        return;
    }

    const [lat, lon] = gpsStr.split(',').map(Number);
    wrap.style.display = '';

    if (minimapInstance) {
        minimapInstance.jumpTo({center: [lon, lat], zoom: 13});
        minimapMarker.setLngLat([lon, lat]);
        setTimeout(() => minimapInstance.resize(), 50);
    } else {
        setTimeout(() => {
            minimapInstance = new maplibregl.Map({
                container: 'lb-minimap',
                style: {
                    version: 8,
                    sources: {
                        'mapy': {
                            type: 'raster',
                            url: 'https://api.mapy.cz/v1/maptiles/outdoor/tiles.json?apikey=' + encodeURIComponent(minimapApiKey),
                            tileSize: 256
                        }
                    },
                    layers: [{id: 'mapy-tiles', type: 'raster', source: 'mapy'}]
                },
                center: [lon, lat],
                zoom: 13,
                attributionControl: false,
                interactive: false,
                transformRequest: (url) => {
                    if (url.includes('api.mapy.cz')) {
                        return { url, headers: { 'X-Mapy-Api-Key': minimapApiKey } };
                    }
                }
            });
            minimapMarker = new maplibregl.Marker({color: '#7eb8da'})
                .setLngLat([lon, lat])
                .addTo(minimapInstance);
        }, 100);
    }
}

// Map
let mapInstance = null;

// Collect GPS points from image cards
const gpsPoints = [];
cards.forEach((card, idx) => {
    if (card.dataset.gps) {
        const [lat, lon] = card.dataset.gps.split(',').map(Number);
        gpsPoints.push({lat, lon, idx, thumb: card.querySelector('img')?.src || ''});
    }
});

// Show map button only if there are GPS points
if (gpsPoints.length > 0) {
    const btnMap = document.getElementById('btn-map');
    if (btnMap) btnMap.style.display = '';
}

function openMap() {
    const overlay = document.getElementById('map-overlay');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    if (mapInstance) {
        mapInstance.resize();
        return;
    }

    // Calculate bounds
    let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
    gpsPoints.forEach(p => {
        if (p.lat < minLat) minLat = p.lat;
        if (p.lat > maxLat) maxLat = p.lat;
        if (p.lon < minLon) minLon = p.lon;
        if (p.lon > maxLon) maxLon = p.lon;
    });

    const centerLat = (minLat + maxLat) / 2;
    const centerLon = (minLon + maxLon) / 2;

    const apiKey = <?= json_encode($mapyApiKey) ?>;

    mapInstance = new maplibregl.Map({
        container: 'map-container',
        style: {
            version: 8,
            sources: {
                'mapy': {
                    type: 'raster',
                    url: 'https://api.mapy.cz/v1/maptiles/outdoor/tiles.json?apikey=' + encodeURIComponent(apiKey),
                    tileSize: 256
                }
            },
            layers: [{
                id: 'mapy-tiles',
                type: 'raster',
                source: 'mapy'
            }]
        },
        center: [centerLon, centerLat],
        zoom: 12,
        transformRequest: (url) => {
            if (url.includes('api.mapy.cz')) {
                return { url, headers: { 'X-Mapy-Api-Key': apiKey } };
            }
        }
    });

    mapInstance.addControl(new maplibregl.NavigationControl(), 'top-right');

    // Fit bounds if multiple points spread out
    if (maxLat - minLat > 0.001 || maxLon - minLon > 0.001) {
        mapInstance.fitBounds([[minLon, minLat], [maxLon, maxLat]], {padding: 60, maxZoom: 16});
    }

    // Add markers
    gpsPoints.forEach(p => {
        const el = document.createElement('div');
        el.className = 'map-marker';
        if (p.thumb) {
            el.style.backgroundImage = 'url(' + p.thumb + ')';
        }
        el.addEventListener('click', () => {
            closeMap();
            openLightbox(p.idx);
        });
        new maplibregl.Marker({element: el})
            .setLngLat([p.lon, p.lat])
            .addTo(mapInstance);
    });
}

function closeMap() {
    document.getElementById('map-overlay').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('map-overlay').style.display !== 'none') {
        closeMap();
    }
});
<?php endif; ?>
</script>

</body>
</html>
