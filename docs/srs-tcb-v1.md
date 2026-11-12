# SRS Task Control Block (TCB) v1

Nov 12, 2026 · @Bagaimana membuat plan pada member yang menggunakan pricicing in

**Status: Baseline v1.0, dibekukan 12 November 2026.** Perubahan sesudah tanggal ini dicatat di bagian 13 (Riwayat revisi) dan mengikuti aturan perubahan di sana.

## 1. Pendahuluan

TCB v1 adalah aplikasi web untuk satu pengguna. Aplikasi ini mencatat setiap task beserta status, konteks kerja, dan keterkaitannya ke goal, lalu merekomendasikan task berikutnya. Keputusan akhir selalu di tangan pengguna.

**Tujuan produk**

- Pengguna selalu tahu task apa yang sedang berjalan, apa yang menunggu, dan mengapa.
- Berpindah task tidak menghilangkan konteks, karena kondisi terakhir tersimpan seperti register CPU pada context switch.
- Setiap jam kerja dapat ditelusuri ke Objective dan Key Result yang dilayaninya.

**Ruang lingkup v1**

| Masuk v1 | Di luar v1 (fase berikut) |
| --- | --- |
| Satu pengguna, aplikasi web responsif | Multi-user, tim, delegasi |
| OKR ringan: Objective, Key Result, Task | Kerangka BSC, KPI perusahaan |
| State machine task dan log transisi | Penjadwalan otomatis tanpa konfirmasi |
| Simpan dan pulihkan konteks kerja | Aplikasi mobile native |
| Rekomendasi task berikutnya beserta alasannya | Rekomendasi berbasis machine learning |
| Sinkron Google Calendar (wajib) | Integrasi Git/GitHub, chat, e-mail, tool lain |

**Istilah**

| Istilah | Arti dalam sistem ini |
| --- | --- |
| TCB (Task Control Block) | Rekaman lengkap satu task, padanan PCB pada sistem operasi |
| Konteks (context snapshot) | Catatan kondisi saat task ditinggalkan: langkah terakhir, langkah berikutnya, tautan, blocker |
| Context switch | Perpindahan dari satu task Running ke task lain, yang memicu penyimpanan konteks |
| Ready queue | Daftar task yang siap dikerjakan, diurutkan oleh skor rekomendasi |
| WIP limit | Batas jumlah task berstatus Running sekaligus, padanan jumlah CPU |
| Aging | Kenaikan skor bertahap untuk task yang lama menunggu, agar tidak terlupakan (starvation) |
| Orphan task | Task yang tidak terhubung ke Key Result mana pun |

## 2. Keputusan desain v1 dan asumsi

Setiap keputusan v1 memilih opsi paling sederhana yang tetap bisa diganti tanpa membongkar model data.

| Aspek | Pilihan v1 | Alasan | Jalur perubahan nanti |
| --- | --- | --- | --- |
| Pengguna | Satu pengguna | Fokus pada kebutuhan personal | Tambah entitas Workspace dan Member; setiap tabel sudah memakai owner\_id |
| Peran scheduler | Rekomendasi: 3 task teratas beserta alasannya | Pengguna yang memutuskan | Mode otomatis menjadi opsi pengaturan |
| Kerangka goal | OKR ringan: Objective, lalu Key Result, lalu Task | Paling mudah diterapkan dan terukur | Entitas Goal generik dengan atribut jenis (OKR, KPI, BSC) |
| Proyek | Label/pengelompokan opsional, bukan level wajib | Mengurangi beban input | Bisa dinaikkan menjadi level hierarki |
| Platform | Web responsif, siap PWA, arsitektur API-first | Mobile nanti cukup memakai API yang sama | Aplikasi mobile sebagai klien baru |
| Kalender | Google Calendar via OAuth: baca free/busy kalender utama, tulis blok fokus ke kalender khusus TCB; ditambah feed iCal hanya-baca | Paling umum; iCal menjangkau kalender lain | Tambah adaptor Outlook atau CalDAV |
| Git | Tidak digunakan | TCB untuk pekerjaan non-kode (keputusan 12 Nov 2026) | Bisa ditambahkan sebagai adaptor bila kebutuhan berubah |
| Penentuan prioritas | Skor berbobot yang transparan | Mudah dijelaskan dan disetel | Ganti fungsi skor tanpa mengubah data |

**Asumsi** dan keputusan yang sudah dikonfirmasi

- Dikonfirmasi: kalender utama Google Calendar, WIP limit default 1, blok fokus ditulis ke kalender khusus TCB, dan progres Key Result dihitung dari task.
- Waktu disimpan dalam UTC dan ditampilkan sesuai zona waktu pengguna (default Asia/Jakarta).
- Kunci task (misalnya TCB-42) dipakai sebagai rujukan singkat di catatan dan event kalender.

## 3. Model konsep

Setiap task wajib menginduk ke satu Key Result, sehingga progres mengalir naik dari task ke Objective.

**Hierarki goal (OKR ringan)**

1. **Objective**: tujuan kualitatif dengan periode, misalnya per kuartal atau semester.
2. **Key Result**: ukuran keberhasilan Objective, berisi nilai awal, target, nilai saat ini, dan bobot.
3. **Task (TCB)**: unit pekerjaan yang dijadwalkan dan diberi status.
   1. **Subtask**: task anak yang memakai struktur TCB yang sama. Task induk baru boleh Done jika semua anaknya selesai atau dibatalkan.

Aturan keterkaitan:

- Task tanpa Key Result tetap boleh dibuat, tetapi ditandai sebagai **orphan** dan diberi skor rendah pada komponen goal.
- Progres Key Result dihitung otomatis dari task terkait: total estimasi task Done dibagi total estimasi task yang tidak Cancelled. Task yang memakai progres satuan ikut dihitung sebagian: estimasi × (selesai ÷ total). Pengisian manual hanya tersedia sebagai pengecualian per Key Result.
- Objective yang periodenya berakhir akan menutup task terkait. Pengguna lalu memilih: pindahkan ke Objective baru, tunda, atau batalkan.

**Pemetaan PCB ke TCB**

| Elemen PCB | Field TCB | Fungsi dalam produk |
| --- | --- | --- |
| Process ID | task\_id, task\_key (misalnya TCB-42) | Identitas unik; rujukan singkat di catatan dan event kalender |
| Process state | state | Posisi task dalam state machine (bagian 4) |
| Program counter | next\_step | Langkah berikutnya yang harus dikerjakan |
| CPU registers | context snapshot | Kondisi kerja saat terakhir ditinggalkan |
| Priority | priority, skor efektif | Masukan bagi mesin rekomendasi |
| Memory/resource info | estimasi durasi, jenis energi, tautan sumber daya | Mencocokkan task dengan slot waktu kosong |
| Accounting info | waktu terpakai, jumlah switch, waktu tunggu | Metrik dan evaluasi estimasi |
| I/O status | dependensi, alasan Waiting | Menentukan task layak dijalankan atau tidak |
| Parent PID | parent\_task\_id, key\_result\_id | Hierarki dan keterkaitan ke goal |

## 4. Model status (state machine)

Task memiliki tujuh status. Hanya status Running yang dibatasi WIP limit, dan setiap transisi keluar dari Running wajib menyimpan konteks.

&#91;embedded content: state machine TCB · 7 status\]

Running adalah satu-satunya status yang dibatasi; Done dan Cancelled adalah status akhir yang bisa dibuka kembali.

**Aturan transisi**

| Dari | Ke | Pemicu | Aturan wajib |
| --- | --- | --- | --- |
| New | Ready | Pengguna melengkapi data minimal | Cukup judul. Estimasi kosong dihitung 60 menit oleh mesin rekomendasi; Key Result kosong otomatis ditandai orphan |
| Ready | Running | Pengguna memilih task, dari rekomendasi atau manual | Jumlah Running di bawah WIP limit; jika penuh, sistem menawarkan context switch |
| Running | Ready | Jeda atau interupsi | Simpan konteks; jumlah switch bertambah satu |
| Running | Waiting | Terhambat oleh pihak luar, data, atau task lain | Simpan konteks dan isi alasan atau dependensi |
| Waiting | Ready | Dependensi selesai atau pengguna menandai blocker teratasi | Otomatis jika dependensi berupa task lain yang sudah Done |
| Ready atau Waiting | Suspended | Ditunda sengaja | Isi tanggal tinjau ulang (opsional) |
| Suspended | Ready | Diaktifkan kembali | Konteks terakhir ditampilkan |
| Running | Done | Pengguna menyatakan selesai | Semua subtask Done atau Cancelled; catatan hasil opsional |
| Status aktif mana pun | Cancelled | Pengguna membatalkan | Alasan wajib diisi |
| Done atau Cancelled | Ready | Dibuka kembali | Tercatat sebagai reopen di log |

**Aturan umum**

- Setiap transisi ditulis ke log: waktu, status asal, status tujuan, alasan, dan sumber (pengguna atau kalender).
- Sumber eksternal (kalender) hanya boleh **mengusulkan** transisi. Perubahan status selalu dikonfirmasi pengguna, kecuali Waiting ke Ready untuk dependensi antartask.
- WIP limit default 1 dan bisa diatur 1 sampai 3.
- Context switch: jika pengguna memulai task baru saat Running sudah penuh, sistem meminta konteks task lama disimpan, memindahkannya ke Ready, lalu menampilkan konteks task baru.

## 5. Model data

Task adalah entitas pusat. Konteks, log transisi, sesi kerja, dan tautan eksternal disimpan di tabel terpisah agar riwayatnya utuh dan bisa dianalisis.

| Entitas | Atribut utama | Relasi |
| --- | --- | --- |
| User | id, nama, zona waktu, WIP limit, bobot skor, jam kerja | Memiliki semua entitas lain |
| Objective | id, judul, deskripsi, periode mulai, periode akhir, status | 1 Objective memiliki banyak Key Result |
| KeyResult | id, objective\_id, judul, nilai awal, target, nilai saat ini, satuan, bobot, mode progres (otomatis sebagai default, manual sebagai pengecualian) | 1 Key Result memiliki banyak Task |
| Task (TCB) | id, task\_key, judul, deskripsi, key\_result\_id, parent\_task\_id, state, priority (1 sampai 4), deadline, sumber deadline (manual atau event kalender + jeda; opsional), estimasi menit, progres satuan (selesai, total, nama satuan; opsional), jenis energi (fokus/ringan), label proyek, next\_step, waktu terpakai, jumlah switch, created\_at, updated\_at | Induk dari Subtask, ContextSnapshot, StateTransition, WorkSession, Dependency, tautan eksternal |
| ContextSnapshot | id, task\_id, waktu, catatan terakhir, langkah berikutnya, blocker, daftar tautan (file, URL) | Banyak per Task; yang terbaru ditampilkan saat dilanjutkan |
| StateTransition | id, task\_id, waktu, dari, ke, alasan, sumber (user/calendar) | Log tidak dapat diubah (append-only) |
| WorkSession | id, task\_id, mulai, selesai, durasi | Dasar perhitungan waktu terpakai |
| Dependency | task\_id, depends\_on\_task\_id atau deskripsi eksternal, status | Menentukan task layak masuk Ready |
| CalendarLink | id, task\_id, event\_id, kalender, mulai, selesai, jenis (blok fokus/deadline/acuan deadline) | Banyak per Task |
| Recommendation | id, waktu, task\_id, skor, rincian skor, peringkat, keputusan (diterima/dilewati) | Umpan balik untuk menyetel bobot |
| Integration | id, penyedia, token terenkripsi, status, waktu sinkron terakhir | Satu per penyedia |

Ketentuan data: semua tabel memuat owner\_id agar v2 multi-user tidak perlu migrasi besar; penghapusan task bersifat soft delete agar log dan metrik tetap konsisten.

## 6. Mesin rekomendasi

Sistem menampilkan tiga task Ready dengan skor tertinggi, lengkap dengan alasannya. Pengguna memilih salah satu, melewatinya, atau memilih task lain secara manual.

**Langkah kerja**

1. **Saring kelayakan**: hanya task Ready yang semua dependensinya Done, tidak Suspended, dan bukan task induk yang masih punya subtask terbuka.
2. **Hitung skor** setiap task layak dengan rumus di bawah.
3. **Urutkan** dan ambil tiga teratas.
4. **Jelaskan**: tampilkan dua komponen skor terbesar sebagai alasan, misalnya "deadline 2 hari lagi" dan "Key Result bobot tinggi, progres 20%".
5. **Catat keputusan** pengguna (dipilih atau dilewati) ke tabel Recommendation.

**Rumus skor**

```latex
S = w_G G + w_U U + w_P P + w_A A + w_F F
```

| Komponen | Arti | Cara hitung (nilai 0 sampai 1) | Bobot awal |
| --- | --- | --- | --- |
| G (goal) | Kontribusi ke Key Result | Bobot KR × (1 − progres KR), dinormalisasi; orphan = 0 | 0,30 |
| U (urgensi) | Kedekatan deadline | 1 jika lewat deadline; 0 jika tanpa deadline atau sisa waktu ≥ 14 hari; linear di antaranya, dihitung dari sisa hari kerja dikurangi estimasi | 0,25 |
| P (prioritas) | Prioritas manual pengguna | (priority − 1) / 3, dengan 1 = rendah dan 4 = kritis | 0,20 |
| A (aging) | Lama menunggu di Ready | min(1, hari di Ready / 14) | 0,15 |
| F (fit) | Kecocokan dengan slot kalender berikutnya | 1 jika estimasi muat di slot kosong dan jenis energi cocok; 0,5 jika salah satu; 0 jika tidak | 0,10 |

Bobot awal adalah titik mulai yang bisa diubah di pengaturan. Rasio dipilih dilewati per komponen dipakai di v2 untuk menyarankan penyesuaian bobot.

**Pencegahan starvation**: komponen A naik setiap hari. Task yang tertahan di Ready lebih dari 14 hari diberi tanda khusus dan muncul sebagai pengingat "kerjakan, tunda, atau batalkan".

**WIP limit**: rekomendasi tetap tampil walaupun Running penuh, tetapi tombol mulai diganti dengan alur context switch (bagian 4).

## 7. Integrasi kalender

Kalender adalah satu-satunya integrasi v1: ia memberi tahu kapan task bisa dikerjakan. Git tidak digunakan karena TCB untuk pekerjaan non-kode. Kalender hanya mengusulkan perubahan status, dan pengguna yang mengonfirmasi.

**Kalender (Google Calendar, ditambah feed iCal)**

| Peristiwa | Aksi sistem | Konfirmasi pengguna? |
| --- | --- | --- |
| Sinkron berkala data free/busy | Menghitung slot kosong untuk komponen F | Tidak |
| Pengguna menerima rekomendasi dan memilih "jadwalkan" | Membuat event blok fokus di kalender khusus TCB, berisi task\_key dan tautan ke task | Ya, saat memilih |
| Waktu mulai blok fokus tiba | Notifikasi: "Mulai TCB-42 sekarang?" beserta konteks terakhir | Ya |
| Blok fokus berakhir saat task masih Running | Mengajak menyimpan konteks: lanjut, jeda, atau selesai | Ya |
| Deadline task diisi atau diubah | Membuat atau memperbarui event deadline (opsional) | Diatur di pengaturan |
| Event blok fokus dipindah atau dihapus di kalender | Memperbarui CalendarLink; task tidak berubah status | Tidak |
| Feed iCal | Menerbitkan deadline dan blok fokus sebagai feed hanya-baca untuk kalender lain | Tidak |
| Event acuan deadline dipindah atau dihapus | Deadline task ikut bergeser dan tercatat di log; jika event dihapus, deadline terakhir dipertahankan dan pengguna diberi notifikasi | Tidak, cukup notifikasi |

**Ketentuan teknis integrasi**

- Token OAuth disimpan terenkripsi, dengan cakupan izin minimum: free/busy dan event di kalender khusus TCB.
- Sinkron kalender yang gagal masuk antrean ulang dan dicoba kembali.
- Setiap aksi integrasi dicatat di StateTransition atau CalendarLink dengan sumber yang jelas.

## 8. Kebutuhan fungsional

Ada 23 kebutuhan fungsional aktif; FR-16 ditiadakan, tetapi ID-nya dipertahankan untuk keterlacakan. Kebutuhan berprioritas Must membentuk rilis pertama yang sudah bisa dipakai sehari-hari.

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| FR-01 | Pengguna dapat membuat, mengubah, dan mengarsipkan Objective beserta periodenya | Must |
| FR-02 | Pengguna dapat mengelola Key Result: target, satuan, bobot, dan mode progres | Must |
| FR-03 | Pengguna dapat membuat task dengan cepat cukup dari judul (masuk sebagai New), lalu melengkapinya | Must |
| FR-04 | Sistem memberi task\_key unik otomatis (misalnya TCB-42) | Must |
| FR-05 | Sistem menjalankan state machine sesuai bagian 4 dan menolak transisi yang tidak sah | Must |
| FR-06 | Sistem menerapkan WIP limit dan alur context switch | Must |
| FR-07 | Sistem meminta dan menyimpan context snapshot setiap keluar dari Running | Must |
| FR-08 | Sistem menampilkan konteks terakhir saat task dimulai kembali | Must |
| FR-09 | Sistem mencatat setiap transisi ke log append-only | Must |
| FR-10 | Sistem mencatat sesi kerja (timer otomatis saat Running) dan menghitung waktu terpakai | Must |
| FR-11 | Pengguna dapat membuat subtask dan dependensi antartask | Must |
| FR-12 | Sistem menampilkan tiga rekomendasi teratas beserta alasannya | Must |
| FR-13 | Pengguna dapat memilih, melewati, atau mengabaikan rekomendasi; keputusan dicatat | Must |
| FR-14 | Sistem tersambung ke Google Calendar dan membaca free/busy | Must |
| FR-15 | Pengguna dapat menjadwalkan task sebagai blok fokus di kalender | Must |
| FR-16 | Ditiadakan 12 Nov 2026: integrasi GitHub, karena TCB untuk pekerjaan non-kode | Ditiadakan |
| FR-17 | Sistem mengusulkan transisi dari peristiwa kalender (bagian 7) | Must |
| FR-18 | Tampilan utama: papan per status, daftar Ready terurut skor, dan panel "sedang berjalan" | Must |
| FR-19 | Tampilan goal: progres setiap Objective dan Key Result, serta daftar orphan task | Should |
| FR-20 | Pengguna dapat mengatur bobot skor, WIP limit, jam kerja, dan zona waktu | Should |
| FR-21 | Dasbor metrik (bagian 10) | Should |
| FR-22 | Feed iCal hanya-baca dan ekspor data (CSV/JSON) | Could |
| FR-23 | Pengguna dapat mencatat progres satuan secara opsional, misalnya 22 dari 40 lembar. Nama satuan default "unit" dengan saran sesuai jenis pekerjaan; angka terakhir terisi otomatis di form konteks dan bisa diubah dengan tombol tambah/kurang. Jika kosong, task dihitung selesai/belum seperti biasa. | Should |
| FR-24 | Pengguna dapat mengikat deadline ke event kalender secara opsional, misalnya slide selesai 1 hari sebelum jadwal kuliah. Jeda default 1 hari dan bisa diubah; deadline ikut bergeser jika event dipindah. Deadline manual tetap tersedia. | Should |

**Prinsip input minimal** (berlaku untuk semua field tambahan)

- Saat membuat task, hanya judul yang wajib. Field lain opsional dan punya nilai default.
- Field opsional tidak pernah menghalangi perubahan status. Jika kosong, sistem memakai perilaku dasar: task dihitung selesai atau belum, dan deadline diisi manual atau dibiarkan kosong.
- Nilai terakhir selalu terisi otomatis di form berikutnya, sehingga pengguna cukup mengoreksi, bukan mengetik ulang.
- Saran sistem, seperti nama satuan atau event acuan deadline, berupa tawaran satu klik yang boleh diabaikan, bukan pertanyaan wajib.

## 9. Kebutuhan non-fungsional

Kebutuhan non-fungsional v1 berfokus pada kecepatan input, keandalan sinkronisasi, dan keamanan token integrasi.

| ID | Aspek | Kebutuhan |
| --- | --- | --- |
| NFR-01 | Kecepatan input | Membuat task cukup dengan judul, dalam satu langkah dan dengan pintasan keyboard |
| NFR-02 | Kinerja | Halaman utama dan daftar rekomendasi tampil kurang dari 1 detik untuk 1.000 task aktif |
| NFR-03 | Keandalan sinkron | Sinkron kalender yang gagal dicoba ulang; tidak ada peristiwa yang hilang tanpa catatan |
| NFR-04 | Keamanan | Token OAuth dan secret dienkripsi; HTTPS wajib; izin integrasi minimum |
| NFR-05 | Privasi | Data milik pengguna; tersedia ekspor penuh dan penghapusan akun |
| NFR-06 | Arsitektur | API-first (REST atau GraphQL) agar klien mobile bisa memakai API yang sama |
| NFR-07 | Responsif | Nyaman dipakai di layar ponsel lewat browser, siap PWA |
| NFR-08 | Auditabilitas | Log transisi tidak dapat diubah; setiap perubahan mencatat sumbernya |
| NFR-09 | Waktu | Disimpan dalam UTC, ditampilkan sesuai zona waktu pengguna |
| NFR-10 | Keterubahan | Fungsi skor dan adaptor integrasi dipisah sebagai modul yang bisa diganti |

Angka 1 detik dan 1.000 task adalah target awal yang diusulkan, bukan hasil pengukuran.

## 10. Metrik, tahapan rilis, risiko, dan pertanyaan terbuka

Rilis dibagi menjadi tiga tahap. Inti TCB dibangun lebih dulu, karena integrasi hanya berguna jika pencatatan status sudah dipakai rutin.

**Metrik yang ditampilkan**

| Metrik | Definisi | Kegunaan |
| --- | --- | --- |
| Lead time | Waktu dari Ready sampai Done | Kecepatan penyelesaian |
| Waktu tunggu | Total waktu di Waiting | Menemukan sumber hambatan |
| Context switch per hari | Jumlah keluar dari Running sebelum Done | Mengukur fragmentasi fokus |
| Akurasi estimasi | Waktu terpakai dibagi estimasi | Memperbaiki estimasi berikutnya |
| Alokasi waktu per goal | Jam kerja per Objective/Key Result, termasuk orphan | Kesesuaian kerja dengan tujuan |
| Rasio rekomendasi dipilih | Rekomendasi dipilih dibagi yang ditampilkan | Kualitas mesin rekomendasi |

**Tahapan rilis**

1. **Rilis 1, inti TCB**: FR-01 sampai FR-13, FR-18, dan FR-23 (progres satuan). Pencatatan, state machine, konteks, dan rekomendasi tanpa slot kalender (komponen F bernilai netral).
2. **Rilis 2, integrasi wajib**: FR-14 sampai FR-17 dan FR-24 (deadline dari event kalender). Google Calendar; komponen F mulai aktif.
3. **Rilis 3, evaluasi dan penyetelan**: FR-19 sampai FR-22. Tampilan goal, pengaturan bobot, dasbor metrik, dan feed iCal.

Setelah itu (v2): klien mobile, multi-user, adaptor Outlook, serta penyetelan bobot otomatis dari riwayat keputusan.

**Risiko**

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Pengguna malas menyimpan konteks | Nilai pembeda produk hilang | Form konteks 2 kolom saja; isian awal disalin dari konteks sebelumnya |
| Pengguna lupa mengubah status | Data dan rekomendasi tidak akurat | Usulan transisi dari kalender; pengingat untuk task Running yang lama tanpa aktivitas |
| Bobot skor terasa tidak cocok | Rekomendasi diabaikan | Alasan skor ditampilkan; bobot bisa diatur; rasio dipilih dipantau |
| Perubahan API pihak ketiga | Sinkron terputus | Adaptor terpisah; status integrasi dan galat ditampilkan jelas |
| Field tambahan terasa membebani | Pengguna berhenti mencatat | Prinsip input minimal (bagian 8): semua field tambahan opsional, berdefault, dan terisi otomatis dari nilai terakhir |

**Pertanyaan terbuka**

- [x] Apakah kalender utama memang Google Calendar dan repositori utama di GitHub? ya
- [x] Berapa WIP limit default yang paling realistis untuk pola kerja Bapak: 1 atau 2? 1
- [x] Apakah blok fokus ditulis ke kalender utama atau ke kalender khusus TCB? khusus
- [x] Apakah progres Key Result umumnya diisi manual atau dihitung dari task? hitung dari task
- [x] Stack teknologi yang diinginkan untuk implementasi (backend, frontend, database)? rekomendasikan yang paling sesuai untuk kebutuhan saat ini

## 11. Rekomendasi stack teknologi

Rekomendasinya adalah **Laravel (REST API) + React SPA + PostgreSQL**. Laravel sudah menyediakan antrean, penjadwal, dan OAuth tanpa menyusun banyak pustaka, sehingga cocok untuk satu pengembang yang wajib menangani sinkron kalender dan pengingat terjadwal.

| Lapisan | Pilihan | Alasan untuk kebutuhan TCB |
| --- | --- | --- |
| Backend / API | Laravel sebagai REST API murni, autentikasi Laravel Sanctum | API-first sejak awal, sehingga klien mobile nanti cukup memakai endpoint yang sama |
| Frontend web | React + Vite, TanStack Query, Tailwind CSS | SPA ringan dan responsif; pengetahuan React terbawa ke mobile |
| Database | PostgreSQL | Constraint kuat untuk aturan state dan WIP limit, JSONB untuk rincian skor dan daftar tautan konteks |
| Pekerjaan latar | Laravel Queue (driver database dulu, Redis bila beban naik) | Memproses sinkron kalender dan notifikasi, mencoba ulang yang gagal (NFR-03) |
| Penjadwalan | Laravel Scheduler (satu cron) | Sinkron free/busy berkala, hitung ulang aging, pengingat task Running yang lama tanpa aktivitas |
| Integrasi | Laravel Socialite (Google OAuth), Google Calendar API | Token dienkripsi bawaan Laravel (NFR-04) |
| Mobile (v2) | React Native dengan Expo, atau PWA dari SPA yang sama | Memakai ulang API dan pola React |
| Deploy | Satu VPS dengan Docker Compose (app, worker, Postgres) | Murah dan cukup untuk satu pengguna |

**Catatan desain implementasi**

- State machine ditulis sebagai satu kelas domain berisi tabel transisi yang sah. Controller dan job semua melewati kelas ini, sehingga aturan bagian 4 hanya ada di satu tempat.
- WIP limit dijaga di dua lapis: validasi di kelas domain dan pengecekan dalam transaksi database agar dua permintaan bersamaan tidak membuat Running melebihi batas.
- Fungsi skor rekomendasi dibuat sebagai service terpisah (NFR-10), sehingga bobot dan rumus bisa diganti tanpa menyentuh data.

**Alternatif yang dipertimbangkan**

| Alternatif | Kelebihan | Mengapa bukan pilihan utama |
| --- | --- | --- |
| TypeScript penuh: NestJS + React + PostgreSQL + BullMQ | Satu bahasa dari backend sampai mobile | Lebih banyak komponen yang harus dirakit sendiri (antrean, scheduler, OAuth) |
| Django + Django REST Framework + React | Admin bawaan, ekosistem Python | Penjadwalan dan antrean butuh Celery, menambah infrastruktur |
| Next.js full-stack | Satu proyek untuk UI dan API | API cenderung menyatu dengan web, kurang ideal untuk API-first dan job latar yang lama |

Jika Bapak atau tim lebih terbiasa dengan TypeScript, alternatif pertama sama layaknya; arsitektur dan model data di dokumen ini tidak berubah.

## 12. Pembagian logika database dan aplikasi

Keputusan 12 Nov 2026: aturan yang harus selalu benar dijaga di database, sedangkan alur kerja dan efek samping bisnis dijalankan service di aplikasi. Dasarnya studi empiris yang menunjukkan validasi khusus aplikasi bocor saat ada permintaan bersamaan dan aturan yang dipecah di dua lapis cenderung tidak konsisten, serta praktik yang menyarankan trigger hanya untuk pola kecil dan audit.

| Logika | Lokasi | Komponen |
| --- | --- | --- |
| Alasan wajib, progres ≤ total, satu sesi terbuka per task, satu acuan deadline per task | Database | Constraint deklaratif |
| Transisi status yang sah | Database (satu sumber) | Tabel allowed\_transitions; kelas TaskStateMachine membacanya, tidak menyalin |
| WIP limit | Database | Trigger dengan penguncian baris pengguna |
| Log transisi otomatis dan append-only | Database | Trigger; sumber diisi aplikasi lewat SET LOCAL tcb.source |
| Kunci TCB-n, updated\_at | Database | Trigger kecil |
| Progres Key Result dan Objective | Database | View |
| Sesi kerja, waktu terpakai, jumlah pindah konteks | Aplikasi | TransitionService |
| Waiting → Ready saat dependensi selesai | Aplikasi | DependencyService |
| Deadline mengikuti event acuan, usulan dari kalender | Aplikasi | CalendarSyncJob |

Skema PostgreSQL revisi 2 dan 19 skenario ujinya sudah lolos di PostgreSQL 16, termasuk bukti bahwa database tetap menolak langkah service yang melanggar aturan.

### TransitionService

Satu-satunya jalan untuk mengubah status, dipakai oleh endpoint transisi, pindah konteks, dan penerimaan proposal.

1. Mulai transaksi dan isi sumber: SET LOCAL tcb.source = 'user', 'calendar', atau 'system'.
2. Periksa transisi lewat TaskStateMachine agar pesan galat ramah; database tetap memeriksa ulang.
3. Jika keluar dari Running ke Ready, Waiting, atau Suspended: simpan context snapshot, salin langkah berikutnya dan progres satuan ke task, tutup sesi kerja, tambahkan waktu terpakai, dan naikkan jumlah pindah konteks satu.
4. Jika keluar dari Running ke Done atau Cancelled: tutup sesi dan tambahkan waktu terpakai, tanpa menaikkan jumlah pindah konteks.
5. Ubah status task. Jika masuk Running, buka sesi kerja baru.
6. Jika status menjadi Done, panggil DependencyService di transaksi yang sama.
7. Commit. Galat database dipetakan ke respons API: invalid\_transition dan wip\_full menjadi 409 (wip\_full beserta daftar task yang sedang berjalan), pelanggaran alasan wajib menjadi 422.

Pindah konteks (POST /switch) menjalankan dua transisi dalam satu transaksi: task lama keluar dari Running, lalu task baru masuk Running. Jika salah satu gagal, tidak ada yang berubah.

### DependencyService

Dipanggil saat sebuah task menjadi Done, atau saat hambatan eksternal ditandai teratasi.

1. Tandai dependensi yang menunggu task tersebut sebagai resolved.
2. Untuk setiap task Waiting yang tidak lagi punya dependensi terbuka, jalankan TransitionService ke Ready dengan sumber 'system'.
3. Tampilkan pemberitahuan ringan di Beranda bahwa task tersebut siap dikerjakan.

Ini satu-satunya perubahan status otomatis tanpa konfirmasi, sesuai bagian 4.

### CalendarSyncJob

Dijalankan Laravel Scheduler secara berkala (usulan awal: setiap 15 menit) dan saat pengguna membuka Beranda.

1. Ambil perubahan event sejak sinkron terakhir dari kalender utama dan kalender khusus TCB.
2. Event acuan deadline bergeser: perbarui waktu event, hitung ulang deadline = mulai event − jeda, lalu buat proposal pemberitahuan deadline\_moved.
3. Event acuan dihapus: tandai event\_deleted, pertahankan deadline terakhir, lalu buat proposal anchor\_deleted.
4. Blok fokus dimulai: buat proposal start\_task. Blok fokus berakhir saat task masih Running: buat proposal save\_context.
5. Jika gagal, coba ulang dengan jeda bertahap dan tampilkan status integrasi sebagai error.

Job ini tidak pernah mengubah status task secara langsung; perubahan status hanya terjadi saat pengguna menerima proposal.

### Uji penerimaan

- Pindah konteks yang gagal di langkah mana pun tidak meninggalkan sesi terbuka, konteks setengah tersimpan, atau dua task Running.
- Menyelesaikan task membuat task yang menunggunya Ready dalam transaksi yang sama, dengan sumber 'system' di log.
- Menggeser jadwal kuliah di Google Calendar menggeser deadline slide paling lambat pada sinkron berikutnya.
- Kode service yang mencoba transisi tidak sah tetap ditolak database.

## 13. Riwayat revisi

SRS ini menjadi baseline v1.0 setelah lima putaran revisi dalam satu hari kajian.

| Versi | Tanggal | Perubahan |
| --- | --- | --- |
| 1.1 | 12 Nov 2026 | Klarifikasi kontradiksi bagian 4 dengan prinsip input minimal: New → Ready cukup dengan judul |
| 1.0 | 12 Nov 2026 | Dibekukan sebagai baseline; backlog Rilis 1 disusun dari versi ini |
| 0.5 | 12 Nov 2026 | Bagian 12: pembagian logika database dan aplikasi; skema revisi 2 |
| 0.4 | 12 Nov 2026 | FR-23 progres satuan, FR-24 deadline dari event kalender, prinsip input minimal |
| 0.3 | 12 Nov 2026 | Integrasi Git/GitHub ditiadakan (FR-16) karena TCB untuk pekerjaan non-kode |
| 0.2 | 12 Nov 2026 | Pertanyaan terbuka dijawab: WIP limit 1, kalender khusus TCB, progres KR dari task; rekomendasi stack |
| 0.1 | 12 Nov 2026 | Draf awal: konsep TCB, state machine, model data, mesin rekomendasi, integrasi |

**Aturan perubahan setelah baseline**

- Setiap perubahan kebutuhan dicatat sebagai baris baru (v1.1, v1.2, dan seterusnya) beserta alasannya.
- Perubahan yang menambah cakupan Rilis 1 harus menukar atau menggeser item lain ke rilis berikutnya, supaya Rilis 1 tetap selesai.
- Klarifikasi yang tidak mengubah cakupan, misalnya memperjelas kalimat atau angka contoh, boleh langsung diperbaiki tanpa naik versi.
