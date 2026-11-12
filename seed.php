<?php
$E = parse_ini_file(__DIR__ . '/../.env');
require __DIR__ . '/../db.php';
$db = connect_db($E);
$ins = $db->prepare('INSERT INTO users(nama,email,password_hash,role) VALUES(?,?,?,?) ON CONFLICT (email) DO NOTHING');
$ins->execute(['Admin', 'admin@perpusku.test', password_hash('Admin12345', PASSWORD_ARGON2ID), 'admin']);
$ins->execute(['Pustakawan', 'pustakawan@perpusku.test', password_hash('Pustaka12345', PASSWORD_ARGON2ID), 'pustakawan']);
foreach (['Teknologi', 'Sains', 'Fiksi'] as $k) $db->prepare('INSERT INTO categories(nama) VALUES(?) ON CONFLICT (nama) DO NOTHING')->execute([$k]);
$b = $db->prepare('INSERT INTO books(category_id,judul,penulis,penerbit,tahun_terbit,isbn,stok) VALUES((SELECT id FROM categories WHERE nama=?),?,?,?,?,?,?) ON CONFLICT (isbn) DO NOTHING');
$b->execute(['Teknologi', 'Clean Code', 'Robert C. Martin', 'Prentice Hall', 2008, '9780132350884', 3]);
$b->execute(['Sains', 'A Brief History of Time', 'Stephen Hawking', 'Bantam', 1988, '9780553380163', 2]);
$b->execute(['Fiksi', 'Laskar Pelangi', 'Andrea Hirata', 'Bentang', 2005, '9789793062792', 4]);
echo "Seed selesai.\n";
