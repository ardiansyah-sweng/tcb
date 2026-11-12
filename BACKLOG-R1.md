# Backlog Rilis 1 — TCB (Task Control Block)

Sumber: SRS TCB v1.1 (baseline), `docs/tcb_schema.sql` (revisi 2), `docs/tcb_openapi.yaml`.
Cakupan Rilis 1: FR-01 s.d. FR-13, FR-18, FR-23. Tanpa kalender (komponen F rekomendasi belum aktif).
Pengembang: satu orang, dibantu Claude Code.

---

## Cara memakai backlog ini dengan Claude Code

- **Satu story = satu branch = satu sesi Claude Code.** Ukuran story dibuat agar selesai dalam satu sesi kerja (S ≈ 1–2 jam, M ≈ setengah hari, L ≈ satu hari).
- Kerjakan **berurutan** sesuai nomor; kolom "Bergantung pada" menunjukkan prasyaratnya.
- Kalimat pembuka sesi yang disarankan:

  > Baca CLAUDE.md dan BACKLOG-R1.md. Kerjakan story R1-xx. Tulis tesnya dulu dari kriteria penerimaan, lalu implementasinya. Jangan ubah kontrak API atau skema tanpa memberi tahu saya.

- Setelah selesai: centang kriteria penerimaan di file ini, lalu merge ke `main`.

## Definition of Done (berlaku untuk semua story)

- [ ] Semua kriteria penerimaan punya tes otomatis yang lulus (Pest/PHPUnit untuk API, Vitest untuk UI).
- [ ] Tes dijalankan terhadap PostgreSQL, bukan SQLite, karena aturan inti ada di database.
- [ ] Kontrak API sesuai `docs/tcb_openapi.yaml`; jika berubah, file OpenAPI ikut diperbarui di commit yang sama.
- [ ] Tidak ada field selain judul yang diwajibkan pengguna (prinsip input minimal, SRS bagian 8).
- [ ] Teks antarmuka berbahasa Indonesia; tampilan bisa dipakai di lebar layar ponsel.
- [ ] CI hijau.

---

## Peta kebutuhan ke story

| FR | Kebutuhan singkat | Story |
| --- | --- | --- |
| FR-01 | Kelola Objective | R1-04, R1-20 |
| FR-02 | Kelola Key Result | R1-05, R1-20 |
| FR-03 | Tambah task cepat | R1-06, R1-16 |
| FR-04 | task_key otomatis | R1-02, R1-06 |
| FR-05 | State machine | R1-02, R1-09 |
| FR-06 | WIP limit dan pindah konteks | R1-02, R1-11, R1-17 |
| FR-07 | Konteks wajib saat keluar Running | R1-10, R1-17 |
| FR-08 | Tampilkan konteks terakhir | R1-10, R1-16, R1-19 |
| FR-09 | Log transisi append-only | R1-02, R1-09, R1-19 |
| FR-10 | Sesi kerja dan waktu terpakai | R1-10 |
| FR-11 | Subtask dan dependensi | R1-07, R1-12, R1-19 |
| FR-12 | Tiga rekomendasi dan alasannya | R1-13, R1-14, R1-16 |
| FR-13 | Keputusan atas rekomendasi | R1-14, R1-16 |
| FR-18 | Beranda, papan, panel berjalan | R1-16, R1-18 |
| FR-23 | Progres satuan | R1-08, R1-17, R1-19 |

## Ringkasan urutan

| Milestone | Story | Hasil |
| --- | --- | --- |
| M0 Fondasi | R1-01 – R1-03 | Proyek jalan, skema termigrasi dan teruji, login |
| M1 Data inti | R1-04 – R1-08 | Goal, Key Result, task, subtask, dependensi, progres satuan lewat API |
| M2 Mesin TCB | R1-09 – R1-12 | State machine, konteks, sesi, pindah konteks, pelepasan dependensi |
| M3 Rekomendasi | R1-13 – R1-14 | Skor, alasan, keputusan |
| M4 Antarmuka | R1-15 – R1-20 | Lima layar sesuai mockup |
| M5 Rilis | R1-21 | Terpasang di VPS dan dipakai harian |

---

## M0 · Fondasi

### R1-01 · Inisialisasi proyek dan lingkungan · M
Bergantung pada: —

Sebagai pengembang, saya ingin satu perintah menjalankan seluruh lingkungan, agar setiap sesi Claude Code mulai dari kondisi yang sama.

Kriteria penerimaan:
- [x] Monorepo: `api/` (Laravel versi stabil terbaru, mode API saja) dan `web/` (React + Vite + TypeScript, Tailwind CSS, TanStack Query).
- [x] `docker compose up` menjalankan `app`, `worker` (queue), `db` (PostgreSQL 16), dan `web` (dev server). _(Diuji otomatis oleh job CI `compose`.)_
- [x] `GET /api/v1/health` mengembalikan `{"status":"ok"}` dan status koneksi database.
- [x] CI (GitHub Actions) menjalankan lint dan tes `api/` serta `web/` dengan layanan PostgreSQL.
- [x] `docs/` berisi `tcb_schema.sql`, `tcb_openapi.yaml`, dan salinan SRS (Markdown).
- [x] `CLAUDE.md` di akar repo.

### R1-02 · Migrasi skema dan test suite aturan database · L
Bergantung pada: R1-01

Sebagai pengembang, saya ingin skema `docs/tcb_schema.sql` menjadi migrasi Laravel yang teruji, agar aturan inti terjaga sejak awal.

Kriteria penerimaan:
- [x] Migrasi Laravel membuat semua tabel, constraint, indeks, dan view sesuai skema. Trigger, fungsi, dan view ditulis dengan `DB::unprepared()`.
- [x] Seeder mengisi `allowed_transitions` (16 baris).
- [x] `php artisan migrate:fresh --seed` berhasil di database kosong.
- [x] Tes database (port dari 19 skenario uji skema) lulus, minimal mencakup:
  - [x] task_key berurutan TCB-1, TCB-2, … per pengguna;
  - [x] transisi tidak sah ditolak dengan hint `invalid_transition`;
  - [x] Running kedua ditolak dengan hint `wip_full` saat WIP limit 1;
  - [x] Waiting/Cancelled tanpa alasan ditolak (`chk_tasks_reason_required`);
  - [x] progres satuan melebihi total ditolak (`chk_tasks_unit_le_total`);
  - [x] log transisi tidak bisa diubah atau dihapus;
  - [x] sumber log mengikuti `SET LOCAL tcb.source`;
  - [x] satu sesi terbuka per task; satu acuan deadline per task;
  - [x] view `key_result_progress` menghitung progres satuan sebagian (contoh: 22/40 dari task 240 menit → 55%).
- [x] Model Eloquent untuk setiap tabel, dengan cast tipe yang benar (jsonb → array, numeric → float).

### R1-03 · Autentikasi satu akun · S
Bergantung pada: R1-02

Sebagai pemilik, saya ingin login dengan aman tanpa ada orang lain yang bisa mendaftar.

Kriteria penerimaan:
- [ ] Login email + password menghasilkan token Sanctum; logout mencabut token.
- [ ] Tidak ada endpoint registrasi publik; akun dibuat lewat `php artisan tcb:create-user`.
- [ ] `GET /me` mengembalikan profil dan pengaturan (WIP limit 1, bobot skor default, zona waktu Asia/Jakarta).
- [ ] Semua endpoint selain login dan health mengembalikan 401 tanpa token.
- [ ] Setiap query dibatasi `owner_id` pengguna yang login (global scope), siap untuk multi-user v2.

---

## M1 · Data inti

### R1-04 · API Objective · S
Bergantung pada: R1-03 · FR-01

Kriteria penerimaan:
- [ ] `GET/POST /objectives`, `GET/PATCH /objectives/{id}` sesuai OpenAPI.
- [ ] Wajib: judul, periode mulai, periode akhir; periode akhir ≥ mulai (422 jika tidak).
- [ ] Status bisa diubah ke `closed` atau `archived`; Objective `archived` tidak muncul di daftar default.
- [ ] Respons memuat `progress` dari view `objective_progress`.

### R1-05 · API Key Result dan progres · M
Bergantung pada: R1-04 · FR-02

Kriteria penerimaan:
- [ ] `POST /objectives/{id}/key-results`, `PATCH/DELETE /key-results/{id}`.
- [ ] Default: bobot 1, mode progres `auto`. Mode `manual` mewajibkan `target_value`.
- [ ] Menghapus KR membuat task terkait menjadi orphan (`key_result_id = null`), bukan ikut terhapus.
- [ ] Respons memuat `progress` dari view `key_result_progress` dan `task_counts` per status.
- [ ] Tes: progres otomatis memakai estimasi; task tanpa estimasi dihitung 60 menit; task Cancelled tidak dihitung.

### R1-06 · API task: tambah cepat, daftar, detail, ubah, hapus · M
Bergantung pada: R1-03 · FR-03, FR-04

Kriteria penerimaan:
- [ ] `POST /tasks` hanya mewajibkan `title`; task lahir sebagai `new` dengan `task_key` otomatis.
- [ ] `start_ready: true` langsung memindahkan task ke `ready` (lewat TransitionService setelah R1-10; sementara lewat update status langsung).
- [ ] `GET /tasks` mendukung filter `state` (dipisah koma), `key_result_id`, `objective_id`, `label`, `orphan`, `q` (judul atau task_key), dan cursor pagination.
- [ ] `GET /tasks/{id}` menerima ID numerik **atau** task_key (`TCB-31`).
- [ ] `PATCH /tasks/{id}` menolak field `state` dengan 422 dan pesan yang menunjuk ke endpoint transisi.
- [ ] `DELETE /tasks/{id}` melakukan soft delete; task terhapus tidak muncul di daftar, log tetap utuh.
- [ ] `is_orphan` bernilai true bila `key_result_id` kosong.

### R1-07 · Subtask dan dependensi · M
Bergantung pada: R1-06 · FR-11

Kriteria penerimaan:
- [ ] `parent_task_id` bisa diisi saat membuat atau mengubah task; task tidak bisa menjadi induk dirinya sendiri atau membentuk siklus (422).
- [ ] `GET/POST /tasks/{id}/dependencies`, `PATCH /dependencies/{id}` (status `resolved`), `DELETE /dependencies/{id}`.
- [ ] Dependensi berupa task lain **atau** deskripsi eksternal, tidak keduanya (422).
- [ ] Dependensi yang membentuk siklus (A menunggu B, B menunggu A) ditolak (422).
- [ ] `GET /tasks/{id}` memuat `subtasks` dan `open_dependencies`.

### R1-08 · Progres satuan · S
Bergantung pada: R1-06 · FR-23

Kriteria penerimaan:
- [ ] `unit_total`, `unit_done`, `unit_name` opsional saat membuat dan mengubah task.
- [ ] `unit_name` kosong disimpan sebagai `unit`.
- [ ] Saran nama satuan dari label (dikembalikan sebagai saran, tidak disimpan otomatis): `Koreksi ujian` → `lembar`, `Menulis paper` → `halaman`, `Mengajar` → `slide`; label lain → `unit`.
- [ ] `unit_done` > `unit_total` ditolak dengan pesan ramah (422), bukan galat 500.
- [ ] Respons task memuat `progress_unit` (`done`, `total`, `name`) atau `null`.

---

## M2 · Mesin TCB

### R1-09 · State machine dan endpoint transisi · M
Bergantung pada: R1-06 · FR-05, FR-09

Kriteria penerimaan:
- [ ] Kelas `TaskStateMachine` **membaca** tabel `allowed_transitions` (di-cache per request); tidak ada daftar transisi yang disalin di kode.
- [ ] `POST /tasks/{id}/transitions` dengan `{to, reason?, context?}`.
- [ ] Transisi tidak sah → 409 `invalid_transition` dengan pesan dalam bahasa Indonesia, mis. "Task berstatus New tidak bisa langsung Running. Pindahkan ke Ready dulu."
- [ ] New → Ready cukup dengan judul (SRS v1.1).
- [ ] Waiting/Cancelled tanpa `reason` → 422 `reason_required`.
- [ ] Running → Done ditolak (409) bila masih ada subtask yang belum Done atau Cancelled.
- [ ] `GET /tasks/{id}/transitions` mengembalikan log, terbaru dulu, beserta sumbernya.
- [ ] Galat database (`invalid_transition`, `wip_full`, `chk_*`) dipetakan ke respons API yang sama, sehingga aturan tetap aman walau validasi aplikasi terlewat.

### R1-10 · TransitionService: konteks, sesi kerja, akuntansi · L
Bergantung pada: R1-09 · FR-07, FR-08, FR-10 · Spesifikasi: SRS bagian 12

Kriteria penerimaan:
- [ ] Semua perubahan status (endpoint, switch, service lain) lewat `TransitionService`, dalam satu transaksi, dengan `SET LOCAL tcb.source`.
- [ ] Keluar dari Running ke Ready/Waiting/Suspended tanpa `context` → 422 `context_required`.
- [ ] Saat keluar dari Running: context snapshot tersimpan; `next_step` dan `unit_done` task diperbarui dari konteks; sesi kerja ditutup; `time_spent_minutes` bertambah; `switch_count` +1.
- [ ] Ke Done/Cancelled dari Running: sesi ditutup dan waktu ditambahkan, `switch_count` tidak bertambah.
- [ ] Masuk Running: sesi kerja baru dibuka.
- [ ] `GET /tasks/{id}/contexts` (riwayat), `POST /tasks/{id}/contexts` (catatan tanpa ubah status).
- [ ] `GET /tasks/{id}/contexts/draft` mengembalikan isian awal dari konteks terakhir + `unit_done` saat ini (prinsip input minimal).
- [ ] Tes kegagalan: jika satu langkah gagal, tidak ada sesi terbuka yang tertinggal dan tidak ada konteks setengah tersimpan.

### R1-11 · Pindah konteks atomik dan WIP limit · M
Bergantung pada: R1-10 · FR-06

Kriteria penerimaan:
- [ ] `POST /switch` dengan `{from_task_id, from_to, context, to_task_id, reason?, recommendation_id?}` menjalankan dua transisi dalam satu transaksi.
- [ ] Gagal di langkah mana pun → tidak ada yang berubah (tes dengan task tujuan yang tidak Ready).
- [ ] Transisi ke Running saat WIP penuh → 409 `wip_full` berisi `wip_limit` dan daftar task yang sedang berjalan.
- [ ] `recommendation_id` terisi → keputusan rekomendasi dicatat `picked`.
- [ ] Tes konkurensi: dua permintaan Running bersamaan untuk task berbeda → tepat satu berhasil.

### R1-12 · DependencyService: pelepasan otomatis · S
Bergantung pada: R1-07, R1-10 · Spesifikasi: SRS bagian 12

Kriteria penerimaan:
- [ ] Saat task menjadi Done, dependensi yang menunggunya ditandai `resolved` dalam transaksi yang sama.
- [ ] Task Waiting tanpa dependensi terbuka lagi pindah ke Ready dengan sumber `system` di log.
- [ ] Menandai hambatan eksternal `resolved` juga memicu pemeriksaan yang sama.
- [ ] Task Waiting yang masih punya dependensi terbuka lain tetap Waiting.

---

## M3 · Rekomendasi

### R1-13 · RecommendationScorer · M
Bergantung pada: R1-07 · FR-12 · Spesifikasi: SRS bagian 6

Kelas murni (tanpa I/O) agar mudah diuji dan diganti (NFR-10).

Kriteria penerimaan:
- [ ] Kelayakan: status Ready, tidak dihapus, tanpa dependensi terbuka, bukan induk yang masih punya subtask terbuka.
- [ ] Komponen (semua 0–1):
  - G = bobot KR × (1 − progres KR); orphan = 0.
  - U = 1 jika lewat deadline; 0 jika tanpa deadline; selain itu max(0, 1 − slack/14), dengan slack = sisa hari kerja − (estimasi menit ÷ menit kerja per hari). Estimasi kosong = 60 menit.
  - P = (priority − 1) / 3.
  - A = min(1, hari sejak `ready_since` / 14).
  - F tidak dipakai di Rilis 1; bobot G, U, P, A dinormalisasi ulang agar berjumlah 1.
- [ ] Skor = Σ bobot × komponen, dibulatkan 4 desimal.
- [ ] Alasan = dua kontribusi terbesar dalam kalimat, contoh: "Key Result bobot tinggi, progres masih 30%", "Deadline Sabtu, 14 Nov", "Sudah 9 hari di Ready", "Prioritas tinggi".
- [ ] Task lebih dari 14 hari di Ready ditandai `stale` untuk pengingat "kerjakan, tunda, atau batalkan".
- [ ] Tes unit dengan jam tetap (clock injection) untuk setiap komponen dan untuk urutan tiga task contoh.

### R1-14 · Endpoint rekomendasi dan keputusan · S
Bergantung pada: R1-13, R1-11 · FR-12, FR-13

Kriteria penerimaan:
- [ ] `GET /recommendations?limit=3` menyimpan satu batch (`batch_id`, peringkat, skor, rincian, alasan) dan mengembalikannya.
- [ ] `running_full: true` bila WIP penuh, agar UI menampilkan "Pindah ke task ini".
- [ ] `POST /recommendations/{id}/decision` menerima `picked`, `skipped`, `scheduled`.
- [ ] Task yang di-`skipped` tidak muncul lagi di batch berikutnya pada hari yang sama, kecuali tidak ada kandidat lain.

---

## M4 · Antarmuka (rujukan: mockup "Mockup TCB v1")

### R1-15 · Kerangka UI, login, klien API · M
Bergantung pada: R1-03

Kriteria penerimaan:
- [ ] Tipe TypeScript dan klien API dibangkitkan dari `docs/tcb_openapi.yaml` (mis. `openapi-typescript`).
- [ ] Halaman login; token disimpan aman; 401 mengarahkan ke login.
- [ ] Navigasi samping: Beranda, Papan status, Goal & Key Result, Detail task; indikator "Running / WIP limit".
- [ ] Pada lebar ponsel, navigasi menumpuk di atas konten.
- [ ] Galat API ditampilkan sebagai pesan yang bisa dibaca, bukan kode mentah.

### R1-16 · Beranda · L
Bergantung pada: R1-14, R1-15 · FR-03, FR-08, FR-12, FR-13, FR-18

Kriteria penerimaan:
- [ ] Kolom tambah task cepat: ketik judul, Enter, task masuk sebagai New.
- [ ] Panel "Sedang berjalan": task_key, judul, KR, timer sesi, total waktu terhadap estimasi, konteks terakhir (terakhir, langkah berikutnya, tautan), dan tombol Selesai, Jeda, Terblokir, Pindah task.
- [ ] Jika tidak ada task Running, panel menampilkan ajakan memilih dari rekomendasi.
- [ ] Tiga kartu rekomendasi: peringkat, skor dan batang skor, judul, dua alasan, dengan tombol Mulai atau Pindah ke task ini (bila Running penuh), dan Lewati. "Jadwalkan" disembunyikan sampai Rilis 2.
- [ ] Daftar Menunggu dengan alasan; hambatan eksternal bisa ditandai teratasi.
- [ ] Tombol Jeda dan Terblokir membuka form konteks yang sama dengan R1-17.

### R1-17 · Dialog pindah konteks · M
Bergantung pada: R1-11, R1-16 · FR-06, FR-07, FR-23

Kriteria penerimaan:
- [ ] Menampilkan task yang ditinggalkan dan task yang dimulai.
- [ ] Form diisi awal dari `/contexts/draft`; pengguna cukup mengoreksi.
- [ ] Baris progres satuan (− angka +, "dari N satuan") hanya muncul bila task punya `unit_total`.
- [ ] Pilihan tujuan task lama: Ready (default), Waiting (alasan wajib), Suspended.
- [ ] "Simpan & pindah" memanggil `POST /switch`; galat ditampilkan tanpa menutup dialog dan tanpa kehilangan isian.
- [ ] Bisa diselesaikan dengan keyboard saja (Tab, Enter, Esc).

### R1-18 · Papan status · M
Bergantung pada: R1-15 · FR-18

Kriteria penerimaan:
- [ ] Tujuh kolom status; Ready diurutkan menurut skor rekomendasi terbaru; Running menampilkan "n / WIP limit".
- [ ] Filter Objective dan jenis pekerjaan (label), termasuk "Tanpa KR (orphan)".
- [ ] Kartu menampilkan task_key, judul, label, dan satu baris info (deadline, progres satuan, atau alasan menunggu).
- [ ] Menyeret kartu ke kolom lain memanggil endpoint transisi; transisi tidak sah ditolak dengan pesan, keluar dari Running membuka form konteks.
- [ ] Kolom bisa digulir horizontal di layar sempit.

### R1-19 · Detail task · M
Bergantung pada: R1-10, R1-15 · FR-08, FR-09, FR-11, FR-23

Kriteria penerimaan:
- [ ] Judul dan status bisa diubah di tempat; tombol aksi sesuai status saat ini.
- [ ] Riwayat konteks berbentuk lini masa, terbaru di atas.
- [ ] Tabel log transisi: waktu, dari → ke, alasan, sumber.
- [ ] Panel data TCB: KR, prioritas, deadline, estimasi/terpakai, progres satuan, jumlah pindah konteks, jenis kerja, subtask, dependensi, dokumen tertaut.
- [ ] Semua field di panel data bisa diedit langsung dan boleh dikosongkan, kecuali judul.

### R1-20 · Kelola Objective dan Key Result (sederhana) · S
Bergantung pada: R1-05, R1-15 · FR-01, FR-02

Tampilan progres penuh (FR-19) masuk Rilis 3; Rilis 1 cukup pengelolaan.

Kriteria penerimaan:
- [ ] Daftar Objective aktif dengan progres; tambah, ubah, dan arsipkan Objective.
- [ ] Tambah, ubah, dan hapus Key Result di bawah Objective; bobot dan mode progres bisa diatur.
- [ ] Task orphan bisa ditautkan ke KR dari halaman ini.

---

## M5 · Rilis

### R1-21 · Pemasangan, cadangan, dan pemakaian harian · M
Bergantung pada: R1-16 – R1-20

Kriteria penerimaan:
- [ ] Terpasang di satu VPS dengan Docker Compose, HTTPS, dan domain.
- [ ] Cadangan PostgreSQL harian otomatis (`pg_dump`), disimpan minimal 14 hari, dan **pemulihan sudah diuji sekali**.
- [ ] Dipakai sendiri minimal 10 hari kerja untuk mengajar, menulis paper, dan mengoreksi ujian.
- [ ] Catatan pemakaian: apa yang mengganjal, rekomendasi yang sering dilewati, dan field yang tidak pernah diisi. Catatan ini menjadi masukan v1.2 dan Rilis 2.

---

## Di luar Rilis 1 (jangan dikerjakan sekarang)

- Integrasi Google Calendar, blok fokus, proposal, deadline dari event: Rilis 2 (FR-14, FR-15, FR-17, FR-24).
- Tampilan progres goal lengkap, pengaturan bobot, dasbor metrik, feed iCal dan ekspor: Rilis 3 (FR-19 s.d. FR-22).
- Penutupan task otomatis saat periode Objective berakhir: dicatat untuk Rilis 3.

## Catatan teknis (boleh dirapikan di story mana pun)

- CI: `actions/checkout@v4` dan `actions/setup-node@v4` masih memakai Node.js 20 yang sudah usang (peringatan GitHub, belum menggagalkan CI). Naikkan ke versi mayor terbaru; sekalian pertimbangkan mematok runner (mis. `ubuntu-24.04`) karena label `ubuntu-latest` sedang dipindahkan ke Ubuntu 26.
