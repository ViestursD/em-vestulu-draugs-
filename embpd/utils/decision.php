<?php
declare(strict_types=1);

/**
 * pick_decision_rule:
 * Meklē labāko ierakstu no embpd_decision_rules pēc:
 * buves_tips, buves_grupa, objekts, darbiba, kulturas_piemineklis
 *
 * Wildcard DB pusē: '*'
 *
 * Izvēles princips:
 * 1) ņem tikai tos, kas atbilst (value vai '*')
 * 2) rangē pēc specifikas (exact match skaits) DESC
 * 3) tad pēc priority ASC
 * 4) tad pēc id ASC
 *
 * Ja padod $debugTop (pēc reference), ieliek TOP kandidātus (lai UI rāda).
 *
 * ✅ FIX: darbiba sinonīmi (jauna_buvnieciba <-> jaunbuvnieciba) tiek apēsti gan filtrā, gan score.
 * ✅ FIX: ja kult='*', vari izvēlēties režīmu:
 *   - $preferExplicitKultWhenUnknown=true → prioritizē yes/no pār '*' (lai neaizvelk uz globālo defaultu)
 *   - false → saglabā veco uzvedību
 */
function pick_decision_rule(
  PDO $pdo,
  string $buvesTips,
  string $buvesGrupa,
  string $objekts,
  string $darbiba,
  string $kulturasPiemineklis = '*',
  ?array &$debugTop = null,
  bool $preferExplicitKultWhenUnknown = true
): ?array {

  $buvesTips = trim($buvesTips);
  $buvesGrupa = trim($buvesGrupa);
  $objekts = trim($objekts);
  $darbiba = trim($darbiba);
  $kulturasPiemineklis = trim($kulturasPiemineklis);

  // --- darbiba sinonīmi (DB var būt vecā vērtība) ---
  $darbibaAliases = [$darbiba];
  if ($darbiba === 'jaunbuvnieciba') $darbibaAliases[] = 'jauna_buvnieciba';
  if ($darbiba === 'jauna_buvnieciba') $darbibaAliases[] = 'jaunbuvnieciba';
  $darbibaAliases = array_values(array_unique($darbibaAliases));

  // --- kult allowed ---
  $kultAllowed = ($kulturasPiemineklis === '*')
    ? ['*', 'no', 'yes']
    : [$kulturasPiemineklis, '*'];

  $phK = implode(',', array_fill(0, count($kultAllowed), '?'));
  $phD = implode(',', array_fill(0, count($darbibaAliases), '?'));

  // score: ja kult='*', neveidojam "fake" exact par yes/no.
  // darbiba score: ja ir sinonīms, skaitām kā exact, ja r.darbiba ir vienā no aliasiem.
  $kpExactForScore = ($kulturasPiemineklis === '*') ? '*' : $kulturasPiemineklis;

  // ja kult='*', vari (pēc izvēles) dot +0.5 par explicit yes/no, lai tie apsteidz r.kult='*'
  // (tad tev UI var rādīt TOP 10 ar šo palīdzību, bet reāli tu chat_send gadījumā 'kult=*' taisīsi 2 scenārijus atsevišķi)
  $kultBoostExpr = "0";
  if ($kulturasPiemineklis === '*' && $preferExplicitKultWhenUnknown) {
    $kultBoostExpr = "CASE WHEN r.kulturas_piemineklis IN ('yes','no') THEN 0.5 ELSE 0 END";
  }

  $sql = "
    SELECT
      r.*,
      (
        (r.buves_tips = ?) +
        (r.buves_grupa = ?) +
        (r.objekts = ?) +
        (CASE WHEN r.darbiba IN ($phD) THEN 1 ELSE 0 END) +
        (r.kulturas_piemineklis = ?)
        + $kultBoostExpr
      ) AS match_score
    FROM embpd_decision_rules r
    WHERE r.enabled=1
      AND r.buves_tips IN (?, '*')
      AND r.buves_grupa IN (?, '*')
      AND r.objekts IN (?, '*')
      AND (r.darbiba IN ($phD) OR r.darbiba='*')
      AND r.kulturas_piemineklis IN ($phK)
    ORDER BY
      match_score DESC,
      r.priority ASC,
      r.id ASC
    LIMIT 10
  ";

  $params = [
    // match_score exact salīdzinājumi
    $buvesTips,
    $buvesGrupa,
    $objekts,
    ...$darbibaAliases,   // score CASE WHEN IN (...)
    $kpExactForScore,

    // IN filtri
    $buvesTips,
    $buvesGrupa,
    $objekts,
    ...$darbibaAliases,   // WHERE r.darbiba IN (...)
    ...$kultAllowed
  ];

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if ($debugTop !== null) {
    $debugTop = array_map(static function(array $r): array {
      return [
        'id' => $r['id'] ?? null,
        'buves_tips' => $r['buves_tips'] ?? null,
        'buves_grupa' => $r['buves_grupa'] ?? null,
        'objekts' => $r['objekts'] ?? null,
        'darbiba' => $r['darbiba'] ?? null,
        'kulturas_piemineklis' => $r['kulturas_piemineklis'] ?? null,
        'doc_type' => $r['doc_type'] ?? null,
        'atsauce' => $r['atsauce'] ?? null,
        'priority' => $r['priority'] ?? null,
        'match_score' => $r['match_score'] ?? null,
      ];
    }, $rows);
  }

  if (!$rows) return null;
  return $rows[0];
}

