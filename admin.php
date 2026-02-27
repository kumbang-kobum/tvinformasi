<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$notice = '';
$error = '';
$videos = loadVideos();
$settings = loadSettings();
$adminUser = loadAdminUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $csrfToken = (string)($_POST['csrf_token'] ?? '');

    if (!verifyCsrfToken($csrfToken)) {
        $error = 'Token keamanan tidak valid. Silakan refresh halaman lalu coba lagi.';
    }

    if ($error === '' && $action === 'upload') {
        if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
            $error = 'File video tidak ditemukan.';
        } else {
            $file = $_FILES['video'];

            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Upload gagal. Kode error: ' . (int)$file['error'];
            } elseif ((int)$file['size'] > MAX_FILE_SIZE) {
                $error = 'Ukuran file terlalu besar. Maksimum 300MB.';
            } else {
                $originalName = (string)$file['name'];
                $tmpName = (string)$file['tmp_name'];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $mime = (string)(mime_content_type($tmpName) ?: 'application/octet-stream');

                if (!isAllowedVideo($mime, $extension)) {
                    $error = 'Format file tidak didukung. Gunakan mp4/webm/ogg/mov/m4v.';
                } else {
                    $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                    $target = UPLOAD_DIR . '/' . $newName;

                    if (!move_uploaded_file($tmpName, $target)) {
                        $error = 'Gagal menyimpan file ke server.';
                    } else {
                        $videos[] = [
                            'id' => generateId(),
                            'original_name' => $originalName,
                            'filename' => $newName,
                            'uploaded_at' => date('c'),
                            'order' => nextOrder($videos),
                        ];

                        if (saveVideos($videos)) {
                            $notice = 'Video berhasil diupload.';
                            $videos = loadVideos();
                        } else {
                            $error = 'Gagal menyimpan metadata video.';
                        }
                    }
                }
            }
        }
    }

    if ($error === '' && $action === 'delete') {
        $id = (string)($_POST['id'] ?? '');
        $deleted = false;

        foreach ($videos as $i => $video) {
            if (($video['id'] ?? '') === $id) {
                $filePath = UPLOAD_DIR . '/' . ($video['filename'] ?? '');
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
                unset($videos[$i]);
                $deleted = true;
                break;
            }
        }

        if ($deleted) {
            $videos = array_values($videos);
            reindexOrders($videos);
            saveVideos($videos);
            $videos = loadVideos();
            $notice = 'Video berhasil dihapus.';
        } else {
            $error = 'Video tidak ditemukan.';
        }
    }

    if ($error === '' && $action === 'reorder') {
        $payload = trim((string)($_POST['order_payload'] ?? ''));
        if ($payload === '') {
            $error = 'Urutan video tidak valid.';
        } else {
            $orderedIds = array_values(array_filter(array_map('trim', explode(',', $payload))));
            $videos = applyVideoOrder($videos, $orderedIds);
            if (saveVideos($videos)) {
                $notice = 'Urutan video berhasil disimpan.';
                $videos = loadVideos();
            } else {
                $error = 'Gagal menyimpan urutan video.';
            }
        }
    }

    if ($error === '' && $action === 'save_settings') {
        $settings = loadSettings();
        $settings['ticker_text'] = trim((string)($_POST['ticker_text'] ?? ''));
        $settings['video_muted'] = isset($_POST['video_muted']) && (string)$_POST['video_muted'] === '1' ? '1' : '0';

        $removeLogo = isset($_POST['remove_logo']) && (string)$_POST['remove_logo'] === '1';

        if ($removeLogo && (string)$settings['logo_filename'] !== '') {
            $old = UPLOAD_DIR . '/' . $settings['logo_filename'];
            if (is_file($old)) {
                @unlink($old);
            }
            $settings['logo_filename'] = '';
        }

        if (isset($_FILES['logo']) && is_array($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $logo = $_FILES['logo'];

            if ((int)$logo['error'] !== UPLOAD_ERR_OK) {
                $error = 'Upload logo gagal. Kode error: ' . (int)$logo['error'];
            } elseif ((int)$logo['size'] > MAX_LOGO_SIZE) {
                $error = 'Ukuran logo terlalu besar. Maksimal 5MB.';
            } else {
                $originalName = (string)$logo['name'];
                $tmpName = (string)$logo['tmp_name'];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $mime = (string)(mime_content_type($tmpName) ?: 'application/octet-stream');

                if (!isAllowedImage($mime, $extension)) {
                    $error = 'Format logo tidak didukung. Gunakan png/jpg/jpeg/webp.';
                } else {
                    $newLogo = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extension;
                    $target = UPLOAD_DIR . '/' . $newLogo;

                    if (!move_uploaded_file($tmpName, $target)) {
                        $error = 'Gagal menyimpan logo ke server.';
                    } else {
                        if ((string)$settings['logo_filename'] !== '') {
                            $old = UPLOAD_DIR . '/' . $settings['logo_filename'];
                            if (is_file($old)) {
                                @unlink($old);
                            }
                        }
                        $settings['logo_filename'] = $newLogo;
                    }
                }
            }
        }

        if ($error === '') {
            if (saveSettings($settings)) {
                $notice = 'Pengaturan tampilan berhasil disimpan.';
                $settings = loadSettings();
            } else {
                $error = 'Gagal menyimpan pengaturan tampilan.';
            }
        }
    }

    if ($error === '' && $action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($newPassword !== $confirmPassword) {
            $error = 'Konfirmasi password baru tidak cocok.';
        } else {
            [$ok, $msg] = updateAdminPassword($currentPassword, $newPassword);
            if ($ok) {
                $notice = $msg;
            } else {
                $error = $msg;
            }
        }
    }

    if ($error === '' && $action === 'change_username') {
        $currentPassword = (string)($_POST['current_password_username'] ?? '');
        $newUsername = trim((string)($_POST['new_username'] ?? ''));

        [$ok, $msg] = updateAdminUsername($currentPassword, $newUsername);
        if ($ok) {
            $notice = $msg;
            $adminUser = loadAdminUser();
        } else {
            $error = $msg;
        }
    }

    if ($error === '' && $action === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

$formToken = getCsrfToken();

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
    <title>Admin TV Informasi</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f7fa;
            color: #1a2a33;
        }
        .container {
            width: min(1080px, 94vw);
            margin: 1.3rem auto;
        }
        .row {
            display: flex;
            gap: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 8px 24px rgba(0,0,0,.06);
            margin-top: 1rem;
        }
        h1 { margin: 0; font-size: 1.35rem; }
        h2 { margin: 0; font-size: 1.08rem; }
        .muted { color: #4a636f; }
        .notice {
            margin-top: .8rem;
            background: #e9fff2;
            border: 1px solid #81d9a6;
            padding: .7rem;
            border-radius: 8px;
        }
        .error {
            margin-top: .8rem;
            background: #fff0f0;
            border: 1px solid #ef9a9a;
            padding: .7rem;
            border-radius: 8px;
        }
        form { margin: 0; }
        input[type="file"], input[type="text"], input[type="password"] {
            margin-top: .4rem;
            width: 100%;
            max-width: 600px;
            border: 1px solid #ccd9e0;
            border-radius: 8px;
            padding: .5rem;
        }
        button {
            border: none;
            border-radius: 8px;
            padding: .5rem .8rem;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-primary { background: #0077b6; color: #fff; }
        .btn-danger { background: #c62828; color: #fff; }
        .btn-dark { background: #253238; color: #fff; }
        .btn-secondary { background: #8aa0aa; color: #fff; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: .6rem;
        }
        th, td {
            text-align: left;
            padding: .6rem;
            border-bottom: 1px solid #e4edf2;
            font-size: .95rem;
            vertical-align: top;
        }
        .actions { display: flex; gap: .4rem; }
        .top-links {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
        }
        .video-row { cursor: move; }
        .video-row.dragging { opacity: .45; }
        .hint { font-size: .88rem; color: #5d717b; margin-top: .4rem; }
        .logo-preview {
            margin-top: .7rem;
            max-height: 80px;
            max-width: 240px;
            object-fit: contain;
            background: #eef5f9;
            border-radius: 8px;
            padding: .3rem;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        a { color: #03527a; text-decoration: none; }
        @media (max-width: 900px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="row" style="justify-content: space-between;">
        <h1>Admin n2N-TV Informasi</h1>
        <div class="top-links">
            <a href="index.php" target="_blank">Buka Layar TV</a>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= h($formToken) ?>">
                <button class="btn-dark" type="submit">Logout</button>
            </form>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="notice"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <h2>Upload Video</h2>
            <div class="muted">Maks 300MB, format: mp4/webm/ogg/mov/m4v</div>
            <form method="post" enctype="multipart/form-data" class="row" style="margin-top:.7rem;">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="csrf_token" value="<?= h($formToken) ?>">
                <input type="file" name="video" accept="video/*" required>
                <button class="btn-primary" type="submit">Upload Video</button>
            </form>
        </div>

        <div class="card">
            <h2>Tampilan Layar TV</h2>
            <form method="post" enctype="multipart/form-data" style="margin-top:.7rem;">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="csrf_token" value="<?= h($formToken) ?>">

                <label for="ticker_text"><strong>Running text</strong></label>
                <input id="ticker_text" type="text" name="ticker_text" maxlength="240" value="<?= h((string)$settings['ticker_text']) ?>" placeholder="Contoh: Jam kunjungan 11:00 - 13:00 WIB">
                <div class="hint">Pastikan klik tombol simpan setelah mengubah text.</div>

                <label style="display:block; margin-top:.8rem;">
                    <input type="checkbox" name="video_muted" value="1" <?= ((string)($settings['video_muted'] ?? '1') === '1') ? 'checked' : '' ?>>
                    Matikan audio video (mode senyap)
                </label>

                <label for="logo" style="display:block; margin-top:.8rem;"><strong>Logo</strong></label>
                <input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                <div class="hint">Format: png/jpg/jpeg/webp, maksimal 5MB.</div>

                <?php if ($logoUrl !== ''): ?>
                    <img class="logo-preview" src="<?= h($logoUrl) ?>" alt="Logo RS">
                    <label style="display:block; margin-top:.6rem;">
                        <input type="checkbox" name="remove_logo" value="1"> Hapus logo saat simpan
                    </label>
                <?php endif; ?>

                <button class="btn-primary" type="submit" style="margin-top:.8rem;">Simpan Pengaturan Tampilan</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="row" style="justify-content: space-between;">
            <h2>Daftar Video (drag & drop untuk atur urutan putar)</h2>
            <form method="post" id="reorderForm">
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="csrf_token" value="<?= h($formToken) ?>">
                <input type="hidden" name="order_payload" id="orderPayload" value="">
                <button class="btn-secondary" type="submit">Simpan Urutan</button>
            </form>
        </div>
        <div class="hint">Geser baris video untuk mengubah urutan, lalu klik Simpan Urutan.</div>
        <table>
            <thead>
                <tr>
                    <th style="width:70px;">No</th>
                    <th>Nama File</th>
                    <th style="width:200px;">Waktu Upload</th>
                    <th style="width:150px;">Aksi</th>
                </tr>
            </thead>
            <tbody id="videoTableBody">
            <?php if (empty($videos)): ?>
                <tr>
                    <td colspan="4" class="muted">Belum ada video.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($videos as $video): ?>
                    <tr class="video-row" draggable="true" data-video-id="<?= h((string)$video['id']) ?>">
                        <td class="order-cell"><?= (int)$video['order'] ?></td>
                        <td><?= h((string)$video['original_name']) ?></td>
                        <td><?= h(date('d-m-Y H:i', strtotime((string)$video['uploaded_at']))) ?></td>
                        <td>
                            <div class="actions">
                                <form method="post" onsubmit="return confirm('Hapus video ini?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?= h($formToken) ?>">
                                    <input type="hidden" name="id" value="<?= h((string)$video['id']) ?>">
                                    <button class="btn-danger" type="submit">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Ubah Username Admin</h2>
        <div class="hint">Username saat ini: <strong><?= h((string)($adminUser['username'] ?? '-')) ?></strong></div>
        <form method="post" class="row" style="margin-top:.7rem; align-items:flex-end;">
            <input type="hidden" name="action" value="change_username">
            <input type="hidden" name="csrf_token" value="<?= h($formToken) ?>">

            <div>
                <label for="new_username"><strong>Username Baru</strong></label>
                <input id="new_username" type="text" name="new_username" minlength="3" maxlength="50" required>
            </div>
            <div>
                <label for="current_password_username"><strong>Password Saat Ini</strong></label>
                <input id="current_password_username" type="password" name="current_password_username" required>
            </div>
            <button class="btn-primary" type="submit">Simpan Username</button>
        </form>
    </div>

    <div class="card">
        <h2>Ubah Password Admin</h2>
        <form method="post" class="row" style="margin-top:.7rem; align-items:flex-end;">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="csrf_token" value="<?= h($formToken) ?>">

            <div>
                <label for="current_password"><strong>Password Lama</strong></label>
                <input id="current_password" type="password" name="current_password" required>
            </div>
            <div>
                <label for="new_password"><strong>Password Baru</strong></label>
                <input id="new_password" type="password" name="new_password" minlength="6" required>
            </div>
            <div>
                <label for="confirm_password"><strong>Konfirmasi Password Baru</strong></label>
                <input id="confirm_password" type="password" name="confirm_password" minlength="6" required>
            </div>
            <button class="btn-primary" type="submit">Simpan Password</button>
        </form>
    </div>
</div>

<script>
    (function () {
        const tbody = document.getElementById('videoTableBody');
        const reorderForm = document.getElementById('reorderForm');
        const orderPayload = document.getElementById('orderPayload');

        if (!tbody || !reorderForm || !orderPayload) {
            return;
        }

        let draggingRow = null;

        function refreshNumbers() {
            const rows = tbody.querySelectorAll('tr.video-row');
            rows.forEach((row, idx) => {
                const cell = row.querySelector('.order-cell');
                if (cell) {
                    cell.textContent = String(idx + 1);
                }
            });
        }

        function buildPayload() {
            const ids = [];
            tbody.querySelectorAll('tr.video-row').forEach((row) => {
                ids.push(row.getAttribute('data-video-id'));
            });
            orderPayload.value = ids.join(',');
        }

        tbody.querySelectorAll('tr.video-row').forEach((row) => {
            row.addEventListener('dragstart', () => {
                draggingRow = row;
                row.classList.add('dragging');
            });

            row.addEventListener('dragend', () => {
                row.classList.remove('dragging');
                draggingRow = null;
                refreshNumbers();
                buildPayload();
            });

            row.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (!draggingRow || draggingRow === row) {
                    return;
                }

                const rect = row.getBoundingClientRect();
                const offset = e.clientY - rect.top;
                const halfway = rect.height / 2;

                if (offset > halfway) {
                    row.after(draggingRow);
                } else {
                    row.before(draggingRow);
                }
            });
        });

        reorderForm.addEventListener('submit', () => {
            buildPayload();
        });

        buildPayload();
    })();
</script>
</body>
</html>
