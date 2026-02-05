<?php
declare(strict_types=1);

// ✅ AGRESĪVI ERROR DISPLAY IESTATĪJUMI
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL | E_STRICT);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
header('Content-Type: text/html; charset=utf-8');

// ✅ Piekļūšanas kļūdu apstrāde
set_error_handler(function($errno, $errstr, $errfile, $errline) {
  echo "<div style='background:#fff3cd; border:1px solid #ffc107; padding:10px; margin:10px; font-family:monospace;'>";
  echo "<strong>⚠️ PHP ERROR ($errno):</strong> $errstr<br>";
  echo "<small>File: $errfile, Line: $errline</small>";
  echo "</div>";
  return false;
});

set_exception_handler(function($e) {
  echo "<div style='background:#f8d7da; border:1px solid #f5c6cb; padding:10px; margin:10px; font-family:monospace; color:#721c24;'>";
  echo "<strong>🔴 EXCEPTION:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
  echo "<small>File: " . htmlspecialchars($e->getFile()) . ", Line: " . $e->getLine() . "</small><br>";
  echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
  echo "</div>";
  exit(1);
});

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

try {
  $pdo = db();
} catch (Exception $e) {
  echo "<div style='background:#f8d7da; border:1px solid #f5c6cb; padding:10px; margin:10px; font-family:monospace; color:#721c24;'>";
  echo "<strong>🔴 DB CONNECTION ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
  exit(1);
}


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

function detect_area_m2(string $text): ?float {
  // Atrod: "24 m2", "24 m²", "līdz 25 m2", "nepārsniedz 25 m²"
  if (!preg_match('/(\d+(?:[.,]\d+)?)\s*(m2|m²)\b/iu', $text, $m)) return null;
  return (float) str_replace(',', '.', $m[1]);
}

/**
 * ✅ STRICT lēmums: meklē tikai ar precīzu kulturas_piemineklis (bez '*')
 * - ja nav atrasts, atgriež null
 */
function pick_decision_rule_strict(
  PDO $pdo,
  string $buvesTips,
  string $buvesGrupa,
  string $objekts,
  string $darbiba,
  string $kult // 'no' vai 'yes'
): ?array {
  $sql = "SELECT *
          FROM embpd_decision_rules
          WHERE enabled=1
            AND (buves_tips=? OR buves_tips='*')
            AND (buves_grupa=? OR buves_grupa='*')
            AND (objekts=? OR objekts='*')
            AND (darbiba=? OR darbiba='*')
            AND kulturas_piemineklis=?
          ORDER BY priority ASC, id ASC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$buvesTips, $buvesGrupa, $objekts, $darbiba, $kult]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

/**
 * ✅ Droša pick_decision_rule izsaukšana (ja tava decision.php atbalsta TOP masīvu ar 7. parametru)
 */
function pick_rule_safe(PDO $pdo, string $buvesTips, string $buvesGrupa, string $objekts, string $darbiba, string $kult, array &$topOut): ?array {
  $topOut = [];
  try {
    return pick_decision_rule($pdo, $buvesTips, $buvesGrupa, $objekts, $darbiba, $kult, $topOut);
  } catch (Throwable $e) {
    error_log("[pick_rule_safe] fallback bez TOP: " . $e->getMessage());
    $topOut = [];
    try {
      return pick_decision_rule($pdo, $buvesTips, $buvesGrupa, $objekts, $darbiba, $kult);
    } catch (Throwable $e2) {
      error_log("[pick_rule_safe] ERROR: " . $e2->getMessage());
      return null;
    }
  }
}

/**
 * ✅ Nolasa konkrēta normatīvā TXT saturu.
 * Pielāgo šo funkciju, ja tev norm faili glabājas citur.
 *
 * Ja normfiles.php tev jau ir helperis (piem. get_norm_file_path($file)),
 * tad šeit izmanto to.
 */
function read_norm_file_text(string $file): string {
  // Tipiska struktūra: /normativi/<file>
  $pathGuess = __DIR__ . '/normativi/' . basename($file);
  if (is_file($pathGuess)) {
    return (string)file_get_contents($pathGuess);
  }

  // Alternatīva: ja glabājas /txt/ vai /data/
  $alt1 = __DIR__ . '/txt/' . basename($file);
  if (is_file($alt1)) return (string)file_get_contents($alt1);

  $alt2 = __DIR__ . '/data/' . basename($file);
  if (is_file($alt2)) return (string)file_get_contents($alt2);

  // Ja tev normfiles.php ir cita shēma, iemet man to failu un es pieskaņošu.
  return '';
}

/**
 * ✅ Mēģina no TXT sākuma izvilkt akta nosaukumu un datumu.
 * - nosaukums: pirmā sakarīgā rinda
 * - datums: pirmā atbilstība (dd.mm.yyyy vai yyyy-mm-dd vai “2025. gada 1. janvāris” u.tml.)
 */
function extract_act_meta(string $txt): array {
  $title = '';
  $date  = '';

  $lines = preg_split("/\R/u", $txt) ?: [];
  foreach ($lines as $ln) {
    $ln = trim((string)$ln);
    if ($ln === '') continue;
    // ignorējam tehniskas rindas
    if (mb_stripos($ln, 'Satura rādītājs') !== false) continue;
    $title = $ln;
    break;
  }

  // datums: dd.mm.yyyy
  if (preg_match('/\b(\d{2}\.\d{2}\.\d{4})\b/u', $txt, $m)) {
    $date = $m[1];
  } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $txt, $m)) {
    $date = $m[1];
  } elseif (preg_match('/\b(\d{4})\.\s*gada\s*(\d{1,2})\.\s*([A-Za-zĀČĒĢĪĶĻŅŠŪŽāčēģīķļņšūž]+)\b/u', $txt, $m)) {
    $date = $m[1] . ". gada " . $m[2] . ". " . $m[3];
  }

  return ['title' => $title, 'date' => $date];
}

/**
 * ✅ Izvelk 100% pilnu punkta/daļas tekstu pēc atsauces markera (piem. "7.§§§1§§§4").
 * Ideja:
 *  - atrodam marker pozīciju
 *  - ņemam no tās līdz nākamajam markerim (nākamajai līdzīgai atsaucei)
 */
function extract_full_point_by_marker(string $txtNormalized, string $marker): string {
  $marker = trim($marker);
  if ($marker === '' || $txtNormalized === '') return '';

  $pos = mb_strpos($txtNormalized, $marker);
  if ($pos === false) return '';

  $slice = mb_substr($txtNormalized, $pos);

  // mēģinam atrast nākamo “atsauces” sākumu:
  // piemērs: "\n7.§§§1§§§5" vai "\n8.§§§2§§§1" vai "\n12." (ja bez prim)
  $patterns = [
    "/\n(?=\d+\.\s*§§§\d+§§§)/u",  // nākamais ar prim
    "/\n(?=\d+\.)/u",             // jebkurš nākamais punkts
  ];

  $cutAt = null;
  foreach ($patterns as $rx) {
    if (preg_match($rx, $slice, $m, PREG_OFFSET_CAPTURE, 1)) {
      $off = (int)($m[0][1] ?? 0);
      if ($off > 0) {
        $cutAt = $off;
        break;
      }
    }
  }

  if ($cutAt === null) return trim($slice);
  return trim(mb_substr($slice, 0, $cutAt));
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

// ✅ Konvertē objekts pamatojoties uz kultūras pieminekļa statusu (mazeka → mazeka_ar_pilsetas, ja nav kultūras piem.)
$objektsOriginal = $objekts;
$objekts = determine_objekts_variant($objekts, $kult);

$debugAdd("POST saņemts", [
  'isNew' => $isNew,
  'session' => $session,
  'topic' => $topic,
  'forma' => $forma,
  'datums' => $datums,
  'buvesTips' => $buvesTips,
  'buvesGrupa' => $buvesGrupa,
  'kult' => $kult,
  'objekts_original' => $objektsOriginal,
  'objekts_converted' => $objekts,
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

/** pinned context + decisions */
$pinned = '';
$decision = null;     // ja kult != '*'
$decisionNo = null;   // ja kult == '*'
$decisionYes = null;  // ja kult == '*'
$decisionVariants25 = []; // <=25 m2 gadījumam

/** jauns thread: ja ieceres tēma -> izveido pinned + atrod decision(s) */
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

  // ===== <=25 m2 override: rādām 3 variantus ar citātiem =====
  $area = detect_area_m2($prompt);
  if ($area !== null && $area <= 25) {
    $decisionVariants25 = pick_decision_variants_le_25(
      $pdo,
      $buvesTipsN,
      (string)$buvesGrupa,
      $objekts ?: '*'
    );
    $debugAdd("<=25m2 atrasts — 3 varianti no DB", [
      'area' => $area,
      'count' => count($decisionVariants25),
      'variant_keys' => array_map(fn($r) => $r['variant_key'] ?? null, $decisionVariants25),
    ]);

    // ✅ šajā režīmā NEMEKLĒJAM 1 "labāko" (ne no/yes, ne single)
    $decision = null;
    $decisionNo = null;
    $decisionYes = null;
  } else {
    // (šeit paliek tavs esošais kult/no/yes lēmuma kods – neko nemainām)

    if ($kult === '*') {
    // ✅ vispirms STRICT (precīzi no/yes), tikai tad fallback
    $decisionNoStrict  = pick_decision_rule_strict($pdo, $buvesTipsN, (string)$buvesGrupa, $objekts ?: '*', $darbibaN ?: '*', 'no');
    $decisionYesStrict = pick_decision_rule_strict($pdo, $buvesTipsN, (string)$buvesGrupa, $objekts ?: '*', $darbibaN ?: '*', 'yes');

    $debugAdd("DB lēmums STRICT (kult=no)", $decisionNoStrict);
    $debugAdd("DB lēmums STRICT (kult=yes)", $decisionYesStrict);

    // fallback (ar TOP debug)
    $topNo = []; $topYes = [];
    $decisionNo  = $decisionNoStrict  ?: pick_rule_safe($pdo, $buvesTipsN, (string)$buvesGrupa, $objekts ?: '*', $darbibaN ?: '*', 'no', $topNo);
    $decisionYes = $decisionYesStrict ?: pick_rule_safe($pdo, $buvesTipsN, (string)$buvesGrupa, $objekts ?: '*', $darbibaN ?: '*', 'yes', $topYes);

    $debugAdd("DB kandidāti (TOP 10) — kult=no", $topNo);
    $debugAdd("DB kandidāti (TOP 10) — kult=yes", $topYes);
    $debugAdd("DB lēmums (kult=no, pēc fallback)", $decisionNo);
    $debugAdd("DB lēmums (kult=yes, pēc fallback)", $decisionYes);

    if (!$decisionNo || !$decisionYes) {
      header("Location: " . APP_BASE . "/?err=" . urlencode("kult='*' režīmā ir jāatrod ABI scenāriji: kult=no un kult=yes. Pārbaudi embpd_decision_rules."));
      exit;
    }

    // Piespiežam klāt norm failus no abiem scenārijiem
    foreach ([$decisionNo, $decisionYes] as $dec) {
      $nf = (string)($dec['normative_file'] ?? '');
      if ($nf !== '' && !in_array($nf, $selected, true)) {
        $selected[] = $nf;
        $debugAdd("Pievienots DB noteiktais normatīvais fails (A/B)", $nf);
      }
    }
    $selected = array_values(array_unique($selected));

  } else {
    $top = [];
    $decision = pick_rule_safe(
      $pdo,
      $buvesTipsN,
      (string)$buvesGrupa,
      $objekts ?: '*',
      $darbibaN ?: '*',
      $kult,
      $top
    );
    $debugAdd("DB kandidāti (TOP 10)", $top);
    $debugAdd("DB lēmums no pick_decision_rule()", $decision);

    if (!$decision) {
      header("Location: " . APP_BASE . "/?err=" . urlencode("Nav atrasts BIS lēmums (embpd_decision_rules)."));
      exit;
    }

    $nf = (string)($decision['normative_file'] ?? '');
    if ($nf !== '' && !in_array($nf, $selected, true)) {
      $selected[] = $nf;
      $selected = array_values(array_unique($selected));
      $debugAdd("Pievienots DB noteiktais normatīvais fails", $nf);
    }
  }
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

/** turpinājumā: ja ieceres tēma -> pārrēķinam decision(s) no pinned */
if ($topic === 'ieceres_dokumentacija' && $pinned !== '' && !$decision && !$decisionNo && !$decisionYes) {
  $bt = ''; $bg = ''; $ob = ''; $dv = ''; $kp = '';
  foreach (explode("\n", $pinned) as $line) {
    $line = trim($line);
    if (str_starts_with($line, "Būves tips:")) $bt = trim(substr($line, strlen("Būves tips:")));
    if (str_starts_with($line, "Būves grupa:")) $bg = trim(substr($line, strlen("Būves grupa:")));
    if (str_starts_with($line, "Objekts:")) $ob = trim(substr($line, strlen("Objekts:")));
    if (str_starts_with($line, "Darbu veids:")) $dv = trim(substr($line, strlen("Darbu veids:")));
    if (str_starts_with($line, "Kultūras piemineklis:")) $kp = trim(substr($line, strlen("Kultūras piemineklis:")));
  }

  $debugAdd("Turpinājums: parsēts no pinned", ['bt'=>$bt,'bg'=>$bg,'ob'=>$ob,'dv'=>$dv,'kp'=>$kp]);

  if ($bt !== '' && $bg !== '' && $ob !== '' && $dv !== '') {
    if ($kp === '*') {
      $decisionNoStrict  = pick_decision_rule_strict($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', 'no');
      $decisionYesStrict = pick_decision_rule_strict($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', 'yes');
      $debugAdd("Turpinājums: DB lēmums STRICT (kult=no)", $decisionNoStrict);
      $debugAdd("Turpinājums: DB lēmums STRICT (kult=yes)", $decisionYesStrict);

      $topNo2 = []; $topYes2 = [];
      $decisionNo  = $decisionNoStrict  ?: pick_rule_safe($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', 'no', $topNo2);
      $decisionYes = $decisionYesStrict ?: pick_rule_safe($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', 'yes', $topYes2);

      $debugAdd("Turpinājums: DB kandidāti (TOP 10) — kult=no", $topNo2);
      $debugAdd("Turpinājums: DB kandidāti (TOP 10) — kult=yes", $topYes2);
      $debugAdd("Turpinājums: lēmums (kult=no)", $decisionNo);
      $debugAdd("Turpinājums: lēmums (kult=yes)", $decisionYes);

    } else {
      $top2 = [];
      $decision = pick_rule_safe($pdo, $bt, $bg, $ob ?: '*', $dv ?: '*', $kp, $top2);
      $debugAdd("Turpinājums: DB kandidāti (TOP 10)", $top2);
      $debugAdd("Turpinājums: decision pārrēķināts", $decision);
    }
  }
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
$extraRules .= "- Nekādā gadījumā nedrīkst izdomāt atsauces vai citātus. Atsauces un citāti jāņem TIKAI no zemāk dotajiem A/B blokiem.\n";
$extraRules .= "- Ja citāts nav atrasts failā pēc atsauces markera, atbildē to skaidri pasaki: 'Citāts nav atrasts TXT' (un nepievieno izdomātu tekstu).\n";

/** normatīvu konteksts (GPT vajadzībām; citātus mēs dodam atsevišķi) */
$normContext = build_norm_context($selected);
$normContext = normalize_prim_markers($normContext);

/** =========================
 * ✅ CITĀTU SAGATAVOŠANA NO DB ATSAUCĒM (100%)
 * ========================= */
function build_citation_block_from_decision(array $decision): array {
  $file = (string)($decision['normative_file'] ?? '');
  $ats  = (string)($decision['atsauce'] ?? '');
  $note = (string)($decision['note'] ?? '');

  $txt = read_norm_file_text($file);
  $txtN = normalize_prim_markers($txt);

  $meta = extract_act_meta($txtN);
  $fullPoint = ($ats !== '') ? extract_full_point_by_marker($txtN, $ats) : '';

  return [
    'file' => $file,
    'atsauce' => $ats,
    'note' => $note,
    'act_title' => (string)($meta['title'] ?? ''),
    'act_date'  => (string)($meta['date'] ?? ''),
    'quote' => $fullPoint,
  ];
}

$citeA = null;
$citeB = null;
$citeSingle = null;
$citeVariants25 = [];

if (!empty($decisionVariants25)) {
  foreach ($decisionVariants25 as $r) {
    $vk = (string)($r['variant_key'] ?? '');
    $citeVariants25[$vk] = build_citation_block_from_decision($r);
  }
  $debugAdd("Citāti (<=25) sagatavoti", array_keys($citeVariants25));
}

if ($kult === '*' && $decisionNo && $decisionYes) {
  $citeA = build_citation_block_from_decision($decisionNo);   // A = no
  $citeB = build_citation_block_from_decision($decisionYes);  // B = yes
  $debugAdd("Citāts A (kult=no) sagatavots", $citeA);
  $debugAdd("Citāts B (kult=yes) sagatavots", $citeB);
} elseif ($decision) {
  $citeSingle = build_citation_block_from_decision($decision);
  $debugAdd("Citāts (single) sagatavots", $citeSingle);
}

/** SISTĒMA */
$system = build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/** =========================
 * ✅ contextBlock (DB + citāti)
 * ========================= */
$contextBlock = '';
if ($pinned !== '') {
  $contextBlock .= "PINNED KONTEKSTS:\n" . $pinned . "\n";
}

if (!empty($decisionVariants25)) {
  $contextBlock .= "BIS LĒMUMS (no DB) — 3 VARIANTI (≤ 25 m²):\n\n";

  $labels = [
    '<=25_city' => '1) Ja būve ir PILSĒTĀ',
    '<=25_outside_city' => '2) Ja būve ir ĀRPUS PILSĒTAS',
    '<=25_heritage' => '3) Ja būve ir KULTŪRAS PIEMINEKĻA teritorijā',
  ];

  foreach ($decisionVariants25 as $r) {
    $vk = (string)($r['variant_key'] ?? '');
    $title = $labels[$vk] ?? ("Variants: " . $vk);

    $contextBlock .= $title . ":\n";
    $contextBlock .= "- Dokuments: " . (string)($r['doc_type'] ?? '') . "\n";
    $contextBlock .= "- Normatīvais fails: " . (string)($r['normative_file'] ?? '') . "\n";
    $contextBlock .= "- Atsauce: " . (string)($r['atsauce'] ?? '') . "\n";
    $contextBlock .= "- Note: " . (string)($r['note'] ?? '') . "\n";

    $c = $citeVariants25[$vk] ?? null;
    if (is_array($c)) {
      $tag = strtoupper(str_replace(['<=','_'], ['LE','_'], $vk)); // piemēram LE25_CITY
      $contextBlock .= "[CITĀTS_$tag]\n";
      $contextBlock .= "Normatīvais akts: " . ($c['act_title'] ?: $c['file']) . "\n";
      if (($c['act_date'] ?? '') !== '') $contextBlock .= "Izdošanas datums: " . $c['act_date'] . "\n";
      $contextBlock .= "Atsauce: " . $c['atsauce'] . "\n";
      $contextBlock .= "Citāts (100% pilns punkts):\n" . ($c['quote'] !== '' ? $c['quote'] : "Citāts nav atrasts TXT") . "\n";
      $contextBlock .= "[/CITĀTS_$tag]\n";
    }

    $contextBlock .= "\n";
  }

  $contextBlock .= "OBLIGĀTI: atbildē jāsniedz visi 3 varianti (pilsēta / ārpus pilsētas / kultūras piemineklis), jo atrašanās vieta/statuss nav precizēts.\n\n";

} elseif ($kult === '*' && $decisionNo && $decisionYes) {
  $contextBlock .= "BIS LĒMUMS (no DB) — DIVI SCENĀRIJI (kult='*'):\n\n";

  // A) kult=no
  $contextBlock .= "A) Ja NAV kultūras piemineklis (kult=no):\n";
  $contextBlock .= "- Dokuments: " . (string)($decisionNo['doc_type'] ?? '') . "\n";
  $contextBlock .= "- Normatīvais fails: " . (string)($decisionNo['normative_file'] ?? '') . "\n";
  $contextBlock .= "- Atsauce: " . (string)($decisionNo['atsauce'] ?? '') . "\n";
  $contextBlock .= "- Note: " . (string)($decisionNo['note'] ?? '') . "\n";
  if ($citeA) {
    $contextBlock .= "[CITĀTS_A]\n";
    $contextBlock .= "Normatīvais akts: " . ($citeA['act_title'] ?: $citeA['file']) . "\n";
    if ($citeA['act_date'] !== '') $contextBlock .= "Izdošanas datums: " . $citeA['act_date'] . "\n";
    $contextBlock .= "Atsauce: " . $citeA['atsauce'] . "\n";
    $contextBlock .= "Citāts (100% pilns punkts):\n" . ($citeA['quote'] !== '' ? $citeA['quote'] : "Citāts nav atrasts TXT") . "\n";
    $contextBlock .= "[/CITĀTS_A]\n";
  }
  $contextBlock .= "\n";

  // B) kult=yes
  $contextBlock .= "B) Ja IR kultūras piemineklis (kult=yes):\n";
  $contextBlock .= "- Dokuments: " . (string)($decisionYes['doc_type'] ?? '') . "\n";
  $contextBlock .= "- Normatīvais fails: " . (string)($decisionYes['normative_file'] ?? '') . "\n";
  $contextBlock .= "- Atsauce: " . (string)($decisionYes['atsauce'] ?? '') . "\n";
  $contextBlock .= "- Note: " . (string)($decisionYes['note'] ?? '') . "\n";
  if ($citeB) {
    $contextBlock .= "[CITĀTS_B]\n";
    $contextBlock .= "Normatīvais akts: " . ($citeB['act_title'] ?: $citeB['file']) . "\n";
    if ($citeB['act_date'] !== '') $contextBlock .= "Izdošanas datums: " . $citeB['act_date'] . "\n";
    $contextBlock .= "Atsauce: " . $citeB['atsauce'] . "\n";
    $contextBlock .= "Citāts (100% pilns punkts):\n" . ($citeB['quote'] !== '' ? $citeB['quote'] : "Citāts nav atrasts TXT") . "\n";
    $contextBlock .= "[/CITĀTS_B]\n";
  }
  $contextBlock .= "\n";

  $contextBlock .= "OBLIGĀTI: atbildē jāsniedz abi scenāriji A un B (jo kult='*'), izmantojot tieši DB un CITĀTU blokus.\n\n";

} elseif ($decision) {
  $contextBlock .= "BIS LĒMUMS (no DB):\n";
  $contextBlock .= "- Dokuments: " . (string)($decision['doc_type'] ?? '') . "\n";
  $contextBlock .= "- Normatīvais fails: " . (string)($decision['normative_file'] ?? '') . "\n";
  $contextBlock .= "- Atsauce: " . (string)($decision['atsauce'] ?? '') . "\n";
  $contextBlock .= "- Note: " . (string)($decision['note'] ?? '') . "\n";
  if ($citeSingle) {
    $contextBlock .= "[CITĀTS]\n";
    $contextBlock .= "Normatīvais akts: " . ($citeSingle['act_title'] ?: $citeSingle['file']) . "\n";
    if ($citeSingle['act_date'] !== '') $contextBlock .= "Izdošanas datums: " . $citeSingle['act_date'] . "\n";
    $contextBlock .= "Atsauce: " . $citeSingle['atsauce'] . "\n";
    $contextBlock .= "Citāts (100% pilns punkts):\n" . ($citeSingle['quote'] !== '' ? $citeSingle['quote'] : "Citāts nav atrasts TXT") . "\n";
    $contextBlock .= "[/CITĀTS]\n";
  }
  $contextBlock .= "\n";
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
  'mode' => ($kult === '*' ? 'A/B' : 'single'),
  'doc_type_no' => (string)($decisionNo['doc_type'] ?? ''),
  'doc_type_yes' => (string)($decisionYes['doc_type'] ?? ''),
  'doc_type' => (string)($decision['doc_type'] ?? ''),
]);

try {
  $assistant = call_openai($messages);

  // ⚠️ ŠEIT vairs nevajag “pārrakstīt” lēmumu – jo A/B jau ir piespiests un citāti iedoti.
  // Tomēr vari atstāt drošības validācijas:
  $assistant = ensure_allowed_norm_act($messages, $assistant, $selected);

  // append_full_citation var atstāt, bet viņš vairs nav kritisks,
  // jo mēs jau ieliekam pilnu citātu paši.
  // Ja viņš tev kādreiz "salauž" A/B, tad labāk šo IZŅEMT.
  // $assistant = append_full_citation($assistant, $selected);

  $debugAdd("Assistant atbilde (saīsināts)", mb_substr((string)$assistant, 0, 1200));
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
    error_log("[DEBUG_LOG_FAIL] " . $e->getMessage());
  }
}

header("Location: " . APP_BASE . "/chat.php?session=" . urlencode($session));
exit;
