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
- [x] Login email + password menghasilkan token Sanctum; logout mencabut token.
- [x] Tidak ada endpoint registrasi publik; akun dibuat lewat `php artisan tcb:create-user`.
- [x] `GET /me` mengembalikan profil dan pengaturan (WIP limit 1, bobot skor default, zona waktu Asia/Jakarta).
- [x] Semua endpoint selain login dan health mengembalikan 401 tanpa token.
- [x] Setiap query dibatasi `owner_id` pengguna yang login (global scope), siap untuk multi-user v2.

---

## M1 · Data inti

### R1-04 · API Objective · S
Bergantung pada: R1-03 · FR-01

Kriteria penerimaan:
- [x] `GET/POST /objectives`, `GET/PATCH /objectives/{id}` sesuai OpenAPI.
- [x] Wajib: judul, periode mulai, periode akhir; periode akhir ≥ mulai (422 jika tidak).
- [x] Status bisa diubah ke `closed` atau `archived`; Objective `archived` tidak muncul di daftar default.
- [x] Respons memuat `progress` dari view `objective_progress`.

Catatan R1-04: detail Objective sudah memuat `key_results` (dengan `progress`); `task_counts` per KR menyusul di R1-05. Field opsional `orphan_task_count` dan `time_allocation` di `ObjectiveDetail` belum diisi (lihat "Catatan teknis").

### R1-05 · API Key Result dan progres · M
Bergantung pada: R1-04 · FR-02

Kriteria penerimaan:
- [x] `POST /objectives/{id}/key-results`, `PATCH/DELETE /key-results/{id}`.
- [x] Default: bobot 1, mode progres `auto`. Mode `manual` mewajibkan `target_value`.
- [x] Menghapus KR membuat task terkait menjadi orphan (`key_result_id = null`), bukan ikut terhapus.
- [x] Respons memuat `progress` dari view `key_result_progress` dan `task_counts` per status.
- [x] Tes: progres otomatis memakai estimasi; task tanpa estimasi dihitung 60 menit; task Cancelled tidak dihitung.

Catatan R1-05: `task_counts` selalu memuat ketujuh status (0 bila kosong) agar UI tidak perlu mengisi nilai yang hilang; task terhapus (soft delete) tidak dihitung, task Cancelled tetap dihitung di `cancelled`. Detail Objective kini juga memuat `task_counts` per KR. Tes progres memindahkan status task langsung lewat SQL (`walkTo`) karena TransitionService baru ada di R1-10.

### R1-06 · API task: tambah cepat, daftar, detail, ubah, hapus · M
Bergantung pada: R1-03 · FR-03, FR-04

Kriteria penerimaan:
- [x] `POST /tasks` hanya mewajibkan `title`; task lahir sebagai `new` dengan `task_key` otomatis.
- [x] `start_ready: true` langsung memindahkan task ke `ready` (lewat TransitionService setelah R1-10; sementara lewat update status langsung).
- [x] `GET /tasks` mendukung filter `state` (dipisah koma), `key_result_id`, `objective_id`, `label`, `orphan`, `q` (judul atau task_key), dan cursor pagination.
- [x] `GET /tasks/{id}` menerima ID numerik **atau** task_key (`TCB-31`).
- [x] `PATCH /tasks/{id}` menolak field `state` dengan 422 dan pesan yang menunjuk ke endpoint transisi.
- [x] `DELETE /tasks/{id}` melakukan soft delete; task terhapus tidak muncul di daftar, log tetap utuh.
- [x] `is_orphan` bernilai true bila `key_result_id` kosong.

### R1-07 · Subtask dan dependensi · M
Bergantung pada: R1-06 · FR-11

Kriteria penerimaan:
- [x] `parent_task_id` bisa diisi saat membuat atau mengubah task; task tidak bisa menjadi induk dirinya sendiri atau membentuk siklus (422).
- [x] `GET/POST /tasks/{id}/dependencies`, `PATCH /dependencies/{id}` (status `resolved`), `DELETE /dependencies/{id}`.
- [x] Dependensi berupa task lain **atau** deskripsi eksternal, tidak keduanya (422).
- [x] Dependensi yang membentuk siklus (A menunggu B, B menunggu A) ditolak (422).
- [x] `GET /tasks/{id}` memuat `subtasks` dan `open_dependencies`.

Catatan R1-07: `depends_on_task_id` menerima ID numerik atau task_key. Selain aturan di kriteria, `POST` juga menolak (422) task yang menunggu dirinya sendiri, pasangan yang sudah ada (UNIQUE di database), dan task tujuan yang sudah Done/Cancelled (dependensinya tidak akan pernah terlepas). Pemeriksaan siklus hanya menelusuri dependensi `open`, langsung maupun tidak langsung. `PATCH /dependencies/{id}` hanya untuk hambatan eksternal (sesuai ringkasan di kontrak); dependensi ke task lain → 422, karena terlepas otomatis saat task itu Done (R1-12). `PATCH` berulang tidak mengubah `resolved_at`. Dependensi milik task terhapus menghasilkan 404. `PATCH /tasks/{id}` kini juga mengembalikan `TaskDetail`, sesuai kontrak.

### R1-08 · Progres satuan · S
Bergantung pada: R1-06 · FR-23

Kriteria penerimaan:
- [x] `unit_total`, `unit_done`, `unit_name` opsional saat membuat dan mengubah task.
- [x] `unit_name` kosong disimpan sebagai `unit`.
- [x] Saran nama satuan dari label (dikembalikan sebagai saran, tidak disimpan otomatis): `Koreksi ujian` → `lembar`, `Menulis paper` → `halaman`, `Mengajar` → `slide`; label lain → `unit`.
- [x] `unit_done` > `unit_total` ditolak dengan pesan ramah (422), bukan galat 500.
- [x] Respons task memuat `progress_unit` (`done`, `total`, `name`) atau `null`.

Catatan R1-08: atas persetujuan pengguna, kontrak berubah: `TaskCreate` menerima `unit_done`, dan `Task` memuat `unit_name_suggestion` (string, dihitung dari label setiap respons, tidak disimpan; pencocokan label tanpa membedakan huruf besar-kecil). `unit_name` kosong disimpan sebagai `unit` hanya bila task punya `unit_total`; tanpa total, nama tetap kosong. `PATCH` dengan `unit_total: null` menghapus progres satuan (`unit_done` dan `unit_name` ikut dikosongkan). Pemeriksaan `unit_done` ≤ `unit_total` memakai nilai efektif (yang dikirim atau yang tersimpan): melebihi → 422 di `unit_done`; menurunkan total di bawah jumlah selesai → 422 di `unit_total`; `unit_done` tanpa total → 422. Bila validasi terlewat, galat CHECK `chk_tasks_unit_le_total`/`chk_tasks_unit_needs_total` dari database dipetakan ke 422 oleh `App\Support\DatabaseErrors` (renderer di `bootstrap/app.php`), yang bisa diperluas R1-09 untuk `chk_*` lain.

---

## M2 · Mesin TCB

### R1-09 · State machine dan endpoint transisi · M
Bergantung pada: R1-06 · FR-05, FR-09

Kriteria penerimaan:
- [x] Kelas `TaskStateMachine` **membaca** tabel `allowed_transitions` (di-cache per request); tidak ada daftar transisi yang disalin di kode.
- [x] `POST /tasks/{id}/transitions` dengan `{to, reason?, context?}`.
- [x] Transisi tidak sah → 409 `invalid_transition` dengan pesan dalam bahasa Indonesia, mis. "Task berstatus New tidak bisa langsung Running. Pindahkan ke Ready dulu."
- [x] New → Ready cukup dengan judul (SRS v1.1).
- [x] Waiting/Cancelled tanpa `reason` → 422 `reason_required`.
- [x] Running → Done ditolak (409) bila masih ada subtask yang belum Done atau Cancelled.
- [x] `GET /tasks/{id}/transitions` mengembalikan log, terbaru dulu, beserta sumbernya.
- [x] Galat database (`invalid_transition`, `wip_full`, `chk_*`) dipetakan ke respons API yang sama, sehingga aturan tetap aman walau validasi aplikasi terlewat.

Catatan R1-09: kontrak dan skema tidak berubah. `TaskStateMachine` (`app/Services`) didaftarkan sebagai `scoped` di `AppServiceProvider`, jadi tabel dibaca paling banyak sekali per request/job; pesan penolakan menyarankan status antara bila ada jalur dua langkah ("Pindahkan ke Ready dulu"), selain itu menyebut status yang bisa dituju, dan `details` memuat `from`, `to`, `allowed`. Daftar status yang wajib beralasan (`waiting`, `cancelled`) ada di `TaskStateMachine::requiresReason()` sebagai cermin `chk_tasks_reason_required`, bukan daftar transisi. Respons 422 `reason_required` berisi `code`, `message`, dan `errors.reason`, sehingga cocok dengan `Error` maupun `ValidationErrorBody`. Penolakan Done karena subtask terbuka memakai kode `conflict` dengan `details.open_subtasks` (kunci task); subtask yang dihapus diabaikan. `TransitionService` versi awal: satu transaksi, `set_config('tcb.source', ?, true)`, kunci baris task, pemeriksaan ramah, lalu update status; galat database di dalamnya diterjemahkan oleh `DatabaseErrors::rejection()` menjadi `TransitionRejected` yang sama dengan pemeriksaan aplikasi, dan renderer global memakai `DatabaseErrors::response()` untuk galat yang muncul di luar endpoint. `wip_full` belum diperiksa di aplikasi (database yang menolak, dengan kunci baris pengguna); responsnya sudah berisi `wip_limit` dan `running`. `context` diterima sesuai kontrak tetapi belum disimpan (R1-10). Atas persetujuan pengguna, kontrak dan skema berubah: 404 `not_found` dicantumkan pada `GET/POST /tasks/{taskId}/transitions`; kolom baru `tasks.review_on` (date) dengan `chk_tasks_review_on_suspended` (hanya task Suspended yang boleh punya tanggal tinjau ulang; skema revisi 3, migrasi `2026_11_12_100500_add_review_on_to_tasks`), dan `Task.review_on` (readOnly) di kontrak. `review_on` hanya diterima bila `to: suspended` (selain itu 422), diisi oleh `TransitionService` saat masuk Suspended dan dikosongkan saat keluar; `PATCH /tasks` tidak menerimanya. Reason tanpa isi (spasi) dianggap kosong; reason pada status yang tidak mewajibkannya tetap disimpan.

### R1-10 · TransitionService: konteks, sesi kerja, akuntansi · L
Bergantung pada: R1-09 · FR-07, FR-08, FR-10 · Spesifikasi: SRS bagian 12

Kriteria penerimaan:
- [x] Semua perubahan status (endpoint, switch, service lain) lewat `TransitionService`, dalam satu transaksi, dengan `SET LOCAL tcb.source`.
- [x] Keluar dari Running ke Ready/Waiting/Suspended tanpa `context` → 422 `context_required`.
- [x] Saat keluar dari Running: context snapshot tersimpan; `next_step` dan `unit_done` task diperbarui dari konteks; sesi kerja ditutup; `time_spent_minutes` bertambah; `switch_count` +1.
- [x] Ke Done/Cancelled dari Running: sesi ditutup dan waktu ditambahkan, `switch_count` tidak bertambah.
- [x] Masuk Running: sesi kerja baru dibuka.
- [x] `GET /tasks/{id}/contexts` (riwayat), `POST /tasks/{id}/contexts` (catatan tanpa ubah status).
- [x] `GET /tasks/{id}/contexts/draft` mengembalikan isian awal dari konteks terakhir + `unit_done` saat ini (prinsip input minimal).
- [x] Tes kegagalan: jika satu langkah gagal, tidak ada sesi terbuka yang tertinggal dan tidak ada konteks setengah tersimpan.

Catatan R1-10: skema tidak berubah; kontrak hanya ditambah 404/422 pada endpoint konteks (lihat "Catatan teknis"). Model `Task` melempar `LogicException` bila `state` diubah lewat Eloquent di luar `TransitionService` (`Task::changingState()`); pembuatan task dengan status tertentu tetap boleh karena tes skema memakainya, dan SQL mentah tetap dijaga trigger. Urutan pemeriksaan: transisi sah → alasan → konteks → subtask. Respons 422 `context_required` berisi `code`, `message`, dan `errors.context`. `context: {}` dianggap konteks (semua field opsional); `null` atau tidak dikirim berarti tanpa konteks. Konteks yang dikirim pada transisi lain (mis. Running → Done) tetap disimpan, tanpa menaikkan `switch_count`. Field `next_step`/`unit_done` yang tidak dikirim mengambil nilai task saat itu, sehingga snapshot selalu memuat "register" lengkap dan `tasks.next_step`/`unit_done` selalu sama dengan snapshot terbaru; `null` yang dikirim mengosongkannya. `unit_done` konteks diperiksa terhadap `unit_total` task (422 di `context.unit_done`, pesan sama dengan R1-08). `POST /contexts` juga menyalin `next_step`/`unit_done` ke task, boleh untuk status apa pun, dan tidak menyentuh sesi atau `switch_count`. Draft: `last_note`, `blocker`, `links` dari snapshot terbaru; `next_step` dan `unit_done` dari task (bisa sudah diubah lewat `PATCH`); `unit_done` 0 bila ada total tetapi belum ada yang selesai. Waktu sesi memakai jam aplikasi (`now()`, bisa dibekukan di tes), dibulatkan ke menit oleh kolom `duration_minutes`; keluar dari Running tanpa sesi terbuka (data lama) menambah 0 menit. `TaskDetail` kini memuat `latest_context` dan `current_session_started_at`. Logika snapshot ada di `ContextService`, aturan "keluar Running tanpa selesai" di `TaskStateMachine::isContextSwitch()`, validasi `ContextInput` di trait `ValidatesContextInput` (siap dipakai `/switch` dengan awalan `context.`). Urutan kunci di `links` mengikuti `jsonb` (`url` sebelum `title`).

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
- Token Sanctum kedaluwarsa setelah 30 hari, tetapi barisnya tetap di `personal_access_tokens`. Jadwalkan `php artisan sanctum:prune-expired --hours=24` harian saat scheduler sudah berjalan (paling lambat R1-21).
- Pesan validasi bawaan Laravel masih berbahasa Inggris (folder `lang/id` belum ada); R1-03 memakai pesan kustom. Pertimbangkan `php artisan lang:publish` + terjemahan `id` sebelum endpoint dengan banyak validasi (R1-04 dst.).
- `ObjectiveDetail.orphan_task_count` dan `time_allocation` (jam per KR 7 hari terakhir) belum diimplementasikan; tidak ada di kriteria R1 mana pun. Perlu diputuskan: `orphan_task_count` menghitung task orphan milik pengguna secara global (orphan tidak terikat Objective), dan `time_allocation` lebih cocok di tampilan progres goal (FR-19, Rilis 3).
- Respons 404 kini seragam `{code: not_found, message: "<Model> tidak ditemukan."}` (renderer di `bootstrap/app.php`); tambahkan model baru ke peta label di sana.
- `GlobalScope` pemilik hanya aktif saat ada pengguna login. Job antrean dan perintah konsol (mis. `CalendarSyncJob`, Rilis 2) harus menyaring `owner_id` sendiri.
- R1-06: `start_ready` memindahkan task ke `ready` dengan update status langsung di `TaskController::moveToReady()` (diizinkan sementara oleh kriteria). Sudah diganti dengan `TransitionService` di R1-09.
- R1-09 (ide, belum dikerjakan): `review_on` belum dipakai di mana pun selain disimpan. Kandidat: tampilkan task Suspended yang tanggal tinjaunya sudah lewat di Beranda, atau ubah tanggalnya tanpa keluar dari Suspended.
- R1-09: `set_config('tcb.source', ?, true)` berlaku sampai akhir transaksi terluar. Bila `TransitionService` dipanggil di dalam transaksi lain (mis. `POST /tasks` dengan `start_ready`, atau `/switch` di R1-11), setiap transisi tetap menyetel sumbernya sendiri, tetapi kode lain dalam transaksi yang sama ikut mewarisi sumber terakhir.
- R1-06: `GET /tasks?sort=` baru menerima `updated` (bawaan). `score` (kontrak) menunggu R1-13; `deadline` (kontrak) belum masuk kriteria story mana pun. Keduanya kini 422 "Urutan yang tersedia saat ini hanya updated." Saat dibuat, urutkan `deadline` dengan `coalesce(deadline_at, 'infinity')` beralias agar cursor tidak rusak oleh NULL.
- R1-06: ukuran halaman `GET /tasks` tetap 50 (`TaskController::PER_PAGE`); kontrak tidak punya parameter `limit`.
- R1-06: cursor `GET /tasks` diurutkan pada alias `cursor_updated_at` (teks timestamptz mentah). Cast `datetime` Eloquent mengubah nilai cursor menjadi detik saja sehingga baris dalam detik yang sama terlewat; pola yang sama perlu dipakai untuk cursor pada kolom waktu lain. Catatan terkait: Eloquent menulis waktu tanpa microsecond (`UsesTimestampTz`), sedangkan `now()` PostgreSQL menyimpan microsecond.
- R1-06: field satuan `unit_*` kini diterima `POST/PATCH /tasks` (R1-08). `parent_task_id` sudah diterima (kriteria 1 R1-07 dikerjakan di branch R1-06 atas permintaan pengguna). `GET /tasks/{id}` baru mengembalikan field `Task`; tambahan `TaskDetail` (`subtasks`, `open_dependencies`, `latest_context`, ...) diisi oleh R1-07 dan R1-10.
- R1-07: atas persetujuan pengguna, `docs/tcb_openapi.yaml` kini mencantumkan 404 pada keempat endpoint dependensi dan 422 pada `PATCH /dependencies/{id}`, beserta penjelasan bahwa `PATCH` hanya untuk hambatan eksternal.
- R1-07 (ide, belum dikerjakan): task yang di-soft delete membuat dependensi yang menunggunya tetap `open` selamanya, sehingga task penunggu tidak pernah layak direkomendasikan. Putuskan di R1-12: lepaskan (`resolved`) atau hapus dependensi itu saat task dihapus.
- R1-07 (ide, belum dikerjakan): subtask yang menunggu induknya sendiri akan buntu, karena induk tidak bisa Done selama subtask terbuka (R1-09). Belum ditolak.
- R1-10: atas persetujuan pengguna, `docs/tcb_openapi.yaml` kini mencantumkan 404 pada ketiga endpoint `/tasks/{taskId}/contexts…` dan 422 pada `POST /tasks/{taskId}/contexts`.
- R1-10 (ide, belum dikerjakan): `tasks.last_activity_at` belum diisi di mana pun. Kandidat: isi saat transisi dan saat menyimpan konteks, untuk deteksi `stale_running`.
- R1-07: pemeriksaan siklus dependensi tidak memakai kunci; dua permintaan bersamaan (A→B dan B→A) secara teori bisa lolos. Risiko kecil untuk satu pengguna.
