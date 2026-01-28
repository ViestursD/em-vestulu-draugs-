<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';
require_once __DIR__ . '/utils/normfiles.php';
require_once __DIR__ . '/utils/intent.php';
require_once __DIR__ . '/utils/entities.php';   // detect_entity()
require_once __DIR__ . '/utils/openai.php';

$pdo = db();

$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($prompt === '') { header("Location: " . APP_BASE . "/"); exit; }

$forma = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');

$buvesTips  = (string)($_POST['buves_tips'] ?? 'nezinu');
$buvesGrupa = (string)($_POST['buves_grupa'] ?? 'nezinu');

$allFiles = list_norm_files();
$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

/** ✅ Auto-detect no DB, ja nav ieķeksēts */
if ($buvesTips === 'nezinu')  $buvesTips  = detect_entity($pdo, 'buves_tips',  $prompt, 'nezinu');
if ($buvesGrupa === 'nezinu') $buvesGrupa = detect_entity($pdo, 'buves_grupa', $prompt, 'nezinu');

/** ✅ Hard filtrēšana pēc būves tipa (lai izvēlēto failu kopa jau sākumā būtu tīra) */
if ($buvesTips === 'eka') {
  $selected = array_values(array_filter($selected, fn($f) => !str_contains(mb_strtolower((string)$f), 'atsevisku-inzenierbuvju-buvnoteikumi')));
  if (count($selected) === 0) $selected = $allFiles;
}
if ($buvesTips === 'inzenierbuve') {
  $selected = array_values(array_filter($selected, fn($f) => !str_contains(mb_strtolower((string)$f), 'eku-buvnoteikumi')));
  if (count($selected) === 0) $selected = $allFiles;
}

/** Rules no DB */
$rulePack = apply_rules($pdo, $prompt);
$extraRules = '';
foreach ($rulePack['actions'] as $a) {
  if (($a['scope'] ?? 'system') === 'system') {
    $extraRules .= "- " . trim((string)$a['text']) . "\n";
  }
}

/** Hard noteikumi pret halucinācijām */
$extraRules .= "- Drīkst apgalvot TIKAI to, kas ir pievienotajos TXT normatīvos. Ja termins/nav prasība nav TXT, to NEDRĪKST minēt.\n";
$extraRules .= "- Ja nevar atrast konkrētu atsauci TXT, [NORMATĪVAIS_PAMATOJUMS] blokā raksti: Atsauce: nav noteikta.\n";
$extraRules .= "- Aizliegts minēt 'apliecinājuma karte', ja tā nav tieši atrodama un citējama no pievienotajiem TXT.\n";

/** Datums LV */
$datumsLv = '';
if ($datums !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $datums);
  if ($dt) $datumsLv = $dt->format('Y. gada m. d.');
}

/** ✅ Lietotāja norādītie fakti (ko redz preview) */
$contextFacts = "LIETOTĀJA NORĀDĪTIE FAKTI:\n";
$contextFacts .= "- Būves tips: " . ($buvesTips === 'eka' ? "ēka" : ($buvesTips === 'inzenierbuve' ? "inženierbūve" : "nav zināms")) . "\n";
$contextFacts .= "- Būves grupa: " . ($buvesGrupa !== 'nezinu' ? ($buvesGrupa . ". grupa") : "nav zināms") . "\n";

/** System prompt (ko var labot preview) */
$system = build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/**
 * ✅ USER PAYLOAD preview: NERĀDĀM TXT SATURU.
 * Tikai: jautājums + fakti + izvēlēto TXT failu saraksts.
 */
$userPayload =
  $prompt
  . "\n\n" . $contextFacts
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
  </style>
</head>
<body>

<h2>✅ Pirms sūtīšanas uz ChatGPT: apstiprini PROMPT</h2>
<div class="muted">
  TXT saturs šeit netiek rādīts. Sūtīšanas brīdī sistēma automātiski pievienos TXT saturu no izvēlētajiem failiem.
</div>

<div class="box">
  <b>Izvēlētie TXT faili (tiks nodoti ChatGPT):</b>
  <ul>
    <?php foreach ($selected as $f): ?>
      <li><?= htmlspecialchars($f, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
    <?php endforeach; ?>
  </ul>
</div>

<form method="POST" action="<?= APP_BASE ?>/start.php">

  <div class="box">
    <b>SYSTEM PROMPT</b>
    <textarea name="system_override" rows="16"><?= htmlspecialchars($system, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
  </div>

  <div class="box">
    <b>USER PAYLOAD (jautājums + fakti)</b>
    <textarea name="user_override" rows="14"><?= htmlspecialchars($userPayload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
  </div>

  <!-- oriģinālie lauki -->
  <input type="hidden" name="prompt" value="<?= htmlspecialchars($prompt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <input type="hidden" name="forma" value="<?= htmlspecialchars($forma, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <input type="hidden" name="datums" value="<?= htmlspecialchars($datums, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <input type="hidden" name="buves_tips" value="<?= htmlspecialchars($buvesTips, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <input type="hidden" name="buves_grupa" value="<?= htmlspecialchars($buvesGrupa, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

  <?php foreach ($selected as $f): ?>
    <input type="hidden" name="norm_file[]" value="<?= htmlspecialchars($f, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <?php endforeach; ?>

  <div class="row">
    <button type="submit">✅ Apstiprināt un sūtīt</button>
    <button type="button" onclick="history.back()">↩️ Atpakaļ</button>
  </div>

</form>
</body>
</html>

