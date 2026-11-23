-- Seed the approved Kinder curriculum extracted from the supplied sample report.
-- Run once after 20261123_milestone_progress_schema.sql on the LOCAL database.
-- All listed milestones are tagged AGE 2, 3, 4, 5, and 6 per the agreed mapping.
INSERT INTO milestone_curricula (name, is_active, created_at, updated_at)
VALUES ('MILESTONE PROGRESS KINDER', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW();
SET @kinder_curriculum_id = (SELECT id FROM milestone_curricula WHERE name = 'MILESTONE PROGRESS KINDER' LIMIT 1);

INSERT INTO milestone_areas (curriculum_id, name, sort_order, is_active, created_at, updated_at) VALUES
(@kinder_curriculum_id, 'Exercise of Practical Life (EPL)', 1, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'Sensorial', 2, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'Cultural', 3, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'Mathematics', 4, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'Language', 5, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'MOTORIK KASAR', 6, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'MOTORIK HALUS', 7, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'BAHASA', 8, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'KEMAMPUAN KEMANDIRIAN', 9, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'KEMAMPUAN SOSIAL-EMOSIONAL', 10, 1, NOW(), NOW()),
(@kinder_curriculum_id, 'KOGNITIF', 11, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = 1, updated_at = NOW();

INSERT INTO curriculum_milestones (area_id, subarea_id, name, sort_order, is_active, created_at, updated_at)
SELECT a.id, NULL, seed.milestone_name, seed.sort_order, 1, NOW(), NOW()
FROM (
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memarut sabun' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Melipat kain' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menjepit jepitan jemuran' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menjepit kertas' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memindahkan kelereng dengan menjepit' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengepang Level 1' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengepang Level 2' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengepang Level 3' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengepang Level 4' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Buka tutup botol' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Buka tutup wadah' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Buka tutup kontainer' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Buka tutup kardus' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Meronce kecil' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Meronce besar' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menuang air dari 1 teko ke teko yang sama besar' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menuang air dari 1 teko ke 2 teko sama besar' AS milestone_name, 17 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menuang air dari 1 teko ke 2 yang berbeda ukuran' AS milestone_name, 18 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memindahkan air dengan pipet' AS milestone_name, 19 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memindahkan air dengan spons' AS milestone_name, 20 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memindahkan kacang dari 1 teko ke teko yang sama besar' AS milestone_name, 21 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memindahkan kacang dari 1 teko ke 2 teko yang sama besar' AS milestone_name, 22 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menyendok jagung dari 1 mangkok ke mangkok yang sama besar' AS milestone_name, 23 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menyendok jagung dari 1 mangkok ke 2 mangkok yang sama besar' AS milestone_name, 24 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memindahkan kertas dengan pinset' AS milestone_name, 25 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Melukis dengan spons' AS milestone_name, 26 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Melukis dengan kuas' AS milestone_name, 27 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menggunting' AS milestone_name, 28 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menempel kertas dengan lem' AS milestone_name, 29 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Baut dan obeng' AS milestone_name, 30 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mencuci kain' AS milestone_name, 31 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mencuci tangan' AS milestone_name, 32 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mencuci sepatu/sanda' AS milestone_name, 33 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menyapu dengan sapu kecil' AS milestone_name, 34 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menggunakan dan menggulung kembali alas kerja' AS milestone_name, 35 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menjahit' AS milestone_name, 36 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Menyiram tanaman' AS milestone_name, 37 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengelap daun' AS milestone_name, 38 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Dressing Frame' AS milestone_name, 39 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengembalikan material ketempat semula' AS milestone_name, 40 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengambil air denga teko secara mandiri' AS milestone_name, 41 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mengelap air yang tumpah dengan kain / tissu' AS milestone_name, 42 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Memanen sayur / buah' AS milestone_name, 43 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Rutinitas Makan Mandiri' AS milestone_name, 44 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Mencuci perlatan makan' AS milestone_name, 45 AS sort_order
    UNION ALL
    SELECT 'Exercise of Practical Life (EPL)' AS area_name, 'Membuang sampah pada tempatnya' AS milestone_name, 46 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Pink tower' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Brown stair' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Knobbed cylinder Level 1' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Knobbed cylinder Level 2' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Knobbed cylinder Level 3' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Knobbed cylinder Level 4' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Color box 1' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Color box 2' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Color box 3' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Presentation tray' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Knobless cylinder' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Alat musik' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Sound box' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Touch board' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Touch tablet' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Stereonostik bag' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Pressure cylinder' AS milestone_name, 17 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Geosolid' AS milestone_name, 18 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Long rods' AS milestone_name, 19 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Geometri set' AS milestone_name, 20 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Mengenal rasa' AS milestone_name, 21 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Direction and colour puzzle' AS milestone_name, 22 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Baut dan obeng' AS milestone_name, 23 AS sort_order
    UNION ALL
    SELECT 'Sensorial' AS area_name, 'Playdough' AS milestone_name, 24 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Kalender' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Kartu anatomi hewan' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Puzzle botani' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Puzzle animal' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Figurin hewan' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Bola dunia kasar-halus' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Bola dunia' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Kartu gambar besar' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Card animal kingdom' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Canvas animal kingdom' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Alat musik' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Mengenal jam' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Kartu Ekspresi' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Peta dunia dan bendera' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Eksperimen campur warna' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Eksperimen pelangi' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Menanam kangkung' AS milestone_name, 17 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Mengamati bunga matahari' AS milestone_name, 18 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Outdoor exploration' AS milestone_name, 19 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Menanam kacang hijau' AS milestone_name, 20 AS sort_order
    UNION ALL
    SELECT 'Cultural' AS area_name, 'Observasi jam pasir' AS milestone_name, 21 AS sort_order
    UNION ALL
    SELECT 'Mathematics' AS area_name, 'Small number rods' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'Mathematics' AS area_name, 'Spindel box' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'Mathematics' AS area_name, 'Card and counter' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'Mathematics' AS area_name, 'Card and banana objek' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'Mathematics' AS area_name, 'Bead stair' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'Mathematics' AS area_name, 'Number rods' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'Mathematics' AS area_name, 'Konsep angka belasan' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Sandpaper letters' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Sandpaper number' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Puzzle alfabet' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Matching objek alphabet dengan canvas alphabet' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Kartu kosa kata' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Canvas kosa kata' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Canvas lowercase letter' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Read Aloud' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Metal inset' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Bercerita' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Bernyanyi' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Boneka tangan' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Percaya diri berbicra di depan teman teman' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Diskusi isi cerita' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'Language' AS area_name, 'Sand tray' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Berlari' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Melompat dengan kedua kaki' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Naik tangga kaki bergantian' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Meloncat ke bawah dari kursi anak' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Melempar bola dengan tangan tanpa jatuh' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Turun tangga kaki bergantian' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Berjalan digaris lurus' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Melompat kangguru' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Berdiri satu kaki lebih kurang dari 3 detik' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Menangkap bola besar dengan kedua tangan' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Mengayuh sepeda roda tiga' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Brakiasi' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Pikler' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Papan keseimbangan' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Walking on the line' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'MOTORIK KASAR' AS area_name, 'Jalan santai' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menyusun 4-8 balok keatas' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Membalikan halaman buku' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menggunakan sendok' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menggunakan gelas atau cangkir dengan satu tangan' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Membuka kancing besar' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Membuka retsleting besar' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Membuka pintu dengan memutar knop pintu' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Memegang dan menggunakan krayon dengan antusias dan mencoret-coret' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Bisa meronce' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Memegang gunting dan bisa menggunting' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Meniru lingkaran' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menggunting kertas menjadi dua' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Memegang pensil lebih stabil.' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Meniru bentuk tanda tambah (+)' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menggambar orang sederhana' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Mulai bisa memasang kancing' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menumpuk 9 balok' AS milestone_name, 17 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Tube test clamp ball' AS milestone_name, 18 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Geoboard' AS milestone_name, 19 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Melipat origami' AS milestone_name, 20 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menempel stiker' AS milestone_name, 21 AS sort_order
    UNION ALL
    SELECT 'MOTORIK HALUS' AS area_name, 'Menulis huruf dan angka' AS milestone_name, 22 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Merangkai 2 kata' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Mengerti intruksi tempat dan mengikuti satu intruksi' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Menunjukkan sakit dimana' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Menamai objek' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Menikmati membaca Bersama apabila diajak untuk menunjuk, meniru suara, dan membalikkan buku' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Kosa kata 50-300 buah' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, '50% kalimatnya di mengerti' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Atensi 4-8 menit' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Membuat kalimat terdiri dari 4-5 kata' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Menyebut nama benda dan fungsinya' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Memahami lawan kata' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Menjelaskan persamaan antara dua objek' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Memahami kata kerja' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Menggunakan kata sifat pada objek' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Pembicaraannya sudah dapat di pahami 100%' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Atensi 6-12 menit' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Mengamati ilustrasi buku' AS milestone_name, 17 AS sort_order
    UNION ALL
    SELECT 'BAHASA' AS area_name, 'Flashcard' AS milestone_name, 18 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Melepas baju kaos (piyama) mandiri' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Mulai toilet training, bagi yang sudah siap' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Mampu menyuap sendiri, namun masih sering lelah' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Mampu minum sendiri, namun kadang masih sering tumpah' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Menikmati mandi, mencoba mandi sendiri, senang terutama bila dibiarkan sambil bermain, seperti ikut memandikan mainan' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Mulai membantu saat memamakai baju' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Makan sendiri hanya perlu sedikit pendamping' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Mampu cuci tangan dan mengeringkan dengan handuk' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Mampu menggosok gigi (belum sempurna)' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Sudah mampu mengontrol kapan ada keinginan BAK' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Mulai bisa mandi sendiri, dengan bantuan' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Buang air besar dan kecil di toilet' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Dapat mengoles selai dan margarin' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Memilih aktivitas sendiri' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Cooking class' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN KEMANDIRIAN' AS area_name, 'Merawat lingkungan sekitar' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Menjawab namanya ketika ditanya' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Mengetahui perbedaan laki-laki dan perempuan' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Mulai mengenal ekspresi senang, sedih, marah dan terkejut' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Menunjukkan peduli atau empati' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Bepartisipasi bermain berkelompok' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Mulai memahami tindakan benar atau salah' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Bermain berimaginasi dan menghayal, seperti bermain boneka' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Mengerti adanya peraturan yang tidak bisa diubah, seperti cara berjalan dipinggir jalan' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Bermain ular tangga' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Mulai memahami kepemilikan' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Mempunyai satu-dua teman untuk bermain' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Bisa berpisah dengan orang tua' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Memahami apa yang dibutuhkan dan dirasakan oleh orang lain' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Meminta bantuan dengan verbal' AS milestone_name, 14 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Membantu teman' AS milestone_name, 15 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Mengucapkan maaf,terima kasih dan tolong' AS milestone_name, 16 AS sort_order
    UNION ALL
    SELECT 'KEMAMPUAN SOSIAL-EMOSIONAL' AS area_name, 'Berkenalan dengan teman' AS milestone_name, 17 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Membedakan ukuran' AS milestone_name, 1 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Menyamakan benda atau bentuk' AS milestone_name, 2 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Bisa bermain puzzle bentuk 6-11 keeping' AS milestone_name, 3 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Mencari pasangan dari benda' AS milestone_name, 4 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Mengulang angka dua digit' AS milestone_name, 5 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Dapat membandingkan ukuran' AS milestone_name, 6 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Mencari benda (gambar yang tersembunyi diantara banyak gambar' AS milestone_name, 7 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Menyusun puzzle yang berbentuk' AS milestone_name, 8 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Memilih baju yang sesuai dengan keadaan' AS milestone_name, 9 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Mengerti pola gambar yang berurutan' AS milestone_name, 10 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Mengulang 4 digit angka' AS milestone_name, 11 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Memory game' AS milestone_name, 12 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Sortir warna' AS milestone_name, 13 AS sort_order
    UNION ALL
    SELECT 'KOGNITIF' AS area_name, 'Eksplorasi bentuk dan pola' AS milestone_name, 14 AS sort_order
) AS seed
JOIN milestone_areas AS a ON a.curriculum_id = @kinder_curriculum_id AND a.name = seed.area_name
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = 1, updated_at = NOW();

INSERT IGNORE INTO curriculum_milestone_ages (milestone_id, age)
SELECT cm.id, ages.age
FROM curriculum_milestones AS cm
JOIN milestone_areas AS a ON a.id = cm.area_id AND a.curriculum_id = @kinder_curriculum_id
CROSS JOIN (SELECT 2 AS age UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) AS ages;

-- Expected: 1 curriculum, 11 areas, 216 milestones, 1,080 age mappings.
