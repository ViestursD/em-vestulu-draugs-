<?php
declare(strict_types=1);

/**
 * POST /embd/api_mycode_upload.php
 * multipart/form-data:
 * - thread_id: UUID (obligāts)
 * - file: uploaded file (obligāts)
 * - note: optional text
 *
 * Atgriež JSON ar artifact ierakstu.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

// --------------------
// AUTH (vienkāršs variants)
// --------------------
// Ieteikums: ieliec vienu tokenu .env vai servera configā
// un sūti Authorization: Bearer <TOKEN>
function require_auth(): void {
  $expected = getenv('MYCODE_API_TOKEN') ?: '';
  if ($expected === '') return; // ja nav uzstādīts, nebloku (bet ieteicams uzstādīt!)
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('~^Bearer\s+(.+)$~i', $hdr, $m)) respond(401, ['ok'=>false,'error'=>'Missing token']);
  $token = trim($m[1]);
  if (!hash_equals($expected, $token)) respond(403, ['ok'=>false,'error'=>'Invalid token']);
}

require_auth();

// --------------------
// DB (pielāgo pēc jūsu esošā embd config)
// --------------------
function pdo(): PDO {
  $host = getenv('DB_HOST') ?: '127.0.0.1';
  $db   = getenv('DB_NAME') ?: 'embd';
  $user = getenv('DB_USER') ?: 'root';
  $pass = getenv('DB_PASS') ?: '';
  $charset = 'utf8mb4';
  $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}

function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  $hex = bin2hex($data);
  return sprintf('%s-%s-%s-%s-%s',
    substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
    substr($hex, 16, 4), substr($hex, 20, 12)
  );
}

function safe_basename(string $name): string {
  $name = str_replace(["\0", "/", "\\"], "_", $name);
  $name = preg_replace('~[^A-Za-z0-9._ -]+~', '_', $name) ?? 'file';
  $name = trim($name);
  return $name !== '' ? $name : 'file';
}

$pdo = pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, ['ok'=>false,'error'=>'POST only']);
}

$threadId = trim((string)($_POST['thread_id'] ?? ''));
if ($threadId === '') respond(400, ['ok'=>false,'error'=>'thread_id is required']);
$note = trim((string)($_POST['note'] ?? ''));

if (!isset($_FILES['file'])) respond(400, ['ok'=>false,'error'=>'file is required']);
$f = $_FILES['file'];

// upload errors
if (!empty($f['error'])) {
  respond(400, ['ok'=>false,'error'=>'Upload error','code'=>$f['error']]);
}

$tmp = $f['tmp_name'] ?? '';
if ($tmp === '' || !is_uploaded_file($tmp)) {
  respond(400, ['ok'=>false,'error'=>'Invalid upload']);
}

// limits
$maxBytes = (int)(getenv('MYCODE_UPLOAD_MAX_BYTES') ?: (25 * 1024 * 1024)); // 25MB default
$size = (int)($f['size'] ?? 0);
if ($size <= 0) respond(400, ['ok'=>false,'error'=>'Empty file']);
if ($size > $maxBytes) respond(413, ['ok'=>false,'error'=>'File too large','max_bytes'=>$maxBytes]);

// detect mime (best-effort)
$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
  $fi = finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $det = finfo_file($fi, $tmp);
    if (is_string($det) && $det !== '') $mime = $det;
    finfo_close($fi);
  }
}

// store on disk
$origName = safe_basename((string)($f['name'] ?? 'file'));
$sha256 = hash_file('sha256', $tmp);
$ext = '';
if (preg_match('~(\.[A-Za-z0-9]{1,10})$~', $origName, $m)) $ext = strtolower($m[1]);

// faila fiziskais nosaukums (bez path traversal)
$storedName = date('Ymd_His') . '_' . substr($sha256, 0, 12) . $ext;

// direktorija
$baseDir = __DIR__ . '/uploads/mycode/' . $threadId;
if (!is_dir($baseDir)) {
  if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    respond(500, ['ok'=>false,'error'=>'Failed to create upload dir']);
  }
}

$destPath = $baseDir . '/' . $storedName;

// ja tāds jau ir (retums), pievieno random
if (file_exists($destPath)) {
  $storedName = date('Ymd_His') . '_' . substr($sha256, 0, 12) . '_' . bin2hex(random_bytes(2)) . $ext;
  $destPath = $baseDir . '/' . $storedName;
}

if (!move_uploaded_file($tmp, $destPath)) {
  respond(500, ['ok'=>false,'error'=>'Failed to move uploaded file']);
}

// URL (pielāgo atbilstoši jūsu public routing)
// Ja /embd/ ir publiski pieejams: https://.../embd/uploads/...
$baseUrl = rtrim((string)(getenv('MYCODE_UPLOAD_BASE_URL') ?: '/embd'), '/');
$url = $baseUrl . '/uploads/mycode/' . rawurlencode($threadId) . '/' . rawurlencode($storedName);

// ierakstām DB artifact
$artifactId = uuidv4();
$meta = [
  'original_name' => $origName,
  'stored_name'   => $storedName,
  'mime'          => $mime,
  'bytes'         => $size,
  'sha256'        => $sha256,
  'note'          => $note,
];

$stmt = $pdo->prepare("
  INSERT INTO mycode_artifacts (id, thread_id, type, name, content, url, meta)
  VALUES (?, ?, 'file', ?, NULL, ?, ?)
");
$stmt->execute([
  $artifactId,
  $threadId,
  $origName,
  $url,
  json_encode($meta, JSON_UNESCAPED_UNICODE),
]);

respond(200, [
  'ok' => true,
  'artifact' => [
    'id' => $artifactId,
    'thread_id' => $threadId,
    'type' => 'file',
    'name' => $origName,
    'url' => $url,
    'meta' => $meta,
  ]
]);
