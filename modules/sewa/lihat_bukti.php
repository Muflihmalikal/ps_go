<?php

require_once __DIR__ . '/../../config/koneksi.php';
require_once __DIR__ . '/../../config/sewa.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/bukti.php';

ini_set('display_errors', '0');

require_login();

function bukti_tidak_ada(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Bukti tidak ditemukan.';
    exit;
}

$idUser = (int) (current_user()['id_user'] ?? 0);
$token  = is_string($_GET['kode'] ?? null) ? $_GET['kode'] : '';
$kode   = strlen($token) <= 200 ? decrypt_id($token) : false;

if ($kode === false || !preg_match('/^[A-Z0-9]{2,5}-\d{8}-\d{4,}$/', (string) $kode)) {
    bukti_tidak_ada();
}

$stmt = $pdo->prepare('SELECT role, id_cabang FROM users WHERE id_user = :id AND is_active');
$stmt->execute(['id' => $idUser]);
$aku = $stmt->fetch();
if (!$aku) {
    bukti_tidak_ada();
}

$idLog = ctype_digit((string) ($_GET['log'] ?? '')) ? (int) $_GET['log'] : 0;
if ($idLog > 0) {
    $stmt = $pdo->prepare(
        'SELECT l.foto_bukti AS file_bukti, p.id_user, p.id_cabang
           FROM log_serah_terima l
           JOIN penyewaan p ON p.id_sewa = l.id_sewa
          WHERE l.id_log = :l AND l.id_sewa = :k'
    );
    $stmt->execute(['l' => $idLog, 'k' => $kode]);
} else {
    $stmt = $pdo->prepare(
        'SELECT b.file_bukti, p.id_user, p.id_cabang
           FROM pembayaran b
           JOIN penyewaan p ON p.id_sewa = b.id_sewa
          WHERE b.id_sewa = :k'
    );
    $stmt->execute(['k' => $kode]);
}
$data = $stmt->fetch();
if (!$data || !$data['file_bukti']) {
    bukti_tidak_ada();
}

$boleh = match ($aku['role']) {
    'pelanggan'         => (int) $data['id_user'] === $idUser,
    'owner'             => true,
    'admin', 'operator' => $aku['id_cabang'] !== null && (int) $aku['id_cabang'] === (int) $data['id_cabang'],
    default             => false,
};
if (!$boleh) {
    bukti_tidak_ada();
}

$path = bukti_path($data['file_bukti']);
if ($path === null || !is_file($path)) {
    bukti_tidak_ada();
}

$ekstensi = pathinfo($path, PATHINFO_EXTENSION);
$tipe = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ekstensi] ?? null;
if ($tipe === null) {
    bukti_tidak_ada();
}

if ($aku['role'] !== 'pelanggan') {
    try {
        $pdo->prepare(
            "INSERT INTO audit_log (id_user, aksi, tabel, id_target, ip)
             VALUES (:u, 'lihat_bukti', 'pembayaran', :t, :ip)"
        )->execute(['u' => $idUser, 't' => (string) $kode, 'ip' => client_ip()]);
    } catch (Throwable $e) {
        error_log('lihat_bukti: gagal mencatat audit: ' . $e->getMessage());
    }
}

session_write_close();
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $tipe);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="bukti-pembayaran.' . $ekstensi . '"');
readfile($path);
exit;
