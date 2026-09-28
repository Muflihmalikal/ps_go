-- 1. tabel cabang
create table cabang (
    id_cabang serial primary key,
    nama_cabang varchar(100) not null,
    alamat text not null,
    koordinat varchar(255)
);

-- 2. tabel unit_ps
create table unit_ps (
    id_unit serial primary key,
    id_cabang int not null,
    jenis_ps varchar(50) not null,
    harga_per_jam decimal(10, 2) not null,
    harga_harian decimal(10, 2) not null,
    status_unit varchar(20) default 'tersedia',
    constraint fk_unit_cabang foreign key (id_cabang) references cabang(id_cabang) on delete cascade
);

-- 3. tabel users
create table users (
    id_user serial primary key,
    id_cabang int,
    nama varchar(100) not null,
    email varchar(100) unique not null,
    password varchar(255) not null,
    no_wa varchar(20) not null,
    role varchar(20) default 'customer',
    is_member boolean default false,
    status_verifikasi boolean default false,
    constraint fk_user_cabang foreign key (id_cabang) references cabang(id_cabang) on delete set null
);

-- 4. tabel penyewaan
create table penyewaan (
    id_sewa serial primary key,
    id_user int not null,
    id_unit int not null,
    id_cabang int not null,
    tipe_layanan varchar(50) not null,
    jenis_jaminan varchar(50) not null,
    foto_jaminan text,
    waktu_mulai timestamp not null,
    waktu_kembali_rencana timestamp not null,
    waktu_kembali_aktual timestamp,
    status_sewa varchar(20) default 'berjalan',
    constraint fk_sewa_user foreign key (id_user) references users(id_user),
    constraint fk_sewa_unit foreign key (id_unit) references unit_ps(id_unit),
    constraint fk_sewa_cabang foreign key (id_cabang) references cabang(id_cabang)
);

-- 5. tabel delivery_info
create table delivery_info (
    id_delivery serial primary key,
    id_sewa int not null,
    alamat_tujuan text not null,
    jarak_km decimal(5, 2) not null,
    ongkir decimal(10, 2) not null,
    constraint fk_delivery_sewa foreign key (id_sewa) references penyewaan(id_sewa) on delete cascade
);

-- 6. tabel pembayaran
create table pembayaran (
    id_pembayaran serial primary key,
    id_sewa int not null unique,
    midtrans_order_id varchar(100) unique,
    total_tagihan decimal(12, 2) not null,
    status_bayar varchar(20) default 'pending',
    waktu_bayar timestamp,
    constraint fk_pembayaran_sewa foreign key (id_sewa) references penyewaan(id_sewa) on delete cascade
);

-- 7. tabel denda
create table denda (
    id_denda serial primary key,
    id_sewa int not null,
    keterlambatan_jam int not null default 0,
    total_denda decimal(10, 2) not null default 0,
    status_denda varchar(20) default 'belum dibayar',
    constraint fk_denda_sewa foreign key (id_sewa) references penyewaan(id_sewa) on delete cascade
);

-- 8. tabel pengeluaran
create table pengeluaran (
    id_pengeluaran serial primary key,
    id_cabang int not null,
    kategori varchar(50) not null,
    jumlah_biaya decimal(12, 2) not null,
    keterangan text,
    tanggal date not null,
    constraint fk_pengeluaran_cabang foreign key (id_cabang) references cabang(id_cabang) on delete cascade
);

-- 1. insert data cabang
insert into cabang (nama_cabang, alamat, koordinat) values
('ps-go suhat', 'jl. soekarno hatta no. 9, malang', '-7.9467,112.6156'),
('ps-go sawojajar', 'jl. danau toba no. 12, malang', '-7.9745,112.6521');

-- 2. insert data unit_ps
insert into unit_ps (id_cabang, jenis_ps, harga_per_jam, harga_harian, status_unit) values
(1, 'ps3', 40000, 100000, 'tersedia'),
(1, 'ps3', 40000, 100000, 'tersedia'),
(1, 'ps4', 50000, 120000, 'disewa'),
(1, 'ps4', 50000, 120000, 'tersedia'),
(1, 'ps5', 75000, 200000, 'disewa'),
(2, 'ps3', 40000, 100000, 'tersedia'),
(2, 'ps4', 50000, 120000, 'maintenance'),
(2, 'ps4', 50000, 120000, 'disewa'),
(2, 'ps5', 75000, 200000, 'tersedia'),
(2, 'ps5', 75000, 200000, 'disewa');

-- 3. insert data users
insert into users (id_cabang, nama, email, password, no_wa, role, is_member, status_verifikasi) values
(1, 'muflih admin', 'admin.suhat@psgo.com', 'hash_pass_123', '081234567890', 'admin', true, true),
(2, 'sarah admin', 'admin.sawojajar@psgo.com', 'hash_pass_123', '081234567891', 'admin', true, true),
(null, 'sulthan', 'sulthan@gmail.com', 'hash_pass_123', '085612345678', 'customer', true, true),
(null, 'alya nisa', 'alya@gmail.com', 'hash_pass_123', '082345678901', 'customer', true, true),
(null, 'bian athallah', 'bian@gmail.com', 'hash_pass_123', '083456789012', 'customer', false, true),
(null, 'budi santoso', 'budi@gmail.com', 'hash_pass_123', '084567890123', 'customer', false, false),
(null, 'citra dewi', 'citra@gmail.com', 'hash_pass_123', '085678901234', 'customer', true, true),
(null, 'dika pratama', 'dika@gmail.com', 'hash_pass_123', '086789012345', 'customer', false, true),
(null, 'eka rahmawati', 'eka@gmail.com', 'hash_pass_123', '087890123456', 'customer', true, true),
(null, 'fajar gumilar', 'fajar@gmail.com', 'hash_pass_123', '088901234567', 'customer', false, true);

-- 4. insert data penyewaan
insert into penyewaan (id_user, id_unit, id_cabang, tipe_layanan, jenis_jaminan, foto_jaminan, waktu_mulai, waktu_kembali_rencana, waktu_kembali_aktual, status_sewa) values
-- transaksi 1: selesai (self-pickup)
(3, 1, 1, 'self-pickup', 'ktp', 'ktp_sulthan.jpg', '2026-09-20 10:00:00', '2026-09-21 10:00:00', '2026-09-21 09:45:00', 'selesai'),
-- transaksi 2: selesai (delivery)
(4, 4, 1, 'delivery', 'ktp', 'ktp_alya.jpg', '2026-09-22 14:00:00', '2026-09-23 14:00:00', '2026-09-23 13:50:00', 'selesai'),
-- transaksi 3: terlambat (ada denda)
(5, 8, 2, 'self-pickup', 'stnk', 'stnk_bian.jpg', '2026-09-24 15:00:00', '2026-09-25 15:00:00', '2026-09-25 18:00:00', 'selesai'),
-- transaksi 4: berjalan saat ini (delivery suhat)
(7, 3, 1, 'delivery', 'ktp', 'ktp_citra.jpg', '2026-09-27 09:00:00', '2026-09-29 09:00:00', null, 'berjalan'),
-- transaksi 5: berjalan saat ini (self-pickup suhat)
(8, 5, 1, 'self-pickup', 'ktp', 'ktp_dika.jpg', '2026-09-28 08:00:00', '2026-09-28 20:00:00', null, 'berjalan'),
-- transaksi 6: berjalan saat ini (delivery sawojajar)
(9, 10, 2, 'delivery', 'stnk', 'stnk_eka.jpg', '2026-09-28 10:00:00', '2026-09-29 10:00:00', null, 'berjalan'),
-- transaksi 7: pending / menunggu konfirmasi
(10, 2, 1, 'self-pickup', 'ktp', 'ktp_fajar.jpg', '2026-09-28 11:00:00', '2026-09-28 17:00:00', null, 'menunggu'),
-- transaksi 8: batal
(6, 6, 2, 'self-pickup', 'ktp', 'ktp_budi.jpg', '2026-09-21 08:00:00', '2026-09-21 14:00:00', null, 'batal');

-- 5. insert data delivery_info
insert into delivery_info (id_sewa, alamat_tujuan, jarak_km, ongkir) values
(2, 'perumahan tlogomas no. 10, malang', 6.5, 15000),
(4, 'jl. veteran no. 45, malang', 3.2, 10000),
(6, 'jl. bukit dieng no. 88, malang', 8.0, 20000);

-- 6. insert data pembayaran
insert into pembayaran (id_sewa, midtrans_order_id, total_tagihan, status_bayar, waktu_bayar) values
(1, 'order-20260920-001', 100000, 'berhasil', '2026-09-20 09:50:00'),
(2, 'order-20260922-002', 135000, 'berhasil', '2026-09-22 13:45:00'), -- 120rb + 15rb ongkir
(3, 'order-20260924-003', 120000, 'berhasil', '2026-09-24 14:55:00'),
(4, 'order-20260927-004', 250000, 'berhasil', '2026-09-27 08:50:00'), -- (2x120rb) + 10rb ongkir
(5, 'order-20260928-005', 200000, 'berhasil', '2026-09-28 07:55:00'),
(6, 'order-20260928-006', 220000, 'berhasil', '2026-09-28 09:40:00'), -- 200rb + 20rb ongkir
(7, 'order-20260928-007', 100000, 'pending', null),
(8, 'order-20260921-008', 100000, 'gagal', null);

-- 7. insert data denda (untuk transaksi 3 yang telat 3 jam)
insert into denda (id_sewa, keterlambatan_jam, total_denda, status_denda) values
(3, 3, 30000, 'lunas');

-- 8. insert data pengeluaran
insert into pengeluaran (id_cabang, kategori, jumlah_biaya, keterangan, tanggal) values
(1, 'listrik', 500000, 'token listrik utama bulan september', '2026-09-15'),
(1, 'internet', 350000, 'langganan wifi indihome', '2026-09-16'),
(2, 'listrik', 450000, 'token listrik cabang sawojajar', '2026-09-15'),
(2, 'maintenance', 150000, 'servis analog controller ps4', '2026-09-23'),
(1, 'perlengkapan', 85000, 'pembelian kabel hdmi ori ps5', '2026-09-26');