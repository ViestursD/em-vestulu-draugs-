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
 */
function pick_decision_rule(
  PDO $pdo,
  string $buvesTips,
  string $buvesGrupa,
  string $objekts,
  string $darbiba,
  string $kulturasPiemineklis = '*',
  ?array &$debugTop = null
): ?array {

  $buvesTips = trim($buvesTips);
  $buvesGrupa = trim($buvesGrupa);
  $objekts = trim($objekts);
  $darbiba = trim($darbiba);
  $kulturasPiemineklis = trim($kulturasPiemineklis);

  // Ja user nezina kultūras pieminekli ('*'), atļaujam kandidātus arī ar 'no'/'yes'
  // (citādi score izvēlēsies pēc pārējiem laukiem, nevis iesprūdīs wildcard).
  $kultAllowed = ($kulturasPiemineklis === '*')
    ? ['*', 'no', 'yes']
    : [$kulturasPiemineklis, '*'];

  $placeholdersK = implode(',', array_fill(0, count($kultAllowed), '?'));

  $sql = "
    SELECT
      r.*,
      (
        (r.buves_tips = ?) +
        (r.buves_grupa = ?) +
        (r.objekts = ?) +
        (r.darbiba = ?) +
        (r.kulturas_piemineklis = ?)
      ) AS match_score
    FROM embpd_decision_rules r
    WHERE r.enabled=1
      AND r.buves_tips IN (?, '*')
      AND r.buves_grupa IN (?, '*')
      AND r.objekts IN (?, '*')
      AND r.darbiba IN (?, '*')
      AND r.kulturas_piemineklis IN ($placeholdersK)
    ORDER BY
      match_score DESC,
      r.priority ASC,
      r.id ASC
    LIMIT 10
  ";

  // kp_exact score: ja user ir '*', tad neceļam score par 'no/yes' (lai score nav mākslīgs)
  $kpExactForScore = ($kulturasPiemineklis === '*') ? '*' : $kulturasPiemineklis;

  $params = [
    // match_score salīdzinājumi (exact)
    $buvesTips, $buvesGrupa, $objekts, $darbiba, $kpExactForScore,
    // IN filtri
    $buvesTips, $buvesGrupa, $objekts, $darbiba,
    // kult allowed
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

