<?php
declare(strict_types=1);

/**
 * PDF -> TXT generator for normativi.
 * - Reads PDFs from $PDF_DIR
 * - Creates TXT in $TXT_DIR (same base filename)
 * - Applies heuristic fix for duplicated point heads like "7.1" -> second occurrence becomes "7.^1"
 *
 * Run:
 *   php embpd/tools/pdf_to_txt.php
 *
 * Optional args:
 *   php embpd/tools/pdf_to_txt.php --pdf=embpd/normativi_pdf --txt=embpd/normativi_txt
 */

function arg(string $name, ?string $default = null): ?string {
  global $argv;
  foreach ($argv as $a) {
    if (str_starts_with($a, "--{$name}=")) return substr($a, strlen("--{$name}="));
  }
  return $default;
}

$BASE = dirname(__DIR__); // embpd/
$PDF_DIR = arg('pdf', $BASE . '/data/normativi_pdf');
$TXT_DIR = arg('txt', $BASE . '/data/normativi');

if (!is_dir($PDF_DIR)) {
  fwrite(STDERR, "PDF dir not found: {$PDF_DIR}\n");
  exit(1);
}
if (!is_dir($TXT_DIR)) {
  if (!mkdir($TXT_DIR, 0775, true)) {
    fwrite(STDERR, "Cannot create TXT dir: {$TXT_DIR}\n");
    exit(1);
  }
}

function list_pdfs(string $dir): array {
  $out = [];
  foreach (scandir($dir) ?: [] as $f) {
    if ($f === '.' || $f === '..') continue;
    if (str_ends_with(mb_strtolower($f), '.pdf')) {
      $out[] = $f;
    }
  }
  sort($out, SORT_NATURAL | SORT_FLAG_CASE);
  return $out;
}

function run_pdftotext(string $pdfPath): string {
  // -layout saglabā kolonnas/rindas samērā saprotami
  // -enc UTF-8 lai latviešu burti netiek sačakarēti
  $cmd = "pdftotext -layout -enc UTF-8 " . escapeshellarg($pdfPath) . " - 2>/dev/null";
  $txt = shell_exec($cmd);
  return is_string($txt) ? $txt : '';
}

/**
 * Heuristic: detect duplicated point-head tokens like:
 *   "7.1." or "7.1 " at line starts
 * If same head "7.1" appears 2+ times, convert 2nd+ to "7.^1".
 *
 * Works for patterns at line start:
 *   7.1. ...
 *   7.1 ...
 */
function fix_prim_points(string $text): array {
  $lines = preg_split("/\R/u", $text) ?: [];
  $counts = [];      // "7.1" => total seen
  $changes = 0;

  // 1) First pass: count point heads
  foreach ($lines as $ln) {
    if (preg_match('/^\s*(\d+)\.(\d+)\s*(?:[.)]|$)/u', $ln, $m)) {
      $key = $m[1] . '.' . $m[2];
      $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
  }

  // 2) Second pass: replace duplicates (2nd+ occurrence) => ^ (prim)
  $seen = [];
  foreach ($lines as $i => $ln) {
    if (preg_match('/^(\s*)(\d+)\.(\d+)(\s*(?:[.)]))/u', $ln, $m)) {
      $lead = $m[1];
      $a = $m[2];
      $b = $m[3];
      $tail = $m[4];
      $key = $a . '.' . $b;

      if (($counts[$key] ?? 0) >= 2) {
        $seen[$key] = ($seen[$key] ?? 0) + 1;

        // starting from 2nd occurrence -> make it prim
        if ($seen[$key] >= 2) {
          // Replace only the head at the beginning
          $newHead = $lead . $a . '.^' . $b;
          $lines[$i] = preg_replace('/^' . preg_quote($lead . $a . '.' . $b, '/') . '/u', $newHead, $ln, 1);
          $changes++;
        }
      }
    }
  }

  return [implode("\n", $lines), $changes, $counts];
}

function write_file(string $path, string $content): void {
  file_put_contents($path, $content);
}

$pdfs = list_pdfs($PDF_DIR);
if (!$pdfs) {
  echo "No PDFs found in: {$PDF_DIR}\n";
  exit(0);
}

echo "PDF_DIR: {$PDF_DIR}\n";
echo "TXT_DIR: {$TXT_DIR}\n";
echo "Found PDFs: " . count($pdfs) . "\n\n";

foreach ($pdfs as $pdf) {
  $pdfPath = $PDF_DIR . '/' . $pdf;
  $base = preg_replace('/\.pdf$/iu', '', $pdf) ?? $pdf;
  $txtPath = $TXT_DIR . '/' . $base . '.txt';

  echo "-> Converting: {$pdf}\n";

  $raw = run_pdftotext($pdfPath);
  $raw = trim($raw);

  if ($raw === '') {
    echo "   !! Empty text (pdftotext failed or PDF has no extractable text)\n";
    continue;
  }

  [$fixed, $changes] = fix_prim_points($raw);

  write_file($txtPath, $fixed);

  echo "   Saved: " . basename($txtPath) . " | prim-fixes: {$changes}\n";
}

echo "\nDone.\n";
