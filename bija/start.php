<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';
require_once __DIR__ . '/utils/normfiles.php';
require_once __DIR__ . '/utils/intent.php';
require_once __DIR__ . '/utils/entities.php';
require_once __DIR__ . '/utils/openai.php';
require_once __DIR__ . '/utils/citations.php';
require_once __DIR__ . '/utils/norm_validate.php';

$pdo = db();

$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($prompt === '') { header("Location: " . APP_BASE . "/"); exit; }

$forma = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');

$buvesTips  = (string)($_POST['buves_tips'] ?? 'nezinu');   // eka | inzenierbuve | nezinu
$buvesGrupa = (string)($_POST['buves_grupa'] ?? 'nezinu');  // 1|2|3|nezinu

$systemOverride = trim((string)($_POST['system_override'] ?? ''));
$userOverride   = trim((string)($_POST['user_override'] ?? ''));

$session = bin2hex(random_bytes(16));
set_session_cookie($session);

/** Izvēlētie normatīvie faili */
$allFiles = list_norm_files();
$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

/** Auto-detect (ja nav ieķeksēts) */
if ($buvesTips === 'nezinu')  $buvesTips  = detect_entity($pdo, 'buves_tips',  $prompt, 'nezinu');
if ($buvesGrupa === 'nezinu') $buvesGrupa = detect_entity($pdo, 'buves_grupa', $prompt, 'nezinu');

/** Hard filtrēšana pēc būves tipa (lai neizvēlas pretējos būvnoteikumus) */
if ($buvesTips === 'eka') {
  $selected = array_values(array_filter($selected, fn($f) => !str_contains(mb_strtolower((string)$f), 'atsevisku-inzenierbuvju-buvnoteikumi')));
  if (count($selected) === 0) $selected = $allFiles;
}
if ($buvesTips === 'inzenierbuve') {
  $selected = array_values(array_filter($selected, fn($f) => !str_contains(mb_strtolower((string)$f), 'eku-buvnoteikumi')));
  if (count($selected) === 0) $selected = $allFiles;
}

/** Saglabājam norm izvēli pie sesijas */
$pdo->prepare("DELETE FROM embpd_norm_prefs WHERE session_id=?")->execute([$session]);
$stIns = $pdo->prepare("INSERT INTO embpd_norm_prefs(session_id, file) VALUES (?,?)");
foreach ($selected as $f) $stIns->execute([$session, $f]);

/** Rules no DB */
$rulePack = apply_rules($pdo, $prompt);

/** extraRules no DB actions */
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

/** Datums LV */
$datumsLv = '';
if ($datums !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $datums);
  if ($dt) $datumsLv = $dt->format('Y. gada m. d.');
}

/** Normatīvu konteksts (TXT saturs tikai sūtīšanai uz GPT) */
$normContext = build_norm_context($selected);
$normContext = normalize_prim_markers($normContext);

/** Lietotāja fakti (vienmēr ieliekam) */
$contextFacts = "LIETOTĀJA NORĀDĪTIE FAKTI:\n";
$contextFacts .= "- Būves tips: " . ($buvesTips === 'eka' ? "ēka" : ($buvesTips === 'inzenierbuve' ? "inženierbūve" : "nav zināms")) . "\n";
$contextFacts .= "- Būves grupa: " . ($buvesGrupa !== 'nezinu' ? ($buvesGrupa . ". grupa") : "nav zināms") . "\n";

/** Izvēlēto failu saraksts (bez satura) */
$selectedList = "IZVĒLĒTIE NORMATĪVIE FAILI (tiks pievienoti kā TXT saturs zemāk):\n";
foreach ($selected as $f) $selectedList .= "- " . $f . "\n";

/** SYSTEM: no preview override vai ģenerēts */
$system = ($systemOverride !== '')
  ? $systemOverride
  : build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/** USER: ja userOverride ir, izmanto to, BET fakti + failu saraksts vienmēr tiek pielikts */
$baseUser = ($userOverride !== '') ? $userOverride : $prompt;

/** Final user payload (ko sūta GPT un ko saglabā vēsturē) */
$finalUserPayload =
  $baseUser
  . "\n\n" . $contextFacts
  . "\n" . $selectedList;

/** ✅ Saglabājam LIETOTĀJA ZIŅU jau ar faktiem (lai turpinājumos viss stabils) */
$pdo->prepare("INSERT INTO embpd_chats(session_id, role, content) VALUES (?,?,?)")
  ->execute([$session, 'user', $finalUserPayload]);

/** Messages GPT */
$messages = [
  ["role" => "system", "content" => $system],
  ["role" => "user", "content" =>
    $finalUserPayload
    . "\n\n--- NORMATĪVIE AKTI (TXT, izvēlētie faili) ---\n"
    . $normContext
  ]
];

try {
  $assistant = call_openai($messages);

  // hard validācija (pārraksta, ja neatļauts akts)
  $assistant = ensure_allowed_norm_act($messages, $assistant, $selected);

  // citāts (pievieno [CITĀTS] bloku)
  $assistant = append_full_citation($assistant, $selected);

} catch (Throwable $e) {
  $assistant = "⚠️ Kļūda: " . $e->getMessage();
}

/** Saglabājam asistenta atbildi */
$pdo->prepare("INSERT INTO embpd_chats(session_id, role, content) VALUES (?,?,?)")
  ->execute([$session, 'assistant', $assistant]);

header("Location: " . APP_BASE . "/");
exit;

