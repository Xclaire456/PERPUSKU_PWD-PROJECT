<?php
declare(strict_types=1);
$E = parse_ini_file(__DIR__ . '/../.env');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $E['CORS_ORIGIN']);
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
$M = $_SERVER['REQUEST_METHOD'];
if ($M === 'OPTIONS') { http_response_code(204); exit; }

function out(int $c, array $d): void { http_response_code($c); echo json_encode($d); exit; }
function fail(int $c, string $m, ?array $e = null): void { out($c, ['success' => false, 'message' => $m] + ($e ? ['errors' => $e] : [])); }
function ok(string $m, $data, ?array $meta = null, int $c = 200): void { out($c, ['success' => true, 'message' => $m, 'data' => $data] + ($meta ? ['meta' => $meta] : [])); }
function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function sign(string $s): string { global $E; return b64(hash_hmac('sha256', $s, $E['JWT_SECRET'], true)); }
function jwt_enc(array $p): string { $h = b64('{"alg":"HS256","typ":"JWT"}'); $b = b64(json_encode($p)); return "$h.$b." . sign("$h.$b"); }
function jwt_dec(string $t): ?array {
  $x = explode('.', $t);
  if (count($x) !== 3 || !hash_equals(sign("$x[0].$x[1]"), $x[2])) return null;
  $p = json_decode(base64_decode(strtr($x[1], '-_', '+/')), true);
  return is_array($p) && ($p['exp'] ?? 0) > time() ? $p : null;
}

// Validasi + sanitasi. Spec: tipe(s=string,i=int,e=email,r=role,p=password) + panjang + "!" (wajib)
function validate(array $spec, array $b): array {
  $d = []; $e = [];
  foreach ($spec as $k => $t) {
    preg_match('/^(\w)(\d*)(!?)$/', $t, $m);
    [$type, $max, $req] = [$m[1], (int)$m[2], $m[3] === '!'];
    $v = $b[$k] ?? null;
    if ($v === null || $v === '') { if ($req) $e[$k][] = 'Wajib diisi'; elseif (array_key_exists($k, $b)) $d[$k] = null; continue; }
    if (!is_scalar($v)) { $e[$k][] = 'Format tidak valid'; continue; }
    $v = is_string($v) ? trim(strip_tags($v)) : $v;
    switch ($type) {
      case 's': if (mb_strlen((string)$v) > $max) $e[$k][] = "Maksimal $max karakter"; else $d[$k] = (string)$v; break;
      case 'i': if (!is_numeric($v) || (int)$v < 0) $e[$k][] = 'Harus bilangan bulat >= 0'; else $d[$k] = (int)$v; break;
      case 'e': if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $e[$k][] = 'Email tidak valid'; else $d[$k] = strtolower($v); break;
      case 'r': if (!in_array($v, ['admin', 'pustakawan', 'anggota'], true)) $e[$k][] = 'Role tidak valid'; else $d[$k] = $v; break;
      case 'p': if (strlen((string)$v) < 8) $e[$k][] = 'Minimal 8 karakter'; else $d[$k] = password_hash((string)$v, PASSWORD_ARGON2ID); break;
    }
  }
  return [$d, $e];
}

require __DIR__ . '/../db.php';
$db = connect_db($E);
$STAFF = ['admin', 'pustakawan'];
$R = [
  'categories' => ['tbl' => 'categories', 'from' => 'categories t', 'sel' => 't.*', 'where' => [], 'search' => ['t.nama'], 'w' => $STAFF, 'r' => null,
    'f' => ['nama' => 's100!', 'deskripsi' => 's1000']],
  'books' => ['tbl' => 'books', 'from' => 'books t LEFT JOIN categories c ON c.id=t.category_id', 'sel' => 't.*, c.nama AS kategori', 'where' => [],
    'search' => ['t.judul', 't.penulis', 't.isbn'], 'w' => $STAFF, 'r' => null,
    'f' => ['category_id' => 'i!', 'judul' => 's200!', 'penulis' => 's150!', 'penerbit' => 's150', 'tahun_terbit' => 'i', 'isbn' => 's20!', 'stok' => 'i!']],
  'users' => ['tbl' => 'users', 'from' => 'users t', 'sel' => 't.id,t.nama,t.email,t.role,t.created_at', 'where' => ['t.deleted_at IS NULL'],
    'search' => ['t.nama', 't.email'], 'w' => ['admin'], 'r' => ['admin'], 'soft' => true,
    'f' => ['nama' => 's100!', 'email' => 'e!', 'role' => 'r!', 'password_hash' => 'p']],
  'loans' => ['tbl' => 'loans', 'from' => 'loans t JOIN books b ON b.id=t.book_id JOIN users u ON u.id=t.user_id',
    'sel' => 't.*, b.judul, u.nama AS peminjam', 'where' => [], 'search' => ['b.judul', 'u.nama'], 'w' => [], 'r' => null, 'f' => []],
];

function find(array $r, int $id, array $user): ?array {
  global $db;
  $w = array_merge(['t.id=:id'], $r['where']); $p = [':id' => $id];
  if ($r['tbl'] === 'loans' && $user['role'] === 'anggota') { $w[] = 't.user_id=:uid'; $p[':uid'] = $user['sub']; }
  $s = $db->prepare("SELECT {$r['sel']} FROM {$r['from']} WHERE " . implode(' AND ', $w));
  $s->execute($p);
  return $s->fetch() ?: null;
}

try {
  $path = trim((string)preg_replace('#^/api#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
  $seg = explode('/', $path); $res = $seg[0]; $sub = $seg[1] ?? null;
  $id = $sub !== null && ctype_digit($sub) ? (int)$sub : null;
  $raw = file_get_contents('php://input');
  $body = $raw === '' ? [] : json_decode($raw, true);
  if (!is_array($body)) fail(400, 'Body harus berupa JSON yang valid');

  // ---- Auth publik ----
  if ($res === 'auth' && $sub === 'login' && $M === 'POST') {
    $s = $db->prepare('SELECT * FROM users WHERE email=? AND deleted_at IS NULL');
    $s->execute([strtolower(trim((string)($body['email'] ?? '')))]);
    $u = $s->fetch();
    if (!$u || !password_verify((string)($body['password'] ?? ''), $u['password_hash'])) fail(401, 'Email atau password salah');
    $tok = jwt_enc(['sub' => (int)$u['id'], 'role' => $u['role'], 'iat' => time(), 'exp' => time() + (int)$E['JWT_TTL']]);
    ok('Login berhasil', ['token' => $tok, 'user' => ['id' => $u['id'], 'nama' => $u['nama'], 'email' => $u['email'], 'role' => $u['role']]]);
  }
  if ($res === 'auth' && $sub === 'register' && $M === 'POST') {
    [$d, $e] = validate(['nama' => 's100!', 'email' => 'e!', 'password_hash' => 'p!'], ['password_hash' => $body['password'] ?? null] + $body);
    if ($e) fail(422, 'Validasi gagal', ['password' => $e['password_hash'] ?? null] + $e);
    $s = $db->prepare("INSERT INTO users(nama,email,password_hash,role) VALUES(?,?,?,'anggota') RETURNING id,nama,email,role");
    $s->execute([$d['nama'], $d['email'], $d['password_hash']]);
    ok('Registrasi berhasil', $s->fetch(), null, 201);
  }

  // ---- JWT wajib untuk semua endpoint di bawah ----
  $h = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
  $auth = $h['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  $user = preg_match('/^Bearer (.+)$/', $auth, $m) ? jwt_dec($m[1]) : null;
  if (!$user) fail(401, 'Token tidak ada, tidak valid, atau kedaluwarsa');
  $need = fn(array $roles) => in_array($user['role'], $roles, true) ?: fail(403, 'Anda tidak berhak mengakses sumber daya ini');

  if ($res === 'auth' && $sub === 'me' && $M === 'GET') {
    $s = $db->prepare('SELECT id,nama,email,role FROM users WHERE id=? AND deleted_at IS NULL'); $s->execute([$user['sub']]);
    $u = $s->fetch() ?: fail(401, 'Akun tidak ditemukan'); ok('OK', $u);
  }
  if ($res === 'dashboard' && $sub === 'summary' && $M === 'GET') {
    $need(['admin', 'pustakawan']);
    ok('OK', $db->query("SELECT (SELECT count(*) FROM books) AS total_buku, (SELECT coalesce(sum(stok),0) FROM books) AS total_stok,
      (SELECT count(*) FROM loans WHERE status<>'dikembalikan') AS dipinjam,
      (SELECT count(*) FROM loans WHERE status<>'dikembalikan' AND tgl_jatuh_tempo<CURRENT_DATE) AS terlambat,
      (SELECT count(*) FROM users WHERE role='anggota' AND deleted_at IS NULL) AS total_anggota")->fetch());
  }

  $r = $R[$res] ?? fail(404, 'Endpoint tidak ditemukan');
  if ($r['r']) $need($r['r']);
  if ($sub !== null && $id === null) fail(404, 'Endpoint tidak ditemukan');

  // ---- READ (list + detail) ----
  if ($M === 'GET') {
    if ($id !== null) ok('OK', find($r, $id, $user) ?? fail(404, 'Data tidak ditemukan'));
    if ($res === 'loans') $db->exec("UPDATE loans SET status='terlambat' WHERE status='dipinjam' AND tgl_jatuh_tempo<CURRENT_DATE");
    $w = $r['where']; $p = [];
    $q = trim(strip_tags((string)($_GET['search'] ?? '')));
    if ($q !== '') { $w[] = '(' . implode(' OR ', array_map(fn($c) => "$c ILIKE :q", $r['search'])) . ')'; $p[':q'] = "%$q%"; }
    if ($res === 'books' && ctype_digit((string)($_GET['category_id'] ?? ''))) { $w[] = 't.category_id=:cat'; $p[':cat'] = (int)$_GET['category_id']; }
    if ($res === 'loans') {
      if (in_array($_GET['status'] ?? '', ['dipinjam', 'dikembalikan', 'terlambat'], true)) { $w[] = 't.status=:st'; $p[':st'] = $_GET['status']; }
      if ($user['role'] === 'anggota') { $w[] = 't.user_id=:uid'; $p[':uid'] = $user['sub']; }
    }
    $page = max(1, (int)($_GET['page'] ?? 1)); $limit = min(100, max(1, (int)($_GET['limit'] ?? 10)));
    $wh = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $c = $db->prepare("SELECT count(*) FROM {$r['from']} $wh"); $c->execute($p); $total = (int)$c->fetchColumn();
    $s = $db->prepare("SELECT {$r['sel']} FROM {$r['from']} $wh ORDER BY t.id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $s->execute($p);
    ok('OK', $s->fetchAll(), ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => max(1, (int)ceil($total / $limit))]);
  }

  // ---- LOANS (logika khusus) ----
  if ($res === 'loans') {
    if ($M === 'POST' && $id === null) {
      $bid = $body['book_id'] ?? null;
      if (!is_numeric($bid)) fail(422, 'Validasi gagal', ['book_id' => ['Pilih buku']]);
      $uid = in_array($user['role'], $STAFF, true) && is_numeric($body['user_id'] ?? null) ? (int)$body['user_id'] : $user['sub'];
      $db->beginTransaction();
      $s = $db->prepare('UPDATE books SET stok=stok-1, updated_at=now() WHERE id=? AND stok>0'); $s->execute([(int)$bid]);
      if (!$s->rowCount()) { $db->rollBack(); fail(422, 'Stok buku habis atau buku tidak ditemukan'); }
      $s = $db->prepare("INSERT INTO loans(user_id,book_id,tgl_jatuh_tempo) VALUES(?,?,CURRENT_DATE+7) RETURNING id");
      $s->execute([$uid, (int)$bid]); $nid = (int)$s->fetchColumn(); $db->commit();
      ok('Peminjaman dicatat', find($r, $nid, $user), null, 201);
    }
    if ($M === 'PUT' && $id !== null) { // kembalikan buku
      $need($STAFF); $db->beginTransaction();
      $s = $db->prepare('SELECT * FROM loans WHERE id=? FOR UPDATE'); $s->execute([$id]); $l = $s->fetch();
      if (!$l) { $db->rollBack(); fail(404, 'Data tidak ditemukan'); }
      if ($l['tgl_kembali']) { $db->rollBack(); fail(422, 'Buku sudah dikembalikan'); }
      $denda = max(0, (int)floor((time() - strtotime($l['tgl_jatuh_tempo'])) / 86400)) * 1000;
      $db->prepare("UPDATE loans SET tgl_kembali=CURRENT_DATE,status='dikembalikan',denda=?,updated_at=now() WHERE id=?")->execute([$denda, $id]);
      $db->prepare('UPDATE books SET stok=stok+1, updated_at=now() WHERE id=?')->execute([$l['book_id']]);
      $db->commit(); ok('Buku dikembalikan', find($r, $id, $user));
    }
    if ($M === 'DELETE' && $id !== null) {
      $need(['admin']); $s = $db->prepare('DELETE FROM loans WHERE id=?'); $s->execute([$id]);
      $s->rowCount() ? ok('Data dihapus', null) : fail(404, 'Data tidak ditemukan');
    }
    fail(405, 'Method tidak diizinkan');
  }

  // ---- CREATE / UPDATE / DELETE generik ----
  $need($r['w']);
  if ($M === 'POST' && $id === null || $M === 'PUT' && $id !== null) {
    $spec = $r['f'];
    if ($res === 'users') { $body['password_hash'] = $body['password'] ?? null; if ($M === 'PUT') $spec['password_hash'] = 'p'; }
    [$d, $e] = validate($spec, $body);
    if ($e) fail(422, 'Validasi gagal', isset($e['password_hash']) ? ['password' => $e['password_hash']] + $e : $e);
    if ($M === 'PUT' && !isset($d['password_hash'])) unset($d['password_hash']);
    if ($res === 'books' && $M === 'POST') $d['created_by'] = $user['sub'];
    $cols = array_keys($d);
    if ($M === 'POST') {
      $s = $db->prepare("INSERT INTO {$r['tbl']}(" . implode(',', $cols) . ') VALUES(:' . implode(',:', $cols) . ') RETURNING id');
      $s->execute($d); $nid = (int)$s->fetchColumn();
      ok('Data berhasil ditambahkan', find($r, $nid, $user), null, 201);
    }
    $s = $db->prepare("UPDATE {$r['tbl']} SET " . implode(',', array_map(fn($c) => "$c=:$c", $cols)) . ', updated_at=now() WHERE id=:id' . ($r['soft'] ?? false ? ' AND deleted_at IS NULL' : ''));
    $s->execute($d + ['id' => $id]);
    $s->rowCount() ? ok('Data berhasil diubah', find($r, $id, $user)) : fail(404, 'Data tidak ditemukan');
  }
  if ($M === 'DELETE' && $id !== null) {
    if ($res === 'users' && $id === $user['sub']) fail(422, 'Tidak dapat menghapus akun sendiri');
    $s = $db->prepare($r['soft'] ?? false ? 'UPDATE users SET deleted_at=now() WHERE id=? AND deleted_at IS NULL' : "DELETE FROM {$r['tbl']} WHERE id=?");
    $s->execute([$id]);
    $s->rowCount() ? ok('Data berhasil dihapus', null) : fail(404, 'Data tidak ditemukan');
  }
  fail(405, 'Method tidak diizinkan');
} catch (PDOException $ex) {
  if ($db->inTransaction()) $db->rollBack();
  $c = (string)$ex->getCode();
  if ($c === '23505') fail(409, 'Data duplikat (nilai unik sudah dipakai)');
  if ($c === '23503') fail(409, 'Data masih dipakai oleh data lain atau referensi tidak ada');
  if ($c === '23514') fail(422, 'Nilai melanggar aturan data');
  error_log($ex->getMessage()); fail(500, 'Terjadi kesalahan pada server');
} catch (Throwable $ex) {
  error_log($ex->getMessage()); fail(500, 'Terjadi kesalahan pada server');
}
