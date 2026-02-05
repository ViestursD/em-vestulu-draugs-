<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

mb_internal_encoding('UTF-8');

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';

$pdo = db();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$session = trim((string)($_GET['session'] ?? ''));
if ($session === '') $session = (string)get_cookie_session();

if ($session !== 'solo' && $session !== '') {
  if (!preg_match('/\A[a-f0-9]{32}\z/i', $session)) {
    header("Location: " . APP_BASE . "/");
    exit;
  }
}
if ($session === '') { header("Location: " . APP_BASE . "/"); exit; }

$err = trim((string)($_GET['err'] ?? ''));

/** pinned_context */
$pinned = '';
try {
  $stp = $pdo->prepare("SELECT pinned_context FROM embpd_threads WHERE session_id=? LIMIT 1");
  $stp->execute([$session]);
  $pinned = (string)($stp->fetchColumn() ?: '');
} catch (Throwable $e) {}

/** vēsture */
$st = $pdo->prepare("SELECT id, role, content, created_at FROM embpd_chats WHERE session_id=? ORDER BY created_at, id");
$st->execute([$session]);
$history = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8"/>
  <title>Čats</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:980px;margin:30px auto;padding:16px}
    .top{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
    .muted{color:#666;font-size:12px}
    .box{border:1px solid #ddd;border-radius:14px;padding:12px;margin:12px 0;background:#fafafa}
    .msg{border:1px solid #ddd;border-radius:12px;padding:12px;margin:10px 0;background:#fafafa;white-space:pre-wrap}
    .role{font-size:12px;color:#666;margin-bottom:6px}
    .user{background:#f6fbff}
    .assistant{background:#fbf7ff}
    .btn{display:inline-block;padding:10px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#111;background:#fff}
    .btn:hover{background:#f3f3f3}
    textarea,button{width:100%;padding:10px;margin:8px 0}
    button{cursor:pointer}
    .err{border:1px solid #ffb3b3;background:#fff5f5;color:#7a0000;padding:10px;border-radius:10px;margin:12px 0}
  </style>
</head>
<body>

<div class="top">
  <div>
    <div><b>Sesija:</b> <?= h($session) ?></div>
    <div class="muted">Turpini sarunu zemāk. Sistēma piesaista pinned_context un normatīvos TXT.</div>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="<?= h(APP_BASE) ?>/">➕ Jauns jautājums</a>
    <a class="btn" href="<?= h(APP_BASE) ?>/chat.php?session=<?= urlencode($session) ?>">🔄 Atsvaidzināt</a>
  </div>
</div>

<?php if ($err !== ''): ?>
  <div class="err"><b>Kļūda:</b> <?= h($err) ?></div>
<?php endif; ?>

<?php if ($pinned !== ''): ?>
  <div class="box">
    <b>Pinned konteksts</b>
    <div class="muted">Šis vienmēr tiek pievienots sistēmas izpildei.</div>
    <div style="white-space:pre-wrap"><?= h($pinned) ?></div>
  </div>
<?php endif; ?>

<?php if (!$history): ?>
  <div class="msg">
    <div class="role">Info</div>
    Nav ziņu šai sesijai.
  </div>
<?php else: ?>
  <?php foreach ($history as $row): ?>
    <?php
      $role = (string)($row['role'] ?? '');
      $cls = $role === 'user' ? 'user' : ($role === 'assistant' ? 'assistant' : '');
    ?>
    <div class="msg <?= h($cls) ?>">
      <div class="role"><?= h($role) ?> • <?= h((string)($row['created_at'] ?? '')) ?></div>
      <?= h((string)($row['content'] ?? '')) ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="box">
  <b>Turpināt sarunu</b>
  <form method="POST" action="<?= h(APP_BASE) ?>/chat_send.php">
    <input type="hidden" name="session" value="<?= h($session) ?>">
    <textarea name="prompt" rows="4" required placeholder="Piem.: Lūdzu pieliec vēl, ka... / Izņem šo frāzi..."></textarea>
    <button type="submit">➡️ Sūtīt</button>
  </form>
</div>

</body>
</html>

