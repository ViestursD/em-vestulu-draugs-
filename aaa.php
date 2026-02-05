<?php

declare(strict_types=1);
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';

$pdo = db();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function list_entity_values(PDO $pdo, string $entity): array {
  $st = $pdo->prepare("
    SELECT DISTINCT value
    FROM embpd_entity_rules
    WHERE enabled=1 AND entity=?
    ORDER BY priority ASC, id ASC
  ");
  $st->execute([$entity]);
  $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

  $out = [];
  foreach ($rows as $v) {
    $v = trim((string)$v);
    if ($v !== '') $out[] = $v;
  }
  return array_values(array_unique($out));
}

$err = trim((string)($_GET['err'] ?? ''));

/** Opcijas (hard-coded, lai NEKAD nepaliktu tukšs) */
$topicOptions = [
  'ieceres_dokumentacija' => 'Ieceres dokumentācija (BIS iesniegums)',
  'cits'                  => 'Cits (brīva vēstule / jautājums)'
];

$buvesTipsOptions = [
  'eka'          => 'Ēka',
  'inzenierbuve' => 'Inženierbūve',
];

$buvesGrupaOptions = [
  '1' => '1. grupa',
  '2' => '2. grupa',
  '3' => '3. grupa',
];

$kulturasOptions = [
  'no'  => 'Nē',
  'yes' => 'Jā',
  '*'   => 'Nezinu (parādīt abus variantus)',
];

/** DB dropdowni */
$objektsOptions = list_entity_values($pdo, 'objekts');
$darbibaOptions = list_entity_values($pdo, 'darbu_veids');

/** Saglabā ievadi, ja pārlādē */
$pref_topic = (string)($_POST['topic'] ?? 'ieceres_dokumentacija');
$pref_tips  = (string)($_POST['buves_tips'] ?? 'eka');
$pref_grupa = (string)($_POST['buves_grupa'] ?? '1');
$pref_kult  = (string)($_POST['kulturas_piemineklis'] ?? 'no');
$pref_obj   = (string)($_POST['objekts'] ?? '');
$pref_darb  = (string)($_POST['darbiba'] ?? '');
$pref_forma = (string)($_POST['forma'] ?? 'epasts');
$pref_date  = (string)($_POST['datums'] ?? date('Y-m-d'));
$pref_prompt= (string)($_POST['prompt'] ?? '');
?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>EM Vēstuļu Draugsss</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:980px;margin:28px auto;padding:16px}
    h1{margin:0 0 8px 0}
    .muted{color:#666;font-size:12px}
    .box{border:1px solid #ddd;border-radius:12px;padding:14px;margin:14px 0;background:#fafafa}
    label{display:block;margin-top:10px;font-weight:bold}
    input,select,textarea,button{width:100%;padding:10px;margin-top:6px;box-sizing:border-box}
    textarea{min-height:120px}
    .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
    .err{border:1px solid #ffb3b3;background:#fff5f5;color:#7a0000;padding:10px;border-radius:12px;margin:12px 0}
    .btnPrimary{background:#111;color:#fff;border:1px solid #111;border-radius:12px;cursor:pointer}
    .btnPrimary:hover{opacity:.92}
    .btnGhost{background:#fff;border:1px solid #ddd;border-radius:12px;cursor:pointer}
    .btnGhost:hover{background:#f3f3f3}
    button[disabled]{opacity:.55;cursor:not-allowed}
    .hide{display:none}
    .pill{display:inline-block;border:1px solid #ddd;border-radius:999px;padding:6px 10px;background:#fff;font-size:12px}
  </style>
</head>
<body>

<h1>EM Vēstuļu Draugs</h1>
<div class="muted">
  Variants A: bez preview. Pēc nosūtīšanas atvērsies čats, kur var turpināt sarunu un koriģēt vēstuli.
</div>

<?php if ($err !== ''): ?>
  <div class="err"><b>⚠️ Kļūda:</b> <?= h($err) ?></div>
<?php endif; ?>

<div class="box">
  <div class="pill">Obligāti: temats + parametri + jautājums</div>
</div>

<form method="POST" action="<?= h(APP_BASE) ?>/chat_new.php" id="newChatForm">

  <label>Temats / kategorija</label>
  <select name="topic" id="topic" required>
    <?php foreach ($topicOptions as $k => $label): ?>
      <option value="<?= h($k) ?>" <?= ($pref_topic === $k ? 'selected' : '') ?>><?= h($label) ?></option>
    <?php endforeach; ?>
  </select>

  <div class="box" id="ieceresBox">
    <b>Ieceres dokumentācija (obligāti aizpildīt)</b>
    <div class="muted">Šie lauki tiks ielikti pinned_context un pēc tiem tiks meklēts BIS lēmums DB.</div>

    <div class="row3">
      <div>
        <label>Būves tips</label>
        <select name="buves_tips" id="buves_tips">
          <?php foreach ($buvesTipsOptions as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ($pref_tips === $k ? 'selected' : '') ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Būves grupa</label>
        <select name="buves_grupa" id="buves_grupa">
          <?php foreach ($buvesGrupaOptions as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ((string)$pref_grupa === (string)$k ? 'selected' : '') ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Kultūras piemineklis</label>
        <select name="kulturas_piemineklis" id="kulturas_piemineklis">
          <?php foreach ($kulturasOptions as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ($pref_kult === $k ? 'selected' : '') ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row2">
      <div>
        <label>Objekts (no DB)</label>
        <select name="objekts" id="objekts">
          <option value="">-- Izvēlies --</option>
          <?php foreach ($objektsOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= ($pref_obj === $opt ? 'selected' : '') ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Darbu veids (no DB)</label>
        <select name="darbiba" id="darbiba">
          <option value="">-- Izvēlies --</option>
          <?php foreach ($darbibaOptions as $opt): ?>
            <option value="<?= h($opt) ?>" <?= ($pref_darb === $opt ? 'selected' : '') ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="box">
    <b>Vēstules formāts</b>
    <div class="row2">
      <div>
        <label>Veids</label>
        <select name="forma" id="forma" required>
          <option value="epasts" <?= ($pref_forma === 'epasts' ? 'selected' : '') ?>>E-pasts</option>
          <option value="vestule" <?= ($pref_forma === 'vestule' ? 'selected' : '') ?>>Vēstule</option>
        </select>
      </div>
      <div>
        <label>Datums</label>
        <input type="date" name="datums" id="datums" value="<?= h($pref_date) ?>">
      </div>
    </div>
  </div>

  <label>Jautājums / uzdevums</label>
  <textarea name="prompt" id="prompt" required placeholder="Ieraksti jautājumu..."><?= h($pref_prompt) ?></textarea>

  <div class="row2" style="margin-top:12px">
    <button type="submit" class="btnPrimary" id="submitBtn">➡️ Sākt čatu</button>
    <a class="btnGhost" href="<?= h(APP_BASE) ?>/chat.php" style="display:flex;align-items:center;justify-content:center;text-decoration:none;color:#111;border-radius:12px">
      Atvērt pēdējo sesiju (cookie)
    </a>
  </div>
</form>

<script>
(function(){
  const topic = document.getElementById('topic');
  const ieceresBox = document.getElementById('ieceresBox');
  const submitBtn = document.getElementById('submitBtn');

  const buvesTips = document.getElementById('buves_tips');
  const buvesGrupa = document.getElementById('buves_grupa');
  const kult = document.getElementById('kulturas_piemineklis');
  const objekts = document.getElementById('objekts');
  const darbiba = document.getElementById('darbiba');
  const prompt = document.getElementById('prompt');

  function isIeceres() { return topic && topic.value === 'ieceres_dokumentacija'; }

  function toggleIeceresUI() {
    const on = isIeceres();
    ieceresBox?.classList.toggle('hide', !on);
  }

  function validate() {
    let ok = true;
    if (!prompt || prompt.value.trim() === '') ok = false;

    if (isIeceres()) {
      if (!buvesTips || buvesTips.value === '') ok = false;
      if (!buvesGrupa || buvesGrupa.value === '') ok = false;
      if (!kult || kult.value === '') ok = false;
      if (!objekts || objekts.value === '') ok = false;
      if (!darbiba || darbiba.value === '') ok = false;
    }

    submitBtn.disabled = !ok;
  }

  topic?.addEventListener('change', function(){ toggleIeceresUI(); validate(); });
  [buvesTips, buvesGrupa, kult, objekts, darbiba, prompt].forEach(el => {
    el?.addEventListener('change', validate);
    el?.addEventListener('keyup', validate);
  });

  toggleIeceresUI();
  validate();
})();
</script>

</body>
</html>
