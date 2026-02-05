<?php
require_once __DIR__ . '/db.php';

function log_apmaciba(PDO $pdo, array $row): void {
  // MySQL kolonnas:
  // jautajums, gpt_atbilde, labota_atbilde, der_apmacibai, datums,
  // forma, session_id, user_id, gpt_modelis, kvalitate, komentars, created_at

  $sql = "
    INSERT INTO embpd_apmaciba
    (jautajums, gpt_atbilde, labota_atbilde, der_apmacibai, datums,
     forma, session_id, user_id, gpt_modelis, kvalitate, komentars, created_at)
    VALUES
    (:jautajums, :gpt_atbilde, :labota_atbilde, :der_apmacibai, :datums,
     :forma, :session_id, :user_id, :gpt_modelis, :kvalitate, :komentars, :created_at)
  ";

  $st = $pdo->prepare($sql);

  $st->execute([
    ':jautajums'      => (string)($row['jautajums'] ?? ''),
    ':gpt_atbilde'    => (string)($row['gpt_atbilde'] ?? ''),
    ':labota_atbilde' => $row['labota_atbilde'] ?? null,
    ':der_apmacibai'  => (int)($row['der_apmacibai'] ?? 0),

    // ja nav padots, ieliekam NOW() MySQL pusē
    ':datums'         => $row['datums'] ?? date('Y-m-d H:i:s'),

    ':forma'          => $row['forma'] ?? null,
    ':session_id'     => $row['session_id'] ?? null,
    ':user_id'        => $row['user_id'] ?? null,
    ':gpt_modelis'    => $row['gpt_modelis'] ?? null,
    ':kvalitate'      => $row['kvalitate'] ?? null,
    ':komentars'      => $row['komentars'] ?? null,

    ':created_at'     => $row['created_at'] ?? date('Y-m-d H:i:s'),
  ]);
}
