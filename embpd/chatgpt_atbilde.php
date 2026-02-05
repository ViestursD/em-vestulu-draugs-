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
require_once __DIR__ . '/utils/normfiles.php';
require_once __DIR__ . '/utils/intent.php';
require_once __DIR__ . '/utils/openai.php';
require_once __DIR__ . '/utils/citations.php';
require_once __DIR__ . '/utils/norm_validate.php';

$pdo = db();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function datums_lv(?string $datums): string {
  $datums = trim((string)$datums);
  if ($datums === '') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $datums);
  if (!$dt) return '';
  $monthsLv = [
    1=>'janvāris', 2=>'februāris', 3=>'marts', 4=>'aprīlis', 5=>'maijs', 6=>'jūnijs',
    7=>'jūlijs', 8=>'augusts', 9=>'septembris', 10=>'oktobris', 11=>'novembris', 12=>'decembris'
  ];
  $m = (int)$dt->format('n');
  return $dt->format('Y') . ". gada " . $dt->format('j') . ". " . ($monthsLv[$m] ?? $dt->format('F'));
}

$allFiles = list_norm_files();

$sent = ($_SERVER['REQUEST_METHOD'] === 'POST');
$prompt = trim((string)($_POST['prompt'] ?? ''));
$forma  = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');

$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

$assistant = '';
$errorMsg = '';

if ($sent) {
  if ($prompt === '') {
    $errorMsg = "Lūdzu ievadi jautājumu.";
  } else if (count($selected) === 0) {
    $errorMsg = "Lūdzu izvēlies vismaz vienu normatīvo TXT failu.";
  } else {
    // Hard noteikumi pret halucinācijām
    $extraRules  = "- Normatīvos aktus drīkst izmantot TIKAI no lietotāja izvēlētajiem TXT failiem, kas iedoti kontekstā.\n";
    $extraRules .= "- Citātus drīkst veidot TIKAI no šiem TXT failiem.\n";
    $extraRules .= "- Ja nevar atrast konkrētu atsauci TXT, [NORMATĪVAIS_PAMATOJUMS] blokā raksti: Atsauce: nav noteikta.\n";
    $extraRules .= "- Drīkst apgalvot TIKAI to, kas ir pievienotajos TXT normatīvos. Ja termins/nav prasība nav TXT, to NEDRĪKST minēt.\n";

    $datumsLv = datums_lv($datums);

    // SYSTEM
    $system = build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

    // USER + saraksts
    $selectedList = "IZVĒLĒTIE NORMATĪVIE FAILI:\n";
    foreach ($selected as $f) $selectedList .= "- " . $f . "\n";

    $finalUserPayload =
      $prompt
      . "\n\n" . $selectedList;

    // TXT konteksts tikai izvēlētajiem failiem
    $normContext = build_norm_context($selected);
    $normContext = normalize_prim_markers($normContext);

    $messages = [
      ["role" => "system", "content" => $system],
      ["role" => "user", "content" =>
        $finalUserPayload
        . "\n\n--- NORMATĪVIE AKTI (TXT, izvēlētie faili) ---\n"
        . $normContext
      ],
    ];

    try {
      $assistant = call_openai($messages);

      // hard validācija (pārraksta, ja neatļauts akts)
      $assistant = ensure_allowed_norm_act($messages, $assistant, $selected);

      // citāts (pievieno [CITĀTS] bloku)
      $assistant = append_full_citation($assistant, $selected);

    } catch (Throwable $e) {
      $errorMsg = "⚠️ Kļūda: " . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="lv">
<head>
  <meta charset="utf-8"/>
  <title>ChatGPT atbilde</title>
  <style>
    body{font-family:Arial,sans-serif;max-width:980px;margin:30px auto;padding:16px}
    textarea,input,select,button{width:100%;padding:10px;margin:8px 0}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .muted{color:#666;font-size:12px}
    .box{border:1px solid #ddd;border-radius:10px;padding:12px;margin:12px 0;background:#fafafa}
    .err{border:1px solid #ffb3b3;background:#fff5f5;color:#7a0000;padding:10px;border-radius:10px;margin:12px 0}
    .ok{border:1px solid #b9e6c0;background:#f3fff5;color:#0b4d16;padding:10px;border-radius:10px;margin:12px 0}
    .files{max-height:220px;overflow:auto;border:1px solid #eee;background:#fff;border-radius:10px;padding:10px}
    label{display:block;margin:6px 0}
    .answer{white-space:pre-wrap}
  </style>
</head>
<body>

<h2>ChatGPT atbilde (tikai no izvēlētajiem TXT)</h2>
<div class="muted">
  Izvēlies normatīvos TXT failus, ievadi jautājumu un nosūti. Atbilde balstīsies tikai uz izvēlētajiem TXT.
</div>

<?php if ($errorMsg !== ''): ?>
  <div class="err"><?= h($errorMsg) ?></div>
<?php endif; ?>

<form method="POST">

  <div class="box">
    <b>Forma</b>
    <select name="forma">
      <option value="epasts" <?= $forma==='epasts'?'selected':'' ?>>E-pasts</option>
      <option value="vestule" <?= $forma==='vestule'?'selected':'' ?>>Vēstule</option>
    </select>

    <b>Saņemšanas datums (neobligāti)</b>
    <input type="date" name="datums" value="<?= h($datums) ?>">
  </div>

  <div class="box">
    <b>Normatīvie TXT faili (izvēlies)</b>
    <div class="muted">Ja neko neatzīmēsi, tiks izmantoti visi faili.</div>
    <div class="files">
      <?php foreach ($allFiles as $f): ?>
        <?php $checked = in_array($f, $selected, true); ?>
        <label>
          <input type="checkbox" name="norm_file[]" value="<?= h($f) ?>" <?= $checked?'checked':'' ?>>
          <?= h($f) ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="box">
    <b>Jautājums</b>
    <textarea name="prompt" rows="5" placeholder="Ievadi jautājumu..."><?= h($prompt) ?></textarea>
  </div>

  <div class="row">
    <button type="submit">🚀 Sagatavot atbildi</button>
    <a href="<?= h(APP_BASE) ?>/" style="display:block;text-align:center;padding:10px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#111;background:#fff">↩️ Atpakaļ</a>
  </div>
</form>

<?php if ($assistant !== ''): ?>
  <div class="ok"><b>Atbilde saņemta</b></div>
  <div class="box answer"><?= h($assistant) ?></div>
<?php endif; ?>

</body>
</html>
