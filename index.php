<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$videos = loadVideos();
$settings = loadSettings();
$videoMuted = (string)($settings['video_muted'] ?? '1') === '1';

$logoUrl = '';
if ((string)($settings['logo_filename'] ?? '') !== '') {
    $candidate = UPLOAD_DIR . '/' . $settings['logo_filename'];
    if (is_file($candidate)) {
        $logoUrl = 'uploads/' . $settings['logo_filename'];
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TV Informasi RS</title>
    <style>
        :root {
            --bg: #00121a;
            --text: #e9f7ff;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: radial-gradient(circle at top right, #00384d, var(--bg) 60%);
            color: var(--text);
            height: 100vh;
            overflow: hidden;
        }

        .screen {
            width: 100vw;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: #000;
        }

        video {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #000;
        }

        .message {
            text-align: center;
            font-size: 2rem;
            opacity: 0.9;
            padding: 2rem;
        }

        .badge {
            position: absolute;
            right: 0.75rem;
            top: 0.75rem;
            font-size: 0.8rem;
            background: rgba(0, 0, 0, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0.3rem 0.5rem;
            border-radius: 8px;
            z-index: 6;
        }

        .admin-link {
            position: absolute;
            right: 0.75rem;
            bottom: 3.3rem;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            color: #fff;
            text-decoration: none;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.14);
            opacity: 0.45;
            z-index: 6;
            transition: opacity 0.2s ease;
        }

        .admin-link:hover,
        .admin-link:focus {
            opacity: 0.85;
        }

        .fullscreen-link {
            position: absolute;
            right: 0.75rem;
            bottom: 5.75rem;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            color: #fff;
            text-decoration: none;
            font-size: 0.92rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.14);
            opacity: 0.45;
            z-index: 6;
            transition: opacity 0.2s ease;
        }

        .fullscreen-link:hover,
        .fullscreen-link:focus {
            opacity: 0.85;
        }

        .logo-wrap {
            position: absolute;
            left: 0.75rem;
            top: 0.75rem;
            z-index: 6;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 0.28rem 0.45rem;
        }

        .logo-wrap img {
            display: block;
            height: 52px;
            max-width: 180px;
            object-fit: contain;
        }

        .ticker {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.72);
            border-top: 1px solid rgba(255, 255, 255, 0.22);
            overflow: hidden;
            z-index: 6;
            height: 40px;
            display: flex;
            align-items: center;
        }

        .ticker-inner {
            display: inline-flex;
            white-space: nowrap;
            animation: marquee 18s linear infinite;
            will-change: transform;
        }

        .ticker-item {
            display: inline-block;
            padding-right: 3rem;
            font-size: 1.02rem;
            font-weight: 600;
            color: #fff;
        }

        @keyframes marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        @media (max-width: 720px) {
            .message { font-size: 1.2rem; }
            .logo-wrap img { height: 42px; max-width: 145px; }
            .ticker { height: 36px; }
            .ticker-item { font-size: 0.9rem; }
            .admin-link { bottom: 3rem; }
            .fullscreen-link { bottom: 5.3rem; }
        }
    </style>
</head>
<body>
<div class="screen" id="screen">
    <a class="fullscreen-link" href="#" id="fullscreenToggle" title="Fullscreen">F</a>
    <a class="admin-link" href="admin.php" title="Admin">A</a>

    <?php if ($logoUrl !== ''): ?>
        <div class="logo-wrap">
            <img src="<?= h($logoUrl) ?>" alt="Logo">
        </div>
    <?php endif; ?>

    <?php if (empty($videos)): ?>
        <div class="message">Belum ada video. Silakan upload melalui halaman admin.</div>
    <?php else: ?>
        <span class="badge" id="videoCounter">1 / <?= count($videos) ?></span>
        <video id="player" autoplay playsinline></video>
    <?php endif; ?>

    <?php $tickerText = trim((string)($settings['ticker_text'] ?? '')); ?>
    <?php if ($tickerText !== ''): ?>
        <div class="ticker" aria-label="Running text informasi">
            <div class="ticker-inner">
                <span class="ticker-item"><?= h($tickerText) ?> •</span>
                <span class="ticker-item"><?= h($tickerText) ?> •</span>
                <span class="ticker-item"><?= h($tickerText) ?> •</span>
                <span class="ticker-item"><?= h($tickerText) ?> •</span>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($videos)): ?>
<script>
    const playlist = <?= json_encode(array_map(static function (array $video): array {
        return [
            'id' => $video['id'],
            'title' => $video['original_name'],
            'url' => 'uploads/' . $video['filename'],
        ];
    }, $videos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const startMuted = <?= $videoMuted ? 'true' : 'false' ?>;
    const screen = document.getElementById('screen');
    const fullscreenToggle = document.getElementById('fullscreenToggle');
    const player = document.getElementById('player');
    const counter = document.getElementById('videoCounter');
    let current = 0;

    function playAt(index) {
        if (playlist.length === 0) return;

        current = index % playlist.length;
        const item = playlist[current];

        player.src = item.url;
        player.muted = startMuted;
        player.load();

        player.play().catch(() => {
            // Fallback browser policy: jika autoplay ber-audio ditolak, coba lagi mode mute.
            player.muted = true;
            player.play().catch(() => {});
        });

        if (counter) {
            counter.textContent = `${current + 1} / ${playlist.length}`;
        }
    }

    player.addEventListener('ended', () => {
        playAt((current + 1) % playlist.length);
    });

    player.addEventListener('error', () => {
        playAt((current + 1) % playlist.length);
    });

    // Hindari fullscreen native video agar overlay (ticker/logo) tetap terlihat.
    player.addEventListener('contextmenu', (e) => {
        e.preventDefault();
    });

    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            screen.requestFullscreen?.().catch(() => {});
            return;
        }

        document.exitFullscreen?.().catch(() => {});
    }

    fullscreenToggle?.addEventListener('click', (e) => {
        e.preventDefault();
        toggleFullscreen();
    });

    // Double click area video untuk toggle fullscreen container.
    screen.addEventListener('dblclick', () => {
        toggleFullscreen();
    });

    playAt(0);
</script>
<?php endif; ?>
</body>
</html>
