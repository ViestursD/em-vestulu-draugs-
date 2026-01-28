<?php
declare(strict_types=1);
require_once __DIR__ . '/openai.php';

/**
 * embpd/utils/norm_validate.php
 *
 * Hard validācija:
 * - allowed akti tiek noteikti no izvēlētajiem TXT failiem (UI izvēle)
 * - pārbauda GPT atbildes [NORMATĪVAIS_PAMATOJUMS] blokā "Normatīvais akts:"
 * - ja neatļauts → automātisks "fix" pārrakstīšanas izsaukums
 */

function extract_norm_act(string $assistant): string
{
    if (preg_match('/Normatīvais\s+akts:\s*(.+)/iu', $assistant, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

/**
 * No izvēlētajiem failiem nosaka allowed flagus.
 * $selectedFiles satur failu nosaukumus (piem. "eku-buvnoteikumi.txt").
 */
function allowed_flags_from_selected_files(array $selectedFiles): array
{
    $allow = [
        'eku'  => false,
        'inz'  => false,
        'visp' => false,
        'lik'  => false,
    ];

    foreach ($selectedFiles as $f) {
        $ff = mb_strtolower((string)$f);

        if (str_contains($ff, 'eku-buvnoteikumi')) $allow['eku'] = true;
        if (str_contains($ff, 'atsevisku-inzenierbuvju-buvnoteikumi')) $allow['inz'] = true;
        if (str_contains($ff, 'visparigie-buvnoteikumi')) $allow['visp'] = true;
        if (str_contains($ff, 'buvniecibas-likums')) $allow['lik'] = true;
    }

    return $allow;
}

/**
 * Nosaka, kurai "kategorijai" pieder GPT uzrakstītais akta nosaukums.
 * Atgriež: eku | inz | visp | lik | unknown
 */
function classify_norm_act(string $act): string
{
    $a = mb_strtolower(trim($act));

    return match (true) {
        $a === '' => 'unknown',

        str_contains($a, 'ēku būvnoteik') || str_contains($a, 'eku buvnoteik') => 'eku',

        str_contains($a, 'atsevišķu inženierbūv') ||
        str_contains($a, 'atsevisku inzenierbuv') ||
        str_contains($a, 'inženierbūv') ||
        str_contains($a, 'inzenierbuv') => 'inz',

        str_contains($a, 'vispārīgie būvnoteik') || str_contains($a, 'visparigie buvnoteik') => 'visp',

        str_contains($a, 'būvniecības lik') || str_contains($a, 'buvniecibas lik') => 'lik',

        default => 'unknown',
    };
}

function is_norm_act_allowed(string $act, array $allowFlags): bool
{
    $kind = classify_norm_act($act);

    if ($kind === 'unknown') {
        // Ja akts nav atpazīstams, drošības pēc aizliedzam
        // (lai GPT neizdomā kaut kādu “MK noteikumi Nr...” kas nav kontekstā).
        return false;
    }

    return !empty($allowFlags[$kind]);
}

function allowed_acts_human_list(array $allowFlags): string
{
    $list = [];
    if (!empty($allowFlags['eku']))  $list[] = 'Ēku būvnoteikumi';
    if (!empty($allowFlags['inz']))  $list[] = 'Atsevišķu inženierbūvju būvnoteikumi';
    if (!empty($allowFlags['visp'])) $list[] = 'Vispārīgie būvnoteikumi';
    if (!empty($allowFlags['lik']))  $list[] = 'Būvniecības likums';
    return $list ? implode(', ', $list) : '(nav atļauto aktu)';
}

/**
 * Ja GPT pamatojumā ielicis neatļautu aktu → pārraksta atbildi.
 *
 * $messages = tie paši messages, ko sūti call_openai() (system + user + normatīvu konteksts).
 * $selectedFiles = UI izvēlēto TXT failu saraksts.
 */
function ensure_allowed_norm_act(array $messages, string $assistant, array $selectedFiles): string
{
    $act = extract_norm_act($assistant);
    if ($act === '') return $assistant;

    $allow = allowed_flags_from_selected_files($selectedFiles);
    if (is_norm_act_allowed($act, $allow)) return $assistant;

    $allowedList = allowed_acts_human_list($allow);

    $fixPrompt = trim("
Tava iepriekšējā atbilde normatīvajā pamatojumā izmanto neatļautu normatīvo aktu: \"$act\".

PĀRRAKSTI visu atbildi, ievērojot:
- Normatīvajā pamatojumā drīkst izmantot tikai: $allowedList.
- Ja no atļautajiem aktiem nav iespējams droši noteikt atsauci, [NORMATĪVAIS_PAMATOJUMS] blokā raksti: \"Atsauce: nav noteikta\".
- Citātu drīkst veidot tikai no kontekstā dotajiem TXT failiem.
- Saglabā EM oficiālo stilu.
");

    $fixMessages = $messages;
    $fixMessages[] = ["role" => "user", "content" => $fixPrompt];

    // call_openai() jau eksistē embpd/utils/openai.php
    $assistant2 = call_openai($fixMessages);
    return trim((string)$assistant2);
}
