<?php
/**
 * /embpd/rakstisanai.php
 * "Kodu rakstīšanas" čats, kas glabā visu DB (mycode_* tabulās).
 * + Failu uploads uz API (rakstisanai.php?api=upload&t=THREAD_ID)
 * + Pielikumi automātiski tiek iekļauti promptā:
 *   - ZIP: failu saraksts + (atlasītu tekstfailu saturs no ZIP)
 *   - Tekstfaili: faila saturs (ar izmēra limitu)
 *
 * Balstīts uz jūsu esošo embpd arhitektūru (db(), openai.php, text.php).
 */

declare(strict_types=1);

/* =====================================================
   Output buffering + API-safe error handling
   ===================================================== */
ob_start();

$isApi = isset($_GET['api']) && $_GET['api'] !== '';

if ($isApi) {
  ini_set('display_errors', '0');
  ini_set('display_startup_errors', '0');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

function json_out(int $code, array $payload): void {
  if (ob_get_length()) { ob_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

register_shutdown_function(function () {
  $err = error_get_last();
  if (!$err) return;

  if (isset($_GET['api']) && $_GET['api'] !== '') {
    if (ob_get_length()) { ob_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => false,
      'error' => 'Fatal error',
      'detail' => $err['message'] ?? 'unknown',
      'file' => $err['file'] ?? null,
      'line' => $err['line'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
});

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';
require_once __DIR__ . '/utils/openai.php';

$pdo = db();

/* ================================
   MYCODE DB (init)
   ================================ */
const MYCODE_PREFIX = 'mycode_';
function t_my_threads(): string { return MYCODE_PREFIX . 'threads'; }
function t_my_msgs(): string { return MYCODE_PREFIX . 'messages'; }
function t_my_artifacts(): string { return MYCODE_PREFIX . 'artifacts'; }

function init_mycode_db(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `" . t_my_threads() . "` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `session_id` VARCHAR(64) NOT NULL,
      `title` VARCHAR(255) NOT NULL DEFAULT 'Kodu sarakste',
      `pinned_context` MEDIUMTEXT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_session_updated` (`session_id`, `updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `" . t_my_msgs() . "` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `thread_id` BIGINT UNSIGNED NOT NULL,
      `role` VARCHAR(20) NOT NULL,
      `content` MEDIUMTEXT NOT NULL,
      `meta` JSON NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_thread_created` (`thread_id`, `created_at`),
      CONSTRAINT `fk_mycode_thread`
        FOREIGN KEY (`thread_id`) REFERENCES `" . t_my_threads() . "`(`id`)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `" . t_my_artifacts() . "` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `thread_id` BIGINT UNSIGNED NOT NULL,
      `type` VARCHAR(20) NOT NULL DEFAULT 'note',
      `name` VARCHAR(255) NOT NULL,
      `content` MEDIUMTEXT NULL,
      `url` TEXT NULL,
      `meta` JSON NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_thread_type_created` (`thread_id`, `type`, `created_at`),
      CONSTRAINT `fk_mycode_art_thread`
        FOREIGN KEY (`thread_id`) REFERENCES `" . t_my_threads() . "`(`id`)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");

  // saderībai ar vecāku tabulu, ja tā bija bez url/meta
  try { $pdo->exec("ALTER TABLE `" . t_my_artifacts() . "` ADD COLUMN `url` TEXT NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE `" . t_my_artifacts() . "` ADD COLUMN `meta` JSON NULL"); } catch (Throwable $e) {}
}
init_mycode_db($pdo);

/* ================================
   Session
   ================================ */
$session = get_cookie_session();
if (!$session) {
  $session = bin2hex(random_bytes(16));
  set_session_cookie($session);
}

/* ================================
   Helpers
   ================================ */
function default_pinned_context(): string {
  return trim(<<<TXT
# Pinned konteksts (izlasi pirms atbildes)
- Projekts: add4live.com/embpd
- Mērķis: ātra koda rakstīšana ar skaidriem failiem/diff.

## Noteikumi atbildēm
- Ja maini failu: dod "Faila ceļš" + pilnu koda bloku VAI diff.
- Ja vajag vairāk konteksta: uzraksti tieši, kuru failu/fragmentu ielikt šeit.
- Neraksti liekus paskaidrojumus, ja var dot gatavu kodu.
TXT);
}

function build_system_prompt_code(string $pinned): string {
  $pinned = trim($pinned);
  $pinnedBlock = $pinned !== '' ? "\n\n--- PINNED KONTEXTS ---\n{$pinned}\n--- /PINNED KONTEXTS ---\n" : '';

  return trim(
"Tu esi senior full-stack izstrādātājs un koda reviewers mūsu projektā add4live.com/embpd.

NOTEIKUMI:
- Atbildi praktiski: dod gatavu kodu, failu ceļus un skaidru izmaiņu aprakstu.
- Ja maināms esošs fails: dod DIFF (unified) VAI pilnu faila saturu.
- Ja vajag kontekstu (failu fragmentu): pasaki tieši kuru ceļu un kādu daļu ielikt.
- Neradi jaunus izdomātus failus, ja tas nav vajadzīgs.
- Neatkārto visu saraksti; koncentrējies uz nākamo soli.

{$pinnedBlock}")
;
}

function safe_basename(string $name): string {
  $name = str_replace(["\0", "/", "\\"], "_", $name);
  $name = preg_replace('~[^A-Za-z0-9._ -]+~', '_', $name) ?? 'file';
  $name = trim($name);
  return $name !== '' ? $name : 'file';
}

/**
 * DB artefakta diska ceļš (droši: tikai /uploads/mycode/{threadId}/)
 */
function artifact_disk_path(int $threadId, array $artifactRow): ?string {
  $url = (string)($artifactRow['url'] ?? '');

  $meta = $artifactRow['meta'] ?? null;
  if (is_string($meta) && $meta !== '') {
    $meta = json_decode($meta, true);
  }

  $stored = '';
  if (is_array($meta) && !empty($meta['stored_name'])) {
    $stored = (string)$meta['stored_name'];
  } else {
    if ($url !== '') {
      $parts = explode('/', $url);
      $stored = (string)(end($parts) ?: '');
    }
  }

  $stored = trim(str_replace(["\0", "/", "\\"], "", $stored));
  if ($stored === '') return null;

  $baseDir = __DIR__ . '/uploads/mycode/' . $threadId;
  $path = $baseDir . '/' . $stored;
  if (!is_file($path)) return null;
  return $path;
}

function ext_lower(string $filename): string {
  if (preg_match('~(\.[A-Za-z0-9]{1,10})$~', $filename, $m)) return strtolower($m[1]);
  return '';
}

function read_text_file_limited(string $path, int $maxBytes = 180000): string {
  $size = filesize($path);
  if ($size === false) return "(Nevar noteikt faila izmēru)";
  if ($size > $maxBytes) {
    $fh = fopen($path, 'rb');
    if (!$fh) return "(Nevar atvērt failu)";
    $data = fread($fh, $maxBytes);
    fclose($fh);
    return (string)$data . "\n\n...(apgriezts, jo fails ir " . (int)$size . " baiti)";
  }
  $txt = file_get_contents($path);
  return is_string($txt) ? $txt : "(Nevar nolasīt failu)";
}

/**
 * ZIP: failu saraksts + atlasītu tekstfailu saturs (no ZIP) ar limitu.
 * Nekādu extract uz disku - lasām ar ZipArchive::getFromIndex()
 */
function zip_summarize_with_contents(
  string $zipPath,
  int $maxList = 800,
  int $maxTextFiles = 20,
  int $maxBytesPerText = 90000
): array {
  if (!class_exists('ZipArchive')) {
    return [
      'list' => ['(ZIP nav atbalstīts serverī: ZipArchive nav pieejams)'],
      'texts' => []
    ];
  }

  $zip = new ZipArchive();
  if ($zip->open($zipPath) !== true) {
    return [
      'list' => ['(Neizdevās atvērt ZIP)'],
      'texts' => []
    ];
  }

  $textExt = ['.php','.js','.ts','.css','.html','.htm','.json','.md','.txt','.sql','.csv','.yml','.yaml','.xml'];
  $list = [];
  $texts = [];

  $num = (int)$zip->numFiles;
  $nList = min($num, $maxList);

  for ($i=0; $i<$nList; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat) continue;
    $fn = (string)($stat['name'] ?? '');
    if ($fn === '') continue;
    $list[] = $fn;
  }

  if ($num > $maxList) {
    $list[] = "... (apgriezts līdz {$maxList} ierakstiem)";
  }
  if (!$list) {
    $list[] = '(ZIP ir tukšs vai nevar nolasīt)';
  }

  // Tekstfailu saturs (tikai daži, lai prompt nekļūst gigantisks)
  $picked = 0;
  $nScan = min($num, $maxList); // skenējam tik, cik listojam
  for ($i=0; $i<$nScan; $i++) {
    if ($picked >= $maxTextFiles) break;
    $stat = $zip->statIndex($i);
    if (!$stat) continue;

    $fn = (string)($stat['name'] ?? '');
    if ($fn === '' || str_ends_with($fn, '/')) continue;

    $ext = ext_lower($fn);
    if (!in_array($ext, $textExt, true)) continue;

    // size limit
    $sz = (int)($stat['size'] ?? 0);
    if ($sz <= 0) continue;

    $data = $zip->getFromIndex($i);
    if (!is_string($data) || $data === '') continue;

    if (strlen($data) > $maxBytesPerText) {
      $data = substr($data, 0, $maxBytesPerText) . "\n\n...(apgriezts, jo fails ZIPā ir {$sz} baiti)";
    }

    $texts[] = [
      'name' => $fn,
      'content' => $data,
      'size' => $sz,
    ];
    $picked++;
  }

  $zip->close();

  return ['list' => $list, 'texts' => $texts];
}

/**
 * Sagatavo pielikumu kontekstu promptam:
 * - ZIP: saraksts + atlasītu failu saturs no ZIP
 * - Tekstfaili: saturs
 */
function build_attachments_context(PDO $pdo, int $threadId, int $limit = 5): string {
  $st = $pdo->prepare("SELECT id, type, name, url, meta, created_at FROM `" . t_my_artifacts() . "` WHERE thread_id=? ORDER BY created_at DESC, id DESC LIMIT {$limit}");
  $st->execute([$threadId]);
  $arts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$arts) return '';

  $textExt = ['.php','.js','.ts','.css','.html','.htm','.json','.md','.txt','.sql','.csv','.yml','.yaml','.xml'];

  $lines = [];
  $lines[] = "ATTACHMENTS CONTEXT (pēdējie {$limit}):";
  $lines[] = "Svarīgi: šis konteksts nāk no jūsu augšupielādētajiem failiem.";

  foreach ($arts as $a) {
    $name = (string)($a['name'] ?? '');
    $type = (string)($a['type'] ?? '');
    $created = (string)($a['created_at'] ?? '');
    $lines[] = "";
    $lines[] = "- [{$type}] {$name} ({$created})";

    $path = artifact_disk_path($threadId, $a);
    if (!$path) {
      $lines[] = "  (failu diskā neatradu; url=" . (string)($a['url'] ?? '') . ")";
      continue;
    }

    $ext = ext_lower($name);

    if ($ext === '.zip') {
      $sum = zip_summarize_with_contents($path, 800, 25, 90000);

      $lines[] = "  ZIP saturs (failu saraksts):";
      foreach ($sum['list'] as $fn) {
        $lines[] = "    - " . $fn;
      }

      if (!empty($sum['texts'])) {
        $lines[] = "  ZIP tekstfailu saturs (izlase):";
        foreach ($sum['texts'] as $t) {
          $lines[] = "  ---BEGIN ZIP FILE: " . $t['name'] . " ---";
          $lines[] = (string)$t['content'];
          $lines[] = "  ---END ZIP FILE: " . $t['name'] . " ---";
        }
      } else {
        $lines[] = "  (ZIPā neatradu tekstfailus vai tie netika nolasīti)";
      }

      continue;
    }

    if (in_array($ext, $textExt, true)) {
      $content = read_text_file_limited($path, 180000);
      $lines[] = "  FAILA SATURS (saīsināts, ja vajag):";
      $lines[] = "  ---BEGIN FILE: {$name}---";
      $lines[] = $content;
      $lines[] = "  ---END FILE: {$name}---";
      continue;
    }

    $lines[] = "  (binārs/nesupportēts tips - saturs netiek ielikts promptā; ext={$ext})";
  }

  return implode("\n", $lines);
}

/* ================================
   Thread selection / create
   ================================ */
$threadId = (int)($_GET['t'] ?? $_POST['thread_id'] ?? 0);

if (isset($_POST['action']) && $_POST['action'] === 'new_thread') {
  $title = trim((string)($_POST['title'] ?? 'Kodu sarakste'));
  if ($title === '') $title = 'Kodu sarakste';
  $pdo->prepare("INSERT INTO `" . t_my_threads() . "` (session_id, title, pinned_context) VALUES (?,?,?)")
      ->execute([$session, $title, default_pinned_context()]);
  $newId = (int)$pdo->lastInsertId();
  header('Location: ' . APP_BASE . '/rakstisanai.php?t=' . $newId);
  exit;
}

if ($threadId <= 0) {
  $st = $pdo->prepare("SELECT id FROM `" . t_my_threads() . "` WHERE session_id=? ORDER BY updated_at DESC, id DESC LIMIT 1");
  $st->execute([$session]);
  $threadId = (int)($st->fetchColumn() ?: 0);

  if ($threadId <= 0) {
    $pdo->prepare("INSERT INTO `" . t_my_threads() . "` (session_id, title, pinned_context) VALUES (?,?,?)")
        ->execute([$session, 'Kodu sarakste', default_pinned_context()]);
    $threadId = (int)$pdo->lastInsertId();
  }
  header('Location: ' . APP_BASE . '/rakstisanai.php?t=' . $threadId);
  exit;
}

// security: ensure thread belongs to this session
$st = $pdo->prepare("SELECT * FROM `" . t_my_threads() . "` WHERE id=? AND session_id=? LIMIT 1");
$st->execute([$threadId, $session]);
$thread = $st->fetch(PDO::FETCH_ASSOC);
if (!$thread) {
  header('Location: ' . APP_BASE . '/rakstisanai.php');
  exit;
}

/* ================================
   API: upload (JSON)
   URL: /embpd/rakstisanai.php?api=upload&t=THREAD_ID
   ================================ */
if ($isApi && $_GET['api'] === 'upload') {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'POST only']);
  }

  // re-check ownership
  $st = $pdo->prepare("SELECT id FROM `" . t_my_threads() . "` WHERE id=? AND session_id=? LIMIT 1");
  $st->execute([$threadId, $session]);
  if (!$st->fetchColumn()) {
    json_out(403, ['ok' => false, 'error' => 'Thread not found / not allowed']);
  }

  if (!isset($_FILES['file'])) json_out(400, ['ok' => false, 'error' => 'file is required']);

  $f = $_FILES['file'];
  if (!empty($f['error'])) json_out(400, ['ok' => false, 'error' => 'Upload error', 'code' => $f['error']]);

  $tmp = (string)($f['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) json_out(400, ['ok' => false, 'error' => 'Invalid upload']);

  $maxBytes = 25 * 1024 * 1024; // 25MB
  $size = (int)($f['size'] ?? 0);
  if ($size <= 0) json_out(400, ['ok' => false, 'error' => 'Empty file']);
  if ($size > $maxBytes) json_out(413, ['ok' => false, 'error' => 'File too large', 'max_bytes' => $maxBytes]);

  // mime detect (best-effort)
  $mime = 'application/octet-stream';
  if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
      $det = finfo_file($fi, $tmp);
      if (is_string($det) && $det !== '') $mime = $det;
      finfo_close($fi);
    }
  }

  $origName = safe_basename((string)($f['name'] ?? 'file'));
  $sha256 = hash_file('sha256', $tmp);

  // extension
  $ext = ext_lower($origName);

  // Drošības bloks (serverī negribam izpildāmus failus uploads mapē)
  // PIEZĪME: ja tu gribi ļaut augšupielādēt .php/.js koda analīzei, tas nav jāizpilda, bet risks ir, ja uploads ir publiski izpildāms.
  // Tāpēc atstājam bloķētus izpildāmos servera pusē; ZIP/teksti analīzei joprojām darbojas.
  $blockedExt = ['.phtml', '.phar', '.cgi', '.pl', '.sh', '.exe'];
  if (in_array($ext, $blockedExt, true)) {
    json_out(400, ['ok' => false, 'error' => 'File type not allowed', 'ext' => $ext]);
  }

  // disk storage
  $baseDir = __DIR__ . '/uploads/mycode/' . $threadId;
  if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
      json_out(500, ['ok' => false, 'error' => 'Failed to create upload dir']);
    }
  }

  $storedName = date('Ymd_His') . '_' . substr($sha256, 0, 12) . ($ext !== '' ? $ext : '');
  $destPath = $baseDir . '/' . $storedName;

  if (file_exists($destPath)) {
    $storedName = date('Ymd_His') . '_' . substr($sha256, 0, 12) . '_' . bin2hex(random_bytes(2)) . ($ext !== '' ? $ext : '');
    $destPath = $baseDir . '/' . $storedName;
  }

  if (!move_uploaded_file($tmp, $destPath)) {
    json_out(500, ['ok' => false, 'error' => 'Failed to move uploaded file']);
  }

  // public URL - assumes /embpd/uploads/ is publicly accessible
  $url = APP_BASE . '/uploads/mycode/' . rawurlencode((string)$threadId) . '/' . rawurlencode($storedName);

  $note = trim((string)($_POST['note'] ?? ''));

  $meta = [
    'original_name' => $origName,
    'stored_name'   => $storedName,
    'mime'          => $mime,
    'bytes'         => $size,
    'sha256'        => $sha256,
    'note'          => $note,
  ];

  $pdo->prepare("INSERT INTO `" . t_my_artifacts() . "` (thread_id, type, name, content, url, meta) VALUES (?,?,?,?,?,?)")
      ->execute([$threadId, 'file', $origName, null, $url, json_encode($meta, JSON_UNESCAPED_UNICODE)]);

  $pdo->prepare("UPDATE `" . t_my_threads() . "` SET updated_at=NOW() WHERE id=? AND session_id=?")
      ->execute([$threadId, $session]);

  json_out(200, [
    'ok' => true,
    'artifact' => [
      'thread_id' => $threadId,
      'type' => 'file',
      'name' => $origName,
      'url' => $url,
      'meta' => $meta,
    ]
  ]);
}

/* ================================
   Actions: save pinned / send message
   ================================ */
if (isset($_POST['action']) && $_POST['action'] === 'save_pinned') {
  $pinned = (string)($_POST['pinned_context'] ?? '');
  $pdo->prepare("UPDATE `" . t_my_threads() . "` SET pinned_context=? WHERE id=? AND session_id=?")
      ->execute([$pinned, $threadId, $session]);
  header('Location: ' . APP_BASE . '/rakstisanai.php?t=' . $threadId);
  exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'send') {
  $prompt = trim((string)($_POST['prompt'] ?? ''));
  if ($prompt !== '') {
    // save user
    $pdo->prepare("INSERT INTO `" . t_my_msgs() . "` (thread_id, role, content) VALUES (?,?,?)")
        ->execute([$threadId, 'user', $prompt]);

    $pdo->prepare("UPDATE `" . t_my_threads() . "` SET updated_at=NOW() WHERE id=? AND session_id=?")
        ->execute([$threadId, $session]);

    // last N messages
    $N = 24;
    $stH = $pdo->prepare("SELECT role, content FROM `" . t_my_msgs() . "` WHERE thread_id=? ORDER BY created_at DESC, id DESC LIMIT {$N}");
    $stH->execute([$threadId]);
    $rows = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = array_reverse($rows);

    $pinned = (string)($thread['pinned_context'] ?? '');
    $system = build_system_prompt_code($pinned);

    $messages = [["role" => "system", "content" => $system]];

    // ✅ PIELIKUMI VIENMĒR PROMPTĀ: ZIP saraksts + ZIP tekstfailu saturs + tekstfailu saturs
    $attachCtx = build_attachments_context($pdo, $threadId, 5);
    if ($attachCtx !== '') {
      $messages[] = ["role" => "system", "content" => $attachCtx];
    }

    foreach ($rows as $r) {
      $role = (string)$r['role'];
      if (!in_array($role, ['user','assistant','system'], true)) $role = 'user';
      $messages[] = ["role" => $role, "content" => (string)$r['content']];
    }

    try {
      $assistant = call_openai($messages, 'gpt-4o', 0.3, 1400);
    } catch (Throwable $e) {
      $assistant = "⚠️ Kļūda: " . $e->getMessage();
    }

    // save assistant
    $pdo->prepare("INSERT INTO `" . t_my_msgs() . "` (thread_id, role, content) VALUES (?,?,?)")
        ->execute([$threadId, 'assistant', $assistant]);

    $pdo->prepare("UPDATE `" . t_my_threads() . "` SET updated_at=NOW() WHERE id=? AND session_id=?")
        ->execute([$threadId, $session]);

    // auto title
    if ((string)$thread['title'] === 'Kodu sarakste') {
      $auto = mb_substr($prompt, 0, 60, 'UTF-8');
      $auto = trim(preg_replace('/\s+/u', ' ', $auto));
      if ($auto !== '') {
        $pdo->prepare("UPDATE `" . t_my_threads() . "` SET title=? WHERE id=? AND session_id=?")
            ->execute([$auto, $threadId, $session]);
      }
    }

    header('Location: ' . APP_BASE . '/rakstisanai.php?t=' . $threadId);
    exit;
  }
}

/* ================================
   Load threads list + messages + artifacts
   ================================ */
$st = $pdo->prepare("SELECT id, title, updated_at FROM `" . t_my_threads() . "` WHERE session_id=? ORDER BY updated_at DESC, id DESC");
$st->execute([$session]);
$threads = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$st = $pdo->prepare("SELECT role, content, created_at FROM `" . t_my_msgs() . "` WHERE thread_id=? ORDER BY created_at, id");
$st->execute([$threadId]);
$messages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$st = $pdo->prepare("SELECT id, type, name, url, created_at FROM `" . t_my_artifacts() . "` WHERE thread_id=? ORDER BY created_at DESC, id DESC LIMIT 50");
$st->execute([$threadId]);
$artifacts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// reload thread
$st = $pdo->prepare("SELECT * FROM `" . t_my_threads() . "` WHERE id=? AND session_id=? LIMIT 1");
$st->execute([$threadId, $session]);
$thread = $st->fetch(PDO::FETCH_ASSOC) ?: $thread;

?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8">
  <title>🧩 Kodu rakstīšana (mycode)</title>
  <link rel="stylesheet" href="<?= APP_BASE ?>/public/style.css">
  <style>
    .layout{display:grid;grid-template-columns:300px 1fr;gap:16px;}
    .card{border:1px solid #ddd;border-radius:10px;padding:12px;background:#fff;}
    .threads a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:#111;margin:6px 0;}
    .threads a.active{background:#eef3ff;font-weight:700;}
    .small{font-size:12px;color:#666;}
    textarea{width:100%;}
    .msg.assistant{background:#f6f6f6;}
    .msg.user{background:#eefbf0;}
    .artifact a{text-decoration:none;}
    .msg{border:1px solid #eee;border-radius:10px;padding:10px;margin:10px 0;}
    code{background:#f4f4f4;padding:2px 6px;border-radius:6px;}
  </style>
</head>
<body>
  <div class="row">
    <h1>🧩 Kodu rakstīšana (DB: mycode_*)</h1>
    <div class="actions">
      <form method="POST" action="<?= APP_BASE ?>/rakstisanai.php?t=<?= (int)$threadId ?>" style="display:inline-block">
        <input type="hidden" name="action" value="new_thread">
        <input type="text" name="title" placeholder="Jaunas tēmas nosaukums" style="width:240px;">
        <button type="submit">➕ Jauna tēma</button>
      </form>
      <a href="<?= APP_BASE ?>/" style="margin-left:10px;">← uz sākumu</a>
    </div>
  </div>

  <div class="layout">
    <div class="card">
      <b>🗂 Tēmas</b>
      <div class="threads">
        <?php foreach ($threads as $t): ?>
          <a class="<?= ((int)$t['id'] === (int)$threadId) ? 'active' : '' ?>" href="<?= APP_BASE ?>/rakstisanai.php?t=<?= (int)$t['id'] ?>">
            <?= escape_html((string)$t['title']) ?><br>
            <span class="small">#<?= (int)$t['id'] ?> · <?= escape_html((string)$t['updated_at']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <hr>
      <b>📎 Pielikumi</b>
      <div class="small" style="margin:6px 0 10px 0;">
        Upload iet caur API: <code>rakstisanai.php?api=upload</code><br>
        Pielikumi automātiski tiek iekļauti promptā (ZIP saraksts + ZIP tekstfailu saturs).
      </div>

      <form id="uploadForm" enctype="multipart/form-data">
        <input type="file" name="file" required>
        <input type="text" name="note" placeholder="Piezīme (neobligāti)" style="width:100%;margin-top:6px;">
        <button type="submit" style="margin-top:8px;">⬆️ Upload</button>
      </form>

      <div id="uploadStatus" class="small" style="margin-top:8px;"></div>

      <div style="margin-top:10px;">
        <?php if (!$artifacts): ?>
          <div class="small">Nav pielikumu.</div>
        <?php else: ?>
          <?php foreach ($artifacts as $a): ?>
            <div class="artifact" style="margin:6px 0;">
              <span class="small">[<?= escape_html((string)$a['type']) ?>]</span>
              <?php if (!empty($a['url'])): ?>
                <a href="<?= escape_html((string)$a['url']) ?>" target="_blank"><?= escape_html((string)$a['name']) ?></a>
              <?php else: ?>
                <?= escape_html((string)$a['name']) ?>
              <?php endif; ?>
              <div class="small"><?= escape_html((string)$a['created_at']) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <hr>
      <b>📌 Pinned konteksts</b>
      <form method="POST" action="<?= APP_BASE ?>/rakstisanai.php?t=<?= (int)$threadId ?>">
        <input type="hidden" name="action" value="save_pinned">
        <textarea name="pinned_context" rows="10"><?= escape_html((string)($thread['pinned_context'] ?? '')) ?></textarea>
        <button type="submit">💾 Saglabāt pinned</button>
      </form>
    </div>

    <div>
      <?php foreach ($messages as $m): ?>
        <div class="msg <?= escape_html((string)$m['role']) ?>">
          <b><?= escape_html((string)$m['role']) ?></b>
          <span class="small"> · <?= escape_html((string)$m['created_at']) ?></span><br>
          <?= nl2br(escape_html((string)$m['content'])) ?>
        </div>
      <?php endforeach; ?>

      <div class="card" style="margin-top:14px;">
        <form method="POST" action="<?= APP_BASE ?>/rakstisanai.php?t=<?= (int)$threadId ?>">
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="thread_id" value="<?= (int)$threadId ?>">
          <label><b>Ziņa (ko darām ar kodu?)</b></label>
          <textarea name="prompt" rows="6" required placeholder="Piem.: Apskati zip arhīvu un pasaki, kādi tur ir faili."></textarea>
          <button type="submit">➡️ Sūtīt</button>
        </form>
      </div>
    </div>
  </div>

<script>
(function(){
  const form = document.getElementById('uploadForm');
  const statusEl = document.getElementById('uploadStatus');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    statusEl.textContent = 'Upload...';

    const fd = new FormData(form);
    fd.append('thread_id', '<?= (int)$threadId ?>');

    const url = '<?= APP_BASE ?>/rakstisanai.php?api=upload&t=<?= (int)$threadId ?>';

    try {
      const res = await fetch(url, { method: 'POST', body: fd });
      const text = await res.text();

      let data = null;
      try {
        data = JSON.parse(text);
      } catch (err) {
        statusEl.textContent = 'Serveris neatgrieza JSON. Pirmie 300 simboli: ' + text.slice(0, 300);
        return;
      }

      if (!data.ok) {
        statusEl.textContent = 'Kļūda: ' + (data.error || 'unknown') + (data.detail ? (' | ' + data.detail) : '');
        return;
      }

      statusEl.textContent = 'OK: ' + (data.artifact?.name || 'uploaded');
      window.location.reload();
    } catch (err) {
      statusEl.textContent = 'Kļūda: ' + (err?.message || err);
    }
  });
})();
</script>

</body>
</html>

