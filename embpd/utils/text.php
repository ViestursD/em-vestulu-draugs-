<?php
function normalizeLV(string $s): string {
  $s = mb_strtolower($s, 'UTF-8');

  if (class_exists('Transliterator')) {
    $tr = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
    if ($tr) $s = $tr->transliterate($s);
  } else {
    $map = ['ā'=>'a','č'=>'c','ē'=>'e','ģ'=>'g','ī'=>'i','ķ'=>'k','ļ'=>'l','ņ'=>'n','š'=>'s','ū'=>'u','ž'=>'z'];
    $s = strtr($s, $map);
  }

  $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function escape_html(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_cookie_session(): ?string {
  return isset($_COOKIE['session_id']) && $_COOKIE['session_id'] !== '' ? $_COOKIE['session_id'] : null;
}

function set_session_cookie(string $sessionId): void {
  setcookie('session_id', $sessionId, [
    'expires'  => time() + 60*60*24*30,
    'path'     => APP_BASE . '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
  ]);
}

function clear_session_cookie(): void {
  setcookie('session_id', '', [
    'expires'  => time() - 3600,
    'path'     => APP_BASE . '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
  ]);
}

function normalize_prim_markers(string $text): string
{
  // §§§1§§§ -> ^1
  $text = preg_replace('/§{3}\s*(\d+)\s*§{3}/u', '^$1', $text);

  // 7^1 -> 7.^1
  $text = preg_replace('/(\d+)\s*\^\s*(\d+)/u', '$1.^$2', $text);

  // 7 . ^ 1 -> 7.^1
  $text = preg_replace('/(\d+)\s*\.\s*\^\s*(\d+)/u', '$1.^$2', $text);

  return $text;
}
function detect_buves_grupa_from_text(string $text): string
{
  $t = normalizeLV($text);

  // 1. grupa
  if (preg_match('/\b(1|1\.|pirma|pirmaa|pirmais|pirmajai|pirmas|pirmas grupas)\b/u', $t)) {
    if (str_contains($t, 'grup')) return '1';
  }

  // 2. grupa
  if (preg_match('/\b(2|2\.|otra|otraa|otrais|otrajai|otras|otras grupas)\b/u', $t)) {
    if (str_contains($t, 'grup')) return '2';
  }

  // 3. grupa
  if (preg_match('/\b(3|3\.|tresa|tresaa|tresais|tresajai|tresas|tresas grupas)\b/u', $t)) {
    if (str_contains($t, 'grup')) return '3';
  }

  return 'nezinu';
}

