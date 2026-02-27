<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const UPLOAD_DIR = __DIR__ . '/../uploads';
const MAX_FILE_SIZE = 300 * 1024 * 1024; // 300 MB
const MAX_LOGO_SIZE = 5 * 1024 * 1024; // 5 MB

function ensureStorage(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }

    @chmod(UPLOAD_DIR, 0777);

    // Force DB bootstrap on first request.
    db();
}

function defaultSettings(): array
{
    return [
        'ticker_text' => 'Selamat datang di layanan informasi rumah sakit.',
        'logo_filename' => '',
        'video_muted' => '1',
    ];
}

function loadSettings(): array
{
    ensureStorage();

    $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
    $result = defaultSettings();

    foreach ($rows as $row) {
        $key = (string)($row['setting_key'] ?? '');
        if (array_key_exists($key, $result)) {
            $result[$key] = (string)($row['setting_value'] ?? '');
        }
    }

    return $result;
}

function saveSettings(array $settings): bool
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach (['ticker_text', 'logo_filename', 'video_muted'] as $key) {
            $stmt->execute([
                ':k' => $key,
                ':v' => (string)($settings[$key] ?? ''),
            ]);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function loadVideos(): array
{
    ensureStorage();

    $stmt = db()->query('SELECT id, original_name, filename, uploaded_at, sort_order FROM videos ORDER BY sort_order ASC, uploaded_at ASC');
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (string)$row['id'],
            'original_name' => (string)$row['original_name'],
            'filename' => (string)$row['filename'],
            'uploaded_at' => date('c', strtotime((string)$row['uploaded_at'])),
            'order' => (int)$row['sort_order'],
        ];
    }, $rows);
}

function saveVideos(array $videos): bool
{
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $upsert = $pdo->prepare(
            'INSERT INTO videos (id, original_name, filename, uploaded_at, sort_order)
             VALUES (:id, :n, :f, :u, :o)
             ON DUPLICATE KEY UPDATE
                original_name = VALUES(original_name),
                filename = VALUES(filename),
                uploaded_at = VALUES(uploaded_at),
                sort_order = VALUES(sort_order)'
        );

        $ids = [];
        foreach (array_values($videos) as $index => $video) {
            $id = (string)($video['id'] ?? generateId());
            $ids[] = $id;
            $upsert->execute([
                ':id' => $id,
                ':n' => (string)($video['original_name'] ?? ''),
                ':f' => (string)($video['filename'] ?? ''),
                ':u' => date('Y-m-d H:i:s', strtotime((string)($video['uploaded_at'] ?? 'now'))),
                ':o' => (int)($video['order'] ?? ($index + 1)),
            ]);
        }

        if (empty($ids)) {
            $pdo->exec('DELETE FROM videos');
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $del = $pdo->prepare("DELETE FROM videos WHERE id NOT IN ($placeholders)");
            $del->execute($ids);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function nextOrder(array $videos): int
{
    if (empty($videos)) {
        return 1;
    }

    $max = max(array_map(static fn(array $v): int => (int)($v['order'] ?? 0), $videos));
    return $max + 1;
}

function generateId(): string
{
    return bin2hex(random_bytes(8));
}

function isAllowedVideo(string $mime, string $extension): bool
{
    $allowedExt = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];
    $allowedMime = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'application/octet-stream',
    ];

    return in_array(strtolower($extension), $allowedExt, true)
        && in_array(strtolower($mime), $allowedMime, true);
}

function isAllowedImage(string $mime, string $extension): bool
{
    $allowedExt = ['png', 'jpg', 'jpeg', 'webp'];
    $allowedMime = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/octet-stream',
    ];

    return in_array(strtolower($extension), $allowedExt, true)
        && in_array(strtolower($mime), $allowedMime, true);
}

function findVideoById(array $videos, string $id): ?array
{
    foreach ($videos as $video) {
        if (($video['id'] ?? '') === $id) {
            return $video;
        }
    }

    return null;
}

function reindexOrders(array &$videos): void
{
    usort($videos, static function (array $a, array $b): int {
        return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
    });

    $i = 1;
    foreach ($videos as &$video) {
        $video['order'] = $i++;
    }
}

function applyVideoOrder(array $videos, array $orderedIds): array
{
    $map = [];
    foreach ($videos as $video) {
        $map[(string)($video['id'] ?? '')] = $video;
    }

    $reordered = [];
    foreach ($orderedIds as $id) {
        $id = (string)$id;
        if (isset($map[$id])) {
            $reordered[] = $map[$id];
            unset($map[$id]);
        }
    }

    foreach ($map as $remaining) {
        $reordered[] = $remaining;
    }

    reindexOrders($reordered);
    return $reordered;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
