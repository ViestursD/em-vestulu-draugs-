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
require_once __DIR__ . '/utils/entities.php';
require_once __DIR__ . '/utils/openai.php';
require_once __DIR__ . '/utils/citations.php';
require_once __DIR__ . '/utils/norm_validate.php';
require_once __DIR__ . '/utils/buves_grupa.php';
require_once __DIR__ . '/utils/decision.php';

$pdo = db();

/** =========================
 * DEBUG (UI žurnāls)
 * ========================= */
$DEBUG_UI = true; // uzliec false, kad vairs nevajag
$debugSteps = [];
$debugAdd = function(string $title, $data = null) use (&$debugSteps) {
  $debugSteps[] = [
    't' => date('Y-m-d H:i:s'),
    'title' => $title,
    'data' => $data,
  ];
};

function normalize_darbiba_for_decision(string $darbiba): string {
  $d = trim($darbiba);
  if ($d === 'jauna_buvnieciba') return 'jaunbuvnieciba';
  return $d;
}
function normalize_buves_tips_for_decision(string $tips): string {
  $t = trim($tips);
  if ($t === 'ēka') return 'eka';
  if ($t === 'inženierbūve') return 'inzenierbuve';
  return $t;
}

/** nosūtāmais */
$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($prompt === '') {
  header("Location: " . APP_BASE . "/?err=" . urlencode("Nav ievadīts jautājums."));
  exit;
}

/** jauns vai turpinājums */
$isNew = ((string)($_POST['new_thread'] ?? '') === '1');

$session = trim((string)($_POST['session'] ?? ''));
if ($isNew) {
  $session = bin2hex(random_bytes(16));
  set_session_cookie($session);
} else {
  if ($session === '') $session = (string)get_cookie_session();
  if ($session === '') {
    header("Location: " . APP_BASE . "/?err=" . urlencode("Nav sesijas."));
    exit;
  }
}

$topic = (string)($_POST['topic'] ?? 'cits');
$forma  = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');
$buvesTips = (string)($_POST['buves_tips'] ?? '');
$buvesGrupa = (string)($_POST['buves_grupa'] ?? '');
$kult = (string)($_POST['kulturas_piemineklis'] ?? '*');
$objekts = (string)($_POST['objekts'] ?? '');
$darbiba = (string)($_POST['darbiba'] ?? '');

$debugAdd("POST saņemts", [
  'isNew' => $isNew,
  'session' => $session,
  'topic' => $topic,
  'forma' => $forma,
  'datums' => $datums,
  'buvesTips' => $buvesTips,
  'buvesGrupa' => $buvesGrupa,
  'kult' => $kult,
  'objekts' => $objekts,
  'darbiba' => $darbiba,
  'prompt' => $prompt,
]);

/** norm faili */
$allFiles = list_norm_files();
$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

$debugAdd("Normatīvie faili izvēlēti", [
  'selected_count' => count($selected),
  'selected' => $selected,
]);

/** pinned context + decision */
$pinned = '';
$decision = null;

/** jauns thread: ja ieceres tēma -> izveido pinned + atrod decision */
if ($isNew && $topic === 'ieceres_dokumentacija') {

  if ($buvesTips === '' || $buvesGrupa === '' || $objekts === '' || $darbiba === '') {
    header("Location: " . APP_BASE . "/?err=" . urlencode("Ieceres dokumentācijai obligāti: būves tips, būves grupa, objekts, darbu veids."));
    exit;
  }

  $buvesTipsN = normalize_buves_tips_for_decision($buvesTips);
  $darbibaN   = normalize_darbiba_for_decision($darbiba);

  $pinned  = "TĒMA: Ieceres dokumentācija (BIS iesniegums)\n";
  $pinned .= "Būves tips: {$buvesTipsN}\n";
  $pinned .= "Būves grupa: {$buvesGrupa}\n";
  $pinned .= "Objekts: {$objekts}\n";
  $pinned .= "Darbu veids: {$darbibaN}\n";
  $pinned .= "Kultūras piemineklis: {$kult}\n";

  $debugAdd("Pinned konteksts uzbūvēts", $pinned);

  $pdo->prepare("INSERT INTO embpd_threads(session_id, title, topic, pinned_context) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE title=VALUES(title), topic=VALUES(topic), pinned_context=VALUES(pinned_context), updated_at=CURRENT_TIMESTAMP")
      ->execute([$session, 'Čats', $topic, $pinned]);

  // ✅ JAUNĀ LOĢIKA: viena atlase ar TOP kandidātu debug (fallback uz no/yes vairs nevajag)
  $top = [];
  $decision = pick_decision_rule(
    $pdo,
    $buvesTipsN,
    (string)$buvesGrupa,
    $objekts ?: '*',
    $darbibaN ?: '*',
    $kult ?: '*',
    $top
  );
  $debugAdd("DB kandidāti (TOP 10)", $top);
  $debugAdd("DB lēmums no pick_decision_rule()", $decision);

  if (!$decision) {
    header("Location: " . APP_BASE . "/?err=" . urlencode("Nav atrasts BIS lēmums (embpd_decision_rules). Pārbaudi: tips/grupa/objekts/darbība/piemineklis."));
    exit;
  }

  $decisionNormFile = (string)($decision['normative_file'] ?? '');
  if ($decisionNormFile !== '' && !in_array($decisionNormFile, $selected, true)) {
    $selected[] = $decisionNormFile;
    $selected = array_values(array_unique($selected));
    $debugAdd("Pievienots DB noteiktais normatīvais fails", $decisionNormFile);
  }
}

/** turpinājumā paņemam pinned + topic no DB */
if (!$isNew) {
  $stp = $pdo->prepare("SELECT pinned_context, topic FROM embpd_threads WHERE session_id=? LIMIT 1");
  $stp->execute([$session]);
  $row = $stp->fetch(PDO::FETCH_ASSOC) ?: [];
  $pinned = (string)($row['pinned_context'] ?? '');
  $topic  = (string)($row['topic'] ?? $topic);
  $debugAdd("Turpinājums: ielādēts pinned + topic no DB", ['topic'=>$topic, 'pinned'=>$pinned]);
}

/** turpinājumā: ja ieceres tēma un vēl nav decision -> pārrēķinam no pinned */
if (!$decision && $topic === 'ieceres_dokumentacija' && $pinned !== '') {
  $bt = ''; $bg = ''; $ob = ''; $dv = ''; $kp = '';
  foreach (explode("\n", $pinned) as $line) {
    $line = trim($line);
    if (str_starts_with($line, "Būves tips:")) $bt = trim(substr($line, strlen("Būves tips:")));
    if (str_starts_with($line, "Būves grupa:")) $bg = trim(substr($line, strlen("Būves grupa:")));
    if (str_starts_with($line, "Objekts:")) $ob = trim(substr($line, strlen("Objekts:")));
    if (str_starts_with($line, "Darbu veids:")) $dv = trim(substr($line, strlen("Darbu veids:")));
    if (str_starts_with($line, "Kultūras piemineklis:")) $kp = trim(substr($line, strlen("Kultūras piemineklis:")));
  }

  $parsed = ['bt'=>$bt,'bg'=>$bg,'ob'=>$ob,'dv'=>$dv,'kp'=>$kp];
  $debugAdd("Turpinājums: parsēts no pinned", $parsed);

  if ($bt !== '' && $bg !== '' && $ob !== '' && $dv !== '') {
    $top2 = [];
    $decision = pick_decision_rule($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', $kp ?: '*', $top2);
    $debugAdd("Turpinājums: DB kandidāti (TOP 10)", $top2);
  }

  $debugAdd("Turpinājums: decision pārrēķināts", $decision);
}

/** saglabājam norm izvēli pie sesijas */
$pdo->prepare("DELETE FROM embpd_norm_prefs WHERE session_id=?")->execute([$session]);
$stIns = $pdo->prepare("INSERT INTO embpd_norm_prefs(session_id, file) VALUES (?,?)");
foreach ($selected as $f) $stIns->execute([$session, $f]);

/** datums LV */
$datumsLv = '';
if ($datums !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $datums);
  if ($dt instanceof DateTime) {
    $monthsLv = [1=>'janvāris',2=>'februāris',3=>'marts',4=>'aprīlis',5=>'maijs',6=>'jūnijs',7=>'jūlijs',8=>'augusts',9=>'septembris',10=>'oktobris',11=>'novembris',12=>'decembris'];
    $m = (int)$dt->format('n');
    $datumsLv = $dt->format('Y') . ". gada " . $dt->format('j') . ". " . ($monthsLv[$m] ?? $dt->format('F'));
  }
}

/** rules */
$rulePack = apply_rules($pdo, $prompt);
$extraRules = '';
foreach (($rulePack['actions'] ?? []) as $a) {
  if (($a['scope'] ?? 'system') === 'system') $extraRules .= "- " . trim((string)($a['text'] ?? '')) . "\n";
}
$extraRules .= "- Drīkst apgalvot TIKAI to, kas ir pievienotajos TXT normatīvos. Ja termins/nav prasība nav TXT, to NEDRĪKST minēt.\n";
$extraRules .= "- Ja nevar atrast konkrētu atsauci TXT, [NORMATĪVAIS_PAMATOJUMS] blokā raksti: Atsauce: nav noteikta.\n";
$extraRules .= "- Aizliegts minēt 'apliecinājuma karte', ja tā nav tieši atrodama un citējama no pievienotajiem TXT.\n";

if ($decision) {
  $doc = (string)($decision['doc_type'] ?? '');
  $extraRules .= "- BIS lēmuma dokumenta veids IR OBLIGĀTS un nemaināms: {$doc}. Nekādā gadījumā nedrīkst piedāvāt citu dokumentu veidu.\n";
}

$debugAdd("extraRules (apkopots)", $extraRules);

/** normatīvu konteksts */
$normContext = build_norm_context($selected);
$normContext = normalize_prim_markers($normContext);

/** SISTĒMA */
$system = build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/** būvējam contextBlock */
$contextBlock = '';
if ($pinned !== '') {
  $contextBlock .= "PINNED KONTEKSTS:\n" . $pinned . "\n";
}
if ($decision) {
  $contextBlock .= "BIS LĒMUMS (no DB):\n";
  $contextBlock .= "- Dokuments: " . (string)($decision['doc_type'] ?? '') . "\n";
  $contextBlock .= "- Normatīvais fails: " . (string)($decision['normative_file'] ?? '') . "\n";
  $contextBlock .= "- Atsauce: " . (string)($decision['atsauce'] ?? '') . "\n";
  $contextBlock .= "- Note: " . (string)($decision['note'] ?? '') . "\n";
  $contextBlock .= "GALVENAIS SECINĀJUMS: jāiesniedz " . (string)($decision['doc_type'] ?? '') . ".\n\n";
  $contextBlock .= "- Dokumenta veidu drīkst noteikt TIKAI no šī DB bloka.\n\n";
}

/** ✅ Saglabā user ziņu DB kopā ar kontekstu, lai UI un vēsture nepazūd */
$storedUser = $contextBlock . "JAUTĀJUMS:\n" . $prompt;
$pdo->prepare("INSERT INTO embpd_chats(session_id, role, content) VALUES (?,?,?)")
  ->execute([$session, 'user', $storedUser]);

/** Messages uz GPT */
$messages = [
  ["role" => "system", "content" => $system],
  ["role" => "user", "content" =>
    $contextBlock .
    "JAUTĀJUMS:\n" . $prompt . "\n\n" .
    "--- NORMATĪVIE AKTI (TXT, izvēlētie faili) ---\n" . $normContext
  ],
];

$debugAdd("Messages kopsavilkums", [
  'system_len' => strlen((string)$system),
  'user_len' => strlen((string)($messages[1]['content'] ?? '')),
  'has_pinned' => ($pinned !== ''),
  'has_decision' => (bool)$decision,
  'doc_type' => (string)($decision['doc_type'] ?? ''),
]);

try {
  $assistant = call_openai($messages);
  $assistant = ensure_allowed_norm_act($messages, $assistant, $selected);
  $assistant = append_full_citation($assistant, $selected);
  $debugAdd("Assistant atbilde (saīsināts)", mb_substr((string)$assistant, 0, 900));
} catch (Throwable $e) {
  $assistant = "⚠️ Kļūda: " . $e->getMessage();
  $debugAdd("Kļūda call_openai()", $e->getMessage());
}

$pdo->prepare("INSERT INTO embpd_chats(session_id, role, content) VALUES (?,?,?)")
  ->execute([$session, 'assistant', $assistant]);

/** Saglabā debug DB (lai chat.php var parādīt) */
if ($DEBUG_UI) {
  try {
    $payload = json_encode($debugSteps, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $pdo->prepare("INSERT INTO embpd_debug_log(session_id, payload) VALUES (?,?)")
        ->execute([$session, $payload]);
  } catch (Throwable $e) {
    // ja nav tabulas vai nav tiesību, nelaužam čatu
    error_log("[DEBUG_LOG_FAIL] " . $e->getMessage());
  }
}

header("Location: " . APP_BASE . "/chat.php?session=" . urlencode($session));
exit;

