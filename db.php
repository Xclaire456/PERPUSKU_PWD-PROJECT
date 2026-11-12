<?php
// Koneksi PostgreSQL. Pakai DB_URL (satu baris dari Supabase) atau DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS.
function connect_db(array $E): PDO {
  if (!empty($E['DB_URL'])) {
    $u = parse_url($E['DB_URL']);
    $E = ['DB_HOST' => $u['host'] ?? '', 'DB_PORT' => $u['port'] ?? 5432, 'DB_NAME' => ltrim($u['path'] ?? '/postgres', '/'),
          'DB_USER' => urldecode($u['user'] ?? ''), 'DB_PASS' => urldecode($u['pass'] ?? '')];
  }
  return new PDO("pgsql:host={$E['DB_HOST']};port={$E['DB_PORT']};dbname={$E['DB_NAME']};sslmode=require", $E['DB_USER'], $E['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
