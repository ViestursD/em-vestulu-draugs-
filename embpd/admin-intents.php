<?php
require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';

// -----------------------
// Simple password guard
// -----------------------
function require_admin(): void {
  $ok = false;

  // 1) HTTP Basic (ja tu gribi)
  if (!empty($_SERVER['PHP_AUTH_PW']) && defined('EMBPD_ADMIN_PASSWORD')) {
    if (hash_equals((string)EMBPD_ADMIN_PASSWORD, (string)$_SERVER['PHP_AUTH_PW'])) $ok = true;
  }

  // 2) Vai ar ?pw= (ērti sev, bet neliec linkos publiski)
  if (!$ok && isset($_GET['pw']) && defined('EMBPD_ADMIN_PASSWORD')) {
    if (hash_equals((string)EMBPD_ADMIN_PASSWORD, (string)$_GET['pw'])) $ok = true;
  }

  if (!$ok) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: text/plain; charset=utf-8');
    echo "401 Unauthorized";
    exit;
  }
}

require_admin();

$pdo = db();

// -----------------------
// CSRF token (minimal)
// -----------------------
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function post_csrf_ok(): bool {
  return isset($_POST['csrf']) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

// -----------------------
// Actions
// -----------------------
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!post_csrf_ok()) {
    $msg = '⚠️ CSRF kļūda (pārlādē lapu).';
  } else {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'mark_status') {
      $id = (int)($_POST['id'] ?? 0);
      $status = (string)($_POST['status'] ?? '');
      if ($id > 0 && in_array($status, ['done','ignored','new'], true)) {
        $st = $pdo->prepare("UPDATE `" . t_intent_suggestions() . "` SET status=? WHERE id=?");
        $st->execute([$status, $id]);
        $msg = "✅ Suggestion #{$id} status → {$status}";
      }
    }

    if ($action === 'create_rule') {
      $id = (int)($_POST['id'] ?? 0);
      $intent = trim((string)($_POST['intent'] ?? ''));
      $rule_type = trim((string)($_POST['rule_type'] ?? 'contains'));
      $pattern = trim((string)($_POST['pattern'] ?? ''));
      $priority = (int)($_POST['priority'] ?? 100);
      $note = trim((string)($_POST['note'] ?? ''));

      if ($id <= 0) $msg = "⚠️ Nav id";
      elseif ($intent === '' || !in_array($intent, ['eka','inzenierbuve'], true)) $msg = "⚠️ Intent jābūt: eka vai inzenierbuve";
      elseif (!in_array($rule_type, ['contains','regex'], true)) $msg = "⚠️ rule_type jābūt: contains vai regex";
      elseif ($pattern === '') $msg = "⚠️ Pattern nedrīkst būt tukšs";
      else {
        // ieliekam noteikumu
        $st = $pdo->prepare("
          INSERT INTO `" . t_intent_rules() . "` (intent, rule_type, pattern, priority, note, enabled)
          VALUES (?,?,?,?,?,1)
        ");
        $st->execute([$intent, $rule_type, $pattern, $priority, $note]);

        // atzīmējam suggestion kā done
        $st2 = $pdo->prepare("UPDATE `" . t_intent_suggestions() . "` SET status='done' WHERE id=?");
        $st2->execute([$id]);

        $msg = "✅ Izveidots noteikums + suggestion #{$id} → done";
      }
    }

    if ($action === 'bulk_mark') {
      $status = (string)($_POST['status'] ?? '');
      if (in_array($status, ['done','ignored'], true)) {
        $pdo->exec("UPDATE `" . t_intent_suggestions() . "` SET status='{$status}' WHERE status='new'");
        $msg = "✅ Visi 'new' → {$status}";
      }
    }
  }
}

// -----------------------
// Data load
// -----------------------
$show = (string)($_GET['show'] ?? 'new'); // new|all
$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 10) $limit = 10;
if ($limit > 200) $limit = 200;

$where = "WHERE status='new'";
if ($show === 'all') $where = "WHERE 1=1";

$st = $pdo->prepare("
  SELECT id, session_id, prompt, normalized, note, created_at, status
  FROM `" . t_intent_suggestions() . "`
  {$where}
  ORDER BY created_at DESC, id DESC
  LIMIT {$limit}
");
$st->execute();
$suggestions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// noteikumu saraksts (ātrai pārbaudei)
$st2 = $pdo->prepare("
  SELECT id, intent, rule_type, pattern, enabled, priority, note
  FROM `" . t_intent_rules() . "`
  ORDER BY intent ASC, enabled DESC, priority ASC, id ASC
  LIMIT 200
");
$st2->execute();
$rules = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8">
  <title>EMBPD Admin – Intent Suggestions</title>
  <style>
    body { font-family: Arial, sans-serif; max-width: 1100px; margin: 24px auto; padding: 16px; }
    .top { display:flex; gap:12px; justify-content:space-between; align-items:center; }
    .box { border:1px solid #ddd; border-radius:10px; padding:12px; background:#fafafa; margin:12px 0; }
    .muted { color:#666; font-size:12px; }
    table { width:100%; border-collapse: collapse; }
    td, th { border-bottom:1px solid #eee; padding:8px; vertical-align:top; }
    textarea, input, select, button { padding:8px; }
    textarea { width:100%; }
    .row { display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    .tag { display:inline-block; padding:2px 8px; border-radius:999px; background:#eee; font-size:12px; }
    .ok { padding:10px; background:#e6ffed; border:1px solid #b7eb8f; border-radius:10px; }
    .warn { padding:10px; background:#fffbe6; border:1px solid #ffe58f; border-radius:10px; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; }
    .small { font-size:12px; }
  </style>
</head>
<body>

<div class="top">
  <div>
    <h2 style="margin:0;">🛠️ Intent suggestions</h2>
    <div class="muted">
      Skats:
      <a href="<?= APP_BASE ?>/admin-intents.php?show=new&limit=<?= (int)$limit ?>">new</a> |
      <a href="<?= APP_BASE ?>/admin-intents.php?show=all&limit=<?= (int)$limit ?>">all</a>
      &nbsp;• limit:
      <a href="<?= APP_BASE ?>/admin-intents.php?show=<?= escape_html($show) ?>&limit=50">50</a> |
      <a href="<?= APP_BASE ?>/admin-intents.php?show=<?= escape_html($show) ?>&limit=100">100</a> |
      <a href="<?= APP_BASE ?>/admin-intents.php?show=<?= escape_html($show) ?>&limit=200">200</a>
    </div>
  </div>

  <form method="post" class="actions">
    <input type="hidden" name="csrf" value="<?= escape_html($csrf) ?>">
    <input type="hidden" name="action" value="bulk_mark">
    <button type="submit" name="status" value="done">Atzīmēt visus NEW kā DONE</button>
    <button type="submit" name="status" value="ignored">Atzīmēt visus NEW kā IGNORED</button>
  </form>
</div>

<?php if ($msg): ?>
  <div class="<?= str_starts_with($msg, '⚠️') ? 'warn' : 'ok' ?>"><?= escape_html($msg) ?></div>
<?php endif; ?>

<div class="box">
  <b>Ātra atmiņa: ko likt pattern laukā</b>
  <div class="muted">
    <div><b>contains</b> → vienkārši teksts normalizētā formā (bez diakritikas): piem. <code>dzivojama eka</code></div>
    <div><b>regex</b> → piemēram: <code>\bmaj\w*\b</code> (māja, mājas, mājai... pēc normalizācijas)</div>
  </div>
</div>

<div class="box">
  <b>Esošie noteikumi (pēdējie 200)</b>
  <table>
    <tr>
      <th>ID</th><th>intent</th><th>type</th><th>pattern</th><th>enabled</th><th>priority</th><th>note</th>
    </tr>
    <?php foreach ($rules as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= escape_html($r['intent']) ?></td>
        <td><?= escape_html($r['rule_type']) ?></td>
        <td><code><?= escape_html($r['pattern']) ?></code></td>
        <td><?= (int)$r['enabled'] ?></td>
        <td><?= (int)$r['priority'] ?></td>
        <td class="small"><?= escape_html((string)$r['note']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="box">
  <b>Suggestions (<?= escape_html($show) ?>)</b>
  <?php if (!$suggestions): ?>
    <div class="muted">Nav ierakstu.</div>
  <?php endif; ?>

  <?php foreach ($suggestions as $s): ?>
    <div class="box" style="background:#fff;">
      <div style="display:flex; justify-content:space-between; gap:10px; align-items:center;">
        <div>
          <b>#<?= (int)$s['id'] ?></b>
          <span class="tag"><?= escape_html($s['status']) ?></span>
          <span class="muted">• <?= escape_html($s['created_at']) ?></span>
          <?php if (!empty($s['session_id'])): ?>
            <span class="muted">• session: <?= escape_html($s['session_id']) ?></span>
          <?php endif; ?>
        </div>

        <form method="post" class="actions">
          <input type="hidden" name="csrf" value="<?= escape_html($csrf) ?>">
          <input type="hidden" name="action" value="mark_status">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button type="submit" name="status" value="new">NEW</button>
          <button type="submit" name="status" value="done">DONE</button>
          <button type="submit" name="status" value="ignored">IGNORED</button>
        </form>
      </div>

      <div class="row">
        <div>
          <div class="muted"><b>prompt</b></div>
          <div style="white-space:pre-wrap;"><?= escape_html((string)$s['prompt']) ?></div>
        </div>
        <div>
          <div class="muted"><b>normalized</b></div>
          <div style="white-space:pre-wrap;"><code><?= escape_html((string)$s['normalized']) ?></code></div>
          <?php if (!empty($s['note'])): ?>
            <div class="muted"><b>note</b></div>
            <div class="small"><?= escape_html((string)$s['note']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <hr>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= escape_html($csrf) ?>">
        <input type="hidden" name="action" value="create_rule">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

        <div class="row">
          <div>
            <label><b>intent</b></label><br>
            <select name="intent">
              <option value="eka">eka</option>
              <option value="inzenierbuve">inzenierbuve</option>
            </select>

            <label style="margin-left:10px;"><b>rule_type</b></label><br>
            <select name="rule_type">
              <option value="contains">contains</option>
              <option value="regex">regex</option>
            </select>

            <label style="margin-left:10px;"><b>priority</b></label><br>
            <input type="number" name="priority" value="60" style="width:120px;">
          </div>

          <div>
            <label><b>note</b></label>
            <input type="text" name="note" value="No suggestion #<?= (int)$s['id'] ?>" style="width:100%;">
          </div>
        </div>

        <label><b>pattern</b></label>
        <input type="text" name="pattern" value="<?= escape_html((string)$s['normalized']) ?>">

        <div class="muted">
          Tip: ja gribi ķert “māja/mājas/mājai…”, ieliec <b>rule_type=regex</b> un pattern: <code>\bmaj\w*\b</code>
        </div>

        <div class="actions" style="margin-top:10px;">
          <button type="submit">✅ Izveidot noteikumu un atzīmēt suggestion kā DONE</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
