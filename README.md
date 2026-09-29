# PS_GO – PlayStation Rental System (`ps_go`)

**PS_GO** adalah Sistem Informasi Manajemen rental PlayStation berbasis web yang dibangun menggunakan **PHP** dan **PostgreSQL**. Aplikasi ini membantu pengelola rental dalam mengelola data unit PlayStation serta mencatat transaksi penyewaan, durasi, dan pembayaran.

---

## Daftar Isi

- [Fitur Utama](#fitur-utama)
- [Struktur Folder](#struktur-folder)
- [Kebutuhan Sistem](#kebutuhan-sistem)
- [Instalasi & Menjalankan Aplikasi](#instalasi--menjalankan-aplikasi)
  - [Parameter Koneksi Database](#parameter-koneksi-database)
  - [Opsi A: XAMPP + PostgreSQL](#opsi-a-xampp--postgresql)
  - [Opsi B: Laragon + PostgreSQL](#opsi-b-laragon--postgresql)
  - [Verifikasi Instalasi](#verifikasi-instalasi)
  - [Troubleshooting](#troubleshooting)
- [Panduan Kerja Tim (Git Workflow)](#panduan-kerja-tim-git-workflow)
- [Tim Pengembang](#tim-pengembang)

---

## Fitur Utama

| Modul         | Deskripsi                                                 |
| ------------- | --------------------------------------------------------- |
| **Auth**      | Autentikasi pengguna: Login, Logout, dan Register.        |
| **Unit**      | Manajemen data unit PlayStation: status, tipe, dan harga. |
| **Transaksi** | Pencatatan penyewaan unit, durasi sewa, dan pembayaran.   |

---

## Struktur Folder

```text
ps_go/
├── config/      # Koneksi & konfigurasi database
├── assets/      # Aset statis (CSS, JavaScript, gambar)
├── includes/    # Komponen halaman yang dipakai ulang (header, footer, sidebar)
├── modules/     # Logika per fitur (Auth, Unit, Transaksi)
└── index.php    # Halaman utama aplikasi
```

---

## Kebutuhan Sistem

| Komponen   | Keterangan                                                                 |
| ---------- | -------------------------------------------------------------------------- |
| Web server | **XAMPP** atau **Laragon** (pilih salah satu, lihat catatan di bawah)      |
| PHP        | Sudah termasuk di XAMPP / Laragon                                          |
| Database   | **PostgreSQL** (default port `5432`)                                       |
| Ekstensi   | PHP `pdo_pgsql` dan `pgsql` harus aktif                                    |
| Git        | [git-scm.com](https://git-scm.com/) (sudah termasuk di Laragon)            |
| Browser    | Chrome, Firefox, Edge, atau browser modern lainnya                         |

> ⚠️ **Jangan menjalankan XAMPP dan Laragon bersamaan.** Keduanya memakai port Apache yang sama (`80` dan `443`) sehingga akan bentrok. Pastikan salah satunya dimatikan (*Stop*) sebelum menjalankan yang lain.

---

## Instalasi & Menjalankan Aplikasi

### Parameter Koneksi Database

Apa pun web server yang dipakai, sesuaikan file koneksi di folder `config/` dengan nilai berikut (ubah sesuai komputer masing-masing):

| Parameter | Nilai Umum                              |
| --------- | --------------------------------------- |
| Host      | `localhost` atau `127.0.0.1`            |
| Port      | `5432`                                  |
| Username  | `postgres`                              |
| Password  | Sesuai yang Anda atur saat instalasi    |
| Database  | Nama database yang Anda buat (mis. `ps_go`) |

---

### Opsi A: XAMPP + PostgreSQL

> XAMPP **tidak** menyertakan PostgreSQL (hanya MySQL/MariaDB), sehingga PostgreSQL perlu diinstal terpisah.

**1. Instal PostgreSQL**

1. Unduh installer dari [postgresql.org/download/windows](https://www.postgresql.org/download/windows/).
2. Jalankan installer, pastikan komponen **PostgreSQL Server** dan **pgAdmin 4** ikut terpilih.
3. Saat diminta, atur **password** untuk user `postgres` (**catat password ini**) dan biarkan port di `5432`.

**2. Aktifkan ekstensi PostgreSQL di PHP**

1. Buka `C:\xampp\php\php.ini` dengan text editor.
2. Cari dua baris berikut, lalu hapus tanda titik koma (`;`) di depannya:

   ```ini
   extension=pdo_pgsql
   extension=pgsql
   ```

3. Simpan file.

**3. Clone proyek**

```bash
cd C:/xampp/htdocs
git clone https://github.com/Muflihmalikal/ps_go.git
cd ps_go
```

**4. Jalankan server**

Buka **XAMPP Control Panel**, lalu klik **Start** pada **Apache**. (MySQL tidak perlu dijalankan karena proyek ini memakai PostgreSQL.) Jika Apache sudah berjalan sebelum langkah 2, klik **Stop** lalu **Start** lagi agar `php.ini` terbaca ulang.

**5. Buat database**

1. Buka **pgAdmin 4** dan masukkan password `postgres`.
2. Klik kanan **Databases** ➔ **Create** ➔ **Database...**, beri nama (mis. `ps_go`), lalu **Save**.
3. Impor struktur tabel: klik kanan database tersebut ➔ **Query Tool** ➔ ikon **Open File** ➔ pilih file `.sql` proyek (jika tersedia) ➔ klik **Execute** (F5).

**6. Atur konfigurasi**

Sesuaikan file koneksi di folder `config/` dengan [parameter koneksi database](#parameter-koneksi-database).

**7. Buka aplikasi**

```text
http://localhost/ps_go/
```

---

### Opsi B: Laragon + PostgreSQL

> Laragon tidak selalu menyertakan PostgreSQL secara bawaan, tetapi bisa ditambahkan dengan mudah lewat fitur **Quick add**.

**1. Instal Laragon**

Unduh dari [laragon.org](https://laragon.org/) (disarankan versi **Full**), lalu instal ke lokasi default `C:\laragon`.

**2. Tambahkan PostgreSQL ke Laragon**

*Cara termudah (Quick add):*

1. Buka Laragon.
2. Klik kanan area Laragon (atau ikon di system tray) ➔ **Tools** ➔ **Quick add** ➔ pilih **PostgreSQL** (pilih versi yang tersedia).
3. Tunggu proses unduh dan instalasi selesai.

*Cara manual (jika PostgreSQL tidak ada di Quick add):*

1. Unduh **binaries (.zip)** PostgreSQL dari [enterprisedb.com/download-postgresql-binaries](https://www.enterprisedb.com/download-postgresql-binaries).
2. Ekstrak file zip. Di dalamnya ada folder bernama `pgsql`.
3. Pindahkan folder `pgsql` ke `C:\laragon\bin\postgresql\` (buat folder `postgresql` jika belum ada), lalu ubah namanya sesuai versi, misalnya `postgresql-16`.
4. Restart Laragon. Menu **PostgreSQL** akan muncul.

**3. Aktifkan ekstensi PostgreSQL di PHP**

Klik kanan Laragon ➔ **PHP** ➔ **Extensions**, lalu centang:

- `pdo_pgsql`
- `pgsql`

Laragon akan me-reload Apache secara otomatis.

**4. Clone proyek**

Klik tombol **Terminal** di Laragon (atau buka terminal biasa), lalu:

```bash
cd C:/laragon/www
git clone https://github.com/Muflihmalikal/ps_go.git
cd ps_go
```

**5. Jalankan server**

- Klik **Start All** pada Laragon untuk menjalankan Apache.
- Jalankan PostgreSQL: klik kanan Laragon ➔ **PostgreSQL** ➔ **Start**. Agar otomatis ikut jalan saat **Start All**, centang PostgreSQL di **Menu ➔ Preferences ➔ Services & Ports**.

**6. Buat database**

Gunakan salah satu tool berikut:

- **pgAdmin** atau **DBeaver** dari menu **PostgreSQL** di Laragon (jika tersedia; DBeaver bisa ditambahkan lewat **Tools ➔ Quick add**).
- **Adminer** (tersedia di Laragon Full): buka `http://localhost/adminer`, pilih sistem **PostgreSQL**, lalu login.
- **Command line** melalui Terminal Laragon:

  ```bash
  psql -U postgres -c "CREATE DATABASE ps_go;"
  psql -U postgres -d ps_go -f nama_file.sql
  ```

> Password bawaan user `postgres` di Laragon bisa berbeda tergantung versi. Jika ragu, cek dokumentasi Laragon atau ubah password dengan perintah `psql -U postgres -c "ALTER USER postgres PASSWORD 'password_baru';"`.

**7. Atur konfigurasi**

Sesuaikan file koneksi di folder `config/` dengan [parameter koneksi database](#parameter-koneksi-database).

**8. Buka aplikasi**

```text
http://localhost/ps_go/
```

---

### Verifikasi Instalasi

Pastikan ekstensi PostgreSQL sudah aktif. Buka terminal (Terminal Laragon, atau CMD dengan PHP XAMPP), lalu jalankan:

```bash
php -m | findstr pgsql
```

Hasil yang benar menampilkan kedua ekstensi:

```text
pdo_pgsql
pgsql
```

---

### Troubleshooting

| Masalah                                                     | Kemungkinan Penyebab & Solusi                                                                                   |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `could not find driver`                                     | Ekstensi `pdo_pgsql` belum aktif. Aktifkan lalu restart Apache.                                                 |
| `Call to undefined function pg_connect()`                   | Ekstensi `pgsql` belum aktif. Aktifkan lalu restart Apache.                                                     |
| `Connection refused` / `could not connect to server`        | Service PostgreSQL belum berjalan, atau port bukan `5432`. Start PostgreSQL dan cek port.                       |
| `password authentication failed for user "postgres"`        | Password di file `config/` tidak sama dengan password PostgreSQL. Samakan keduanya.                             |
| `database "ps_go" does not exist`                           | Database belum dibuat, atau nama di `config/` berbeda dengan nama database sebenarnya.                          |
| Apache gagal start / port 80 sudah dipakai                  | XAMPP dan Laragon berjalan bersamaan, atau aplikasi lain memakai port 80. Matikan salah satunya.                |
| Halaman `404 Not Found`                                     | Folder proyek tidak berada di `htdocs` (XAMPP) atau `www` (Laragon), atau nama folder bukan `ps_go`.            |
| Perubahan di `php.ini` tidak berpengaruh                    | Apache belum di-restart, atau Anda mengedit `php.ini` yang salah. Cek dengan `phpinfo()` bagian *Loaded Configuration File*. |

---

## Panduan Kerja Tim (Git Workflow)

Ikuti panduan berikut setiap kali bekerja pada proyek ini agar terhindar dari konflik file (*merge conflict*).

### 1. Persiapan Awal (Clone Repository)

Langkah ini **hanya dilakukan satu kali**, yaitu saat pertama kali mengambil proyek ke komputer lokal. Clone ke folder `htdocs` (XAMPP) atau `www` (Laragon).

```bash
git clone https://github.com/Muflihmalikal/ps_go.git
cd ps_go
```

### 2. Sebelum Mulai Coding

Selalu tarik kode terbaru terlebih dahulu, karena anggota tim lain mungkin sudah memperbarui proyek.

```bash
git checkout main
git pull origin main
```

### 3. Mengubah atau Menambah Kode

**a. Edit atau tambah file**

Kerjakan tugas Anda pada file di dalam folder proyek (`config`, `assets`, `includes`, `modules`, atau `index.php`). Untuk melihat file apa saja yang berubah:

```bash
git status
```

**b. Simpan dan kirim perubahan (commit & push)**

Setelah selesai dan memastikan kode berjalan tanpa error:

```bash
# 1. Masukkan semua perubahan ke staging area
git add .

# 2. Simpan perubahan dengan pesan commit yang jelas
git commit -m "menambahkan halaman login pada modul auth"

# 3. Kirim perubahan ke GitHub
git push origin main
```

> **Tips penulisan pesan commit:** gunakan kalimat singkat yang menjelaskan apa yang ditambah atau diubah, misalnya `memperbaiki validasi form register` atau `menambahkan fitur hitung durasi sewa`.

> 🔒 **Penting:** Jangan meng-commit password database asli ke GitHub. Jika file di `config/` berisi kredensial pribadi, sepakati dengan tim untuk memakai file contoh (mis. `config/database.example.php`) dan masukkan file asli ke `.gitignore`.

### Ringkasan Alur Harian

```text
git pull origin main  →  Edit/tambah kode  →  git status  →  git add .  →  git commit -m "..."  →  git push origin main
```

> **Catatan:** Jika `git push` ditolak karena ada perubahan baru di GitHub, jalankan `git pull origin main` terlebih dahulu, selesaikan konflik (jika ada), lalu `push` kembali.

---

## Tim Pengembang

| No | Nama                         |
| -- | ---------------------------- |
| 1  | M. Muflih Rafiansyah Fendy   |
| 2  | Sarah Sabrina Kusumadewi     |
| 3  | Alya Fakhrun Nisa            |
| 4  | Sulthaan Ahmad Effendy       |
| 5  | Muhammad Khairibyan Athallah |
