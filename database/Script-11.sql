-- 1. Tabel Cabang Store
CREATE TABLE cabang (
    id_cabang SERIAL PRIMARY KEY,
    nama_cabang VARCHAR(100) NOT NULL,
    alamat TEXT NOT NULL,
    koordinat VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabel Pengguna (Owner, Admin, Operator, Pelanggan)
CREATE TABLE users (
    id_user SERIAL PRIMARY KEY,
    id_cabang INT DEFAULT NULL, -- NULL khusus untuk role Owner (Akses All Cabang)
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    no_wa VARCHAR(20) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'pelanggan' CHECK (role IN ('owner', 'admin', 'operator', 'pelanggan')),
    is_member BOOLEAN DEFAULT FALSE,
    status_verifikasi VARCHAR(20) DEFAULT 'unverified' CHECK (status_verifikasi IN ('unverified', 'verified')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cabang) REFERENCES cabang(id_cabang) ON DELETE SET NULL ON UPDATE CASCADE
);

-- 3. Tabel Master Paket & Tarif Sewa
CREATE TABLE paket_sewa (
    id_paket SERIAL PRIMARY KEY,
    id_cabang INT NOT NULL,
    kode_paket VARCHAR(50) NOT NULL,
    nama_paket VARCHAR(100) NOT NULL,
    kategori VARCHAR(20) NOT NULL CHECK (kategori IN ('ps3', 'ps4', 'ps5', 'combo', 'tv')),
    label_badge VARCHAR(50) DEFAULT 'KONSOL ONLY',
    tag_highlight VARCHAR(100) DEFAULT NULL,
    deskripsi TEXT DEFAULT NULL,
    harga_wk_12h NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    harga_wk_24h NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    harga_wn_12h NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    harga_wn_24h NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    override_status VARCHAR(20) DEFAULT 'auto' CHECK (override_status IN ('auto', 'Maintenance')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cabang) REFERENCES cabang(id_cabang) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 4. Tabel Pivot Komponen Paket Combo
CREATE TABLE paket_komponen (
    id_paket_parent INT NOT NULL,
    id_paket_child INT NOT NULL,
    PRIMARY KEY (id_paket_parent, id_paket_child),
    FOREIGN KEY (id_paket_parent) REFERENCES paket_sewa(id_paket) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_paket_child) REFERENCES paket_sewa(id_paket) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 5. Tabel Perangkat Fisik (Unit PS & TV)
CREATE TABLE unit_ps (
    id_unit SERIAL PRIMARY KEY,
    id_cabang INT NOT NULL,
    id_paket INT NOT NULL,
    kode_barcode VARCHAR(50) NOT NULL UNIQUE, -- Serial number/barcode unit
    nama_unit_ruang VARCHAR(100) NOT NULL,
    status_unit VARCHAR(20) NOT NULL DEFAULT 'tersedia' CHECK (status_unit IN ('tersedia', 'disewa', 'maintenance', 'rusak')),
    catatan_kondisi TEXT DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cabang) REFERENCES cabang(id_cabang) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_paket) REFERENCES paket_sewa(id_paket) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 6. Tabel Transaksi Penyewaan Utama
CREATE TABLE penyewaan (
    id_sewa VARCHAR(30) PRIMARY KEY, -- Format ID: #TRX-100 / #GG-YYYYMMDD-xxxx
    id_user INT NOT NULL,
    id_operator INT DEFAULT NULL, -- Operator penginput sewa / pengelola
    id_unit INT DEFAULT NULL,
    id_cabang INT NOT NULL,
    tipe_layanan VARCHAR(20) NOT NULL CHECK (tipe_layanan IN ('ditempat', 'pickup', 'delivery')),
    tipe_sewa VARCHAR(20) NOT NULL DEFAULT 'jam' CHECK (tipe_sewa IN ('jam', 'harian')),
    durasi INT NOT NULL DEFAULT 1,
    jenis_tarif VARCHAR(20) NOT NULL DEFAULT 'weekday' CHECK (jenis_tarif IN ('weekday', 'weekend')),
    jenis_jaminan VARCHAR(50) DEFAULT NULL, -- KTP / SIM / STNK
    foto_jaminan VARCHAR(255) DEFAULT NULL,
    status_verifikasi_jaminan VARCHAR(20) DEFAULT 'menunggu' CHECK (status_verifikasi_jaminan IN ('menunggu', 'terverifikasi', 'ditolak')),
    waktu_mulai TIMESTAMP NOT NULL,
    waktu_kembali_rencana TIMESTAMP NOT NULL,
    waktu_kembali_aktual TIMESTAMP DEFAULT NULL,
    biaya_sewa NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    total_biaya NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    status_sewa VARCHAR(20) NOT NULL DEFAULT 'menunggu' CHECK (status_sewa IN ('menunggu', 'siap', 'diantar', 'sampai', 'aktif', 'habis', 'terlambat', 'selesai', 'ditolak')),
    alasan_penolakan TEXT DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_operator) REFERENCES users(id_user) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (id_unit) REFERENCES unit_ps(id_unit) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (id_cabang) REFERENCES cabang(id_cabang) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 7. Tabel Detail Pengantaran Delivery
CREATE TABLE delivery_info (
    id_delivery SERIAL PRIMARY KEY,
    id_sewa VARCHAR(30) NOT NULL UNIQUE,
    alamat_tujuan TEXT NOT NULL,
    kecamatan VARCHAR(100) DEFAULT NULL,
    patokan TEXT DEFAULT NULL,
    jarak_km NUMERIC(5,2) DEFAULT 0.00,
    ongkir NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    status_delivery VARCHAR(20) NOT NULL DEFAULT 'menunggu' CHECK (status_delivery IN ('menunggu', 'masih_diantar', 'sudah_sampai')),
    FOREIGN KEY (id_sewa) REFERENCES penyewaan(id_sewa) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 8. Tabel Transaksi Pembayaran
CREATE TABLE pembayaran (
    id_pembayaran SERIAL PRIMARY KEY,
    id_sewa VARCHAR(30) NOT NULL UNIQUE,
    id_operator_verifikasi INT DEFAULT NULL,
    midtrans_order_id VARCHAR(100) DEFAULT NULL,
    metode_pembayaran VARCHAR(20) NOT NULL CHECK (metode_pembayaran IN ('transfer', 'qris', 'cash', 'cod')),
    nama_pengirim VARCHAR(100) DEFAULT NULL,
    bank_asal VARCHAR(50) DEFAULT NULL,
    file_bukti VARCHAR(255) DEFAULT NULL,
    total_tagihan NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    status_bayar VARCHAR(20) NOT NULL DEFAULT 'belum_bayar' CHECK (status_bayar IN ('belum_bayar', 'menunggu_verifikasi', 'lunas', 'ditolak')),
    waktu_bayar TIMESTAMP DEFAULT NULL,
    FOREIGN KEY (id_sewa) REFERENCES penyewaan(id_sewa) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_operator_verifikasi) REFERENCES users(id_user) ON DELETE SET NULL ON UPDATE CASCADE
);

-- 9. Tabel Log Scan Barcode & Foto Serah-Terima Unit
CREATE TABLE log_serah_terima (
    id_log SERIAL PRIMARY KEY,
    id_sewa VARCHAR(30) NOT NULL,
    id_operator INT NOT NULL,
    jenis_log VARCHAR(20) NOT NULL CHECK (jenis_log IN ('penyerahan', 'pengembalian')),
    foto_bukti VARCHAR(255) NOT NULL,
    kondisi_unit VARCHAR(20) NOT NULL DEFAULT 'baik' CHECK (kondisi_unit IN ('baik', 'maint', 'rusak')),
    catatan TEXT DEFAULT NULL,
    waktu_log TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_sewa) REFERENCES penyewaan(id_sewa) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_operator) REFERENCES users(id_user) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 10. Tabel Denda Keterlambatan
CREATE TABLE denda (
    id_denda SERIAL PRIMARY KEY,
    id_sewa VARCHAR(30) NOT NULL UNIQUE,
    keterlambatan_jam INT NOT NULL DEFAULT 0,
    total_denda NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    status_denda VARCHAR(20) NOT NULL DEFAULT 'belum_bayar' CHECK (status_denda IN ('belum_bayar', 'lunas')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_sewa) REFERENCES penyewaan(id_sewa) ON DELETE CASCADE ON UPDATE CASCADE
);

-- 11. Tabel Pengeluaran Operasional Harian
CREATE TABLE pengeluaran (
    id_pengeluaran SERIAL PRIMARY KEY,
    id_cabang INT NOT NULL,
    id_operator INT NOT NULL,
    kategori VARCHAR(100) NOT NULL,
    jumlah_biaya NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    keterangan TEXT DEFAULT NULL,
    tanggal DATE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cabang) REFERENCES cabang(id_cabang) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (id_operator) REFERENCES users(id_user) ON DELETE CASCADE ON UPDATE CASCADE
);