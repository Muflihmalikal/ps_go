-- =====================================================================
-- 002_alokasi_unit.sql
-- Stok komponen dipakai bersama antara paket combo dan sewa satuan.
-- Unit yang dipakai sebuah penyewaan dicatat di tabel penyewaan_unit,
-- menggantikan kolom penyewaan.id_unit.
--
-- Jalankan SETELAH 001_schema_awal.sql, dan bersamaan dengan
-- proses_sewa.php versi baru (versi lama membutuhkan kolom id_unit).
-- Disarankan backup dulu:  pg_dump -U postgres ps_go > backup_sebelum_002.sql
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- 0. Pengaman: paket combo tidak boleh punya unit fisik sendiri
-- ---------------------------------------------------------------------
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM unit_ps u
          JOIN paket_sewa p ON p.id_paket = u.id_paket
         WHERE p.kategori = 'combo'
    ) THEN
        RAISE EXCEPTION 'Masih ada unit_ps yang terdaftar di paket combo. Pindahkan atau hapus dulu (unit fisik didaftarkan di paket komponennya, mis. PS4 dan TV), lalu ulangi migrasi ini.';
    END IF;
END $$;

CREATE FUNCTION cek_unit_bukan_combo() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    k varchar;
BEGIN
    SELECT kategori INTO k FROM paket_sewa WHERE id_paket = NEW.id_paket;
    IF k = 'combo' THEN
        RAISE EXCEPTION 'Paket combo tidak punya unit sendiri. Daftarkan unit pada paket komponennya (mis. PS4, TV).'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_unit_bukan_combo
    BEFORE INSERT OR UPDATE OF id_paket ON unit_ps
    FOR EACH ROW EXECUTE FUNCTION cek_unit_bukan_combo();

-- ---------------------------------------------------------------------
-- 1. Tabel alokasi: unit apa saja yang dipakai sebuah penyewaan
--    - sewa satuan : 1 baris
--    - sewa combo  : 1 baris per unit komponen (mis. 1 PS4 + 1 TV)
--    Kolom waktu_mulai, waktu_kembali_rencana, aktif adalah salinan yang
--    diisi dan disinkronkan trigger (dibutuhkan constraint anti-bentrok).
--    Dari PHP cukup isi id_sewa dan id_unit.
-- ---------------------------------------------------------------------
CREATE TABLE penyewaan_unit (
    id_sewa                VARCHAR(30) NOT NULL
                           REFERENCES penyewaan (id_sewa) ON UPDATE CASCADE ON DELETE CASCADE,
    id_unit                INT NOT NULL
                           REFERENCES unit_ps (id_unit) ON UPDATE CASCADE ON DELETE RESTRICT,
    waktu_mulai            TIMESTAMPTZ NOT NULL,
    waktu_kembali_rencana  TIMESTAMPTZ NOT NULL,
    aktif                  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id_sewa, id_unit),

    -- Satu unit tidak boleh dialokasikan ke dua penyewaan aktif yang jadwalnya beririsan
    CONSTRAINT ex_alokasi_unit_tidak_bentrok EXCLUDE USING gist (
        id_unit WITH =,
        tstzrange(waktu_mulai, waktu_kembali_rencana, '[)') WITH &&
    ) WHERE (aktif)
);

CREATE INDEX idx_penyewaan_unit_unit ON penyewaan_unit (id_unit);

-- Validasi + isi salinan jadwal saat baris dibuat (atau unitnya diganti)
CREATE FUNCTION isi_penyewaan_unit() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    s penyewaan%ROWTYPE;
    u unit_ps%ROWTYPE;
BEGIN
    SELECT * INTO s FROM penyewaan WHERE id_sewa = NEW.id_sewa;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Penyewaan % tidak ditemukan.', NEW.id_sewa
            USING ERRCODE = 'foreign_key_violation';
    END IF;

    SELECT * INTO u FROM unit_ps WHERE id_unit = NEW.id_unit;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Unit % tidak ditemukan.', NEW.id_unit
            USING ERRCODE = 'foreign_key_violation';
    END IF;

    IF u.id_cabang <> s.id_cabang THEN
        RAISE EXCEPTION 'Unit % bukan milik cabang penyewaan %.', u.kode_barcode, s.id_sewa
            USING ERRCODE = 'check_violation';
    END IF;

    -- Unit harus dari paket yang disewa, atau dari salah satu komponen paket combo-nya
    IF u.id_paket <> s.id_paket AND NOT EXISTS (
        SELECT 1 FROM paket_komponen k
         WHERE k.id_paket_parent = s.id_paket AND k.id_paket_child = u.id_paket
    ) THEN
        RAISE EXCEPTION 'Unit % bukan bagian dari paket penyewaan %.', u.kode_barcode, s.id_sewa
            USING ERRCODE = 'check_violation';
    END IF;

    NEW.waktu_mulai           := s.waktu_mulai;
    NEW.waktu_kembali_rencana := s.waktu_kembali_rencana;
    NEW.aktif                 := s.status_sewa NOT IN ('selesai', 'ditolak', 'dibatalkan');
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_penyewaan_unit_isi
    BEFORE INSERT OR UPDATE OF id_sewa, id_unit ON penyewaan_unit
    FOR EACH ROW EXECUTE FUNCTION isi_penyewaan_unit();

-- Jadwal/status penyewaan berubah -> alokasinya ikut (mis. batal = unit bebas lagi)
CREATE FUNCTION sinkron_penyewaan_unit() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    UPDATE penyewaan_unit
       SET waktu_mulai           = NEW.waktu_mulai,
           waktu_kembali_rencana = NEW.waktu_kembali_rencana,
           aktif                 = NEW.status_sewa NOT IN ('selesai', 'ditolak', 'dibatalkan')
     WHERE id_sewa = NEW.id_sewa;
    RETURN NULL;
END;
$$;

CREATE TRIGGER trg_penyewaan_sinkron_unit
    AFTER UPDATE OF waktu_mulai, waktu_kembali_rencana, status_sewa ON penyewaan
    FOR EACH ROW
    WHEN (OLD.waktu_mulai IS DISTINCT FROM NEW.waktu_mulai
       OR OLD.waktu_kembali_rencana IS DISTINCT FROM NEW.waktu_kembali_rencana
       OR OLD.status_sewa IS DISTINCT FROM NEW.status_sewa)
    EXECUTE FUNCTION sinkron_penyewaan_unit();

-- ---------------------------------------------------------------------
-- 2. Pindahkan data lama, lalu hapus penyewaan.id_unit
-- ---------------------------------------------------------------------
INSERT INTO penyewaan_unit (id_sewa, id_unit)
SELECT id_sewa, id_unit FROM penyewaan WHERE id_unit IS NOT NULL;

ALTER TABLE penyewaan DROP CONSTRAINT IF EXISTS ex_penyewaan_unit_tidak_bentrok;
ALTER TABLE penyewaan DROP CONSTRAINT IF EXISTS fk_penyewaan_unit;
ALTER TABLE penyewaan DROP COLUMN IF EXISTS id_unit;
ALTER TABLE unit_ps   DROP CONSTRAINT IF EXISTS uq_unit_id_paket;

-- ---------------------------------------------------------------------
-- 3. View & fungsi bantu (dipakai PHP agar logika stok ada di satu tempat)
-- ---------------------------------------------------------------------

-- Unit apa yang dibutuhkan untuk menyewa sebuah paket:
--   paket biasa -> dirinya sendiri x 1
--   paket combo -> komponen-komponennya (sesuai paket_komponen.jumlah)
CREATE VIEW v_kebutuhan_unit AS
SELECT p.id_paket        AS id_paket,
       p.id_paket        AS id_paket_unit,
       1                 AS jumlah
  FROM paket_sewa p
 WHERE p.kategori <> 'combo'
UNION ALL
SELECT k.id_paket_parent AS id_paket,
       k.id_paket_child  AS id_paket_unit,
       k.jumlah          AS jumlah
  FROM paket_komponen k;

-- Unit milik sebuah paket (paket biasa/komponen) yang kosong pada rentang waktu tertentu
CREATE FUNCTION unit_kosong(p_id_paket INT, p_mulai TIMESTAMPTZ, p_kembali TIMESTAMPTZ)
RETURNS SETOF unit_ps
LANGUAGE sql STABLE AS $$
    SELECT u.*
      FROM unit_ps u
     WHERE u.id_paket = p_id_paket
       AND u.status_unit IN ('tersedia', 'disewa')
       AND NOT EXISTS (
            SELECT 1 FROM penyewaan_unit pu
             WHERE pu.id_unit = u.id_unit
               AND pu.aktif
               AND tstzrange(pu.waktu_mulai, pu.waktu_kembali_rencana, '[)')
                   && tstzrange(p_mulai, p_kembali, '[)')
       )
     ORDER BY u.id_unit;
$$;

-- Stok tersedia SEKARANG per paket (untuk katalog).
-- Paket combo = jumlah set lengkap yang bisa dibentuk dari komponen yang masih bebas.
CREATE VIEW v_stok_paket AS
WITH bebas AS (
    SELECT u.id_paket, count(*)::int AS jumlah_bebas
      FROM unit_ps u
     WHERE u.status_unit = 'tersedia'
       AND NOT EXISTS (
            SELECT 1 FROM penyewaan_unit pu
             WHERE pu.id_unit = u.id_unit
               AND pu.aktif
               AND pu.waktu_mulai <= now()
               AND pu.waktu_kembali_rencana > now()
       )
     GROUP BY u.id_paket
)
SELECT p.id_paket,
       COALESCE((
           SELECT min(COALESCE(b.jumlah_bebas, 0) / k.jumlah)
             FROM v_kebutuhan_unit k
             LEFT JOIN bebas b ON b.id_paket = k.id_paket_unit
            WHERE k.id_paket = p.id_paket
       ), 0)::int AS stok_tersedia
  FROM paket_sewa p;

INSERT INTO schema_migrations (versi, keterangan)
VALUES ('002_alokasi_unit', 'Alokasi unit bersama (combo + satuan) lewat penyewaan_unit; hapus penyewaan.id_unit');

COMMIT;

-- =====================================================================
-- CONTOH PEMAKAIAN DARI PHP
-- =====================================================================
-- Kebutuhan unit sebuah paket (hasil kosong = paket combo belum punya komponen):
--   SELECT id_paket_unit, jumlah FROM v_kebutuhan_unit WHERE id_paket = :p ORDER BY id_paket_unit;
-- Unit kosong untuk satu komponen:
--   SELECT id_unit FROM unit_kosong(:paket_unit, CAST(:m AS timestamptz), CAST(:s AS timestamptz)) LIMIT :jumlah;
-- Setelah INSERT INTO penyewaan, catat alokasinya (satu baris per unit):
--   INSERT INTO penyewaan_unit (id_sewa, id_unit) VALUES (:sewa, :unit);
-- Stok katalog:
--   SELECT p.*, s.stok_tersedia FROM paket_sewa p JOIN v_stok_paket s USING (id_paket);