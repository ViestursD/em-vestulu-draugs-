<?php
require_once __DIR__ . '/../.env.php';

/**
 * $extraRules = teksts (parasti bullet-list) no DB actions.
 * To pieliekam pie system prompt kā “PAPILDU NOTEIKUMI (no DB)”.
 */
function build_system_prompt_em(string $forma, string $datumsLv, string $jautajums, string $extraRules = ''): string {
  $irEpasts = ($forma === 'epasts');
  $ievads = trim(preg_replace('/\s+/u', ' ',
    "Ekonomikas ministrija (turpmāk – EM) {$datumsLv} ir saņēmusi Jūsu " . ($irEpasts ? "e-pastu" : "vēstuli") . ", kurā lūdzāt sniegt skaidrojumu par {$jautajums}."
  ));

  $extra = '';
  $extraRules = trim($extraRules);
  if ($extraRules !== '') {
    $extra = "\n\nPAPILDU NOTEIKUMI (no DB):\n" . $extraRules . "\n";
  }

  return trim("
Tu esi Ekonomikas ministrijas jurists.

OBLIGĀTS IEVADS (pirmais teikums, nemainīt):
\"{$ievads}\"

NOTEIKUMI:
- Atbildi sāc tieši ar iepriekš norādīto ievadu.
- Pēc ievada seko atbilde plūstošā tekstā (bez sarakstiem).
- Valoda: latviešu.
- Obligāti iekļauj normatīvo pamatojumu šādā formā:

[NORMATĪVAIS_PAMATOJUMS]
Normatīvais akts: <pilns nosaukums>
Atsauce: <pants / daļa / punkts / apakšpunkts>
[/NORMATĪVAIS_PAMATOJUMS]

- Ja neesi pārliecināts par atsauci: raksti “Atsauce: nav noteikta”.
- Normatīvos aktus drīkst izmantot tikai no lietotāja izvēlētajiem TXT failiem, kas iedoti kontekstā.
- Citātu drīkst veidot tikai no šiem TXT failiem.
{$extra}
");
}

function call_openai(array $messages, string $model="gpt-4o", float $temperature=0.6, int $max_tokens=1200): string {
  if (OPENAI_API_KEY === '' || OPENAI_API_KEY === 'PASTE_YOUR_OPENAI_KEY_HERE') {
    throw new Exception("OPENAI_API_KEY nav iestatīts .env.php");
  }

  $payload = json_encode([
    "model" => $model,
    "messages" => $messages,
    "temperature" => $temperature,
    "max_tokens" => $max_tokens
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init("https://api.openai.com/v1/chat/completions");
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Content-Type: application/json",
      "Authorization: Bearer " . OPENAI_API_KEY
    ],
    CURLOPT_POSTFIELDS => $payload
  ]);

  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($res === false) throw new Exception("cURL error: " . curl_error($ch));
 // curl_close($ch); // PHP 8.5: deprecated (no effect since 8.0)


  if ($code < 200 || $code >= 300) throw new Exception("OpenAI error {$code}: {$res}");

  $data = json_decode($res, true);
  $content = $data["choices"][0]["message"]["content"] ?? "";
  return trim((string)$content);
}

