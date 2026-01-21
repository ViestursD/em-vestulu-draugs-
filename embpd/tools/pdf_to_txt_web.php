<?php
declare(strict_types=1);

require_once __DIR__ . '/../.env.php';

// =====================================================
// ✅ AIZSARDZĪBA AR PAROLI
// =====================================================
$pass = (string)($_GET['pass'] ?? $_POST['pass'] ?? '');
if (!defined('EMBPD_ADMIN_PASSWORD') || EMBPD_ADMIN_PASSWORD === '') {
  http_response_code(500);
  echo "❌ EMBPD_ADMIN_PASSWORD nav definēts .env.php";
  exit;
}

if (!hash_equals((string)EMBPD_ADMIN_PASSWORD, $pass)) {
  http_response_code(403);
  echo "<h3>403 Forbidden</h3>";
  echo "<p>Nepareiza parole.</p>";
  echo "<form method='post'>
          <input type='password' name='pass' placeholder='Parole' />
          <button type='submit'>Palaist</button>
        </form>";
  exit;
}

// =====================================================
// ✅ MAPES
// =====================================================
$BASE = dirname(__DIR__); // embpd/
$PDF_DIR = $BASE . '/data/normativi_pdf';
$TXT_DIR = $BASE . '/data/normativi';

// =====================================================
// ✅ FUNKCIJAS
// =====================================================
function list_pdfs(string $dir): array {
  $out = [];
  foreach (scandir($dir) ?: [] as $f) {
    if ($f === '.' || $f === '..') continue;
    if (str_ends_with(mb_strtolower($f), '.pdf')) $out[] = $f;
  }
  sort($out, SORT_NATURAL | SORT_FLAG_CASE);
  return $out;
}

function run_pdftotext(string $pdfPath): string {
  $cmd = "pdftotext -layout -enc UTF-8 " . escapeshellarg($pdfPath) . " - 2>/dev/null";
  $txt = shell_exec($cmd);
  return is_string($txt) ? $txt : '';
}

function fix_prim_points(string $text): array {
  $lines = preg_split("/\R/u", $text) ?: [];
  $counts = [];
  $changes = 0;

  foreach ($lines as $ln) {
    if (preg_match('/^\s*(\d+)\.(\d+)\s*(?:[.)]|$)/u', $ln, $m)) {
      $key = $m[1] . '.' . $m[2];
      $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
  }

  $seen = [];
  foreach ($lines as $i => $ln) {
    if (preg_match('/^(\s*)(\d+)\.(\d+)(\s*(?:[.)]))/u', $ln, $m)) {
      $lead = $m[1];
      $a = $m[2];
      $b = $m[3];
      $key = $a . '.' . $b;

      if (($counts[$key] ?? 0) >= 2) {
        $seen[$key] = ($seen[$key] ?? 0) + 1;

        if ($seen[$key] >= 2) {
          $newHead = $lead . $a . '.^' . $b;
          $lines[$i] = preg_replace(
            '/^' . preg_quote($lead . $a . '.' . $b, '/') . '/u',
            $newHead,
            $ln,
            1
          );
          $changes++;
        }
      }
    }
  }

  return [implode("\n", $lines), $changes];
}

// =====================================================
// ✅ IZPILDE
// =====================================================
header("Content-Type: text/html; charset=utf-8");

echo "<h2>✅ PDF → TXT (normatīvi)</h2>";
echo "<div><b>PDF_DIR:</b> {$PDF_DIR}</div>";
echo "<div><b>TXT_DIR:</b> {$TXT_DIR}</div><hr/>";

if (!is_dir($PDF_DIR)) { echo "❌ Nav mapes: {$PDF_DIR}"; exit; }
if (!is_dir($TXT_DIR)) { echo "❌ Nav mapes: {$TXT_DIR}"; exit; }

$pdfs = list_pdfs($PDF_DIR);
echo "<div>Atrasti PDF: <b>" . count($pdfs) . "</b></div><br/>";

if (!$pdfs) { echo "Nav ko apstrādāt."; exit; }

echo "<pre>";

foreach ($pdfs as $pdf) {
  $pdfPath = $PDF_DIR . '/' . $pdf;
  $base = preg_replace('/\.pdf$/iu', '', $pdf) ?? $pdf;
  $txtPath = $TXT_DIR . '/' . $base . '.txt';

  echo "-> {$pdf}\n";

  $raw = trim(run_pdftotext($pdfPath));
  if ($raw === '') {
    echo "   !! tukšs teksts (PDF var būt skenēts vai aizsargāts)\n";
    continue;
  }

  [$fixed, $changes] = fix_prim_points($raw);
  file_put_contents($txtPath, $fixed);

  echo "   OK -> " . basename($txtPath) . " | prim-fixes: {$changes}\n";
}

echo "\nDONE\n</pre>";

echo "<hr/><div>✅ Pabeigts. TXT faili saglabāti mapē <b>{$TXT_DIR}</b></div>";
