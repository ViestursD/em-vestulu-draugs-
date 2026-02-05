<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function pdo(): PDO { /* tāpat kā iepriekš */ }

$threadId = trim((string)($_GET['thread_id'] ?? ''));
if ($threadId === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'thread_id required']); exit; }

$pdo = pdo();
$stmt = $pdo->prepare("SELECT id, type, name, url, meta, created_at FROM mycode_artifacts WHERE thread_id=? ORDER BY created_at DESC");
$stmt->execute([$threadId]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
  $r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : null;
}
echo json_encode(['ok'=>true,'artifacts'=>$rows], JSON_UNESCAPED_UNICODE);
