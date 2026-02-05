<?php



function convert_words_to_number(string $text): ?int {

    $wordToNumber = [

        'viens' => 1,

        'divi' => 2,

        'trīs' => 3,

        'četri' => 4,

        'pieci' => 5,

        'seši' => 6,

        'septiņi' => 7,

        'astoņi' => 8,

        'deviņi' => 9,

        'desmit' => 10,

        'divdesmit' => 20,

        'trīsdesmit' => 30,

        'četrdesmit' => 40,

        'piecdesmit' => 50,

        'sešdesmit' => 60,

        // Pievienojiet vairāk vārdu, ja nepieciešams

    ];



    foreach ($wordToNumber as $word => $number) {

        if (stripos($text, $word) !== false) {

            return $number;

        }

    }



    return null;

}



function determine_buves_grupa_from_rules(string $prompt): string {

    // Pārbauda, vai tekstā ir minēta platība skaitliski

    if (preg_match('/(\d+)\s*m2/i', $prompt, $matches)) {

        $area = (int)$matches[1];

    } else {

        // Ja platība nav skaitliski, pārbauda, vai tā ir uzrakstīta vārdiem

        $area = convert_words_to_number($prompt);

    }



    // Ja platība ir noteikta un ir līdz 60 m2, tad tā ir 1. grupa

    if ($area !== null && $area <= 60) {

        return '1';

    }



    // Pievienojiet citus nosacījumus, balstoties uz noteikumiem Nr. 500

    // Piemēram, pārbaudiet, vai platība ir lielāka par 60 m2, bet mazāka par 2000 m2

    if ($area !== null && $area > 60 && $area < 2000) {

        return '2';

    }



    // Piemēram, pārbaudiet, vai platība ir lielāka par 2000 m2

    if ($area !== null && $area >= 2000) {

        return '3';

    }



    // Ja nevar noteikt, atgriež 'nezinu'

    return 'nezinu';

}

/**
 * ✅ Konvertē objekts vērtību pamatojoties uz area (ja ≤25 m²)
 * 
 * Piemērs:
 *   determine_objekts_variant('mazeka', 'maču ēka 20 m2', 'no')
 *   → 'mazeka_ar_pilsetas' (jo area ≤ 25 un nav kultūras pieminekļa)
 *   
 *   determine_objekts_variant('mazeka', 'maču ēka 20 m2', 'yes')
 *   → 'mazeka' (jo tā ir kultūras piemineklis)
 */
function determine_objekts_variant(string $objekts, string $prompt, string $kulturasPiemineklis): string {
  // Tikai mazeka gadījumā veicam konversiju
  if ($objekts !== 'mazeka') {
    return $objekts;
  }

  // Ekstrahē area no prompt
  $area = extract_area_from_prompt($prompt);
  
  // Ja area ≤ 25 m² un NAV kultūras piemineklis → 'mazeka_ar_pilsetas'
  if ($area !== null && $area <= 25 && $kulturasPiemineklis !== 'yes') {
    return 'mazeka_ar_pilsetas';
  }

  // Ja kultūras piemineklis → pamet standarta 'mazeka'
  return $objekts;
}

/**
 * ✅ Ekstrahē numeric area (m2) no prompt teksta
 * Meklē "XX m2", "XX m²", vai arī vārdiskus skaitļus
 * 
 * Atgriež:
 *   - integer, ja atrasts
 *   - null, ja nav atrasts
 */
function extract_area_from_prompt(string $prompt): ?int {
  // Numeric regex: "20 m2", "20m2", "20 m²"
  if (preg_match('/(\d+)\s*m[2²]/i', $prompt, $matches)) {
    return (int)$matches[1];
  }

  // Vārdiskā forma: "desmit m2", "divdesmit m2", utt.
  // Meklējam: [vārds] + whitespace + m2/m²
  if (preg_match('/(\b[a-zāčēģķļņšžū]+)\s+m[2²]/i', $prompt, $matches)) {
    $word = strtolower($matches[1]);
    $num = convert_words_to_number($word);
    if ($num !== null) {
      return $num;
    }
  }

  return null;
}

