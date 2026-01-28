<?php
require_once __DIR__ . '/text.php';

/**
 * Nolasa visus enabled noteikumus no DB.
 * Atbalsta gan veco shēmu (bez action...), gan jauno (ar action...).
 */
function load_all_rules(PDO $pdo): array {
  // mēģinām jauno shēmu
  try {
    $st = $pdo->prepare("
      SELECT
        id,
        intent,
        rule_type,
        pattern,
        enabled,
        priority,
        action,
        action_priority,
        scope,
        note
      FROM embpd_intent_rules
      WHERE enabled=1
      ORDER BY priority ASC, id ASC
    ");
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    // fallback uz veco shēmu
    $st = $pdo->prepare("
      SELECT
        id,
        intent,
        rule_type,
        pattern,
        enabled,
        priority,
        note
      FROM embpd_intent_rules
      WHERE enabled=1
      ORDER BY priority ASC, id ASC
    ");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // pievienojam trūkstošos laukus, lai pārējais kods strādā vienādi
    foreach ($rows as &$r) {
      $r['action'] = null;
      $r['action_priority'] = 100;
      $r['scope'] = 'system';
    }
    return $rows;
  }
}

/**
 * Pārbauda, vai rule nostrādā uz normalizēta teksta.
 */
function rule_matches(string $normalized, string $type, string $pat): bool {
  if ($pat === '') return false;

  if ($type === 'contains') {
    // pattern DB parasti ir bez diakritikas (dzivojama eka, bis, iesniegt...)
    // normalized arī ir bez diakritikas (normalizeLV).
    return str_contains($normalized, normalizeLV($pat));
  }

  if ($type === 'regex') {
    // DB pattern piemērs: \\bmaj\\w*\\b (phpMyAdmin rāda dubultās slīpsvītras)
    // PHP regexā lietojam /.../iu
    $rx = '/' . $pat . '/iu';
    return (@preg_match($rx, $normalized) === 1);
  }

  return false;
}

/**
 * Universālais dzinējs:
 * - nolasa visus enabled noteikumus
 * - atrod, kas nostrādāja
 * - atgriež:
 *   - intents (kādi intenti nostrādāja)
 *   - actions (instrukcijas, ko likt promptā)
 *   - debug (kuras rindas nostrādāja)
 */
function apply_rules(PDO $pdo, string $text): array {
  $norm = normalizeLV($text);
  $rules = load_all_rules($pdo);

  $matchedIntents = [];   // intent => true
  $debug = [];            // saraksts ar nostrādājušiem noteikumiem
  $actions = [];          // instrukcijas promptam

  foreach ($rules as $r) {
    $type = (string)($r['rule_type'] ?? '');
    $pat  = (string)($r['pattern'] ?? '');

    if (!rule_matches($norm, $type, $pat)) continue;

    $intent = (string)($r['intent'] ?? '');
    if ($intent !== '') $matchedIntents[$intent] = true;

    $debug[] = [
      'id' => $r['id'] ?? null,
      'intent' => $intent,
      'rule_type' => $type,
      'pattern' => $pat,
      'note' => $r['note'] ?? null,
    ];

    $act = trim((string)($r['action'] ?? ''));
    if ($act !== '') {
      $actions[] = [
        'scope' => (string)($r['scope'] ?? 'system'),
        'prio' => (int)($r['action_priority'] ?? 100),
        'text' => $act,
      ];
    }
  }

  // sakārtojam actions pēc prioritātes + noņemam dublikātus
  usort($actions, fn($a, $b) => $a['prio'] <=> $b['prio']);

  $uniq = [];
  $finalActions = [];
  foreach ($actions as $a) {
    $key = $a['scope'] . '|' . $a['text'];
    if (isset($uniq[$key])) continue;
    $uniq[$key] = true;
    $finalActions[] = $a;
  }

  return [
    'normalized' => $norm,
    'intents' => array_keys($matchedIntents),
    'actions' => $finalActions,
    'debug' => $debug,
  ];
}

function save_intent_suggestion(PDO $pdo, ?string $session_id, string $prompt, string $normalized, string $note): void {
  $st = $pdo->prepare("
    INSERT INTO embpd_intent_suggestions(session_id, prompt, normalized, note)
    VALUES (?,?,?,?)
  ");
  $st->execute([$session_id, $prompt, $normalized, $note]);
}

