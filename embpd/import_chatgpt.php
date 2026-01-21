<?php
declare(strict_types=1);

/**
 * /embpd/import_chatgpt.php
 * ChatGPT Data Export importer -> mycode_* (BIGINT shēma kā rakstisanai.php)
 *
 * - Upload ChatGPT export ZIP OR conversations.json
 * - Pick conversation
 * - Import -> mycode_threads (BIGINT) + mycode_messages (BIGINT)
 * - Idempotent via mycode_import_map (source, conv_id, msg_id unique)
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
ini_set('memory_limit', '1024M');
set_time_limit(0);

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';

header('Content-Type: text/html; charset=utf-8');

session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function ensure_mycode_tables_bigint(PDO $pdo): void {
  // Tās pašas tabulas kā rakstisanai.php (+ import_map)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `mycode_threads` (
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
    CREATE TABLE IF NOT EXISTS `mycode_messages` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `thread_id` BIGINT UNSIGNED NOT NULL,
      `role` VARCHAR(20) NOT NULL,
      `content` MEDIUMTEXT NOT NULL,
      `meta` JSON NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_thread_created` (`thread_id`, `created_at`),
      CONSTRAINT `fk_mycode_thread`
        FOREIGN KEY (`thread_id`) REFERENCES `mycode_threads`(`id`)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");

  // Import map (idempotence)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `mycode_import_map` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `source` VARCHAR(32) NOT NULL,
      `source_conv_id` VARCHAR(128) NOT NULL,
      `source_msg_id` VARCHAR(128) NOT NULL,
      `mycode_thread_id` BIGINT UNSIGNED NOT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_src` (`source`, `source_conv_id`, `source_msg_id`),
      KEY `idx_thread` (`mycode_thread_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
}

function ensure_upload_dir(): string {
  $dir = __DIR__ . '/uploads/mycode_import';
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException("Nevar izveidot mapi: {$dir}");
    }
  }
  return $dir;
}

function save_uploaded_file(array $file): string {
  if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    throw new RuntimeException("Fails nav augšupielādēts.");
  }
  if (!empty($file['error'])) {
    throw new RuntimeException("Upload error code: " . (string)$file['error']);
  }

  $dir = ensure_upload_dir();
  $name = (string)($file['name'] ?? 'upload');
  $ext = '';
  if (preg_match('~(\.[A-Za-z0-9]{1,10})$~', $name, $m)) $ext = strtolower($m[1]);

  $target = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . $ext;
  if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
    throw new RuntimeException("Neizdevās saglabāt failu serverī.");
  }
  return $target;
}

function read_conversations_from_path(string $path): array {
  if (!is_file($path)) throw new RuntimeException("Fails nav atrasts serverī.");

  if (preg_match('/\.zip$/i', $path)) {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException("ZIP nav atbalstīts (ZipArchive nav pieejams). Lūdzu augšupielādē conversations.json.");
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("Neizdevās atvērt ZIP.");

    $jsonStr = null;
    for ($i=0; $i < $zip->numFiles; $i++) {
      $stat = $zip->statIndex($i);
      if (!$stat) continue;
      $fn = $stat['name'] ?? '';
      if (preg_match('~(^|/)(conversations\.json)$~i', $fn)) {
        $jsonStr = $zip->getFromIndex($i);
        break;
      }
    }
    $zip->close();

    if (!$jsonStr) throw new RuntimeException("ZIP arhīvā neatradu conversations.json.");

    $data = json_decode($jsonStr, true);
    if (!is_array($data)) throw new RuntimeException("conversations.json nav derīgs JSON.");
    return $data;
  }

  if (preg_match('/\.json$/i', $path)) {
    $jsonStr = file_get_contents($path);
    $data = json_decode($jsonStr ?: '', true);
    if (!is_array($data)) throw new RuntimeException("JSON nav derīgs.");
    return $data;
  }

  throw new RuntimeException("Atbalstīts tikai ZIP vai JSON.");
}

function list_conversations(array $export): array {
  $out = [];
  foreach ($export as $conv) {
    if (!is_array($conv)) continue;
    $id = (string)($conv['id'] ?? '');
    if ($id === '') continue;

    $title = (string)($conv['title'] ?? '(bez nosaukuma)');
    $create = $conv['create_time'] ?? null;
    $dt = '';
    if (is_numeric($create)) $dt = date('Y-m-d H:i:s', (int)$create);

    $out[] = ['id'=>$id,'title'=>$title,'created_at'=>$dt];
  }
  usort($out, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
  return $out;
}

function extract_messages(array $conv): array {
  $messages = [];
  $mapping = $conv['mapping'] ?? null;
  if (!is_array($mapping)) return $messages;

  foreach ($mapping as $node) {
    if (!is_array($node)) continue;
    $msg = $node['message'] ?? null;
    if (!is_array($msg)) continue;

    $author = $msg['author'] ?? [];
    $role = (string)($author['role'] ?? '');
    if (!in_array($role, ['user','assistant','system'], true)) continue;

    $msgId = (string)($msg['id'] ?? '');
    if ($msgId === '') $msgId = (string)($node['id'] ?? '');
    if ($msgId === '') continue;

    $create = $msg['create_time'] ?? null;
    $createdAt = is_numeric($create) ? (int)$create : null;

    $content = '';
    $contentObj = $msg['content'] ?? null;
    if (is_array($contentObj)) {
      $parts = $contentObj['parts'] ?? null;
      if (is_array($parts)) {
        $content = implode("\n", array_map(fn($p) => is_string($p) ? $p : json_encode($p, JSON_UNESCAPED_UNICODE), $parts));
      } else {
        $content = json_encode($contentObj, JSON_UNESCAPED_UNICODE);
      }
    } elseif (is_string($contentObj)) {
      $content = $contentObj;
    }

    $content = trim($content);
    if ($content === '') continue;

    $messages[] = [
      'source_msg_id' => $msgId,
      'role' => $role,
      'content' => $content,
      'created_at' => $createdAt,
      'meta' => [
        'chatgpt' => [
          'conversation_id' => $conv['id'] ?? null,
          'message_id' => $msgId,
        ]
      ],
    ];
  }

  usort($messages, function($a, $b){
    $ta = $a['created_at'] ?? 0;
    $tb = $b['created_at'] ?? 0;
    return $ta <=> $tb;
  });

  return $messages;
}

function get_cookie_session_safe(): string {
  // izmantojam jūsu esošo cookie sesiju, ja pieejams
  if (function_exists('get_cookie_session')) {
    $s = get_cookie_session();
    if (is_string($s) && $s !== '') return $s;
  }
  // fallback
  return $_COOKIE['session_id'] ?? bin2hex(random_bytes(16));
}

function get_or_create_thread_for_conv(PDO $pdo, string $sessionId, string $convId, string $title): int {
  // ja jau mapā ir ieraksts – paņem thread id
  $stmt = $pdo->prepare("SELECT mycode_thread_id FROM mycode_import_map WHERE source='chatgpt' AND source_conv_id=? LIMIT 1");
  $stmt->execute([$convId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row && !empty($row['mycode_thread_id'])) return (int)$row['mycode_thread_id'];

  // izveido jaunu thread šai sesijai
  $stmt = $pdo->prepare("INSERT INTO mycode_threads (session_id, title, pinned_context) VALUES (?,?,NULL)");
  $stmt->execute([$sessionId, $title]);
  return (int)$pdo->lastInsertId();
}

function import_conversation_bigint(PDO $pdo, string $sessionId, array $conv): array {
  $convId = (string)($conv['id'] ?? '');
  if ($convId === '') throw new RuntimeException("Nav conversation id.");

  $title = (string)($conv['title'] ?? 'ChatGPT imports');
  $threadId = get_or_create_thread_for_conv($pdo, $sessionId, $convId, $title);

  $msgs = extract_messages($conv);
  $imported = 0;
  $skipped  = 0;

  $pdo->beginTransaction();
  try {
    $insMsg = $pdo->prepare("
      INSERT INTO mycode_messages (thread_id, role, content, meta, created_at)
      VALUES (?, ?, ?, ?, ?)
    ");

    $insMap = $pdo->prepare("
      INSERT IGNORE INTO mycode_import_map (source, source_conv_id, source_msg_id, mycode_thread_id)
      VALUES ('chatgpt', ?, ?, ?)
    ");

    foreach ($msgs as $m) {
      $sourceMsgId = (string)$m['source_msg_id'];

      $insMap->execute([$convId, $sourceMsgId, $threadId]);
      if ($insMap->rowCount() === 0) { $skipped++; continue; }

      $createdAt = $m['created_at'] ? date('Y-m-d H:i:s', (int)$m['created_at']) : date('Y-m-d H:i:s');
      $metaJson = json_encode($m['meta'], JSON_UNESCAPED_UNICODE);

      $insMsg->execute([$threadId, $m['role'], $m['content'], $metaJson, $createdAt]);
      $imported++;
    }

    // bump thread updated_at
    $pdo->prepare("UPDATE mycode_threads SET updated_at=NOW() WHERE id=?")->execute([$threadId]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  return [
    'thread_id' => $threadId,
    'imported' => $imported,
    'skipped' => $skipped,
    'total_parsed' => count($msgs),
  ];
}

// --------------------
// MAIN
// --------------------
$pdo = db();
ensure_mycode_tables_bigint($pdo);

$sessionId = get_cookie_session_safe();

$error = null;
$result = null;
$convs = [];
$hasFile = false;

try {
  // Upload -> save to disk
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['export_file'])) {
    $path = save_uploaded_file($_FILES['export_file']);
    $_SESSION['chatgpt_export_path'] = $path;
  }

  $path = (string)($_SESSION['chatgpt_export_path'] ?? '');
  if ($path !== '' && is_file($path)) {
    $hasFile = true;
    $export = read_conversations_from_path($path);
    $convs = list_conversations($export);

    // Import selected
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_conv_id'])) {
      $targetId = (string)$_POST['import_conv_id'];
      $selected = null;
      foreach ($export as $c) {
        if (is_array($c) && (string)($c['id'] ?? '') === $targetId) { $selected = $c; break; }
      }
      if (!$selected) throw new RuntimeException("Neatradu izvēlēto sarunu exportā.");
      $result = import_conversation_bigint($pdo, $sessionId, $selected);
    }
  }

} catch (Throwable $e) {
  $error = $e->getMessage();
}

?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ChatGPT export imports → mycode_ (BIGINT)</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:20px;}
    .box{border:1px solid #ddd;border-radius:10px;padding:14px;margin-bottom:16px;}
    .err{color:#b00020;}
    table{border-collapse:collapse;width:100%;}
    th,td{border-bottom:1px solid #eee;padding:8px;vertical-align:top;}
    th{background:#fafafa;text-align:left;}
    .small{color:#666;font-size:12px;}
    button{cursor:pointer;padding:8px 10px;}
    input[type=file]{padding:6px;}
    .ok{color:#0a7a2f;}
  </style>
</head>
<body>

<h2>ChatGPT Data Export → mycode_ imports (BIGINT shēma)</h2>

<div class="box">
  <form method="post" enctype="multipart/form-data">
    <div><b>1) Augšupielādē ChatGPT export ZIP</b> (vai <code>conversations.json</code>)</div>
    <div style="margin-top:10px;">
      <input type="file" name="export_file" required>
      <button type="submit">Ielādēt</button>
    </div>
    <div class="small" style="margin-top:8px;">
      Ja serverī nav <code>ZipArchive</code>, augšupielādē tieši <code>conversations.json</code>.
      Skaties logu: <code>/embpd/php-error.log</code>
    </div>
  </form>
</div>

<?php if ($error): ?>
  <div class="box err"><b>Kļūda:</b> <?=h($error)?></div>
<?php endif; ?>

<?php if ($result): ?>
  <div class="box ok">
    <b>Imports pabeigts</b><br>
    Thread ID: <code><?=h((string)$result['thread_id'])?></code><br>
    Importētas ziņas: <?=h((string)$result['imported'])?><br>
    Izlaistas (dublikāti): <?=h((string)$result['skipped'])?><br>
    Kopā atrastas ziņas: <?=h((string)$result['total_parsed'])?><br>
    <div class="small" style="margin-top:8px;">
      Tagad ej uz: <code>rakstisanai.php?t=<?=h((string)$result['thread_id'])?></code>
    </div>
  </div>
<?php endif; ?>

<?php if ($hasFile && $convs): ?>
  <div class="box">
    <b>2) Izvēlies sarunu importam</b>
    <div class="small" style="margin-top:6px;">Sakārtots pēc datuma (ja pieejams).</div>

    <table style="margin-top:10px;">
      <thead>
        <tr>
          <th>Datums</th>
          <th>Nosaukums</th>
          <th>ID</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($convs as $c): ?>
        <tr>
          <td class="small"><?=h($c['created_at'] ?: '-')?></td>
          <td><?=h($c['title'])?></td>
          <td class="small"><code><?=h($c['id'])?></code></td>
          <td style="white-space:nowrap;">
            <form method="post" style="margin:0;">
              <input type="hidden" name="import_conv_id" value="<?=h($c['id'])?>">
              <button type="submit">Importēt</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  </div>
<?php endif; ?>

</body>
</html>

