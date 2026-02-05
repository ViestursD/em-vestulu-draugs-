<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = db();

$session = trim((string)($_GET['session'] ?? ''));
if ($session === '') $session = (string)get_cookie_session();
if ($session === '') {
  header("Location: " . APP_BASE . "/?err=" . urlencode("Nav sesijas."));
  exit;
}

/** Čata vēsture */
$st = $pdo->prepare("SELECT role, content, created_at FROM embpd_chats WHERE session_id=? ORDER BY created_at, id");
$st->execute([$session]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/** Debug (pēdējais ieraksts) */
$debug = null;
try {
  $sd = $pdo->prepare("SELECT payload, created_at FROM embpd_debug_log WHERE session_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
  $sd->execute([$session]);
  $dbgRow = $sd->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($dbgRow && !empty($dbgRow['payload'])) {
    $decoded = json_decode((string)$dbgRow['payload'], true);
    if (is_array($decoded)) {
      $debug = [
        'created_at' => (string)($dbgRow['created_at'] ?? ''),
        'steps' => $decoded
      ];
    }
  }
} catch (Throwable $e) {
  // ja tabula neeksistē, UI vienkārši nerāda debug
  $debug = null;
}

?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8"/>
  <title>EM Vēstuļu Draugs — Čats</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:980px;margin:30px auto;padding:16px}
    .msg{border:1px solid #ddd;border-radius:14px;padding:12px;margin:10px 0;background:#fafafa}
    .role{font-weight:bold;margin-bottom:6px}
    .user{background:#f2fbff}
    .assistant{background:#fff8f0}
    textarea,input,select,button{width:100%;padding:10px;margin-top:6px}
    button{cursor:pointer}
    .muted{color:#666;font-size:12px}
    pre{white-space:pre-wrap;word-wrap:break-word;margin:0}

    .panel{border:1px solid #ddd;border-radius:16px;padding:14px;background:#f7f7f7;margin:14px 0}
    .panel h2{margin:0 0 8px 0;font-size:16px}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #ccc;background:#fff;font-size:12px}
    .steps{margin-top:10px;display:flex;flex-direction:column;gap:8px}
    .step{border:1px solid #e0e0e0;border-radius:14px;background:#fff;padding:10px}
    .stephead{display:flex;justify-content:space-between;gap:10px;align-items:center}
    .stephead b{font-size:13px}
    .ts{font-size:12px;color:#666}
    details{margin-top:8px}
    details pre{background:#fbfbfb;border:1px solid #eee;border-radius:12px;padding:10px}
    .topbar{display:flex;justify-content:space-between;align-items:flex-end;gap:12px}
    .topbar a{font-size:12px}
    .btnSmall{width:auto;padding:8px 10px;border-radius:12px;border:1px solid #ccc;background:#fff}
  </style>
</head>
<body>

<div class="topbar">
  <div>
    <h1 style="margin:0">Čats</h1>
    <div class="muted">Sesija: <span class="pill"><?= h($session) ?></span></div>
  </div>
  <div>
    <a href="<?= h(APP_BASE) ?>/" class="muted">Atpakaļ uz sākumu</a>
  </div>
</div>

<?php if ($debug && !empty($debug['steps'])): ?>
  <div class="panel" id="debugPanel">
    <h2>🧭 Sistēmas darbības (debug)</h2>
    <div class="muted">Pēdējais izsaukums: <?= h($debug['created_at'] ?? '') ?></div>

    <div class="steps">
      <?php foreach (($debug['steps'] ?? []) as $idx => $s): ?>
        <?php
          $title = (string)($s['title'] ?? ('Step ' . ($idx+1)));
          $t = (string)($s['t'] ?? '');
          $data = $s['data'] ?? null;
        ?>
        <div class="step">
          <div class="stephead">
            <b><?= h($title) ?></b>
            <span class="ts"><?= h($t) ?></span>
          </div>

          <?php if ($data !== null && $data !== ''): ?>
            <details>
              <summary class="muted">Skatīt detaļas</summary>
              <pre><?= h(is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <h2>🧭 Sistēmas darbības (debug)</h2>
    <div class="muted">Nav atrasts debug ieraksts (vai debug nav ieslēgts, vai nav izveidota tabula <code>embpd_debug_log</code>).</div>
  </div>
<?php endif; ?>

<?php foreach ($rows as $r): ?>
  <?php $cls = (($r['role'] ?? '') === 'user') ? 'user' : 'assistant'; ?>
  <div class="msg <?= h($cls) ?>">
    <div class="role"><?= h($r['role'] ?? '') ?> • <?= h($r['created_at'] ?? '') ?></div>
    <pre><?= h($r['content'] ?? '') ?></pre>
  </div>
<?php endforeach; ?>

<form method="POST" action="<?= h(APP_BASE) ?>/chat_send.php" style="margin-top:18px">
  <input type="hidden" name="new_thread" value="0">
  <input type="hidden" name="session" value="<?= h($session) ?>">

  <label>Turpināt sarunu</label>
  <textarea name="prompt" rows="5" required placeholder="Ieraksti..."></textarea>

  <button type="submit">➡️ Sūtīt</button>
</form>

</body>
</html>

