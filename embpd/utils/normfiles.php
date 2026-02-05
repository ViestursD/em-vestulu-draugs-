<?php
function norm_dir(): string {
  return __DIR__ . '/../data/normativi';
}

function list_norm_files(): array {
  $dir = norm_dir();
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  $files = glob($dir . '/*.txt') ?: [];
  $names = array_map(fn($p) => basename($p), $files);
  sort($names, SORT_NATURAL);
  return $names;
}

function read_norm_file(string $filename): string {
  $path = norm_dir() . '/' . basename($filename);
  return is_file($path) ? (string)file_get_contents($path) : '';
}

function build_norm_context(array $selectedFiles): string {
  $parts = [];
  foreach ($selectedFiles as $f) {
    $txt = trim(read_norm_file($f));
    if ($txt !== '') $parts[] = "### {$f}\n{$txt}";
  }
  $ctx = implode("\n\n", $parts);
  if (mb_strlen($ctx, 'UTF-8') > 24000) {
    $ctx = mb_substr($ctx, 0, 24000, 'UTF-8') . "\n\n⚠️ (saīsināts, jo teksts bija pārāk garš)";
  }
  return $ctx ?: "⚠️ Nav izvēlēti normatīvie faili.";
}
