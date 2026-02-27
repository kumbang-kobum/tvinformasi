<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_SECONDS = 300; // 5 menit
const LOGIN_LOCKOUT_SECONDS = 600; // 10 menit

function getCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function getClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function loginAttemptKey(string $username, string $ip): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . trim($ip));
}

function isLoginAllowed(string $username, string $ip): array
{
    $pdo = db();
    $key = loginAttemptKey($username, $ip);
    $now = time();

    $stmt = $pdo->prepare('SELECT blocked_until FROM login_attempts WHERE attempt_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();

    if (!$row) {
        return [true, 0];
    }

    $blockedUntil = (int)($row['blocked_until'] ?? 0);
    if ($blockedUntil > $now) {
        return [false, $blockedUntil - $now];
    }

    return [true, 0];
}

function registerFailedLogin(string $username, string $ip): void
{
    $pdo = db();
    $key = loginAttemptKey($username, $ip);
    $now = time();

    $stmt = $pdo->prepare('SELECT attempts, first_failed_at, blocked_until FROM login_attempts WHERE attempt_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();

    $attempts = 1;
    $firstFailedAt = $now;
    $blockedUntil = 0;

    if ($row) {
        $prevAttempts = (int)($row['attempts'] ?? 0);
        $prevFirst = (int)($row['first_failed_at'] ?? 0);

        if ($prevFirst > 0 && ($now - $prevFirst) <= LOGIN_WINDOW_SECONDS) {
            $attempts = $prevAttempts + 1;
            $firstFailedAt = $prevFirst;
        }
    }

    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $blockedUntil = $now + LOGIN_LOCKOUT_SECONDS;
        $attempts = 0;
        $firstFailedAt = 0;
    }

    $upsert = $pdo->prepare(
        'INSERT INTO login_attempts (attempt_key, username, ip_address, attempts, first_failed_at, blocked_until, updated_at)
         VALUES (:k, :u, :ip, :a, :f, :b, :m)
         ON DUPLICATE KEY UPDATE
            attempts = VALUES(attempts),
            first_failed_at = VALUES(first_failed_at),
            blocked_until = VALUES(blocked_until),
            updated_at = VALUES(updated_at),
            username = VALUES(username),
            ip_address = VALUES(ip_address)'
    );

    $upsert->execute([
        ':k' => $key,
        ':u' => $username,
        ':ip' => $ip,
        ':a' => $attempts,
        ':f' => $firstFailedAt,
        ':b' => $blockedUntil,
        ':m' => $now,
    ]);
}

function clearFailedLogin(string $username, string $ip): void
{
    $pdo = db();
    $key = loginAttemptKey($username, $ip);

    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE attempt_key = :k');
    $stmt->execute([':k' => $key]);
}

function loadAdminUser(): array
{
    $pdo = db();
    $stmt = $pdo->query('SELECT username, password_hash FROM admin_users ORDER BY id ASC LIMIT 1');
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

function verifyAdminCredentials(string $username, string $password): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT username, password_hash FROM admin_users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $admin = $stmt->fetch();

    if (!is_array($admin)) {
        return false;
    }

    $savedUsername = (string)($admin['username'] ?? '');
    $savedHash = (string)($admin['password_hash'] ?? '');

    if ($savedUsername === '' || $savedHash === '') {
        return false;
    }

    return hash_equals($savedUsername, $username) && password_verify($password, $savedHash);
}

function updateAdminPassword(string $currentPassword, string $newPassword): array
{
    $pdo = db();

    $username = (string)($_SESSION['admin_username'] ?? 'admin');
    $stmt = $pdo->prepare('SELECT id, password_hash FROM admin_users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $admin = $stmt->fetch();

    if (!is_array($admin)) {
        return [false, 'Akun admin tidak ditemukan.'];
    }

    $savedHash = (string)($admin['password_hash'] ?? '');

    if ($savedHash === '' || !password_verify($currentPassword, $savedHash)) {
        return [false, 'Password lama tidak sesuai.'];
    }

    if (strlen($newPassword) < 6) {
        return [false, 'Password baru minimal 6 karakter.'];
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare('UPDATE admin_users SET password_hash = :p, updated_at = :m WHERE id = :id');

    $ok = $update->execute([
        ':p' => $newHash,
        ':m' => date('Y-m-d H:i:s'),
        ':id' => (int)$admin['id'],
    ]);

    if (!$ok) {
        return [false, 'Gagal menyimpan password baru.'];
    }

    return [true, 'Password berhasil diubah.'];
}

function updateAdminUsername(string $currentPassword, string $newUsername): array
{
    $pdo = db();

    $currentUsername = (string)($_SESSION['admin_username'] ?? 'admin');
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $currentUsername]);
    $admin = $stmt->fetch();

    if (!is_array($admin)) {
        return [false, 'Akun admin tidak ditemukan.'];
    }

    $savedHash = (string)($admin['password_hash'] ?? '');
    if ($savedHash === '' || !password_verify($currentPassword, $savedHash)) {
        return [false, 'Password saat ini tidak sesuai.'];
    }

    $newUsername = trim($newUsername);
    if (strlen($newUsername) < 3 || strlen($newUsername) > 50) {
        return [false, 'Username baru harus 3-50 karakter.'];
    }

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $newUsername)) {
        return [false, 'Username hanya boleh huruf, angka, titik, strip, atau underscore.'];
    }

    $sameUsername = hash_equals((string)$admin['username'], $newUsername);
    if ($sameUsername) {
        return [false, 'Username baru sama dengan username saat ini.'];
    }

    $check = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
    $check->execute([':u' => $newUsername]);
    if ($check->fetch()) {
        return [false, 'Username sudah digunakan. Pilih username lain.'];
    }

    $upd = $pdo->prepare('UPDATE admin_users SET username = :u, updated_at = :m WHERE id = :id');
    $ok = $upd->execute([
        ':u' => $newUsername,
        ':m' => date('Y-m-d H:i:s'),
        ':id' => (int)$admin['id'],
    ]);

    if (!$ok) {
        return [false, 'Gagal menyimpan username baru.'];
    }

    $_SESSION['admin_username'] = $newUsername;

    return [true, 'Username berhasil diubah menjadi ' . $newUsername . '.'];
}

function isLoggedIn(): bool
{
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}
