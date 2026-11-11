BEGIN;

CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE schema_migrations (
    versi            VARCHAR(100) PRIMARY KEY,
    keterangan       TEXT,
    dijalankan_pada  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE SEQUENCE seq_kode_sewa START 1;

-- Format kode: GG-YYYYMMDD-0001 (tanggal WIB)
CREATE FUNCTION generate_kode_sewa() RETURNS varchar
LANGUAGE sql VOLATILE AS $$
    SELECT 'P2B-' || to_char(now() AT TIME ZONE 'Asia/Jakarta', 'YYYYMMDD')
           || '-' || lpad(nextval('seq_kode_sewa')::text, 4, '0');
$$;

CREATE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

-- ---------------------------------------------------------------------
-- 2. Cabang
-- ---------------------------------------------------------------------
CREATE TABLE cabang (
    id_cabang    SERIAL PRIMARY KEY,
    nama_cabang  VARCHAR(100) NOT NULL,
    alamat       TEXT NOT NULL,
    koordinat    VARCHAR(100),
    no_wa        VARCHAR(20),
    jam_buka     TIME,          -- NULL pada jam_buka DAN jam_tutup = buka 24 jam
    jam_tutup    TIME,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_cabang_jam CHECK ((jam_buka IS NULL) = (jam_tutup IS NULL))
);

-- ---------------------------------------------------------------------
-- 3. Pengguna (owner, admin, operator, pelanggan)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id_user            SERIAL PRIMARY KEY,
    id_cabang          INT REFERENCES cabang (id_cabang) ON UPDATE CASCADE ON DELETE RESTRICT,
    nama               VARCHAR(100) NOT NULL,
    email              VARCHAR(100) NOT NULL,
    password           VARCHAR(255) NOT NULL,
    no_wa              VARCHAR(20)  NOT NULL,
    role               VARCHAR(20)  NOT NULL DEFAULT 'pelanggan',
    is_member          BOOLEAN NOT NULL DEFAULT FALSE,
    is_walkin          BOOLEAN NOT NULL DEFAULT FALSE,   -- akun khusus untuk sewa "di tempat"
    is_active          BOOLEAN NOT NULL DEFAULT TRUE,    -- nonaktifkan akun, jangan dihapus
    status_verifikasi  VARCHAR(20)  NOT NULL DEFAULT 'unverified',
    last_login_at      TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_users_role CHECK (role IN ('owner', 'admin', 'operator', 'pelanggan')),
    CONSTRAINT ck_users_status_verifikasi CHECK (status_verifikasi IN ('unverified', 'verified')),
    CONSTRAINT ck_users_staf_punya_cabang CHECK (role NOT IN ('admin', 'operator') OR id_cabang IS NOT NULL),
    CONSTRAINT ck_users_walkin_pelanggan CHECK (NOT is_walkin OR role = 'pelanggan')
);

CREATE UNIQUE INDEX ux_users_email_lower ON users (lower(email));
CREATE UNIQUE INDEX ux_users_satu_walkin ON users (is_walkin) WHERE is_walkin;
CREATE INDEX idx_users_role_cabang ON users (role, id_cabang);

-- ---------------------------------------------------------------------
-- 4. Master paket & tarif
-- ---------------------------------------------------------------------
CREATE TABLE paket_sewa (
    id_paket         SERIAL PRIMARY KEY,
    id_cabang        INT NOT NULL REFERENCES cabang (id_cabang) ON UPDATE CASCADE ON DELETE RESTRICT,
    kode_paket       VARCHAR(50)  NOT NULL,
    nama_paket       VARCHAR(100) NOT NULL,
    kategori         VARCHAR(20)  NOT NULL,
    label_badge      VARCHAR(50)  DEFAULT 'KONSOL ONLY',
    tag_highlight    VARCHAR(100),
    deskripsi        TEXT,
    gambar           VARCHAR(255),
    harga_wk_12h     NUMERIC(12,2) NOT NULL DEFAULT 0,   -- weekday (Senin-Kamis)
    harga_wk_24h     NUMERIC(12,2) NOT NULL DEFAULT 0,
    harga_wn_12h     NUMERIC(12,2) NOT NULL DEFAULT 0,   -- weekend (Jumat-Minggu)
    harga_wn_24h     NUMERIC(12,2) NOT NULL DEFAULT 0,
    denda_per_jam    NUMERIC(12,2) NOT NULL DEFAULT 0,   -- isi sesuai kebijakan toko
    override_status  VARCHAR(20)  NOT NULL DEFAULT 'auto',
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_paket_kode_per_cabang UNIQUE (id_cabang, kode_paket),
    CONSTRAINT uq_paket_id_cabang UNIQUE (id_paket, id_cabang),
    CONSTRAINT ck_paket_kategori CHECK (kategori IN ('ps3', 'ps4', 'ps5', 'combo', 'tv')),
    CONSTRAINT ck_paket_override CHECK (override_status IN ('auto', 'Maintenance')),
    CONSTRAINT ck_paket_harga CHECK (
        harga_wk_12h >= 0 AND harga_wk_24h >= 0 AND harga_wn_12h >= 0
        AND harga_wn_24h >= 0 AND denda_per_jam >= 0
    )
);

CREATE INDEX idx_paket_katalog ON paket_sewa (id_cabang, kategori) WHERE is_active;

-- Komponen paket combo (mis. combo Playbox = 1 PS4 + 1 TV)
CREATE TABLE paket_komponen (
    id_paket_parent  INT NOT NULL REFERENCES paket_sewa (id_paket) ON UPDATE CASCADE ON DELETE CASCADE,
    id_paket_child   INT NOT NULL REFERENCES paket_sewa (id_paket) ON UPDATE CASCADE ON DELETE CASCADE,
    jumlah           INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id_paket_parent, id_paket_child),
    CONSTRAINT ck_komponen_beda   CHECK (id_paket_parent <> id_paket_child),
    CONSTRAINT ck_komponen_jumlah CHECK (jumlah > 0)
);

CREATE INDEX idx_komponen_child ON paket_komponen (id_paket_child);

-- Induk harus combo, anak bukan combo, dan keduanya satu cabang
CREATE FUNCTION cek_paket_komponen() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    p paket_sewa%ROWTYPE;
    c paket_sewa%ROWTYPE;
BEGIN
    SELECT * INTO p FROM paket_sewa WHERE id_paket = NEW.id_paket_parent;
    SELECT * INTO c FROM paket_sewa WHERE id_paket = NEW.id_paket_child;

    IF p.kategori <> 'combo' THEN
        RAISE EXCEPTION 'Paket induk "%" harus berkategori combo.', p.kode_paket
            USING ERRCODE = 'check_violation';
    END IF;
    IF c.kategori = 'combo' THEN
        RAISE EXCEPTION 'Komponen "%" tidak boleh berkategori combo.', c.kode_paket
            USING ERRCODE = 'check_violation';
    END IF;
    IF p.id_cabang <> c.id_cabang THEN
        RAISE EXCEPTION 'Paket induk dan komponen harus berada di cabang yang sama.'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_paket_komponen_cek
    BEFORE INSERT OR UPDATE ON paket_komponen
    FOR EACH ROW EXECUTE FUNCTION cek_paket_komponen();

-- ---------------------------------------------------------------------
-- 5. Unit fisik (PS, TV, set combo)
--    Set combo didaftarkan sebagai unit sendiri (mis. CMB-001) di paket
--    combo. Unit komponennya jangan didaftarkan lagi sebagai unit terpisah.
-- ---------------------------------------------------------------------
CREATE TABLE unit_ps (
    id_unit          SERIAL PRIMARY KEY,
    id_cabang        INT NOT NULL REFERENCES cabang (id_cabang) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_paket         INT NOT NULL,
    kode_barcode     VARCHAR(50)  NOT NULL UNIQUE,
    nama_unit_ruang  VARCHAR(100) NOT NULL,
    status_unit      VARCHAR(20)  NOT NULL DEFAULT 'tersedia',
    catatan_kondisi  TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_unit_id_paket UNIQUE (id_unit, id_paket),
    CONSTRAINT fk_unit_paket FOREIGN KEY (id_paket, id_cabang)
        REFERENCES paket_sewa (id_paket, id_cabang) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ck_unit_status CHECK (status_unit IN ('tersedia', 'disewa', 'maintenance', 'rusak'))
);

CREATE INDEX idx_unit_paket_status ON unit_ps (id_paket, status_unit);

-- ---------------------------------------------------------------------
-- 6. Penyewaan (transaksi utama)
--    Nama, email, dan WA pelanggan TIDAK diduplikasi di sini: ambil dari users.
--    Sewa "di tempat" memakai akun users.is_walkin = TRUE.
-- ---------------------------------------------------------------------
CREATE TABLE penyewaan (
    id_sewa                    VARCHAR(30) PRIMARY KEY DEFAULT generate_kode_sewa(),
    id_user                    INT NOT NULL REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_operator                INT REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE SET NULL,
    id_cabang                  INT NOT NULL REFERENCES cabang (id_cabang) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_paket                   INT NOT NULL,
    id_unit                    INT,
    tipe_layanan               VARCHAR(20)  NOT NULL,
    tipe_sewa                  VARCHAR(20)  NOT NULL DEFAULT 'jam',
    durasi                     INT NOT NULL DEFAULT 1,
    jenis_tarif                VARCHAR(20)  NOT NULL DEFAULT 'weekday',
    jenis_jaminan              VARCHAR(50),                 -- KTP / SIM / STNK
    foto_jaminan               VARCHAR(255),
    status_verifikasi_jaminan  VARCHAR(20)  NOT NULL DEFAULT 'menunggu',
    waktu_mulai                TIMESTAMPTZ NOT NULL,
    waktu_kembali_rencana      TIMESTAMPTZ NOT NULL,
    waktu_kembali_aktual       TIMESTAMPTZ,
    harga_12h_snapshot         NUMERIC(12,2) NOT NULL,      -- harga saat pesan (tidak ikut berubah)
    harga_24h_snapshot         NUMERIC(12,2) NOT NULL,
    biaya_sewa                 NUMERIC(12,2) NOT NULL DEFAULT 0,
    total_biaya                NUMERIC(12,2) NOT NULL DEFAULT 0,
    status_sewa                VARCHAR(20)  NOT NULL DEFAULT 'menunggu',
    alasan_penolakan           TEXT,
    batas_bayar                TIMESTAMPTZ,
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT fk_penyewaan_paket FOREIGN KEY (id_paket, id_cabang)
        REFERENCES paket_sewa (id_paket, id_cabang) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_penyewaan_unit FOREIGN KEY (id_unit, id_paket)
        REFERENCES unit_ps (id_unit, id_paket) ON UPDATE RESTRICT ON DELETE RESTRICT,

    CONSTRAINT ck_penyewaan_tipe_layanan CHECK (tipe_layanan IN ('ditempat', 'pickup', 'delivery')),
    CONSTRAINT ck_penyewaan_tipe_sewa    CHECK (tipe_sewa IN ('jam', 'harian')),
    CONSTRAINT ck_penyewaan_jenis_tarif  CHECK (jenis_tarif IN ('weekday', 'weekend')),
    CONSTRAINT ck_penyewaan_jaminan      CHECK (status_verifikasi_jaminan IN ('menunggu', 'terverifikasi', 'ditolak')),
    CONSTRAINT ck_penyewaan_status       CHECK (status_sewa IN (
        'menunggu', 'siap', 'diantar', 'sampai', 'aktif', 'habis',
        'terlambat', 'selesai', 'ditolak', 'dibatalkan'
    )),
    CONSTRAINT ck_penyewaan_durasi_positif  CHECK (durasi > 0),
    CONSTRAINT ck_penyewaan_rentang_waktu   CHECK (waktu_kembali_rencana > waktu_mulai),
    CONSTRAINT ck_penyewaan_kembali_aktual  CHECK (waktu_kembali_aktual IS NULL OR waktu_kembali_aktual >= waktu_mulai),
    CONSTRAINT ck_penyewaan_snapshot        CHECK (harga_12h_snapshot >= 0 AND harga_24h_snapshot >= 0),
    CONSTRAINT ck_penyewaan_biaya_wajar     CHECK (biaya_sewa >= 0 AND total_biaya >= biaya_sewa),

    -- Satu unit tidak boleh dipakai dua pesanan aktif pada rentang waktu yang beririsan
    CONSTRAINT ex_penyewaan_unit_tidak_bentrok EXCLUDE USING gist (
        id_unit WITH =,
        tstzrange(waktu_mulai, waktu_kembali_rencana, '[)') WITH &&
    ) WHERE (id_unit IS NOT NULL AND status_sewa NOT IN ('selesai', 'ditolak', 'dibatalkan'))
);

CREATE INDEX idx_penyewaan_user        ON penyewaan (id_user, created_at DESC);
CREATE INDEX idx_penyewaan_paket       ON penyewaan (id_paket);
CREATE INDEX idx_penyewaan_status      ON penyewaan (status_sewa, waktu_mulai);
CREATE INDEX idx_penyewaan_cabang_waktu ON penyewaan (id_cabang, waktu_mulai DESC);
CREATE INDEX idx_penyewaan_kedaluwarsa ON penyewaan (batas_bayar) WHERE status_sewa = 'menunggu';

-- Detail pengantaran (hanya untuk tipe_layanan = 'delivery')
CREATE TABLE delivery_info (
    id_delivery      SERIAL PRIMARY KEY,
    id_sewa          VARCHAR(30) NOT NULL UNIQUE
                     REFERENCES penyewaan (id_sewa) ON UPDATE CASCADE ON DELETE CASCADE,
    alamat_tujuan    TEXT NOT NULL,
    kecamatan        VARCHAR(100),
    patokan          TEXT,
    jarak_km         NUMERIC(6,2)  NOT NULL DEFAULT 0,
    ongkir           NUMERIC(12,2) NOT NULL DEFAULT 0,
    status_delivery  VARCHAR(20)   NOT NULL DEFAULT 'menunggu',
    CONSTRAINT ck_delivery_status CHECK (status_delivery IN ('menunggu', 'masih_diantar', 'sudah_sampai')),
    CONSTRAINT ck_delivery_angka  CHECK (jarak_km >= 0 AND ongkir >= 0)
);

-- ---------------------------------------------------------------------
-- 7. Pembayaran (satu baris per penyewaan)
-- ---------------------------------------------------------------------
CREATE TABLE pembayaran (
    id_pembayaran           SERIAL PRIMARY KEY,
    id_sewa                 VARCHAR(30) NOT NULL UNIQUE
                            REFERENCES penyewaan (id_sewa) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_operator_verifikasi  INT REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE SET NULL,
    midtrans_order_id       VARCHAR(100),
    metode_pembayaran       VARCHAR(20) NOT NULL,
    nama_pengirim           VARCHAR(100),
    bank_asal               VARCHAR(50),
    file_bukti              VARCHAR(255),
    bukti_hash              CHAR(64),        -- SHA-256 file asli, untuk mendeteksi bukti dipakai ulang
    total_tagihan           NUMERIC(12,2) NOT NULL DEFAULT 0,
    status_bayar            VARCHAR(20) NOT NULL DEFAULT 'belum_bayar',
    catatan_verifikasi      TEXT,
    waktu_bayar             TIMESTAMPTZ,
    waktu_verifikasi        TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_pembayaran_metode CHECK (metode_pembayaran IN ('transfer', 'qris', 'cash', 'cod')),
    CONSTRAINT ck_pembayaran_status CHECK (status_bayar IN ('belum_bayar', 'menunggu_verifikasi', 'lunas', 'ditolak')),
    CONSTRAINT ck_pembayaran_tagihan_wajar CHECK (total_tagihan >= 0),
    CONSTRAINT ck_pembayaran_bukti_wajib CHECK (
        metode_pembayaran IN ('cod', 'cash')
        OR status_bayar = 'belum_bayar'
        OR file_bukti IS NOT NULL
    )
);

-- Satu bukti tidak boleh dipakai di dua pembayaran yang masih berjalan/lunas
CREATE UNIQUE INDEX ux_pembayaran_bukti_hash_aktif
    ON pembayaran (bukti_hash)
    WHERE bukti_hash IS NOT NULL AND status_bayar <> 'ditolak';
CREATE UNIQUE INDEX ux_pembayaran_midtrans
    ON pembayaran (midtrans_order_id) WHERE midtrans_order_id IS NOT NULL;
CREATE INDEX idx_pembayaran_status ON pembayaran (status_bayar);

-- Rekening bank & QRIS tujuan pembayaran per cabang (menggantikan teks [Nama Bank])
CREATE TABLE rekening_pembayaran (
    id_rekening  SERIAL PRIMARY KEY,
    id_cabang    INT NOT NULL REFERENCES cabang (id_cabang) ON UPDATE CASCADE ON DELETE CASCADE,
    jenis        VARCHAR(10) NOT NULL,
    nama_bank    VARCHAR(50),
    no_rekening  VARCHAR(40),
    atas_nama    VARCHAR(100),
    file_qris    VARCHAR(255),
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_rekening_jenis CHECK (jenis IN ('transfer', 'qris')),
    CONSTRAINT ck_rekening_lengkap CHECK (
        (jenis = 'transfer' AND nama_bank IS NOT NULL AND no_rekening IS NOT NULL AND atas_nama IS NOT NULL)
        OR (jenis = 'qris' AND file_qris IS NOT NULL)
    )
);

CREATE INDEX idx_rekening_cabang ON rekening_pembayaran (id_cabang) WHERE is_active;

-- ---------------------------------------------------------------------
-- 8. Serah-terima, denda, pengeluaran
-- ---------------------------------------------------------------------
CREATE TABLE log_serah_terima (
    id_log        SERIAL PRIMARY KEY,
    id_sewa       VARCHAR(30) NOT NULL REFERENCES penyewaan (id_sewa) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_operator   INT NOT NULL REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE RESTRICT,
    jenis_log     VARCHAR(20) NOT NULL,
    foto_bukti    VARCHAR(255) NOT NULL,
    kondisi_unit  VARCHAR(20) NOT NULL DEFAULT 'baik',
    catatan       TEXT,
    waktu_log     TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_log_jenis   CHECK (jenis_log IN ('penyerahan', 'pengembalian')),
    CONSTRAINT ck_log_kondisi CHECK (kondisi_unit IN ('baik', 'maint', 'rusak'))
);

CREATE INDEX idx_log_serah_sewa ON log_serah_terima (id_sewa);

CREATE TABLE denda (
    id_denda           SERIAL PRIMARY KEY,
    id_sewa            VARCHAR(30) NOT NULL UNIQUE
                       REFERENCES penyewaan (id_sewa) ON UPDATE CASCADE ON DELETE RESTRICT,
    keterlambatan_jam  INT NOT NULL DEFAULT 0,
    total_denda        NUMERIC(12,2) NOT NULL DEFAULT 0,
    status_denda       VARCHAR(20) NOT NULL DEFAULT 'belum_bayar',
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_denda_status CHECK (status_denda IN ('belum_bayar', 'lunas')),
    CONSTRAINT ck_denda_angka  CHECK (keterlambatan_jam >= 0 AND total_denda >= 0)
);

CREATE TABLE pengeluaran (
    id_pengeluaran  SERIAL PRIMARY KEY,
    id_cabang       INT NOT NULL REFERENCES cabang (id_cabang) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_operator     INT NOT NULL REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE RESTRICT,
    kategori        VARCHAR(100) NOT NULL,
    jumlah_biaya    NUMERIC(12,2) NOT NULL,
    keterangan      TEXT,
    tanggal         DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_pengeluaran_jumlah CHECK (jumlah_biaya > 0)
);

CREATE INDEX idx_pengeluaran_cabang_tanggal ON pengeluaran (id_cabang, tanggal DESC);

-- ---------------------------------------------------------------------
-- 9. Keamanan: pembatasan percobaan & jejak audit
-- ---------------------------------------------------------------------
-- kunci = SHA-256 (hex) dari IP atau email; jangan simpan nilai mentahnya.
CREATE TABLE percobaan_akses (
    id_percobaan  BIGSERIAL PRIMARY KEY,
    jenis         VARCHAR(20) NOT NULL,
    kunci         CHAR(64)    NOT NULL,
    berhasil      BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT ck_percobaan_jenis CHECK (jenis IN ('login', 'register', 'upload'))
);

CREATE INDEX idx_percobaan_cari  ON percobaan_akses (jenis, kunci, created_at DESC);
CREATE INDEX idx_percobaan_waktu ON percobaan_akses (created_at);

CREATE FUNCTION bersihkan_percobaan_akses(umur interval DEFAULT interval '1 day') RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE
    jumlah integer;
BEGIN
    DELETE FROM percobaan_akses WHERE created_at < now() - umur;
    GET DIAGNOSTICS jumlah = ROW_COUNT;
    RETURN jumlah;
END;
$$;

CREATE TABLE audit_log (
    id_audit    BIGSERIAL PRIMARY KEY,
    id_user     INT REFERENCES users (id_user) ON UPDATE CASCADE ON DELETE SET NULL,
    aksi        VARCHAR(60) NOT NULL,      -- mis. 'verifikasi_bayar', 'ubah_status_sewa'
    tabel       VARCHAR(60),
    id_target   VARCHAR(60),
    detail      JSONB,
    ip          VARCHAR(45),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_target ON audit_log (tabel, id_target);
CREATE INDEX idx_audit_waktu  ON audit_log (created_at DESC);

-- ---------------------------------------------------------------------
-- 10. Pembatalan otomatis pesanan kedaluwarsa
--     Dipanggil dari PHP: SELECT batalkan_pesanan_kedaluwarsa();
-- ---------------------------------------------------------------------
CREATE FUNCTION batalkan_pesanan_kedaluwarsa() RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE
    jumlah integer;
BEGIN
    UPDATE penyewaan p
       SET status_sewa = 'ditolak',
           alasan_penolakan = CASE
               WHEN EXISTS (SELECT 1 FROM pembayaran b
                             WHERE b.id_sewa = p.id_sewa AND b.metode_pembayaran = 'cod')
                   THEN 'Tidak datang sesuai jadwal pengambilan (bayar di toko)'
               WHEN EXISTS (SELECT 1 FROM pembayaran b
                             WHERE b.id_sewa = p.id_sewa AND b.status_bayar = 'ditolak')
                   THEN 'Pembayaran ditolak dan tidak diperbaiki sampai batas waktu'
               ELSE 'Batas waktu pembayaran habis'
           END
     WHERE p.status_sewa = 'menunggu'
       AND p.batas_bayar IS NOT NULL
       AND p.batas_bayar < now()
       AND NOT EXISTS (
            SELECT 1 FROM pembayaran b
             WHERE b.id_sewa = p.id_sewa
               AND b.status_bayar IN ('menunggu_verifikasi', 'lunas')
       );

    GET DIAGNOSTICS jumlah = ROW_COUNT;
    RETURN jumlah;
END;
$$;

-- ---------------------------------------------------------------------
-- 11. Trigger updated_at
-- ---------------------------------------------------------------------
CREATE TRIGGER trg_cabang_updated     BEFORE UPDATE ON cabang     FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_users_updated      BEFORE UPDATE ON users      FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_paket_updated      BEFORE UPDATE ON paket_sewa FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_unit_updated       BEFORE UPDATE ON unit_ps    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_penyewaan_updated  BEFORE UPDATE ON penyewaan  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_pembayaran_updated BEFORE UPDATE ON pembayaran FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------
-- 12. Data wajib: akun walk-in untuk sewa "di tempat"
--     Password '!' bukan hash yang valid, jadi akun ini tidak bisa dipakai login.
--     Ubah no_wa sesuai kebutuhan.
-- ---------------------------------------------------------------------
INSERT INTO users (nama, email, password, no_wa, role, is_walkin, status_verifikasi)
VALUES ('Pelanggan Walk-in', 'walkin@ps-go.local', '!', '0000000000', 'pelanggan', TRUE, 'verified');

INSERT INTO schema_migrations (versi, keterangan)
VALUES ('001_schema_awal', 'Skema lengkap PostgreSQL lokal (gabungan Script-11 + migrasi lama + perbaikan)');

COMMIT;

-- =====================================================================
-- CATATAN PENGGUNAAN
-- =====================================================================
-- Admin pertama: daftar lewat register.php, lalu naikkan role-nya:
--   UPDATE users SET role = 'owner', id_cabang = NULL WHERE email = 'email_anda@contoh.com';
--   UPDATE users SET role = 'admin', id_cabang = 1    WHERE email = 'admin@contoh.com';
--
-- Reset total (HANYA untuk database lokal, menghapus SEMUA data):
--   DROP SCHEMA public CASCADE; CREATE SCHEMA public;