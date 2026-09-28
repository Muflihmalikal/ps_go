# SIMPas - PlayStation Rental System (`ps_go`)

Aplikasi Sistem Informasi Manajemen PlayStation Rental berbasis web menggunakan PHP.

---

## 📁 Struktur Folder

```text
ps_go/
├── config/      # File koneksi & konfigurasi database
├── assets/      # Aset statis (CSS, JavaScript, Gambar)
├── includes/    # Komponen halaman reusable (Header, Footer, Sidebar)
├── modules/     # File logika per fitur (Auth, Unit, Transaksi)
└── index.php    # Halaman utama aplikasi
```
## 🚀 Fitur Utama & Modul
```text
Auth: Autentikasi pengguna (Login, Logout, Register).
Unit: Manajemen data unit PlayStation (Status, Tipe, Harga).
Transaksi: Pencatatan penyewaan unit, durasi, dan pembayaran.
```
##📖 Panduan Kerja Tim (Git Workflow)
```text
Gunakan panduan berikut setiap kali Anda bekerja pada proyek ini untuk menghindari bentrok file (merge conflict).
1. Persiapan Awal (Clone Repository)
Lakukan langkah ini hanya satu kali saat pertama kali ingin mengambil proyek ke komputer lokal Anda:
Buka terminal / Git Bash di folder web server Anda (contoh: folder htdocs pada XAMPP).
Jalankan perintah berikut:
git clone [https://github.com/Muflihmalikal/ps_go.git](https://github.com/Muflihmalikal/ps_go.git)
cd ps_go
2. Sebelum Mulai Koding (Selalu Tarik Kode Terbaru)
Sebelum Anda membuat atau mengedit file baru, wajib menarik kode terbaru yang mungkin telah diperbarui oleh anggota tim lain:
git checkout main
git pull origin main
3. Alur Mengubah atau Menambah Kode
A. Mengedit atau Menambah File
Kerjakan tugas atau fitur Anda pada file di dalam folder proyek (config, assets, includes, modules, atau index.php).
Anda bisa mengecek file apa saja yang telah diubah dengan perintah:
git status
B. Menyimpan & Mengirim Perubahan (Commit & Push)
Setelah selesai menambah/mengedit file dan memastikan kode berjalan tanpa error:
Tambahkan semua file yang diubah ke staging area:
Bash
git add .
Simpan perubahan dengan pesan commit yang jelas:
Bash
git commit -m "penjelasan singkat mengenai apa yang ditambah atau diubah"
Contoh: git commit -m "menambahkan halaman login pada modul auth"
Kirim perubahan ke GitHub:
Bash
git push origin main
```
##💡 Ringkasan Alur Harian Tim
```text
Tarik Kode Terbaru (git pull origin main) ➔ Edit/Tambah Kode ➔ Cek Status (git status) ➔ Stage (git add .) ➔ Commit (git commit -m "...") ➔ Push (git push origin main)
```
## 👥 Tim Pengembang
```text
[M. Muflih Rafiansyah Fendy]
[Sarah Sabrina Kusumadewi]
[Alya Fakhrun Nisa]
[Sulthaan Ahmad Effendy]
[Muhammad Khairibyan Athallah]
