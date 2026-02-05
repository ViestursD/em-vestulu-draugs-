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
if ($prompt === '') { header("Location: " . APP_BASE . "/?err=" . urlencode("Nav ievadīts jautājums.")); exit; }

/** jauns vai turpinājums */
$isNew = ((string)($_POST['new_thread'] ?? '') === '1');

$session = trim((string)($_POST['session'] ?? ''));
if ($isNew) {
  $session = bin2hex(random_bytes(16));
  set_session_cookie($session);
} else {
  if ($session === '') $session = (string)get_cookie_session();
  if ($session === '') { header("Location: " . APP_BASE . "/?err=" . urlencode("Nav sesijas.")); exit; }
}

$topic = (string)($_POST['topic'] ?? 'cits');
$forma  = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');
$buvesTips = (string)($_POST['buves_tips'] ?? '');
$buvesGrupa = (string)($_POST['buves_grupa'] ?? '');
$kult = (string)($_POST['kulturas_piemineklis'] ?? '*');
$objekts = (string)($_POST['objekts'] ?? '');
$darbiba = (string)($_POST['darbiba'] ?? '');

/** norm faili */
$allFiles = list_norm_files();
$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

/** pinned context (tikai jaunam thread, ja iecere) */
$pinned = '';
$decision = null;

if ($isNew && $topic === 'ieceres_dokumentacija') {

  // obligāti lauki
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

  // saglabājam thread pinned_context
  $pdo->prepare("INSERT INTO embpd_threads(session_id, topic, pinned_context) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE topic=VALUES(topic), pinned_context=VALUES(pinned_context)")
      ->execute([$session, $topic, $pinned]);

  // atrodam lēmumu (OBLIGĀTS)
  $decision = pick_decision_rule($pdo, $buvesTipsN, (string)$buvesGrupa, $objekts ?: '*', $darbibaN ?: '*', $kult ?: '*');
  if (!$decision && $kult === '*') {
    // ja user “nezinu”, mēģinam atrast jebkuru (no/yes) lai vismaz iedod 2 variantus vēlāk
    $decision = pick_decision_rule($pdo, $buvesTipsN, (string)$buvesGrupa, $objekts ?: '*', $darbibaN ?: '*', 'no')
            ?? pick_decision_rule($pdo, $buvesTipsN, (string)$buvesGrupa, $objekts ?: '*', $darbibaN ?: '*', 'yes');
  }
  if (!$decision) {
    header("Location: " . APP_BASE . "/?err=" . urlencode("Nav atrasts BIS lēmums (embpd_decision_rules). Pārbaudi: tips/grupa/objekts/darbība/piemineklis."));
    exit;
  }

  // piespiežam norm failu, lai var citēt
  $decisionNormFile = (string)($decision['normative_file'] ?? '');
  if ($decisionNormFile !== '' && !in_array($decisionNormFile, $selected, true)) {
    $selected[] = $decisionNormFile;
    $selected = array_values(array_unique($selected));
  }
}

/** turpinājumā paņemam pinned no DB (ja ir) */
if (!$isNew) {
  $stp = $pdo->prepare("SELECT pinned_context, topic FROM embpd_threads WHERE session_id=? LIMIT 1");
  $stp->execute([$session]);
  $row = $stp->fetch(PDO::FETCH_ASSOC) ?: [];
  $pinned = (string)($row['pinned_context'] ?? '');
  $topic  = (string)($row['topic'] ?? $topic);
}

/** ielikts 21.01.2026 */
// ja ir ieceres tēma, lēmumu turpinājumā arī vienmēr padodam
if (!$decision && $topic === 'ieceres_dokumentacija' && $pinned !== '') {
  // ŠEIT vienkāršākais: pārrēķinam pēc POST (ja user turpina no chat.php, POST lauki var nebūt),
  // tāpēc drošāk ir: saglabāt lēmumu DB vai parsēt pinned.
  // Ātrais variants: atkārtoti izmantot pēdējos zināmos parametrus no pinned:
  $bt = ''; $bg = ''; $ob = ''; $dv = ''; $kp = '';
  foreach (explode("\n", $pinned) as $line) {
    if (str_starts_with($line, "Būves tips:")) $bt = trim(substr($line, strlen("Būves tips:")));
    if (str_starts_with($line, "Būves grupa:")) $bg = trim(substr($line, strlen("Būves grupa:")));
    if (str_starts_with($line, "Objekts:")) $ob = trim(substr($line, strlen("Objekts:")));
    if (str_starts_with($line, "Darbu veids:")) $dv = trim(substr($line, strlen("Darbu veids:")));
    if (str_starts_with($line, "Kultūras piemineklis:")) $kp = trim(substr($line, strlen("Kultūras piemineklis:")));
  }

  if ($bt !== '' && $bg !== '' && $ob !== '' && $dv !== '') {
    $decision = pick_decision_rule($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', $kp ?: '*');
    if (!$decision && $kp === '*') {
      $decision = pick_decision_rule($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', 'no')
              ?? pick_decision_rule($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', 'yes');
    }
  }
}
/** ielikts 21.01.2026 beidzas */

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

/** normatīvu konteksts */
$normContext = build_norm_context($selected);
$normContext = normalize_prim_markers($normContext);
/** ievietots 21.01.2026 sākas */
if ($decision) {
  $doc = (string)($decision['doc_type'] ?? '');
  $extraRules .= "- BIS lēmuma dokumenta veids IR OBLIGĀTS un nemaināms: {$doc}. Nekādā gadījumā nedrīkst piedāvāt citu dokumentu veidu.\n";
}


/** ievietots 21.01.2026 beidzas*/

/** SISTĒMA */
$system = build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/** būvējam payload */
$contextBlock = '';
if ($pinned !== '') {
  $contextBlock .= "PINNED KONTEKSTS:\n" . $pinned . "\n";
}

if ($decision) {
  $contextBlock .= "BIS LĒMUMS (no DB):\n";
  $contextBlock .= "- Dokuments: " . (string)($decision['doc_type'] ?? '') . "\n";
  $contextBlock .= "- Normatīvais fails: " . (string)($decision['normative_file'] ?? '') . "\n";
  $contextBlock .= "- Atsauce: " . (string)($decision['atsauce'] ?? '') . "\n";
  $contextBlock .= "- Note: " . (string)($decision['note'] ?? '') . "\n\n";
  $contextBlock .= "- Dokumenta veidu drīkst noteikt TIKAI no šī DB bloka.\n\n";
}

/** saglabā user ziņu DB */
$pdo->prepare("INSERT INTO embpd_chats(session_id, role, content) VALUES (?,?,?)")
  ->execute([$session, 'user', $prompt]);

$messages = [
  ["role" => "system", "content" => $system],
  ["role" => "user", "content" =>
    $contextBlock .
    "JAUTĀJUMS:\n" . $prompt . "\n\n" .
    "--- NORMATĪVIE AKTI (TXT, izvēlētie faili) ---\n" . $normContext
  ],
];

try {
  $assistant = call_openai($messages);
  $assistant = ensure_allowed_norm_act($messages, $assistant, $selected);
  $assistant = append_full_citation($assistant, $selected);
} catch (Throwable $e) {
  $assistant = "⚠️ Kļūda: " . $e->getMessage();
}

$pdo->prepare("INSERT INTO embpd_chats(session_id, role, content) VALUES (?,?,?)")
  ->execute([$session, 'assistant', $assistant]);

header("Location: " . APP_BASE . "/chat.php?session=" . urlencode($session));
exit;

