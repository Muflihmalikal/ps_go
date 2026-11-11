BEGIN;
INSERT INTO cabang (id_cabang, nama_cabang, alamat, no_wa)
VALUES (1, 'Galaxy Game Lumajang', 'Lumajang', '088973539946')
ON CONFLICT (id_cabang) DO NOTHING;

INSERT INTO paket_sewa 
(id_paket, id_cabang, kode_paket, nama_paket, kategori, gambar, harga_wk_12h, harga_wk_24h, harga_wn_12h, harga_wn_24h)
VALUES
(1, 1, 'PS4-ONLY', 'PS 4 Aja', 'ps4', 'ps4.png', 40000, 70000, 50000, 80000),
(2, 1, 'PS3-ONLY', 'PS 3 Aja', 'ps3', 'ps3.png', 30000, 50000, 30000, 50000),
(3, 1, 'TV-43', 'TV LED 43 Inch', 'tv', 'tv-43.png', 30000, 40000, 30000, 40000),
(4, 1, 'TV-32', 'TV LED 32/40 Inch', 'tv', 'tv-32.png', 20000, 30000, 20000, 30000);

INSERT INTO paket_sewa 
(id_paket, id_cabang, kode_paket, nama_paket, kategori, label_badge, gambar, harga_wk_12h, harga_wk_24h, harga_wn_12h, harga_wn_24h)
VALUES
(5, 1, 'CMB-PLAYBOX-PS4', 'Playbox PS 4 + TV', 'combo', 'PAKET LENGKAP', 'paket-ps4.png', 70000, 100000, 70000, 100000),
(6, 1, 'CMB-PLAYBOX-PS3', 'Playbox PS 3 + TV', 'combo', 'PAKET LENGKAP', 'paket-ps3.png', 50000, 70000, 50000, 70000);

-- 4. Set Komponen untuk Paket Combo
INSERT INTO paket_komponen (id_paket_parent, id_paket_child, jumlah)
VALUES
(5, 1, 1), -- Playbox PS4 butuh 1x PS 4
(5, 3, 1), -- Playbox PS4 butuh 1x TV 43 Inch (Diasumsikan pakai TV 43 Inch untuk paket atas)
(6, 2, 1), -- Playbox PS3 butuh 1x PS 3
(6, 4, 1); -- Playbox PS3 butuh 1x TV 32/40 Inch (Diasumsikan pakai TV 32 Inch)

INSERT INTO unit_ps (id_cabang, id_paket, kode_barcode, nama_unit_ruang, status_unit)
VALUES
-- Unit PS 4
(1, 1, 'SN-PS4-001', 'PS4 Unit 1', 'tersedia'),
(1, 1, 'SN-PS4-002', 'PS4 Unit 2', 'tersedia'),
-- Unit PS 3
(1, 2, 'SN-PS3-001', 'PS3 Unit 1', 'tersedia'),
(1, 2, 'SN-PS3-002', 'PS3 Unit 2', 'tersedia'),
-- Unit TV 43
(1, 3, 'SN-TV43-001', 'TV 43" Unit 1', 'tersedia'),
(1, 3, 'SN-TV43-002', 'TV 43" Unit 2', 'tersedia'),
-- Unit TV 32
(1, 4, 'SN-TV32-001', 'TV 32" Unit 1', 'tersedia'),
(1, 4, 'SN-TV32-002', 'TV 32" Unit 2', 'tersedia');

-- Update sequences (Opsional, agar jika ada insert baru dari aplikasi tidak bentrok dengan ID dummy)
SELECT setval('paket_sewa_id_paket_seq', (SELECT MAX(id_paket) FROM paket_sewa));
SELECT setval('unit_ps_id_unit_seq', (SELECT MAX(id_unit) FROM unit_ps));

COMMIT;