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
