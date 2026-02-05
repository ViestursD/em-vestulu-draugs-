<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

mb_internal_encoding('UTF-8');

/**
 * ✅ Noķer fatālus 500 (parse/fatal) un ieliek php-error.log
 */
register_shutdown_function(function () {
  $e = error_get_last();
  if (!$e) return;
  $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array($e['type'], $fatal, true)) return;

  $msg = "[SHUTDOWN_FATAL] {$e['message']} in {$e['file']}:{$e['line']}";
  error_log($msg);
});

/**
 * ✅ Drošs html escape
 */
function h($s): string {
  if ($s === null) $s = '';
  if (is_bool($s)) $s = $s ? '1' : '0';
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$bootErr = '';
$pdo = null;
$allFiles = [];
$darbibaOptions = [];
$objektsOptions = [];

try {
  // svarīgi: tieši šie ceļi, lai nav “failed to open stream”
  require_once __DIR__ . '/.env.php';
  require_once __DIR__ . '/utils/db.php';
  require_once __DIR__ . '/utils/text.php';
  require_once __DIR__ . '/utils/normfiles.php';

  // DB var mest exception → noķeram un parādam
  $pdo = db();

  /**
   * DB dropdown helper
   */
  $list_entity_values = function (PDO $pdo, string $entity): array {
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
  };

  // norm faili
  if (!function_exists('list_norm_files')) {
    throw new RuntimeException("Trūkst funkcija list_norm_files() — pārbaudi utils/normfiles.php");
  }
  $allFiles = list_norm_files();

  // dropdown no DB
  $darbibaOptions = $list_entity_values($pdo, 'darbu_veids');
  $objektsOptions = $list_entity_values($pdo, 'objekts');

} catch (Throwable $e) {
  $bootErr = $e->getMessage();
  error_log("[INDEX_BOOT_ERR] " . $e->getMessage());
}

// UI opcijas
$topics = [
  'ieceres_dokumentacija' => 'Ieceres dokumentācija (BIS iesniegums)',
  'cits' => 'Cits (brīva vēstule / jautājums)',
];

$buvesTipsOptions = [
  'eka' => 'Ēka',
  'inzenierbuve' => 'Inženierbūve',
];

$buvesGrupaOptions = [
  '1' => '1. grupa',
  '2' => '2. grupa',
  '3' => '3. grupa',
];

$kultOptions = [
  '*' => 'Nezinu',
  'no' => 'Nē',
  'yes' => 'Jā',
];

$err = trim((string)($_GET['err'] ?? ''));

?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8"/>
  <title>EM Vēstuļu Draugs</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:980px;margin:30px auto;padding:16px}
    h1{margin:0 0 6px 0}
    .muted{color:#666;font-size:12px}
    .box{border:1px solid #ddd;border-radius:14px;padding:14px;margin:14px 0;background:#fafafa}
    .err{border:1px solid #ffb3b3;background:#fff5f5;color:#7a0000;padding:12px;border-radius:12px;margin:12px 0}
    label{display:block;font-weight:bold;margin-top:10px}
    input,select,textarea,button{width:100%;padding:10px;margin-top:6px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    button{cursor:pointer}
  </style>
</head>
<body>

<h1>EM Vēstuļu Draugs</h1>
<div class="muted">Variants A: bez preview. Pēc nosūtīšanas atvērsies čats, kur varēsi turpināt sarunu un koriģēt vēstuli.</div>

<?php if ($bootErr !== ''): ?>
  <div class="err">
    <b>Index startā nokrita ar kļūdu:</b><br>
    <?= h($bootErr) ?><br><br>
    <span class="muted">Skaties arī failu: <b>/embpd/php-error.log</b></span>
  </div>
<?php endif; ?>

<?php if ($err !== ''): ?>
  <div class="err"><b>Kļūda:</b> <?= h($err) ?></div>
<?php endif; ?>

<form method="POST" action="<?= defined('APP_BASE') ? h(APP_BASE) : '' ?>/chat_send.php">
  <input type="hidden" name="new_thread" value="1">

  <div class="box">
    <div class="muted"><b>Obligāti:</b> temats + (ja ieceres_dokumentacija → parametri) + jautājums</div>

    <label>Temats / kategorija</label>
    <select name="topic" id="topic" required>
      <?php foreach ($topics as $k => $lbl): ?>
        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
      <?php endforeach; ?>
    </select>

    <div class="box" id="ieceresBox">
      <b>Ieceres dokumentācija (obligāti aizpildīt)</b>
      <div class="muted">Šie lauki tiks ielikti pinned_context un pēc tiem tiks meklēts BIS lēmums DB.</div>

      <div class="grid2">
        <div>
          <label>Būves tips</label>
          <select name="buves_tips" required>
            <option value="">-- Izvēlies --</option>
            <?php foreach ($buvesTipsOptions as $k => $lbl): ?>
              <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Būves grupa</label>
          <select name="buves_grupa" required>
            <option value="">-- Izvēlies --</option>
            <?php foreach ($buvesGrupaOptions as $k => $lbl): ?>
              <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="grid2">
        <div>
          <label>Kultūras piemineklis</label>
          <select name="kulturas_piemineklis" required>
            <?php foreach ($kultOptions as $k => $lbl): ?>
              <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Darbu veids (no DB)</label>
          <select name="darbiba" required>
            <option value="">-- Izvēlies --</option>
            <?php foreach ($darbibaOptions as $opt): ?>
              <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$pdo): ?>
            <div class="muted">DB nav pieejams — darbu veidu saraksts nevar ielādēties.</div>
          <?php endif; ?>
        </div>
      </div>

      <label>Objekts (no DB)</label>
      <select name="objekts" required>
        <option value="">-- Izvēlies --</option>
        <?php foreach ($objektsOptions as $opt): ?>
          <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$pdo): ?>
        <div class="muted">DB nav pieejams — objekta saraksts nevar ielādēties.</div>
      <?php endif; ?>
    </div>

    <label>Forma</label>
    <select name="forma" required>
      <option value="epasts">E-pasts</option>
      <option value="vestule">Vēstule</option>
    </select>

    <label>Datums (neobligāti)</label>
    <input type="date" name="datums" value="">

    <label>Jautājums / uzdevums</label>
    <textarea name="prompt" rows="6" required placeholder="Ieraksti jautājumu..."></textarea>
  </div>

  <div class="box">
    <b>Normatīvie TXT</b>
    <div class="muted">Ja neatzīmēsi neko, tiks izmantoti visi pieejamie TXT.</div>

    <?php if (!$allFiles): ?>
      <div class="muted">Nav atrasts neviens TXT fails (list_norm_files atgrieza tukšu).</div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px">
        <?php foreach ($allFiles as $f): ?>
          <label style="font-weight:normal;margin:0;display:flex;gap:8px;align-items:center">
            <input type="checkbox" name="norm_file[]" value="<?= h($f) ?>" style="width:auto;margin:0" checked>
            <?= h($f) ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <button type="submit">✅ Sagatavot un atvērt čatu</button>
</form>

<script>
(function(){
  const topic = document.getElementById('topic');
  const box = document.getElementById('ieceresBox');

  function toggle(){
    const isIecere = (topic.value === 'ieceres_dokumentacija');
    box.style.display = isIecere ? 'block' : 'none';

    const req = box.querySelectorAll('select, input');
    req.forEach(el => {
      if (isIecere) el.setAttribute('required','required');
      else el.removeAttribute('required');
    });
  }
  topic.addEventListener('change', toggle);
  toggle();
})();
</script>

</body>
</html>

