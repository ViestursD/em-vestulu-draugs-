<?php
declare(strict_types=1);

function set_session_cookie(string $session): void {
  setcookie('embpd_session', $session, [
    'expires'  => time() + 60*60*24*30,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

function get_cookie_session(): string {
  $s = (string)($_COOKIE['embpd_session'] ?? '');
  return trim($s);
}
