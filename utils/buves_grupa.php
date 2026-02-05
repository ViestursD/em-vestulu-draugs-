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

