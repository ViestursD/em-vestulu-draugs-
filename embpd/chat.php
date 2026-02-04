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
require_once __DIR__ . '/utils/openai.php';
require_once __DIR__ . '/utils/citations.php';
require_once __DIR__ . '/utils/norm_validate.php';

$normalizeLvText = static function (string $text): string {
  $text = mb_strtolower($text);
  return strtr($text, [
    'ā' => 'a', 'č' => 'c', 'ē' => 'e', 'ģ' => 'g', 'ī' => 'i', 'ķ' => 'k',
    'ļ' => 'l', 'ņ' => 'n', 'š' => 's', 'ū' => 'u', 'ž' => 'z',
  ]);
};

$extractAreaM2 = static function (string $text): ?float {
  if ($text === '') return null;
  $pattern = '/(\d+(?:[.,]\d+)?)\s*(?:m2|m²|m\^2|kv\.?\s*m|kvadratmetri)/u';
  if (!preg_match_all($pattern, $text, $matches)) return null;

  $areas = [];
  foreach ($matches[1] as $raw) {
    $value = (float)str_replace(',', '.', $raw);
    if ($value > 0) $areas[] = $value;
  }
  if (!$areas) return null;
  return min($areas);
};

$isMazekaLidz25 = static function (string $prompt) use ($normalizeLvText, $extractAreaM2): bool {
  $normalized = $normalizeLvText($prompt);
  $mentionsMazeka = str_contains($normalized, 'mazeka') || str_contains($normalized, 'maza eka');
  if (!$mentionsMazeka) return false;

  $area = $extractAreaM2($prompt);
  if ($area === null) return false;

  return $area <= 25.0;
};

$pdo = db();

$session = get_cookie_session();
if (!$session) { header("Location: " . APP_BASE . "/"); exit; }

$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($prompt === '') { header("Location: " . APP_BASE . "/"); exit; }

$forma = (string)($_POST['forma'] ?? 'epasts');
$datums = (string)($_POST['datums'] ?? '');
$buvesTips  = (string)($_POST['buves_tips'] ?? 'nezinu');   // eka | inzenierbuve | nezinu
$buvesGrupa = (string)($_POST['buves_grupa'] ?? 'nezinu');  // 1|2|3|nezinu

/** Izvēlētie normatīvie faili */
$allFiles = list_norm_files();
$selected = $_POST['norm_file'] ?? [];
if (!is_array($selected)) $selected = [];
$selected = array_values(array_unique(array_map('basename', $selected)));
if (count($selected) === 0) $selected = $allFiles;

/**
 * ✅ HARD filtrēšana pēc būves tipa
 */
if ($buvesTips === 'eka') {
  $selected = array_values(array_filter($selected, function($f){
    $ff = mb_strtolower((string)$f);
    return !str_contains($ff, 'atsevisku-inzenierbuvju-buvnoteikumi');
  }));
  if (count($selected) === 0) $selected = $allFiles;
}
if ($buvesTips === 'inzenierbuve') {
  $selected = array_values(array_filter($selected, function($f){
    $ff = mb_strtolower((string)$f);
    return !str_contains($ff, 'eku-buvnoteikumi');
  }));
  if (count($selected) === 0) $selected = $allFiles;
}

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
if ($isMazekaLidz25($prompt)) {
  $extraRules .= "- Ja tekstā ir minēta mazēka līdz 25 m2, pārbaudi TXT izņēmumu šādai mazēkai un, ja noteikts, skaidri norādi, ka būvprojekts minimālā sastāvā un būvatļauja nav nepieciešami.\n";
}

/** Datums LV (ja dots) */
$datumsLv = '';
if ($datums !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $datums);
  if ($dt) $datumsLv = $dt->format('Y. gada m. d.');
}

/** Normatīvu konteksts (TXT saturs sūtīšanai uz GPT) */
$normContext = build_norm_context($selected);
$normContext = normalize_prim_markers($normContext);

/** Lietotāja fakti */
$contextFacts = "LIETOTĀJA NORĀDĪTIE FAKTI:\n";
$contextFacts .= "- Būves tips: " . ($buvesTips === 'eka' ? "ēka" : ($buvesTips === 'inzenierbuve' ? "inženierbūve" : "nav zināms")) . "\n";
$contextFacts .= "- Būves grupa: " . ($buvesGrupa !== 'nezinu' ? ($buvesGrupa . ". grupa") : "nav zināms") . "\n";
$areaM2 = $extractAreaM2($prompt);
if ($areaM2 !== null) {
  $contextFacts .= "- Būves platība (no teksta): " . rtrim(rtrim((string)$areaM2, '0'), '.') . " m2\n";
}

/** Izvēlēto failu saraksts (bez satura) */
$selectedList = "IZVĒLĒTIE NORMATĪVIE FAILI (tiks pievienoti kā TXT saturs zemāk):\n";
foreach ($selected as $f) $selectedList .= "- " . $f . "\n";

/** System prompt */
$system = build_system_prompt_em($forma, $datumsLv, "Jūsu iesniegto jautājumu", $extraRules);

/** Final user payload (ko saglabā DB un sūta GPT) */
$finalUserPayload =
  $prompt
  . "\n\n" . $contextFacts
  . "\n" . $selectedList;

/** ✅ Saglabājam lietotāja ziņu ar faktiem (stabilai vēsturei) */
$pdo->prepare("INSERT INTO embpd_chats(session_id, role, content) VALUES (?,?,?)")
  ->execute([$session, 'user', $finalUserPayload]);

/** Vēsture (bez TXT) */
$st = $pdo->prepare("SELECT role, content FROM embpd_chats WHERE session_id=? ORDER BY created_at, id");
$st->execute([$session]);
$history = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/**
 * ✅ Messages:
 * - system
 * - visa vēsture (kas jau satur faktus user ziņās)
 * - pie pēdējās user ziņas pieliekam TXT kontekstu
 */
$messages = [["role" => "system", "content" => $system]];

/**
 * Vēsturi pievienojam, bet pēdējo user ziņu papildinām ar normatīvu TXT,
 * lai TXT netiktu glabāts DB un vēsture neuzpūstos.
 */
$lastIndex = count($history) - 1;

foreach ($history as $i => $h) {
  $role = (string)($h['role'] ?? '');
  $content = (string)($h['content'] ?? '');

  if ($i === $lastIndex && $role === 'user') {
    $content .= "\n\n--- NORMATĪVIE AKTI (TXT, izvēlētie faili) ---\n" . $normContext;
  }

  $messages[] = ["role" => $role, "content" => $content];
}

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
