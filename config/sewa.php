<?php

/**
 * config/sewa.php
 * Semua aturan bisnis penyewaan di satu tempat.
 * Dipakai oleh proses_sewa.php, proses_bayar.php, bukti.php, dan form_sewa.php.
 */

// ---------------------------------------------------------------------------
// Class pengecualian untuk kesalahan yang aman ditampilkan ke pelanggan.
// Dijaga class_exists agar tidak bentrok dengan deklarasi lama di proses_sewa.php.
// ---------------------------------------------------------------------------
if (!class_exists('SewaGagal', false)) {
    final class SewaGagal extends RuntimeException {}
}

// ---------------------------------------------------------------------------
// Jadwal & pemesanan
// ---------------------------------------------------------------------------
const SEWA_TZ                = 'Asia/Jakarta';
const SEWA_MAKS_JAM          = 168;   // sewa terlama: 7 hari
const SEWA_MAKS_HARI_DEPAN   = 30;    // booking maksimal 30 hari ke depan
const SEWA_TOLERANSI_MENIT   = 15;    // toleransi jam mulai yang "baru saja lewat"
const SEWA_BATAS_BAYAR_MENIT = 60;    // unit ditahan 60 menit untuk transfer/QRIS
const SEWA_MAKS_BELUM_BAYAR  = 2;     // maksimal pesanan belum dibayar per pelanggan

// Bayar di toko (COD): pesanan dibatalkan otomatis bila pelanggan tidak datang
// dalam sekian menit setelah jam mulai sewa.
const SEWA_TOLERANSI_COD_MENIT = 60;

// ---------------------------------------------------------------------------
// Upload bukti pembayaran
// ---------------------------------------------------------------------------
const SEWA_MAKS_BUKTI_BYTE  = 5 * 1024 * 1024; // 5 MB (harus sama dengan batas di pembayaran_sewa.js)
const SEWA_MIN_SISI_PIKSEL  = 200;             // gambar lebih kecil dianggap tidak terbaca
const SEWA_MAKS_PIKSEL      = 12000000;        // 12 megapiksel; melindungi memori PHP saat GD memproses
const SEWA_SISI_MAKS_SIMPAN = 1600;            // sisi terpanjang gambar disimpan maksimal 1600 px