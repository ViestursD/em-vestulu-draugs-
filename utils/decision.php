<?php
function pick_decision_rule(PDO $pdo, string $buvesTips, string $buvesGrupa, string $objekts, string $darbiba): ?array {
  $sql = "SELECT * FROM embpd_decision_rules
          WHERE enabled=1
            AND (buves_tips=? OR buves_tips='*')
            AND (buves_grupa=? OR buves_grupa='*')
            AND (objekts=? OR objekts='*')
            AND (darbiba=? OR darbiba='*')
          ORDER BY priority ASC, id ASC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$buvesTips, $buvesGrupa, $objekts, $darbiba]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

/**
 * pick_decision_variants_le_25:
 * Ja ir <=25 m2, atgriež 3 variantus:
 *  - <=25_city
 *  - <=25_outside_city
 *  - <=25_heritage
 *
 * DB jābūt laukam variant_key.
 */
function pick_decision_variants_le_25(
  PDO $pdo,
  string $buvesTips,
  string $buvesGrupa,
  string $objekts
): array {

  $keys = ['<=25_city', '<=25_outside_city', '<=25_heritage'];
  $ph = implode(',', array_fill(0, count($keys), '?'));

  $sql = "
    SELECT r.*
    FROM embpd_decision_rules r
    WHERE r.enabled=1
      AND r.variant_key IN ($ph)
      AND r.buves_tips IN (?, '*')
      AND r.buves_grupa IN (?, '*')
      AND r.objekts IN (?, '*')
    ORDER BY
      CASE r.variant_key
        WHEN '<=25_city' THEN 1
        WHEN '<=25_outside_city' THEN 2
        WHEN '<=25_heritage' THEN 3
        ELSE 9
      END,
      r.priority ASC,
      r.id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ...$keys,
    $buvesTips,
    $buvesGrupa,
    $objekts
  ]);

  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
