# CLAUDE.md — TCB (Task Control Block)

TCB adalah aplikasi web personal yang mencatat setiap task beserta status, konteks kerja, dan keterkaitannya ke goal (OKR ringan), lalu merekomendasikan task berikutnya. Konsepnya meniru Process Control Block pada sistem operasi: task punya state, "register" (konteks saat ditinggalkan), prioritas, dan akuntansi waktu. Pengguna: satu dosen; pekerjaan utamanya mengajar, menulis paper, dan mengoreksi ujian (bukan pekerjaan kode).

## Dokumen rujukan (baca sebelum mengubah perilaku)

- `docs/srs-tcb-v1.md` — kebutuhan (baseline v1.1). Bagian 4 (state machine), 6 (rekomendasi), 8 (FR dan prinsip input minimal), 12 (pembagian logika database vs aplikasi).
- `docs/tcb_schema.sql` — skema PostgreSQL acuan (revisi 2).
- `docs/tcb_openapi.yaml` — kontrak API. Sumber kebenaran untuk request/response.
- `BACKLOG-R1.md` — story Rilis 1, urutan, dan kriteria penerimaan.

## Stack

- `api/`: Laravel (REST API saja), Sanctum, PostgreSQL 16, Pest.
- `web/`: React + Vite + TypeScript, TanStack Query, Tailwind CSS, Vitest.
- `docker compose up` menjalankan app, worker, db, dan web.

## Aturan arsitektur (jangan dilanggar)

1. **Status task hanya berubah lewat `TransitionService`.** Jangan pernah `update(['state' => ...])` langsung dari controller, job, atau seeder selain tes skema. `PATCH /tasks/{id}` menolak field `state`.
2. **`allowed_transitions` adalah satu-satunya sumber aturan transisi.** `TaskStateMachine` membaca tabel itu; jangan menyalin daftar transisi ke kode PHP atau TypeScript.
3. **Database adalah pagar terakhir.** Trigger WIP limit, cek transisi, log append-only, dan CHECK constraint sengaja ada di database. Jangan menghapus atau melemahkannya agar tes lulus; perbaiki kodenya. Petakan galat database (hint `invalid_transition`, `wip_full`, constraint `chk_*`) ke respons API yang ramah.
4. **Isi sumber log di setiap transaksi transisi:** `DB::statement("SET LOCAL tcb.source = ?", [$source])` dengan nilai `user`, `calendar`, atau `system`.
5. **Alur kerja ada di aplikasi, bukan trigger:** sesi kerja dan akuntansi (`TransitionService`), pelepasan dependensi (`DependencyService`), sinkron kalender (`CalendarSyncJob`, Rilis 2). Jangan menambah trigger baru untuk alur kerja.
6. **Prinsip input minimal:** hanya `title` yang wajib. Field lain opsional, berdefault, dan tidak boleh menghalangi perubahan status. Form diisi awal dari nilai terakhir.
7. **Satu pengguna, siap multi-user:** setiap tabel punya `owner_id`; semua query dibatasi global scope pemilik.
8. **Waktu disimpan UTC**, ditampilkan sesuai zona waktu pengguna (default Asia/Jakarta).

## Cara kerja per story

- Satu story di `BACKLOG-R1.md` = satu branch `r1-xx-nama-singkat`.
- Tulis tes dari kriteria penerimaan lebih dulu, lalu implementasi.
- Tes API berjalan terhadap PostgreSQL (bukan SQLite), karena aturan inti ada di database.
- Jika kontrak API atau skema perlu berubah, **tanyakan dulu**; bila disetujui, perbarui `docs/tcb_openapi.yaml` atau migrasi di commit yang sama.
- Setelah selesai, centang kriteria penerimaan story tersebut di `BACKLOG-R1.md`.

## Konvensi

- Teks antarmuka dan pesan galat untuk pengguna: bahasa Indonesia. Kode, nama variabel, dan komentar teknis: bahasa Inggris.
- Nama status di kode: `new`, `ready`, `running`, `waiting`, `suspended`, `done`, `cancelled`. Di UI: New, Ready, Running, Waiting, Suspended, Done, Cancelled.
- Kunci task tampil apa adanya (`TCB-31`) dan diterima di URL sebagai pengganti ID numerik.
- Jangan menambah fitur di luar story yang sedang dikerjakan; catat idenya di bagian akhir `BACKLOG-R1.md`.

## Perintah umum

```bash
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan test
docker compose exec web npm run test
docker compose exec app php artisan tcb:create-user
```

Catatan lingkungan:

- Service `app` menjalankan `php artisan migrate --force` setiap kali start, sebelum `serve`. `migrate:fresh --seed` tetap manual.
- `php artisan test` selalu memakai database `tcb_test` (dipaksa di `api/phpunit.xml`), jadi tidak menyentuh data dev di `tcb`.
- Job CI `compose` menguji `docker compose up` (keempat service, health check, worker tidak crash-loop). Jika mengubah `docker-compose.yml` atau Dockerfile, pastikan job ini tetap hijau.

### Mesin pengembang tanpa Docker (Windows, saat ini)

Docker belum terpasang di mesin pengembang. Perintah `docker compose ...` di atas tidak bisa dijalankan lokal; gunakan padanannya:

```bash
# API: PostgreSQL 18 lokal, kredensial di api/.env (database tcb dan tcb_test, pemilik tcb_user)
cd api
php artisan migrate:fresh --seed
php vendor/bin/pest            # jangan `php artisan test` bila pdo_pgsql belum aktif di php.ini

# Web: jalankan dari path berhuruf drive besar (D:\tcb\web), bukan d:\
cd web
npm run test
npm run lint
```

- Jika `extension=pdo_pgsql` dan `extension=pgsql` belum aktif di `C:\php\php.ini`, tambahkan `-d extension=pdo_pgsql -d extension=pgsql` setelah `php`. `php artisan test` tidak bisa memakai flag ini karena menjalankan proses PHP baru (galat "could not find driver").
- Vitest gagal dengan "failed to find the current suite" bila dijalankan dari `d:\` (huruf kecil); jalankan dari `D:\` (PowerShell: `Set-Location D:\tcb\web`).
- Folder tes kosong harus berisi `.gitkeep`; Git tidak menyimpan folder kosong dan PHPUnit gagal di CI bila folder di `phpunit.xml` tidak ada.
