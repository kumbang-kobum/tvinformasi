## Traktir Kopi BCA 8110400102 A/N Chandra Irawan** ☕🙏 
## ☕ Donasi
Dukung pengembangan aplikasi ini melalui Saweria:  

| Scan QR Code | Klik Link |
|--------------|-----------|
| <img src="screenshot/qrsaweria.png" width="180" /> | [👉 Saweria.co](https://saweria.co/KumbangKobum) | 
# TV Informasi

Aplikasi web sederhana untuk Android Smart TV / browser TV:
- Memutar playlist video otomatis berurutan
- Loop kembali ke video pertama setelah playlist selesai
- Panel admin untuk upload/hapus/reorder video
- Running text, logo, dan opsi audio ON/OFF
- Login admin + CSRF + rate limit login

## Lokasi Proyek

`/Applications/XAMPP/xamppfiles/htdocs/tvinformasi`

## Kebutuhan

- XAMPP (Apache + MySQL aktif)
- PHP 8+ (disarankan)
- Browser TV / Android TV

## Instalasi Cepat

1. Pastikan folder project ada di:
   - `/Applications/XAMPP/xamppfiles/htdocs/tvinformasi`
2. Jalankan XAMPP:
   - Start `Apache`
   - Start `MySQL`
3. Buka di browser:
   - Player TV: `http://localhost/tvinformasi/`
   - Admin: `http://localhost/tvinformasi/admin.php`

## Database

Aplikasi memakai MySQL database `tvinformasi`.

Bootstrap otomatis saat akses pertama:
- Membuat database `tvinformasi`
- Membuat tabel:
  - `videos`
  - `settings`
  - `admin_users`
  - `login_attempts`

Konfigurasi koneksi database ada di:
- `includes/db.php`

Default:
- Host: `127.0.0.1`
- Port: `3306`
- User: `root`
- Password: kosong

Jika MySQL Anda berbeda, edit konstanta di `includes/db.php`.

## Login Admin

- URL: `http://localhost/tvinformasi/admin.php`
- Default awal:
  - Username: `naira`
  - Password: `nafasya123`

Setelah login pertama, ubah password di panel admin.

## Penggunaan Admin

### 1. Upload Video

- Pilih file video, klik `Upload Video`
- Format yang didukung: `mp4`, `webm`, `ogg`, `mov`, `m4v`
- Maksimum sesuai setting aplikasi dan PHP

### 2. Atur Urutan Video

- Drag & drop baris video
- Klik `Simpan Urutan`

### 3. Hapus Video

- Klik `Hapus` pada video yang diinginkan

### 4. Pengaturan Tampilan

Di menu `Tampilan Layar TV`:
- Ubah `Running text`
- Upload / hapus logo
- Toggle audio:
  - Centang `Matikan audio video (mode senyap)` untuk mute

Klik `Simpan Pengaturan Tampilan`.

### 5. Logout

- Tombol `Logout` akan kembali ke halaman utama player (`index.php`)

## Halaman Player TV

URL:
- `http://localhost/tvinformasi/`

Perilaku:
- Video diputar otomatis berurutan
- Saat selesai playlist, kembali ke video pertama
- Running text tampil di bagian bawah
- Tombol kecil:
  - `A` = Admin
  - `F` = Fullscreen container (agar overlay ticker tetap terlihat)

Catatan:
- Hindari fullscreen native dari menu klik kanan video
- Gunakan tombol `F`/double-click untuk fullscreen yang benar

## Struktur Folder

- `index.php` -> halaman player TV
- `admin.php` -> panel admin
- `login.php` -> login admin
- `includes/`
  - `db.php` -> koneksi + bootstrap database
  - `functions.php` -> helper video/settings
  - `auth.php` -> auth, CSRF, rate limit
- `uploads/` -> file video/logo
- `data/` -> data legacy (json), sisa migrasi

## Keamanan yang Sudah Aktif

- Password disimpan dalam hash (`password_hash`)
- Proteksi CSRF untuk form POST
- Rate limit login (blokir sementara jika terlalu banyak gagal)
- Session regenerate saat login sukses

## Troubleshooting

### Running text tidak berubah

1. Simpan dari admin (`Simpan Pengaturan Tampilan`)
2. Refresh halaman player (hard refresh)
3. Pastikan MySQL aktif

### Upload gagal

1. Pastikan Apache + MySQL aktif
2. Cek permission folder:
   - `uploads/` harus writable oleh web server
3. Cek limit upload PHP
   - file `.htaccess` proyek sudah menambahkan batas lebih besar

### Tidak bisa login

1. Pastikan credential benar
2. Jika terlalu banyak gagal login, tunggu lockout selesai
3. Jika lupa password, reset langsung di tabel `admin_users`

## Catatan Operasional TV

- Untuk mode kiosk, gunakan browser full screen di Android TV
- Gunakan URL player langsung sebagai homepage/start page
- Nonaktifkan sleep screen di device TV jika diperlukan
