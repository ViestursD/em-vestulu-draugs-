<?php
require_once __DIR__ . '/text.php';

/**
 * DB-based entity detector (uz normalizēta LV teksta)
 *
 * entity: buves_tips | buves_grupa | objekts | ...
 * value:  eka | inzenierbuve | 1 | 2 | 3 | dzivojama_eka | paligeka | ...
 *
 * DB tabula: embpd_entity_rules
 * kolonnas: entity, value, pattern, pattern_type, priority, enabled, note
 */
function detect_entity(PDO $pdo, string $entity, string $text, string $default = 'nezinu'): string
{
  $normText = normalizeLV($text);

  $st = $pdo->prepare("
    SELECT value, pattern, pattern_type
    FROM embpd_entity_rules
    WHERE entity=? AND enabled=1
    ORDER BY priority ASC, id ASC
  ");
  $st->execute([$entity]);
  $rules = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rules as $r) {
    $value   = (string)($r['value'] ?? '');
    $pattern = (string)($r['pattern'] ?? '');
    $type    = (string)($r['pattern_type'] ?? 'regex');

    if ($value === '' || $pattern === '') continue;

    if ($type === 'contains') {
      $p = normalizeLV($pattern);
      if ($p !== '' && mb_stripos($normText, $p) !== false) return $value;
      continue;
    }

    // regex (pattern ir domāts jau normalizētam tekstam)
    $re = '/' . $pattern . '/iu';
    $ok = @preg_match($re, $normText);
    if ($ok === 1) return $value;
  }

  return $default;
}

