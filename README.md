# PS_GO – PlayStation Rental System (`ps_go`)

**PS_GO** adalah Sistem Informasi Manajemen rental PlayStation berbasis web yang dibangun menggunakan **PHP Native** dan **PostgreSQL**. Aplikasi ini membantu pengelola rental dalam mengelola unit PlayStation, paket harga, serta mencatat transaksi penyewaan, durasi, dan pembayaran pelanggan.

## Daftar Isi

* [Fitur Utama](#fitur-utama)
* [Arsitektur & Pemisahan Kode](#arsitektur--pemisahan-kode)
* [Struktur Folder Lengkap & Analisis Isi](#struktur-folder-lengkap--analisis-isi)
* [Kebutuhan Sistem](#kebutuhan-sistem)
* [Instalasi & Menjalankan Aplikasi](#instalasi--menjalankan-aplikasi)
* [Panduan Keamanan & Konvensi Kode](#panduan-keamanan--konvensi-kode)
* [Panduan Kerja Tim (Git Workflow)](#panduan-kerja-tim-git-workflow)
* [Tim Pengembang](#tim-pengembang)

---

## Fitur Utama

| Modul | Deskripsi |
| ----- | ----- |
| **Auth & Security** | Registrasi pelanggan, login multi-role (Admin/Pelanggan), logout, proteksi session, dan CSRF protection. |
| **Katalog & Unit** | Display status unit real-time (tersedia/disewa), filter jenis konsol (PS3, PS4, Playbox), dan toggle tarif (Weekday/Weekend). |
| **Transaksi & Rental** | Booking unit, pemilihan durasi sewa (12/24 jam), kalkulasi harga otomatis, dan riwayat pesanan pelanggan. |
| **Panel Admin** | Manajemen status unit fisik, pengawasan transaksi aktif, dan kelola paket sewa. |

---

## Arsitektur & Pemisahan Kode

Proyek ini menerapkan prinsip **Separation of Concerns (Pemisahan Tampilan dan Logika Pemrosesan)**:

1. **View (Tampilan UI):** Terletak di *root folder* (`login.php`, `register.php`), `/pelanggan/`, dan `/admin/`. File-file ini hanya bertanggung jawab menampilkan antarmuka HTML/Bootstrap dan tidak mengeksekusi query `INSERT`/`UPDATE`/`DELETE` langsung.
2. **Process / Logic (Backend):** Terletak di dalam folder `/modules/`. File ini menerima data via `POST`, melakukan validasi data & keamanan (CSRF/Sanitasi), memproses query database, lalu mengarahkan kembali (*redirect*) ke tampilan terkait.
3. **Configuration & Helpers:** Terletak di `/config/` dan `/includes/` untuk menyediakan koneksi database, fungsi pengaman, serta komponen UI yang dapat digunakan kembali (*reusable components*).

---

## Struktur Folder Lengkap & Analisis Isi

Pohon folder dan alokasi file pada proyek `ps_go`:

```text
ps_go/
├── .env                  # Variabel lingkungan sensitif (DB Credentials) - TIDAK DIBAGIKAN KE GIT
├── .env.example          # Template konfigurasi environment untuk anggota tim
├── .gitignore            # Daftar file/folder yang diabaikan oleh Git
├── index.php             # Beranda utama / Katalog unit & paket untuk pelanggan
├── login.php             # Halaman tampilan (UI) form Login
├── logout.php            # Skrip penghapus session untuk Logout
├── register.php          # Halaman tampilan (UI) form Registrasi
├── README.md             # Dokumentasi utama proyek
│
├── admin/                # PANEL KHUSUS PENGELOLA / OPERATOR
│   ├── index.php         # Dashboard utama admin & statistik rental
│   ├── penyewaan.php     # Manajemen transaksi penyewaan aktif & histori
│   └── unit-ps.php       # Kelola unit fisik PlayStation (tambah, edit status)
│
├── assets/               # ASET STATIS FRONTEND
│   ├── css/              # File stylesheet kustom
│   ├── js/               # Skrip JavaScript kustom (misal: filter katalog)
│   └── img/              # Foto produk konsol, logo, dan gambar default
│
├── config/               # KONFIGURASI DAN UTILITAS DASAR
│   ├── koneksi.php       # Inisialisasi koneksi Database PostgreSQL via PDO
│   └── security.php      # Fungsi keamanan (Token CSRF, Sanitasi Input, Encrypt ID)
│
├── database/             # DOKUMENTASI DATABASE
│   └── schema.sql        # File DDL/Skema tabel database PostgreSQL & data awal
│
├── includes/             # TEMPLATE LAYOUT & MIDDLEWARE
│   ├── auth.php          # Helper autentikasi (require_login, require_role, user_home_path)
│   ├── header.php        # Head HTML, pemanggilan CSS Bootstrap & Navbar atas
│   ├── footer.php        # Bagian bawah halaman & pemanggilan JavaScript Bootstrap
│   ├── sidebar.php       # Menu navigasi samping untuk dashboard admin/pelanggan
│   └── .gitkeep          # Menjaga folder tetap terlacak Git meski kosong
│
├── modules/              # PEMROSESAN LOGIKA BACKEND (PROCESSORS)
│   ├── auth/             # Pemrosesan Akun & Autentikasi
│   │   ├── proses_login.php    # Validasi email/password, session_regenerate, set session
│   │   └── proses_register.php # Validasi input form, hash password, INSERT user baru
│   └── sewa/             # Pemrosesan Bisnis Penyewaan
│       └── proses_sewa.php     # Pemrosesan transaksi booking & pembuatan order
│
└── pelanggan/            # ALUR HALAMAN KHUSUS PELANGGAN
    ├── form_sewa.php     # Form checkout & penentuan durasi sewa unit
    ├── pembayaran.php    # Halaman upload bukti / metode pembayaran
    ├── pesanan.php       # Riwayat dan status transaksi sewa milik pelanggan
    └── profil.php        # Pengaturan data pribadi pelanggan
```

### Rincian Fungsi Setiap Folder

#### 1. Root Directory (`/`)
* **`index.php`**: Beranda publik yang menampilkan katalog unit PlayStation secara real-time. Memuat logika pembacaan stok unit yang tersedia via `PDO`.
* **`login.php` & `register.php`**: Berisi elemen visual HTML formulir. Mengirim data `POST` ke folder `modules/auth/`.
* **`logout.php`**: Menghancurkan cookie dan session `$_SESSION`, lalu mengembalikan pengguna ke `login.php`.

#### 2. Folder `config/`
* **`koneksi.php`**: Mengisi variabel koneksi PDO PostgreSQL berdasarkan isi `.env`.
* **`security.php`**: Berisi fungsi penting seperti `csrf_field()` untuk membuat token keamanan form, `verify_csrf_token()` untuk memeriksa token saat form dikirim, dan `encrypt_id()` untuk menyembunyikan ID numerik di URL.

#### 3. Folder `includes/`
* **`auth.php`**: Pusat kontrol akses halaman (*middleware*). Berisi fungsi `require_login()` untuk memblokir pengguna belum login, `require_role()` untuk membatasi halaman berdasarkan hak akses (admin/pelanggan), dan `user_home_path()` untuk menentukan halaman tujuan pengarahan setelah login.
* **`header.php`, `footer.php`, `sidebar.php`**: Potongan elemen UI agar tampilan di semua halaman tetap konsisten dan tidak perlu menuliskan skrip HTML Bootstrap berulang-ulang.

#### 4. Folder `modules/`
* Folder ini **tidak menampilkan HTML**, melainkan murni mengeksekusi data logic.
* **`modules/auth/proses_login.php`**: Memeriksa token CSRF $\rightarrow$ mengecek email & password hash (`password_verify`) $\rightarrow$ mencatat session user $\rightarrow$ mengarahkan ke dashboard.
* **`modules/auth/proses_register.php`**: Memeriksa token CSRF $\rightarrow$ validasi format email/nomor WA $\rightarrow$ memastikan email belum terdaftar $\rightarrow$ menyimpan user baru dengan `password_hash()` $\rightarrow$ mengarahkan ke `login.php?registered=1`.

#### 5. Folder `admin/`
* Berisi halaman khusus dengan proteksi `require_role(['admin', 'owner', 'operator'])`.
* Digunakan oleh pengelola rental untuk memantau unit yang sedang disewa, mengembalikan unit, atau mengubah harga rental.

#### 6. Folder `pelanggan/`
* Berisi alur checkout dan transaksi pelanggan setelah mereka memilih paket di `index.php`. Diproteksi menggunakan `require_login()`.

---

## Kebutuhan Sistem

| Komponen | Spesifikasi Minimal |
| ----- | ----- |
| **Web Server** | Apache (XAMPP / Laragon) |
| **Bahasa Pemrograman** | PHP `>= 8.0` |
| **Database Engine** | PostgreSQL `>= 13.0` (Default Port: `5432`) |
| **Ekstensi PHP Wajib** | `pdo_pgsql`, `pgsql`, `openssl`, `mbstring` |

---

## Instalasi & Menjalankan Aplikasi

### 1. Salin Konfigurasi Environment
Salin file `.env.example` menjadi `.env` di direktori utama:
```bash
cp .env.example .env
```

Sesuaikan kredensial `.env` sesuai dengan konfigurasi PostgreSQL lokal Anda:
```env
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=db_rental
DB_USER=postgres
DB_PASSWORD=password_postgres_anda
```

### 2. Impor Skema Database
Buat database bernama `db_rental` pada PostgreSQL (via pgAdmin / DBeaver / psql), lalu impor skema dari folder `database/schema.sql`:
```bash
psql -U postgres -d db_rental -f database/schema.sql
```

### 3. Aktifkan Ekstensi PostgreSQL pada PHP
Pastikan baris berikut pada `php.ini` tidak diawali tanda titik koma (`;`):
```ini
extension=pdo_pgsql
extension=pgsql
```

### 4. Jalankan Aplikasi
Jalankan lokal server via terminal di direktori utama:
```bash
php -S localhost:8000
```
Akses aplikasi melalui browser di: `http://localhost:8000`

---

## Panduan Keamanan & Konvensi Kode

Demi menjaga keamanan dan keteraturan kode dalam tim, wajib mematuhi aturan berikut:

1. **Gunakan Prepared Statements:** Dilarang menyambung string SQL (*string concatenation*) secara langsung. Gunakan PDO Prepared Statements untuk mencegah SQL Injection.
   ```php
   // SALAH
   $pdo->query("SELECT * FROM users WHERE email = '$email'");
   
   // BENAR
   $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
   $stmt->execute(['email' => $email]);
   ```

2. **Gunakan Protection Form CSRF:** Setiap kali membuat tag `<form method="POST">`, wajib menyertakan helper `<?= csrf_field() ?>` di dalam form, dan memvalidasinya di file pemroses dengan `verify_csrf_token()`.

3. **Escaping Output HTML:** Gunakan `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` pada setiap variabel PHP yang dicetak ke dalam elemen HTML untuk mencegah XSS.

4. **Gunakan Absolute Path untuk Redirect:** Saat melakukan redirect header, gunakan jalur yang konsisten dari root (diawali `/`) agar tidak terjadi kesalahan URL 404.

---

## Panduan Kerja Tim (Git Workflow)

### 1. Sebelum Mulai Mengerjakan Fitur
Selalu tarik perubahan kode terbaru dari repositori utama:
```bash
git checkout main
git pull origin main
```

### 2. Alur Commit & Push
```bash
# 1. Periksa file apa saja yang diubah
git status

# 2. Tambahkan perubahan ke Staging Area
git add .

# 3. Buat commit dengan standar tipe yang disepakati
git commit -m "feat(auth): menambah validasi nomor WA pada proses register"

# 4. Kirim ke repositori GitHub
git push origin main
```

### 3. Standar Tipe Commit (Conventional Commits)

Seluruh anggota tim wajib menggunakan awalan tipe commit berikut agar histori *version control* rapi dan mudah ditelusuri:

* **`feat`**: Menambah fitur baru
  ```bash
  git commit -m "feat(cart): add checkout button to shopping cart"
  ```
* **`fix`**: Memperbaiki bug atau error
  ```bash
  git commit -m "fix(auth): resolve token expiration issue on login"
  ```
* **`docs`**: Mengubah atau menambah dokumentasi
  ```bash
  git commit -m "docs(readme): update installation steps"
  ```
* **`refactor`**: Merapikan atau mengubah struktur kode tanpa mengubah fungsi
  ```bash
  git commit -m "refactor(api): simplify user data fetching logic"
  ```
* **`style`**: Perubahan format atau spasi (tidak mengubah logika kode)
  ```bash
  git commit -m "style: fix indentation in navbar component"
  ```
* **`test`**: Menambah atau memperbaiki unit test
  ```bash
  git commit -m "test(cart): add unit test for total price calculation"
  ```

🔒 **Peringatan Keamanan:** Dilarang meng-commit file `.env` yang berisi password database asli ke dalam repositori Git!

---

## Tim Pengembang

| No | Nama | Peran & Responsibilitas Utama |
| --- | --- | --- |
| 1 | **M. Muflih Rafiansyah Fendy** | **Product Manager (PM) / Lead Developer**<br>Mengontrol seluruh alur proyek, manajemen tim, serta supervisi sistem. |
| 2 | **Sarah Sabrina Kusumadewi** | **Sekretaris & Documenter**<br>Menyiapkan dokumentasi non-coding (User Persona, Use Case Diagram, SRS), serta membantu UI/UX design. |
| 3 | **Alya Fakhrun Nisa** | **UI/UX Specialist & Lead Frontend Developer**<br>Merancang antarmuka visual (desain) dan mengimplementasikan tampilan frontend aplikasi. |
| 4 | **Sulthaan Ahmad Effendy** | **Backend Developer**<br>Mengembangkan alur logika server, pemrosesan transaksi (`modules/`), dan integrasi database PostgreSQL. |
| 5 | **Muhammad Khairibyan Athallah** | **Quality Assurance (QA) & Frontend Assistant**<br>Melakukan pengujian sistem (bug testing & security audit) serta membantu pengembangan frontend. |