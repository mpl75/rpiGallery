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

$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$videoExts = [];
$allExts = array_merge($imageExts, $videoExts);

// --- Crawler control API (AJAX) ---
if (isset($_GET['action']) && !empty($_SESSION['authenticated'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'crawler-start':
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
            file_put_contents(__DIR__ . '/crawler.stop', '1');
            echo json_encode(['ok' => true]);
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
if (isset($_GET['logout'])) {
    session_destroy();
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
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $loginError = true;
    }
}

if (empty($_SESSION['authenticated'])) {
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

// If path points to a file, serve the fullsize preview
if (is_file($fullPath)) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if (!in_array($ext, $imageExts)) {
        http_response_code(403);
        exit;
    }

    $dir = dirname($path);
    $fileName = basename($path);
    $previewDir = $fullsizeFolder . ($dir ? '/' . $dir : '');

    $djPath = $thumbsFolder . ($dir ? '/' . $dir : '') . '/data.json';
    $djData = file_exists($djPath) ? (json_decode(file_get_contents($djPath), true) ?: []) : [];
    $mappedName = $djData[$fileName]['mappedName'] ?? $fileName;

    $previewFile = $previewDir . '/' . $mappedName;
    if (!file_exists($previewFile)) {
        // Fullsize not yet generated, serve original
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
}

// Sort by date taken
usort($mediaFiles, function ($a, $b) use ($data) {
    $dateA = $data[$a]['dateTaken'] ?? '9999';
    $dateB = $data[$b]['dateTaken'] ?? '9999';
    return strcmp($dateA, $dateB);
});

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
    <link rel="stylesheet" href="/rpiGallery/gallery.css">
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
    <a href="?logout" class="logout-link">Odhlásit</a>
</nav>

<!-- Crawler control -->
<div id="crawler-bar" class="crawler-bar">
    <div id="crawler-status"></div>
    <div class="crawler-buttons">
        <button id="btn-start" onclick="crawlerAction('start')">Aktualizovat galerii</button>
        <button id="btn-stop" onclick="crawlerAction('stop')" style="display:none">Zastavit</button>
    </div>
</div>

<?php if ($folders): ?>
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
            // Not yet processed by crawler - show placeholder
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
        $fullUrl = $baseUrl . '/' . encodePath($path ? $path . '/' . $name : $name);
        $mapped = $entry['mappedName'] ?? $name;
        $thumbUrl = $thumbsUrl . '/' . encodePath($path ? $path . '/' . $mapped : $mapped);
    ?>
        <div class="image-card" data-lb-index="<?= $lbIndex ?>" data-full="<?= htmlspecialchars($fullUrl) ?>" data-exif="<?= htmlspecialchars(json_encode($exif, JSON_UNESCAPED_UNICODE)) ?>"<?php if ($owner): ?> data-owner="<?= htmlspecialchars($owner['name']) ?>"<?php endif; ?>>
            <div class="thumb-wrap" onclick="openLightbox(<?= $lbIndex ?>)">
                <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy">
                <?php if ($owner): ?>
                    <span class="owner-badge"><?= htmlspecialchars($owner['initials']) ?></span>
                <?php endif; ?>
            </div>
            <div class="image-info">
                <span class="image-date"><?= htmlspecialchars(formatDate($dateTaken)) ?></span>
                <?php if ($camera): ?>
                    <span class="image-camera"><?= htmlspecialchars($camera) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php
        $lbIndex++;
    endforeach; ?>
</section>
<?php endif; ?>

<?php if (!$folders && !$mediaFiles): ?>
    <p class="empty">Složka je prázdná.</p>
<?php endif; ?>

<!-- Lightbox -->
<div id="lightbox" class="lightbox" onclick="closeLightbox(event)">
    <button class="lb-close" onclick="closeLightbox()">&times;</button>
    <button class="lb-prev" onclick="navigateLightbox(event, -1)">&#10094;</button>
    <button class="lb-next" onclick="navigateLightbox(event, 1)">&#10095;</button>
    <div class="lb-content" onclick="event.stopPropagation()">
        <img id="lb-img" src="" alt="">
        <div id="lb-exif" class="lb-exif"></div>
    </div>
</div>

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
}

function navigateLightbox(e, dir) {
    if (e) e.stopPropagation();
    currentIdx += dir;
    if (currentIdx < 0) currentIdx = cards.length - 1;
    if (currentIdx >= cards.length) currentIdx = 0;
    showImage();
}

function showImage() {
    const card = cards[currentIdx];
    document.getElementById('lb-img').src = card.dataset.full;

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
    el.innerHTML = h;
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

            if (s.running) {
                let text = 'Crawler běží';
                if (s.currentFolder) text += ': ' + s.currentFolder;
                if (s.currentFile) text += ' (' + s.currentFile + ')';
                if (s.processedFolders && s.totalFolders) {
                    text += ' — ' + s.processedFolders + '/' + s.totalFolders + ' složek';
                }
                statusEl.textContent = text;
                btnStart.style.display = 'none';
                btnStop.style.display = '';
                if (!crawlerPolling) {
                    crawlerPolling = setInterval(updateCrawlerStatus, 3000);
                }
            } else {
                if (s.state === 'done') {
                    statusEl.textContent = 'Hotovo — ' + (s.totalNewFiles || 0) + ' nových souborů v ' + (s.foldersWithNewFiles || 0) + ' složkách';
                } else if (s.state === 'stopped') {
                    statusEl.textContent = 'Zastaveno — ' + (s.processedFolders || 0) + '/' + (s.totalFolders || 0) + ' složek';
                } else {
                    statusEl.textContent = '';
                }
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
</script>

</body>
</html>
