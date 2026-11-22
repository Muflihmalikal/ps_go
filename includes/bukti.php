<?php

require_once __DIR__ . '/../config/sewa.php';

/** Folder bukti bayar. Path relatif di UPLOAD_DIR dihitung dari folder proyek. */
function bukti_direktori(): string
{
    $dasar = trim((string) getenv('UPLOAD_DIR'));
    if ($dasar === '') {
        $dasar = dirname(__DIR__) . '/storage/uploads';
    } else {
        $mutlak = $dasar[0] === '/' || $dasar[0] === '\\'
            || (strlen($dasar) > 2 && ctype_alpha($dasar[0]) && $dasar[1] === ':');
        if (!$mutlak) {
            $dasar = dirname(__DIR__) . '/' . $dasar;
        }
    }
    return rtrim($dasar, '/\\') . '/bukti_bayar';
}

/** Nama berkas bukti selalu 32 heksadesimal + ekstensi gambar. Menolak "../" dan sejenisnya. */
function bukti_nama_valid(?string $nama): bool
{
    return is_string($nama) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $nama) === 1;
}

function bukti_path(?string $nama): ?string
{
    return bukti_nama_valid($nama) ? bukti_direktori() . '/' . $nama : null;
}

function bukti_hapus(?string $nama): void
{
    $path = bukti_path($nama);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

/** Siapkan folder dan pasang .htaccess penolak akses langsung (Apache). */
function bukti_siapkan_direktori(): string
{
    $dir = bukti_direktori();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder bukti tidak dapat dibuat: ' . $dir);
    }

    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "# Bukti pembayaran hanya boleh dibuka lewat modules/sewa/lihat_bukti.php\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
        );
    }
    return $dir;
}

/** memory_limit PHP dalam byte; null = tanpa batas. */
function bukti_batas_memori(): ?int
{
    $v = trim((string) ini_get('memory_limit'));
    if ($v === '' || $v === '-1') {
        return null;
    }
    $faktor = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024][strtolower(substr($v, -1))] ?? 1;
    return (int) $v * $faktor;
}

/**
 * Proses satu berkas dari $_FILES['bukti'].
 * Mengembalikan ['nama' => nama berkas tersimpan, 'hash' => SHA-256 berkas asli].
 * Melempar SewaGagal (pesan aman ditampilkan ke pelanggan) atau RuntimeException (kesalahan server).
 */
function bukti_simpan(array $f): array
{
    $maksMb = (int) (SEWA_MAKS_BUKTI_BYTE / 1048576);

    switch ($f['error'] ?? UPLOAD_ERR_NO_FILE) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new SewaGagal('Bukti pembayaran wajib diunggah.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new SewaGagal("Ukuran file terlalu besar (maksimal {$maksMb} MB). Kecilkan fotonya atau gunakan screenshot.");
        case UPLOAD_ERR_PARTIAL:
            throw new SewaGagal('Unggahan terputus. Silakan coba lagi.');
        default:
            error_log('bukti_simpan: kode error upload ' . ($f['error'] ?? '?'));
            throw new SewaGagal('Bukti pembayaran gagal diunggah. Silakan coba lagi.');
    }

    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new SewaGagal('Berkas tidak valid.');
    }
    $ukuran = (int) ($f['size'] ?? 0);
    if ($ukuran <= 0 || $ukuran > SEWA_MAKS_BUKTI_BYTE) {
        throw new SewaGagal("Ukuran file maksimal {$maksMb} MB.");
    }

    // Jenis ditentukan dari ISI file, bukan dari nama atau Content-Type kiriman browser.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ekstensi = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ekstensi === null) {
        throw new SewaGagal('Format bukti harus gambar JPG, PNG, atau WebP (PDF tidak diterima, gunakan screenshot).');
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        throw new SewaGagal('File bukan gambar yang valid.');
    }
    [$lebar, $tinggi] = $info;
    if ($lebar < SEWA_MIN_SISI_PIKSEL || $tinggi < SEWA_MIN_SISI_PIKSEL) {
        throw new SewaGagal('Resolusi gambar terlalu kecil untuk dibaca sebagai bukti.');
    }
    if ($lebar * $tinggi > SEWA_MAKS_PIKSEL) {
        throw new SewaGagal('Resolusi gambar terlalu besar. Gunakan screenshot atau kecilkan fotonya.');
    }

    // Tolak lebih awal bila perkiraan memori melebihi batas PHP (GD memakai ~4 byte per piksel
    // plus salinan kerja), supaya gagal dengan pesan jelas, bukan "Allowed memory size exhausted".
    $batas = bukti_batas_memori();
    if ($batas !== null && (int) ($lebar * $tinggi * 5.5) + $ukuran + memory_get_usage(true) > $batas * 0.9) {
        throw new SewaGagal('Resolusi gambar terlalu besar untuk diproses server. Gunakan screenshot atau kecilkan fotonya.');
    }

    // GD wajib: tanpa pembuatan ulang gambar, file asli (dengan metadata/kode tersembunyi)
    // tidak boleh disimpan. Gagal dengan aman, bukan menyimpan apa adanya.
    foreach (['imagecreatetruecolor', 'imagejpeg', 'imagecopy', 'imagefill'] as $fn) {
        if (!function_exists($fn)) {
            throw new RuntimeException('Ekstensi PHP GD tidak aktif; bukti tidak dapat diproses dengan aman.');
        }
    }
    $buat = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime];
    if (!function_exists($buat)) {
        throw new SewaGagal('Format ' . strtoupper($ekstensi) . ' belum didukung server. Gunakan JPG atau PNG.');
    }

    $hash = hash_file('sha256', $tmp);
    $dir  = bukti_siapkan_direktori();

    $img = @$buat($tmp);
    if ($img === false) {
        throw new SewaGagal('Gambar rusak atau tidak dapat dibaca.');
    }

    // Perkecil bila sisi terpanjang melebihi batas simpan.
    $w = imagesx($img);
    $h = imagesy($img);
    $sisiMaks = max($w, $h);
    if ($sisiMaks > SEWA_SISI_MAKS_SIMPAN) {
        $lebarBaru = (int) round($w * SEWA_SISI_MAKS_SIMPAN / $sisiMaks);
        $kecil = imagescale($img, max(1, $lebarBaru), -1, IMG_BICUBIC);
        if ($kecil !== false) {
            $img = $kecil;
        }
    }

    // Foto dari ponsel sering "miring" lewat tanda EXIF; putar fisiknya sebelum EXIF dibuang.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
        $exif  = @exif_read_data($tmp);
        $sudut = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($sudut !== 0) {
            $putar = imagerotate($img, $sudut, 0);
            if ($putar !== false) {
                $img = $putar;
            }
        }
    }

    // Salin ke kanvas baru berlatar putih (PNG/WebP transparan jadi putih; semua metadata hilang).
    $w = imagesx($img);
    $h = imagesy($img);
    $kanvas = imagecreatetruecolor($w, $h);
    imagefill($kanvas, 0, 0, imagecolorallocate($kanvas, 255, 255, 255));
    imagecopy($kanvas, $img, 0, 0, 0, 0, $w, $h);

    $nama      = bin2hex(random_bytes(16)) . '.jpg';
    $sementara = $dir . '/' . $nama . '.part';
    if (!imagejpeg($kanvas, $sementara, 85) || !rename($sementara, $dir . '/' . $nama)) {
        @unlink($sementara);
        throw new RuntimeException('Gagal menulis berkas bukti.');
    }

    @chmod($dir . '/' . $nama, 0640);
    return ['nama' => $nama, 'hash' => $hash];
}
