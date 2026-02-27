<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: admin.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = getClientIp();

    if (!verifyCsrfToken($csrfToken)) {
        $error = 'Token keamanan tidak valid. Silakan refresh halaman lalu coba lagi.';
    } else {
        [$allowed, $wait] = isLoginAllowed($username, $ip);
        if (!$allowed) {
            $error = 'Terlalu banyak percobaan login. Coba lagi dalam ' . $wait . ' detik.';
        } elseif (verifyAdminCredentials($username, $password)) {
            clearFailedLogin($username, $ip);
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            header('Location: admin.php');
            exit;
        } else {
            registerFailedLogin($username, $ip);
            $error = 'Username atau password salah.';
        }
    }
}

$formToken = getCsrfToken();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin TV Informasi</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            color: #fff;
        }
        .card {
            width: min(420px, 92vw);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            padding: 1.4rem;
            backdrop-filter: blur(6px);
        }
        h1 { margin-top: 0; font-size: 1.4rem; }
        label { display: block; margin-top: 0.8rem; margin-bottom: 0.3rem; }
        input {
            width: 100%;
            padding: 0.6rem;
            border-radius: 8px;
            border: none;
        }
        button {
            margin-top: 1rem;
            width: 100%;
            padding: 0.7rem;
            border: none;
            border-radius: 8px;
            background: #00b4d8;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }
        .error {
            margin-top: 0.8rem;
            background: rgba(255, 0, 0, 0.2);
            border: 1px solid rgba(255, 140, 140, 0.7);
            padding: 0.6rem;
            border-radius: 8px;
        }
        .help {
            margin-top: 0.8rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Login Admin</h1>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8') ?>">
            <label for="username">Username</label>
            <input id="username" name="username" required>

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required>

            <button type="submit">Masuk</button>
        </form>
        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="help">Default awal: <strong>admin / admin123</strong>. Setelah login, ubah password di menu admin.</div>
    </div>
</body>
</html>
