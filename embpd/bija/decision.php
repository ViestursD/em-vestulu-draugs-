<?php
declare(strict_types=1);

/**
 * pick_decision_rule:
 * Meklē 1 ierakstu no embpd_decision_rules pēc:
 * buves_tips, buves_grupa, objekts, darbiba, kulturas_piemineklis
 *
 * Wildcard DB pusē: '*'
 */
function pick_decision_rule(
  PDO $pdo,
  string $buvesTips,
  string $buvesGrupa,
  string $objekts,
  string $darbiba,
  string $kulturasPiemineklis = '*'
): ?array {

  $sql = "SELECT *
          FROM embpd_decision_rules
          WHERE enabled=1
            AND (buves_tips=? OR buves_tips='*')
            AND (buves_grupa=? OR buves_grupa='*')
            AND (objekts=? OR objekts='*')
            AND (darbiba=? OR darbiba='*')
            AND (kulturas_piemineklis=? OR kulturas_piemineklis='*')
          ORDER BY priority ASC, id ASC
          LIMIT 1";

  $st = $pdo->prepare($sql);
  $st->execute([$buvesTips, $buvesGrupa, $objekts, $darbiba, $kulturasPiemineklis]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

