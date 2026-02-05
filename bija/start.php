<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

mb_internal_encoding('UTF-8');

/**
 * ✅ NORMALIZĀCIJAS slānis starp embpd_entity_rules un embpd_decision_rules
 * Jo vērtības var atšķirties (piem., jauna_buvnieciba vs jaunbuvnieciba).
 */
function normalize_darbiba_for_decision(string $darbiba): string {
  $d = trim($darbiba);
  if ($d === 'jauna_buvnieciba') return 'jaunbuvnieciba'; // FIX: saskaņo ar embpd_decision_rules
  return $d;
}
function normalize_buves_tips_for_decision(string $tips): string {
  $t = trim($tips);
  // drošībai (ja kaut kur ievazājas 'ēka' vs 'eka')
  if ($t === 'ēka') return 'eka';
  if ($t === 'inženierbūve') return 'inzenierbuve';
  return $t;
}

require_once __DIR__ . '/.env.php';
require_once __DIR__ . '/utils/db.php';
require_once __DIR__ . '/utils/text.php';
require_once __DIR__ . '/utils/normfiles.php';
require_once __DIR__ . '/utils/intent.php';
require_once __DIR__ . '/utils/entities.php';
require_once __DIR__ . '/utils/openai.php';
require_once __DIR__ . '/utils/citations.php';
require_once __DIR__ . '/utils/norm_validate.php';
require_once __DIR__ . '/utils/buves_grupa.php';
require_once __DIR__ . '/utils/decision.php'; // pick_decision_rule()

$pdo = db();

/**
 * SOLO režīms:
 * - true  => vienmēr viena sesija "solo", netiek likts cookie
 * - false => katram pieprasījumam jauna sesija + cookie
 */
$SOLO_MODE = false;

$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($prompt === '') {
  header("Location: " . APP_BASE . "/");
  exit;
}

$forma  = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');

/**
 * ✅ USER izvēle (no preview hidden laukiem)
 */
$buvesTips_user  = (string)($_POST['buves_tips_user']  ?? ($_POST['buves_tips']  ?? 'nezinu'));
$buvesGrupa_user = (string)($_POST['buves_grupa_user'] ?? ($_POST['buves_grupa'] ?? 'nezinu'));

/**
 * ✅ EFEKTĪVAIS (no preview)
 */
$buvesTips  = (string)($_POST['buves_tips_eff']  ?? ($_POST['buves_tips']  ?? 'nezinu'));
$buvesGrupa = (string)($_POST['buves_grupa_eff'] ?? ($_POST['buves_grupa'] ?? 'nezinu'));

/**
 * ✅ Objekts / darbība no preview
 */
$objekts = (string)($_POST['objekts_eff'] ?? '*');
$darbiba = (string)($_POST['darbiba_eff'] ?? '*');

$systemOverride = trim((string)($_POST['system_override'] ?? ''));
$userOverride   = trim((string)($_POST['user_override'] ?? ''));

/** Sesija */
if ($SOLO_MODE) {
  $session = 'solo';
} else {
  $session = bin2hex(random_bytes(16));
  set_session_cookie($session);
}

/** Izvēlētie normatīvie faili */
$allFiles = list_norm_files();
$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

/**
 * ✅ Drošības fallback (ja kāds apiet preview)
 */
if ($buvesTips === 'nezinu') {
  $buvesTips = detect_entity($pdo, 'buves_tips', $prompt, 'nezinu');
}
if ($buvesGrupa === 'nezinu') {
  $buvesGrupa = determine_buves_grupa_from_rules($prompt);
}
if ($objekts === '' || $objekts === '*') {
  $objekts = detect_entity($pdo, 'objekts', $prompt, '*');
}
if ($darbiba === '' || $darbiba === '*') {
  $darbiba = detect_entity($pdo, 'darbu_veids', $prompt, '*');
}

/**
 * ✅ NORMALIZĀCIJA pirms decision lookup (SVAIGĀKAIS FIX)
 */
$buvesTips = normalize_buves_tips_for_decision($buvesTips);
if ($darbiba !== '' && $darbiba !== '*') {
  $darbiba = normalize_darbiba_for_decision($darbiba);
}

/**
 * ✅ BIS lēmums no DB (OBLIGĀTS)
 */
$decision = null;
if ($buvesTips !== 'nezinu' && $buvesGrupa !== 'nezinu') {
  $decision = pick_decision_rule($pdo, $buvesTips, (string)$buvesGrupa, $objekts ?: '*', $darbiba ?: '*');
}

if (!$decision) {
  header("Location: " . APP_BASE . "/?err=" . urlencode("Nav atrasts BIS lēmums (embpd_decision_rules). Precizē objekts/darbība/būves tips/grupa."));
  exit;
}

/**
 * ✅ Piespiežam decision normatīvo failu iekšā selected (lai var citēt)
 */
$decisionNormFile = (string)($decision['normative_file'] ?? '');
if ($decisionNormFile !== '' && !in_array($decisionNormFile, $selected, true)) {
  $selected[] = $decisionNormFile;
  $selected = array_values(array_unique($selected));
}

/** Hard filtrēšana pēc būves tipa (lai neizvēlas pretējos būvnoteikumus) */
if ($buvesTips === 'eka') {
  $selected = array_values(array_filter(
    $selected,
    fn($f) => !str_contains(mb_strtolower((string)$f), 'atsevisku-inzenierbuvju-buvnoteikumi')
  ));
  if (count($selected) === 0) $selected = $allFiles;
}
if ($buvesTips === 'inzenierbuve') {
  $selected = array_values(array_filter(
    $selected,
    fn($f) => !str_contains(mb_strtolower((string)$f), 'eku-buvnoteikumi')
  ));
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

/** ✅ SUPER IMPORTANT: dokumentu veidu drīkst ņemt tikai no DB lēmuma */
$extraRules .= "- Dokumenta veidu (piem., PAZ / PASKAIDROJUMA RAKSTS / BŪVATĻAUJA) drīkst noteikt TIKAI no bloka 'BIS LĒMUMS (no DB)'. Aizliegts minēt citu dokumentu.\n";

/** Datums LV */
$datumsLv = '';
if ($datums !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $datums);
  if ($dt instanceof DateTime) {
    $monthsLv = [
      1=>'janvāris', 2=>'februāris', 3=>'marts', 4=>'aprīlis', 5=>'maijs', 6=>'jūnijs',
      7=>'jūlijs', 8=>'augusts', 9=>'septembris', 10=>'oktobris', 11=>'novembris', 12=>'decembris'
    ];
    $m = (int)$dt->format('n');
    $datumsLv = $dt->format('Y') . ". gada " . $dt->format('j') . ". " . ($monthsLv[$m] ?? $dt->format('F'));
  }
}

/** Normatīvu konteksts (TXT saturs tikai sūtīšanai uz GPT) */
$normContext = build_norm_context($selected);
$normContext = normalize_prim_markers($normContext);

/** Lietotāja fakti */
$contextFacts  = "LIETOTĀJA NORĀDĪTIE FAKTI:\n";
$contextFacts .= "- Būves tips (izvēlēts): " . ($buvesTips_user !== 'nezinu' ? $buvesTips_user : "nav norādīts") . "\n";
$contextFacts .= "- Būves grupa (izvēlēta): " . ($buvesGrupa_user !== 'nezinu' ? ($buvesGrupa_user . ". grupa") : "nav norādīta") . "\n";

$contextFacts .= "\nSISTĒMAS NOTEIKTAIS (auto / izmantosim):\n";
$contextFacts .= "- Būves tips (efektīvi): " . ($buvesTips !== 'nezinu' ? $buvesTips : "nav zināms") . "\n";
$contextFacts .= "- Būves grupa (efektīvi): " . ($buvesGrupa !== 'nezinu' ? ($buvesGrupa . ". grupa") : "nav zināms") . "\n";
$contextFacts .= "- Objekts (no DB): " . ($objekts !== '' ? $objekts : '*') . "\n";
$contextFacts .= "- Darbu veids (no DB): " . ($darbiba !== '' ? $darbiba : '*') . "\n";

/** ✅ BIS lēmums no DB (vienmēr būs, jo augšā STOP ja nav) */
$decisionText =
  "BIS LĒMUMS (no DB):\n" .
  "- Dokuments: " . (string)($decision['doc_type'] ?? '') . "\n" .
  "- Normatīvais fails: " . (string)($decision['normative_file'] ?? '') . "\n" .
  "- Atsauce: " . (string)($decision['atsauce'] ?? '') . "\n" .
  "- Note: " . (string)($decision['note'] ?? '') . "\n";

/** Izvēlēto failu saraksts (bez satura) */
$selectedList = "IZVĒLĒTIE NORMATĪVIE FAILI (tiks pievienoti kā TXT saturs zemāk):\n";
foreach ($selected as $f) $selectedList .= "- " . $f . "\n";

/** SYSTEM */
$system = ($systemOverride !== '')
  ? $systemOverride
  : build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/** USER */
$baseUser = ($userOverride !== '') ? $userOverride : $prompt;

/** Final user payload */
$finalUserPayload =
  $baseUser
  . "\n\n" . $contextFacts
  . "\n\n" . $decisionText
  . "\n" . $selectedList;

/** Saglabājam LIETOTĀJA ZIŅU */
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

/** ✅ Pēc nosūtīšanas aizved uz čatu ar atbildi */
header("Location: " . APP_BASE . "/chat.php?session=" . urlencode($session));
exit;

