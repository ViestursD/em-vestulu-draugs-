<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';
require_once __DIR__ . '/utils/normfiles.php';
require_once __DIR__ . '/utils/intent.php';
require_once __DIR__ . '/utils/entities.php';      // detect_entity()
require_once __DIR__ . '/utils/openai.php';
require_once __DIR__ . '/utils/buves_grupa.php';   // determine_buves_grupa_from_rules()
require_once __DIR__ . '/utils/decision.php';      // pick_decision_rule()

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
  $rows = $st->fetchAll(PDO::FETCH_COLUMN);
  $out = [];
  foreach ($rows as $v) {
    $v = trim((string)$v);
    if ($v !== '') $out[] = $v;
  }
  return array_values(array_unique($out));
}

/**
 * ✅ Normalizē darbu_veids vērtības uz tām, kas ir embpd_decision_rules
 */
function normalize_darbiba_for_decision(string $darbiba): string {
  $d = trim($darbiba);
  if ($d === 'jauna_buvnieciba') return 'jaunbuvnieciba';
  return $d;
}

/** Ievade no sākumlapas / refresh */
$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($prompt === '') { header("Location: " . APP_BASE . "/"); exit; }

$forma  = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');

/** textarea saglabāšana caur refresh */
$system_override_in = (string)($_POST['system_override'] ?? '');
$user_override_in   = (string)($_POST['user_override'] ?? '');

/**
 * 1) USER izvēle (tas, ko lietotājs izvēlējās sākumlapā)
 *    Ja nav – nezinu.
 */
$buvesTips_user  = (string)($_POST['buves_tips'] ?? 'nezinu');
$buvesGrupa_user = (string)($_POST['buves_grupa'] ?? 'nezinu');

/**
 * 2) Efektīvais (ko sistēma izmantos)
 *    - sākumā paņem no user
 *    - bet ja preview refresh laikā lietotājs izvēlējās eff dropdownos, ņemam no tiem
 */
$buvesTips_eff  = $buvesTips_user;
$buvesGrupa_eff = $buvesGrupa_user;

/** Norm failu izvēle */
$allFiles = list_norm_files();
$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

/** Manual override no preview dropdown (refresh laikā) */
$manualObjekts     = (string)($_POST['objekts_eff'] ?? '');
$manualDarbiba     = (string)($_POST['darbiba_eff'] ?? '');
$manualBuvesTips   = (string)($_POST['buves_tips_eff'] ?? '');
$manualBuvesGrupa  = (string)($_POST['buves_grupa_eff'] ?? '');

if ($manualBuvesTips !== '')  $buvesTips_eff = $manualBuvesTips;
if ($manualBuvesGrupa !== '') $buvesGrupa_eff = $manualBuvesGrupa;

/** Auto-detect būves grupa/tips tikai, ja eff vēl nav izvēlēts */
if ($buvesGrupa_eff === 'nezinu' || $buvesGrupa_eff === '') {
  $buvesGrupa_eff = determine_buves_grupa_from_rules($prompt);
}
if ($buvesTips_eff === 'nezinu' || $buvesTips_eff === '') {
  $buvesTips_eff = detect_entity($pdo, 'buves_tips', $prompt, 'nezinu');
}

/** Hard filtrēšana pēc būves tipa */
if ($buvesTips_eff === 'eka') {
  $selected = array_values(array_filter(
    $selected,
    fn($f) => !str_contains(mb_strtolower((string)$f), 'atsevisku-inzenierbuvju-buvnoteikumi')
  ));
  if (!$selected) $selected = $allFiles;
}
if ($buvesTips_eff === 'inzenierbuve') {
  $selected = array_values(array_filter(
    $selected,
    fn($f) => !str_contains(mb_strtolower((string)$f), 'eku-buvnoteikumi')
  ));
  if (!$selected) $selected = $allFiles;
}

/** DB rules (extra system noteikumi) */
$rulePack = apply_rules($pdo, $prompt);
$extraRules = '';
foreach (($rulePack['actions'] ?? []) as $a) {
  if (($a['scope'] ?? 'system') === 'system') {
    $extraRules .= "- " . trim((string)($a['text'] ?? '')) . "\n";
  }
}

/** Hard noteikumi pret halucinācijām */
$extraRules .= "- Drīkst apgalvot TIKAI to, kas ir pievienotajos TXT normatīvos. Ja termins/nav prasība nav TXT, to NEDRĪKST minēt.\n";
$extraRules .= "- Ja nevar atrast konkrētu atsauci TXT, [NORMATĪVAIS_PAMATOJUMS] blokā raksti: Atsauce: nav noteikta.\n";
$extraRules .= "- Aizliegts minēt 'apliecinājuma karte', ja tā nav tieši atrodama un citējama no pievienotajiem TXT.\n";

/** Datums LV preview (nav kritiski) */
$datumsLv = '';
if ($datums !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $datums);
  if ($dt instanceof DateTime) $datumsLv = $dt->format('Y. gada j. m');
}

/** Objekts + darbi no DB vai manuāli */
$objekts = '*';
$darbiba = '*';

if ($buvesTips_eff !== 'nezinu') {
  $objekts = detect_entity($pdo, 'objekts', $prompt, '*');
  $darbiba = detect_entity($pdo, 'darbu_veids', $prompt, '*');
}
if ($manualObjekts !== '') $objekts = $manualObjekts;
if ($manualDarbiba !== '') $darbiba = $manualDarbiba;

$darbiba_for_decision = ($darbiba !== '*') ? normalize_darbiba_for_decision($darbiba) : '*';

/** Decision */
$decision = null;
if ($buvesTips_eff !== 'nezinu' && $buvesGrupa_eff !== 'nezinu' && $buvesGrupa_eff !== '') {
  $decision = pick_decision_rule(
    $pdo,
    $buvesTips_eff,
    (string)$buvesGrupa_eff,
    $objekts ?: '*',
    $darbiba_for_decision ?: '*'
  );
}

$decisionText = 'nav noteikts';
if ($decision) {
  $decisionText =
    "Dokuments BIS: " . (string)($decision['doc_type'] ?? '') .
    " | Normatīvais fails: " . (string)($decision['normative_file'] ?? '') .
    " | Atsauce: " . (string)($decision['atsauce'] ?? '');
}

/** needChoice */
$needChoice = (
  $buvesTips_eff === 'nezinu' ||
  $buvesGrupa_eff === 'nezinu' || $buvesGrupa_eff === '' ||
  $objekts === '*' ||
  $darbiba === '*' ||
  !$decision
);

/** Dropdown dati */
$objektsOptions = list_entity_values($pdo, 'objekts');
$darbibaOptions = list_entity_values($pdo, 'darbu_veids');

$buvesTipsOptions  = ['eka' => 'ēka', 'inzenierbuve' => 'inženierbūve'];
$buvesGrupaOptions = ['1' => '1. grupa', '2' => '2. grupa', '3' => '3. grupa'];

/** kļūdas */
$errors = [];
if ($needChoice) {
  if ($buvesTips_eff === 'nezinu')  $errors[] = "Nav noteikts būves tips. Lūdzu izvēlies būves tipu.";
  if ($buvesGrupa_eff === 'nezinu' || $buvesGrupa_eff === '') $errors[] = "Nav noteikta būves grupa. Lūdzu izvēlies būves grupu.";
  if ($objekts === '*')            $errors[] = "Nav noteikts objekts. Lūdzu izvēlies objekta veidu.";
  if ($darbiba === '*')            $errors[] = "Nav noteikts darbu veids. Lūdzu izvēlies darbu veidu.";
  if (!$decision)                  $errors[] = "Nav atrasts BIS lēmums (embpd_decision_rules). Precizē izvēles, lai atrastu atbilstošu ierakstu.";
}

/** preview teksti */
$contextFacts  = "LIETOTĀJA NORĀDĪTIE FAKTI:\n";
$contextFacts .= "- Būves tips (izvēlēts): " . ($buvesTips_user !== 'nezinu' ? $buvesTips_user : "nav norādīts") . "\n";
$contextFacts .= "- Būves grupa (izvēlēta): " . ($buvesGrupa_user !== 'nezinu' ? ($buvesGrupa_user . ". grupa") : "nav norādīta") . "\n";

$contextFacts .= "\nSISTĒMAS NOTEIKTAIS (auto / izmantosim):\n";
$contextFacts .= "- Būves tips (efektīvi): " . ($buvesTips_eff !== 'nezinu' ? $buvesTips_eff : "nav zināms") . "\n";
$contextFacts .= "- Būves grupa (efektīvi): " . ($buvesGrupa_eff !== 'nezinu' && $buvesGrupa_eff !== '' ? ($buvesGrupa_eff . ". grupa") : "nav zināms") . "\n";
$contextFacts .= "- Objekts (no DB): " . ($objekts !== '' ? $objekts : '*') . "\n";
$contextFacts .= "- Darbu veids (no DB): " . ($darbiba !== '' ? $darbiba : '*') . "\n";
if ($darbiba !== '*' && $darbiba_for_decision !== $darbiba) {
  $contextFacts .= "- Darbu veids (normalizēts decision DB): " . $darbiba_for_decision . "\n";
}

$letterInfo  = "VĒSTULEI IZMANTOSIM:\n";
$letterInfo .= "- Jautājums: " . $prompt . "\n";
$letterInfo .= "- Izmantosim (no DB): " . $decisionText . "\n";

/** system */
$system = ($system_override_in !== '')
  ? $system_override_in
  : build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/** user payload preview (bez txt) */
$userPayload =
  (($user_override_in !== '') ? $user_override_in : $prompt)
  . "\n\n" . $contextFacts
  . "\n\n" . $letterInfo
  . "\n--- NORMATĪVIE AKTI (tiks pievienoti automātiski no izvēlētajiem TXT failiem) ---\n"
  . "Izvēlētie faili:\n- " . implode("\n- ", $selected) . "\n";
?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8"/>
  <title>Prompt apstiprināšana</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:980px;margin:30px auto;padding:16px}
    textarea,input,select,button{width:100%;padding:10px;margin:8px 0}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .muted{color:#666;font-size:12px}
    .box{border:1px solid #ddd;border-radius:10px;padding:12px;margin:12px 0;background:#fafafa}
    button{cursor:pointer}
    ul{margin:8px 0 0 18px}
    .err{border:1px solid #ffb3b3;background:#fff5f5;color:#7a0000;padding:10px;border-radius:10px;margin:12px 0}
    .ok{border:1px solid #b9e6c0;background:#f3fff5;color:#0b4d16;padding:10px;border-radius:10px;margin:12px 0}
    button[disabled]{opacity:.55;cursor:not-allowed}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  </style>
</head>
<body>

<h2>✅ Pirms sūtīšanas uz ChatGPT: apstiprini PROMPT</h2>
<div class="muted">
  TXT saturs šeit netiek rādīts. Sūtīšanas brīdī sistēma automātiski pievienos TXT saturu no izvēlētajiem failiem.
</div>

<?php if ($needChoice): ?>
  <div class="err">
    <b>⚠️ Nepietiek informācijas, lai droši noteiktu iesniedzamo ieceri.</b><br>
    Lūdzu izvēlies trūkstošos laukus zemāk. Kamēr nav izvēlēts, nosūtīt nevarēs.
    <?php if ($errors): ?>
      <ul>
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="ok">✅ Sistēma ir noteikusi nepieciešamos parametrus. Vari sūtīt.</div>
<?php endif; ?>

<div class="box">
  <b>Izvēlētie TXT faili (tiks nodoti ChatGPT):</b>
  <ul><?php foreach ($selected as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
</div>

<!-- ✅ refresh form -->
<form method="POST" action="<?= h(APP_BASE) ?>/preview.php" id="refreshForm">
  <input type="hidden" name="prompt" value="<?= h($prompt) ?>">
  <input type="hidden" name="forma" value="<?= h($forma) ?>">
  <input type="hidden" name="datums" value="<?= h($datums) ?>">

  <!-- USER izvēle (saglabājam, bet eff mēs turam atsevišķi) -->
  <input type="hidden" name="buves_tips" value="<?= h($buvesTips_user) ?>">
  <input type="hidden" name="buves_grupa" value="<?= h($buvesGrupa_user) ?>">

  <!-- textarea saturs -->
  <input type="hidden" name="system_override" id="system_override_h" value="<?= h($system_override_in) ?>">
  <input type="hidden" name="user_override" id="user_override_h" value="<?= h($user_override_in) ?>">

  <!-- eff izvēles -->
  <input type="hidden" name="buves_tips_eff" id="buves_tips_eff_h" value="<?= h(($buvesTips_eff !== 'nezinu' ? $buvesTips_eff : '')) ?>">
  <input type="hidden" name="buves_grupa_eff" id="buves_grupa_eff_h" value="<?= h(($buvesGrupa_eff !== 'nezinu' ? (string)$buvesGrupa_eff : '')) ?>">
  <input type="hidden" name="objekts_eff" id="objekts_eff_h" value="<?= h(($objekts !== '*' ? $objekts : '')) ?>">
  <input type="hidden" name="darbiba_eff" id="darbiba_eff_h" value="<?= h(($darbiba !== '*' ? $darbiba : '')) ?>">

  <?php foreach ($selected as $f): ?>
    <input type="hidden" name="norm_file[]" value="<?= h($f) ?>">
  <?php endforeach; ?>
</form>

<!-- ✅ send form -->
<form method="POST" action="<?= h(APP_BASE) ?>/start.php" id="sendForm">

  <div class="box">
    <b>✅ Parametri (mainot izvēles, preview pārrēķinās “Izmantosim (no DB)”) </b>

    <div class="grid2">
      <div>
        <label><b>Būves tips</b></label>
        <select id="buves_tips_eff" name="buves_tips_eff" required>
          <option value="">-- Izvēlies --</option>
          <?php foreach ($buvesTipsOptions as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ($buvesTips_eff === $k ? 'selected' : '') ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label><b>Būves grupa</b></label>
        <select id="buves_grupa_eff" name="buves_grupa_eff" required>
          <option value="">-- Izvēlies --</option>
          <?php foreach ($buvesGrupaOptions as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= ((string)$buvesGrupa_eff === (string)$k ? 'selected' : '') ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label><b>Objekts (ēkas veids)</b></label>
    <select name="objekts_eff" id="objekts_eff" required>
      <option value="*">-- Izvēlies --</option>
      <?php foreach ($objektsOptions as $opt): ?>
        <option value="<?= h($opt) ?>" <?= ($objekts === $opt ? 'selected' : '') ?>><?= h($opt) ?></option>
      <?php endforeach; ?>
    </select>

    <label><b>Darbu veids</b></label>
    <select name="darbiba_eff" id="darbiba_eff" required>
      <option value="*">-- Izvēlies --</option>
      <?php foreach ($darbibaOptions as $opt): ?>
        <option value="<?= h($opt) ?>" <?= ($darbiba === $opt ? 'selected' : '') ?>><?= h($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="box">
    <b>SYSTEM PROMPT</b>
    <textarea name="system_override" id="system_override" rows="16"><?= h($system) ?></textarea>
  </div>

  <div class="box">
    <b>USER PAYLOAD (jautājums + fakti)</b>
    <textarea name="user_override" id="user_override" rows="14"><?= h($userPayload) ?></textarea>
  </div>

  <!-- Oriģinālie ievadi -->
  <input type="hidden" name="prompt" value="<?= h($prompt) ?>">
  <input type="hidden" name="forma" value="<?= h($forma) ?>">
  <input type="hidden" name="datums" value="<?= h($datums) ?>">

  <!-- USER izvēle -->
  <input type="hidden" name="buves_tips_user" value="<?= h($buvesTips_user) ?>">
  <input type="hidden" name="buves_grupa_user" value="<?= h($buvesGrupa_user) ?>">

  <?php foreach ($selected as $f): ?>
    <input type="hidden" name="norm_file[]" value="<?= h($f) ?>">
  <?php endforeach; ?>

  <div class="row">
    <button type="submit" id="submitBtn">✅ Apstiprināt un sūtīt</button>
    <button type="button" onclick="history.back()">↩️ Atpakaļ</button>
  </div>
</form>

<script>
(function(){
  const btn = document.getElementById('submitBtn');

  function syncTextareasToRefreshForm() {
    document.getElementById('system_override_h').value = document.getElementById('system_override').value;
    document.getElementById('user_override_h').value   = document.getElementById('user_override').value;
  }

  function refreshPreviewFromSelects() {
    syncTextareasToRefreshForm();

    const o  = document.getElementById('objekts_eff').value;
    const d  = document.getElementById('darbiba_eff').value;
    const bt = document.getElementById('buves_tips_eff').value;
    const bg = ddocument.getElementById('buves_grupa_eff_sel').value;

    document.getElementById('objekts_eff_h').value = (o !== '*' ? o : '');
    document.getElementById('darbiba_eff_h').value = (d !== '*' ? d : '');
    document.getElementById('buves_tips_eff_h').value = bt;
    document.getElementById('buves_grupa_eff_h').value = bg;

    document.getElementById('refreshForm').submit();
  }

  function validateSend() {
    const o  = document.getElementById('objekts_eff').value;
    const d  = document.getElementById('darbiba_eff').value;
    const bt = document.getElementById('buves_tips_eff').value;
    const bg = document.getElementById('buves_grupa_eff_sel').value;

    const ok = (bt !== '' && bg !== '' && o !== '*' && d !== '*');
    // server-side vēl pārbaudīs decision, bet UI bloķē acīmredzami nepilnu
    btn.disabled = !ok;
  }

  // initial
  validateSend();

  // on change => validate + refresh
  ['objekts_eff','darbiba_eff','buves_tips_eff','buves_grupa_eff'].forEach(id=>{
    document.getElementById(id).addEventListener('change', function(){
      validateSend();
      refreshPreviewFromSelects();
    });
  });

  document.getElementById('sendForm').addEventListener('submit', function(e){
    validateSend();
    if (btn.disabled) {
      e.preventDefault();
      alert('Lūdzu aizpildi būves tipu, grupu, objektu un darbu veidu, lai varētu nosūtīt.');
    }
  });
})();
</script>

</body>
</html>

