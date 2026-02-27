<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'tvinformasi';
const DB_USER = 'root';
const DB_PASS = '';

function dbRoot(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensureDatabase();

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function ensureDatabase(): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    $root = dbRoot();
    $root->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $root->exec('USE `' . DB_NAME . '`');

    $root->exec(
        'CREATE TABLE IF NOT EXISTS videos (
            id VARCHAR(32) PRIMARY KEY,
            original_name VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL UNIQUE,
            uploaded_at DATETIME NOT NULL,
            sort_order INT NOT NULL,
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $root->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $root->exec(
        'CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $root->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_key VARCHAR(64) PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            first_failed_at INT NOT NULL DEFAULT 0,
            blocked_until INT NOT NULL DEFAULT 0,
            updated_at INT NOT NULL DEFAULT 0,
            INDEX idx_user_ip (username, ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    migrateJsonToDb($root);
    seedDefaults($root);

    $bootstrapped = true;
}

function seedDefaults(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) AS c FROM settings');
    $count = (int)($stmt->fetch()['c'] ?? 0);

    if ($count === 0) {
        $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)');
        $ins->execute([':k' => 'ticker_text', ':v' => 'Selamat datang di layanan informasi rumah sakit.']);
        $ins->execute([':k' => 'logo_filename', ':v' => '']);
        $ins->execute([':k' => 'video_muted', ':v' => '1']);
    } else {
        $ins = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (:k, :v)');
        $ins->execute([':k' => 'video_muted', ':v' => '1']);
    }

    $stmt = $pdo->query('SELECT COUNT(*) AS c FROM admin_users');
    $adminCount = (int)($stmt->fetch()['c'] ?? 0);

    if ($adminCount === 0) {
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare('INSERT INTO admin_users (username, password_hash, created_at, updated_at) VALUES (:u, :p, :c, :m)');
        $ins->execute([
            ':u' => 'admin',
            ':p' => password_hash('admin123', PASSWORD_DEFAULT),
            ':c' => $now,
            ':m' => $now,
        ]);
    }
}

function migrateJsonToDb(PDO $pdo): void
{
    $base = dirname(__DIR__);
    $videosJson = $base . '/data/videos.json';
    $settingsJson = $base . '/data/settings.json';
    $adminJson = $base . '/data/admin.json';

    $videoCount = (int)($pdo->query('SELECT COUNT(*) AS c FROM videos')->fetch()['c'] ?? 0);
    if ($videoCount === 0 && file_exists($videosJson)) {
        $raw = file_get_contents($videosJson);
        $arr = json_decode((string)$raw, true);
        if (is_array($arr) && !empty($arr)) {
            $ins = $pdo->prepare('INSERT INTO videos (id, original_name, filename, uploaded_at, sort_order) VALUES (:id, :n, :f, :u, :o)');
            foreach ($arr as $row) {
                $ins->execute([
                    ':id' => (string)($row['id'] ?? bin2hex(random_bytes(8))),
                    ':n' => (string)($row['original_name'] ?? ''),
                    ':f' => (string)($row['filename'] ?? ''),
                    ':u' => date('Y-m-d H:i:s', strtotime((string)($row['uploaded_at'] ?? 'now'))),
                    ':o' => (int)($row['order'] ?? 0),
                ]);
            }
        }
    }

    $settingsCount = (int)($pdo->query('SELECT COUNT(*) AS c FROM settings')->fetch()['c'] ?? 0);
    if ($settingsCount === 0 && file_exists($settingsJson)) {
        $raw = file_get_contents($settingsJson);
        $s = json_decode((string)$raw, true);
        if (is_array($s)) {
            foreach (['ticker_text', 'logo_filename', 'video_muted'] as $key) {
                if (array_key_exists($key, $s)) {
                    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                    $stmt->execute([':k' => $key, ':v' => (string)$s[$key]]);
                }
            }
        }
    }

    $adminCount = (int)($pdo->query('SELECT COUNT(*) AS c FROM admin_users')->fetch()['c'] ?? 0);
    if ($adminCount === 0 && file_exists($adminJson)) {
        $raw = file_get_contents($adminJson);
        $a = json_decode((string)$raw, true);
        if (is_array($a) && isset($a['username'], $a['password_hash'])) {
            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => (string)$a['username']]);
            $exists = $stmt->fetch();

            if ($exists) {
                $upd = $pdo->prepare('UPDATE admin_users SET password_hash = :p, updated_at = :m WHERE username = :u');
                $upd->execute([
                    ':u' => (string)$a['username'],
                    ':p' => (string)$a['password_hash'],
                    ':m' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
