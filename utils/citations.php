<?php
require_once __DIR__ . '/normfiles.php';

function parse_norm_block(string $text): ?array {
  if (!preg_match('/\[NORMATĪVAIS_PAMATOJUMS\]([\s\S]*?)\[\/NORMATĪVAIS_PAMATOJUMS\]/ui', $text, $m)) return null;
  $inside = $m[1];

  preg_match('/Normatīvais\s+akts:\s*(.+)/ui', $inside, $a);
  preg_match('/Atsauce:\s*(.+)/ui', $inside, $b);

  $act = trim($a[1] ?? '');
  $ats = trim($b[1] ?? '');
  if ($act === '' || $ats === '') return null;
  return ['act'=>$act, 'ats'=>$ats];
}

function parse_atsauce(string $ats): ?array {
  if (!preg_match('/(\d+(?:\.\d+){0,3})/u', $ats, $m)) return null;
  $num = $m[1];
  $isPants = (bool)preg_match('/pants/ui', $ats);
  return ['num'=>$num, 'isPants'=>$isPants];
}

function extract_full_block(string $fullText, string $atsRaw): ?string {
  $p = parse_atsauce($atsRaw);
  if (!$p) return null;

  $num = $p['num'];
  $isPants = $p['isPants'];
  $text = str_replace("\r\n", "\n", $fullText);

  $startRe = $isPants
    ? '/(^|\n)\s*' . preg_quote($num, '/') . '\\s*\\.\\s*pants\\b/ui'
    : '/(^|\n)\s*' . preg_quote($num, '/') . '\\s*\\.\\b/ui';

  if (!preg_match($startRe, $text, $sm, PREG_OFFSET_CAPTURE)) return null;

  $startIdx = $sm[0][1] + (strlen($sm[1][0]) ?: 0);
  $rest = substr($text, $startIdx);

  $nextRe = $isPants
    ? '/\n\s*\d+(?:\.\d+){0,3}\\s*\\.\\s*pants\\b/ui'
    : '/\n\s*\d+\\s*\\.\\b/ui';

  if (preg_match($nextRe, substr($rest, 1), $nm, PREG_OFFSET_CAPTURE)) {
    $end = 1 + $nm[0][1];
    $block = trim(substr($rest, 0, $end));
  } else {
    $block = trim($rest);
  }

  return $block !== '' ? $block : null;
}

function append_full_citation(string $assistantText, array $selectedFiles): string {
  $nb = parse_norm_block($assistantText);
  if (!$nb) return $assistantText;
  if (preg_match('/nav noteikta/ui', $nb['ats'])) return $assistantText;

  foreach ($selectedFiles as $f) {
    $txt = read_norm_file($f);
    $block = extract_full_block($txt, $nb['ats']);
    if ($block) {
      return $assistantText . "\n\n[CITĀTS – {$f}; {$nb['ats']}]\n{$block}\n[/CITĀTS]";
    }
  }
  return $assistantText;
}
