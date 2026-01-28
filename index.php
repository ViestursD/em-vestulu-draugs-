<?php
require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';
require_once __DIR__ . '/utils/normfiles.php';

$pdo = db();
$session = get_cookie_session();
$allFiles = list_norm_files();

$chatRows = [];
if ($session) {
  $st = $pdo->prepare("SELECT role, content FROM `" . t_chats() . "` WHERE session_id=? ORDER BY created_at, id");
  $st->execute([$session]);
  $chatRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8">
  <title>EM Vēstuļu Draugs</title>
  <link rel="stylesheet" href="<?= APP_BASE ?>/public/style.css">
</head>
<body>
  <div class="row">
    <h1>📨 EM Vēstuļu Draugs (Hetzner /embpd)</h1>
    <div class="actions">
      <form method="POST" action="<?= APP_BASE ?>/new-session.php">
        <button type="submit">➕ Sākt jaunu sarunu</button>
      </form>
    </div>
  </div>

  <?php foreach ($chatRows as $r): ?>
    <div class="msg <?= escape_html($r['role']) ?>">
      <b><?= escape_html($r['role']) ?></b><br>
      <?= nl2br(escape_html($r['content'])) ?>
    </div>
  <?php endforeach; ?>

  <form method="POST" action="<?= APP_BASE ?>/<?= $session ? 'chat.php' : 'preview.php' ?>">
    <div class="box">
      <b>🔎 Normatīvie akti (TXT lokāli)</b><br>
      <small class="muted">Ja neko neatlasīsi, izmantos visus failus.</small>
      <div class="normlist">
        <?php foreach ($allFiles as $f): ?>
          <label style="display:block;margin:6px 0;">
            <input type="checkbox" name="norm_file[]" value="<?= escape_html($f) ?>" checked>
            <?= escape_html($f) ?>
          </label>
        <?php endforeach; ?>
        <?php if (count($allFiles) === 0): ?>
          <div class="muted">Nav atrasti .txt faili mapē data/normativi/</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="box">
      <b>🏗️ Būves klasifikācija (palīdz precīzākai atbildei)</b><br>
      <small class="muted">Ja neesi drošs, atstāj “Nezinu”.</small><br><br>

      <label><b>Būves tips</b></label>
      <select name="buves_tips">
        <option value="nezinu" selected>Nezinu</option>
        <option value="eka">Ēka</option>
        <option value="inzenierbuve">Inženierbūve</option>
      </select>

      <label><b>Būves grupa</b></label>
      <select name="buves_grupa">
        <option value="nezinu" selected>Nezinu</option>
        <option value="1">1. grupa</option>
        <option value="2">2. grupa</option>
        <option value="3">3. grupa</option>
      </select>
    </div>

    <label><b>Jautājums</b></label>
    <textarea name="prompt" rows="5" required></textarea>

    <label>📅 Saņemšanas datums (neobligāti)</label>
    <input type="date" name="datums">

    <label>Forma</label>
    <select name="forma">
      <option value="epasts">E-pasts</option>
      <option value="vestule">Vēstule</option>
    </select>

    <button type="submit">➡️ Sagatavot atbildi</button>
  </form>
</body>
</html>

