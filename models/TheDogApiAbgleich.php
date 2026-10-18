<?php
/**
 * models/TheDogApiAbgleich.php
 *
 * Ordnet VDH-Rassen den Rassen von TheDogAPI zu. Genutzt von
 * admin_thedogapi_review.php, gemessen mit tools/thedogapi_abgleich_messen.php.
 *
 * Vorgehen je Rasse, der erste Treffer gewinnt:
 *  1. schon zugeordnet (breeds.thedogapi_id)                     → 100
 *  2. englischer Name (breeds.name_en) passt exakt               → 100
 *  3. feste Zuordnung (MANUELL), für Namen ohne Ähnlichkeit       → 100
 *  4. ein Namensteil passt exakt nach Aufbereitung                → 98
 *     (VDH-Namen sind oft "Originalname, deutscher Name"; dazu die
 *     alternativen Namen und Varianten ohne Klammerzusatz)
 *  5. ein Namensteil passt exakt nach Wort-Übersetzung            → 95
 *  6. sonst Ähnlichkeit, höchstens 84, also nie "sicher"
 *
 * Aufbereitung: klein, Akzente weg (é → e, ä → a), Satzzeichen weg.
 * Übersetzt werden ganze Wörter, deutsche Endungen (-er, -e, -es, -en)
 * und zusammengesetzte Wörter ("zwergspitz" → "miniature spitz").
 */

declare(strict_types=1);

class TheDogApiAbgleich
{
    /** Ab hier gilt ein Treffer als sicher und ist vorausgewählt. */
    public const SICHER = 85;

    /** Darunter gilt es als "kein Treffer". */
    public const SCHWELLE = 55;

    /** Deutsche Wörter und Wortteile → Englisch (aufbereitet, ohne Umlaute). */
    private const WOERTER = [
        'schaferhund' => 'shepherd dog', 'hutehund' => 'sheepdog', 'treibhund' => 'cattle dog',
        'sennenhund' => 'mountain dog', 'berghund' => 'mountain dog', 'hirtenhund' => 'shepherd dog',
        'vorstehhund' => 'pointer', 'wachtelhund' => 'spaniel', 'wasserhund' => 'water dog',
        'windhund' => 'greyhound', 'windspiel' => 'greyhound', 'laufhund' => 'hound', 'bracke' => 'hound',
        'schweisshund' => 'scent hound', 'stoberhund' => 'spaniel', 'apportierhund' => 'retriever',
        'hund' => 'dog', 'dogge' => 'mastiff', 'bulldogge' => 'bulldog', 'pudel' => 'poodle',
        'dackel' => 'dachshund', 'teckel' => 'dachshund', 'dachsbracke' => 'dachsbracke',
        'spitz' => 'spitz', 'pinscher' => 'pinscher', 'schnauzer' => 'schnauzer', 'terrier' => 'terrier',
        'zwerg' => 'miniature', 'riesen' => 'giant', 'gross' => 'large', 'klein' => 'small', 'mittel' => 'medium',
        'kurzhaar' => 'shorthaired', 'langhaar' => 'longhaired', 'rauhaar' => 'wirehaired', 'rauhhaar' => 'wirehaired',
        'drahthaar' => 'wirehaired', 'stichelhaar' => 'wirehaired', 'glatthaar' => 'smooth', 'kurzhaarig' => 'shorthaired',
        'langhaarig' => 'longhaired', 'rauhaarig' => 'wirehaired',
        'deutsch' => 'german', 'englisch' => 'english', 'franzosisch' => 'french', 'belgisch' => 'belgian',
        'amerikanisch' => 'american', 'irisch' => 'irish', 'schottisch' => 'scottish', 'sibirisch' => 'siberian',
        'russisch' => 'russian', 'ungarisch' => 'hungarian', 'osterreichisch' => 'austrian', 'schweizer' => 'swiss',
        'spanisch' => 'spanish', 'portugiesisch' => 'portuguese', 'italienisch' => 'italian', 'hollandisch' => 'dutch',
        'niederlandisch' => 'dutch', 'japanisch' => 'japanese', 'japan' => 'japanese', 'chinesisch' => 'chinese',
        'tibetisch' => 'tibetan', 'tibet' => 'tibetan', 'finnisch' => 'finnish', 'finnen' => 'finnish',
        'schwedisch' => 'swedish', 'norwegisch' => 'norwegian', 'danisch' => 'danish', 'polnisch' => 'polish',
        'tschechisch' => 'czech', 'slowakisch' => 'slovakian', 'kroatisch' => 'croatian', 'serbisch' => 'serbian',
        'bosnisch' => 'bosnian', 'griechisch' => 'greek', 'turkisch' => 'turkish', 'australisch' => 'australian',
        'kanadisch' => 'canadian', 'mexikanisch' => 'mexican', 'peruanisch' => 'peruvian', 'islandisch' => 'icelandic',
        'island' => 'icelandic', 'karelisch' => 'karelian', 'mallorquinisch' => 'majorca', 'thai' => 'thai',
        'kaukasisch' => 'caucasian', 'mittelasiatisch' => 'central asian', 'anatolisch' => 'anatolian',
        'pyrenaen' => 'pyrenean', 'bayerisch' => 'bavarian', 'estnisch' => 'estonian', 'lettisch' => 'latvian',
        'litauisch' => 'lithuanian', 'rumanisch' => 'romanian', 'bulgarisch' => 'bulgarian', 'slowenisch' => 'slovenian',
        'montenegrinisch' => 'montenegrin', 'argentinisch' => 'argentine', 'brasilianisch' => 'brazilian',
        'chilenisch' => 'chilean', 'kubanisch' => 'cuban', 'marokkanisch' => 'moroccan', 'afghanisch' => 'afghan',
        'kretisch' => 'cretan', 'sizilianisch' => 'sicilian', 'katalanisch' => 'catalan', 'kanarisch' => 'canarian',
        'ibizenkisch' => 'ibizan', 'ostsibirisch' => 'east siberian', 'westsibirisch' => 'west siberian',
        'jakutisch' => 'yakutian', 'gronlandisch' => 'greenland', 'gronland' => 'greenland', 'toy' => 'toy', 'kanaan' => 'canaan',
        'koreanisch' => 'korean', 'taiwanesisch' => 'taiwan', 'thailandisch' => 'thai', 'chinesisch' => 'chinese',
        'schapendoes' => 'schapendoes', 'retriever' => 'retriever', 'spaniel' => 'spaniel', 'setter' => 'setter',
        'collie' => 'collie', 'laika' => 'laika', 'mastiff' => 'mastiff', 'griffon' => 'griffon', 'basset' => 'basset',
        'molosser' => 'molosser', 'muensterlander' => 'munsterlander', 'munsterlander' => 'munsterlander',
        'schwarz' => 'black', 'weiss' => 'white', 'blau' => 'blue', 'rot' => 'red', 'gelb' => 'yellow', 'braun' => 'brown',
        'kontinental' => 'continental', 'mit' => '', 'der' => '', 'die' => '', 'das' => '', 'von' => '', 'vom' => '', 'und' => 'and',
    ];

    /** Feste Zuordnungen für Namen ohne jede Ähnlichkeit (aufbereiteter VDH-Name → TheDogAPI-Name). */
    public const MANUELL = [
        'deutscher schaferhund' => 'German Shepherd',
        'deutsche dogge' => 'Great Dane',
        'berner sennenhund' => 'Bernese Mountain Dog',
        'grosser schweizer sennenhund' => 'Greater Swiss Mountain Dog',
        'zwergpinscher' => 'Miniature Pinscher',
        'rauhaardackel' => 'Dachshund', 'kurzhaardackel' => 'Dachshund', 'langhaardackel' => 'Dachshund',
        'mops' => 'Pug', 'malteser' => 'Maltese', 'dalmatiner' => 'Dalmatian', 'neufundlander' => 'Newfoundland',
        'bernhardiner' => 'Saint Bernard', 'bologneser' => 'Bolognese', 'havaneser' => 'Havanese',
        'italienisches windspiel' => 'Italian Greyhound', 'islandhund' => 'Icelandic Sheepdog',
        'zwergspitz' => 'Pomeranian', 'dobermann' => 'Doberman Pinscher', 'akita' => 'Akita',
        'tibet terrier' => 'Tibetan Terrier', 'tibet spaniel' => 'Tibetan Spaniel',
        'afghanischer windhund' => 'Afghan Hound', 'irish red setter' => 'Irish Setter',
        'deutsch kurzhaar' => 'German Shorthaired Pointer', 'deutsch drahthaar' => 'German Wirehaired Pointer',
        'deutsch langhaar' => 'German Longhaired Pointer', 'deutsch stichelhaar' => 'German Roughhaired Pointer',
        'samojede' => 'Samoyed', 'tiroler bracke' => 'Tyrolean Hound',
        'westfalische dachsbracke' => 'Westphalian Dachsbracke', 'alpenlandische dachsbracke' => 'Alpine Dachsbracke',
        'hannoverscher schweisshund' => 'Hanoverian Scenthound', 'bayerischer gebirgsschweisshund' => 'Bavarian Mountain Hound',
        'deutscher jagdterrier' => 'Jagdterrier', 'mastiff' => 'English Mastiff', 'schnauzer' => 'Standard Schnauzer',
        'toypudel' => 'Poodle (Toy)', 'zwergpudel' => 'Poodle (Miniature)', 'grosspudel' => 'Poodle',
        'fox terrier glatthaar' => 'Smooth Fox Terrier', 'fox terrier drahthaar' => 'Wire Fox Terrier',
        'norwegischer elchhund' => 'Norwegian Elkhound', 'norwegischer elchhund schwarz' => 'Black Norwegian Elkhound',
        'norwegischer elchhund grau' => 'Norwegian Elkhound',
        'bergamasker hirtenhund' => 'Bergamasco Sheepdog', 'hrvatski ovcar' => 'Croatian Sheepdog',
        'gos d atura catala' => 'Catalan Sheepdog', 'chodsky pes' => 'Bohemian Shepherd',
        'epagneul breton' => 'Brittany', 'english pointer' => 'Pointer',
        'grossspitz' => 'German Spitz', 'mittelspitz' => 'German Spitz', 'kleinspitz' => 'German Spitz',
        // Originalnamen, die TheDogAPI anders schreibt (geprüft mit tools/thedogapi_abgleich_messen.php)
        'cao da serra de aires' => 'Portuguese Sheepdog', 'berger de picardie' => 'Berger Picard',
        'xoloitzcuintle' => 'Xoloitzcuintli', 'vastgotaspets' => 'Swedish Vallhund', 'stabijhoun' => 'Stabyhoun',
        'schweizer niederlaufhund' => 'Schweizerischer Niederlaufhund', 'saarloos wolfhond' => 'Saarloos Wolfdog',
        'perro de pastor mallorquin' => 'Majorca Shepherd Dog',
        'irish soft coated wheaten terrier' => 'Soft Coated Wheaten Terrier',
        'irish glen of imaal terrier' => 'Glen of Imaal Terrier', 'hollandse smoushond' => 'Dutch Smoushond',
        'hellinikos ichnilatis' => 'Greek Harehound', 'francais tricolore' => 'Chien Français Tricolore',
        'francais blanc et orange' => 'Chien Français Blanc et Orange', 'francais blanc et noir' => 'Chien Français Blanc et Noir',
        'epagneul picard' => 'Picardy Spaniel', 'epagneul bleu de picardie' => 'Blue Picardy Spaniel',
        'english toy terrier black and tan' => 'English Toy Terrier (Black & Tan)',
        'drentsche patrijshond' => 'Drentse Patrijshond', 'bobtail' => 'Old English Sheepdog',
        'grosser munsterlander vorstehhund' => 'Large Münsterländer', 'cao da serra da estrela' => 'Estrela Mountain Dog',
        'russkiy toy' => 'Russian Toy', 'slovensky cuvac' => 'Slovak Cuvac', 'yakutskaya laika' => 'Yakutian Laika',
        'gammel dansk honsehund' => 'Old Danish Pointer', 'epagneul francais' => 'French Spaniel',
        'russisch europaischer laika' => 'Russo-European Laika', 'perro sin pelo del peru' => 'Peruvian Inca Orchid',
        'karelischer barenhund' => 'Karelian Bear Dog', 'shiba' => 'Shiba Inu', 'kishu' => 'Kishu Ken',
        'laekenois' => 'Belgian Laekenois', 'tervueren' => 'Belgian Tervuren', 'malinois' => 'Belgian Malinois',
        'groenendael' => 'Belgian Shepherd', 'nederlandse kooikerhondje' => 'Kooikerhondje',
        'shar pei' => 'Chinese Shar-Pei', 'barsoi' => 'Borzoi', 'kintamani bali dog' => 'Kintamani',
        'tschechoslowakischer wolfhund' => 'Czechoslovakian Wolfdog', 'montenegrinischer gebirgslaufhund' => 'Montenegrin Mountain Hound',
        'posavski gonic' => 'Posavac Hound', 'perdiguero de burgos' => 'Burgos Pointer', 'podenco ibicenco' => 'Ibizan Hound',
        'cane corso italiano' => 'Cane Corso', 'deerhound' => 'Scottish Deerhound', 'mastino napoletano' => 'Neapolitan Mastiff',
        'do khyi' => 'Tibetan Mastiff', 'magyar vizsla kurzhaar' => 'Vizsla', 'magyar vizsla drahthaar' => 'Wirehaired Vizsla',
        'korea jindo dog' => 'Jindo', 'bordeauxdogge' => 'Dogue de Bordeaux', 'polski owczarek nizinny' => 'Polish Lowland Sheepdog',
        'ciobanesc romanesc corb' => 'Romanian Raven Shepherd Dog',
        'ciobanesc romanesc de bucovina' => 'Bucovina Shepherd Dog', 'ciobanesc romanesc carpatin' => 'Carpathian Shepherd Dog',
        'ciobanesc romanesc mioritic' => 'Romanian Mioritic Shepherd Dog', 'erdelyi kopo' => 'Transylvanian Hound',
        'griffon d arret a poil dur korthals' => 'Wirehaired Pointing Griffon', 'slowakischer rauhbart' => 'Slovak Rough-haired Pointer',
        'maremmen abruzzen schaferhund' => 'Maremma Sheepdog', 'collie langhaar' => 'Rough Collie', 'collie kurzhaar' => 'Smooth Collie',
        'berger des pyrenees langhaar' => 'Pyrenean Sheepdog', 'berger des pyrenees kurzhaar' => 'Pyrenean Sheepdog',
        'perro dogo mallorquin' => 'Ca de Bou', 'wolfsspitz' => 'Keeshond', 'brusseler griffon' => 'Griffon Bruxellois',
        'berger de brie' => 'Briard',
        'berger de beauce' => 'Beauceron', 'prager rattler' => 'Prazsky Krysarik', 'chien d artois' => 'Artois Hound',
        'epagneul de saint usuge' => 'Saint-Usuge Spaniel', 'cao fila de sao miguel' => 'Saint Miguel Cattle Dog',
        'suomenajokoira' => 'Finnish Hound', 'steirische rauhhaarbracke' => 'Styrian Coarse-haired Hound',
        'dreifarbiger serbischer laufhund' => 'Serbian Tricolour Hound', 'berner laufhund' => 'Schweizer Laufhund', 'jura laufhund' => 'Schweizer Laufhund', 'luzerner laufhund' => 'Schweizer Laufhund', 'schwyzer laufhund' => 'Schweizer Laufhund', 'kleinpudel' => 'Poodle', 'lapinporokoira' => 'Lapponian Herder',
    ];

    /** Ein aufbereiteter Vergleichstext: klein, ohne Akzente und Satzzeichen. */
    public static function aufbereiten(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
        if (class_exists('Normalizer')) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($text, Normalizer::FORM_D));
        } else {
            $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
                'ç' => 'c', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'û' => 'u', 'ñ' => 'n', 'š' => 's', 'č' => 'c', 'ž' => 'z']);
        }
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Vergleichsnamen einer VDH-Rasse: Teile an ", ", alternative Namen,
     * jeweils auch ohne Klammerzusatz. Gekürzte Teile ("…") fallen weg.
     *
     * @return list<string> aufbereitet, ohne Dubletten
     */
    public static function kandidaten(string $name, ?string $alternativeNamen = null): array
    {
        $roh = array_merge(explode(', ', $name), $alternativeNamen ? explode(',', $alternativeNamen) : []);
        $ergebnis = [];
        foreach ($roh as $teil) {
            $teil = trim($teil);
            if ($teil === '' || str_ends_with($teil, '…') || str_ends_with($teil, '?')) {
                continue;
            }
            foreach ([$teil, (string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $teil)] as $variante) {
                $a = self::aufbereiten($variante);
                if ($a !== '') {
                    $ergebnis[$a] = true;
                }
            }
        }
        // Ganzer Name zusätzlich, für feste Zuordnungen auf den vollen Namen
        $ganz = self::aufbereiten($name);
        if ($ganz !== '') {
            $ergebnis[$ganz] = true;
        }
        return array_keys($ergebnis);
    }

    /** Wort für Wort ins Englische, auch Endungen und zusammengesetzte Wörter. */
    public static function uebersetzen(string $aufbereitet): string
    {
        $woerter = [];
        foreach (explode(' ', $aufbereitet) as $wort) {
            $woerter[] = self::wortUebersetzen($wort);
        }
        return trim((string) preg_replace('/\s+/', ' ', implode(' ', $woerter)));
    }

    private static function wortUebersetzen(string $wort): string
    {
        return self::wortBekannt($wort) ?? $wort;
    }

    /** Übersetzung, wenn das Wort (oder alle seine Teile) bekannt ist, sonst null. */
    private static function wortBekannt(string $wort, int $tiefe = 0): ?string
    {
        if (isset(self::WOERTER[$wort])) {
            return self::WOERTER[$wort];
        }
        // Adjektivendung: deutscher, deutsche, deutsches, deutschen
        foreach (['er', 'es', 'en', 'e'] as $endung) {
            if (strlen($wort) > strlen($endung) + 3 && str_ends_with($wort, $endung)) {
                $stamm = substr($wort, 0, -strlen($endung));
                if (isset(self::WOERTER[$stamm])) {
                    return self::WOERTER[$stamm];
                }
            }
        }
        // Zusammengesetzt: längster bekannter Anfang, Rest muss ebenfalls
        // bekannt sein ("zwerg" + "schnauzer"), sonst keine Übersetzung
        if ($tiefe < 3) {
            for ($i = strlen($wort) - 3; $i >= 3; $i--) {
                $anfang = substr($wort, 0, $i);
                if (!isset(self::WOERTER[$anfang])) {
                    continue;
                }
                $rest = self::wortBekannt(substr($wort, $i), $tiefe + 1);
                if ($rest !== null) {
                    return trim(self::WOERTER[$anfang] . ' ' . $rest);
                }
            }
        }
        return null;
    }

    /** Sortierte Wortliste ohne Füllwort "dog", als Schlüssel. */
    private static function wortmenge(string $aufbereitet): string
    {
        $woerter = array_values(array_filter(explode(' ', $aufbereitet), fn($w) => $w !== '' && $w !== 'dog'));
        sort($woerter);
        return implode(' ', $woerter);
    }

    /**
     * Bester Treffer für eine Rasse.
     *
     * @param array{name: string, name_en?: ?string, alternative_namen?: ?string, thedogapi_id?: ?string} $rasse
     * @param list<array{id: int|string, name: string}> $apiBreeds
     * @return array{breed: array, score: int, grund: string}|null
     */
    public static function bestesMatch(array $rasse, array $apiBreeds): ?array
    {
        $nachName = [];
        $nachId = [];
        foreach ($apiBreeds as $b) {
            $nachName[self::aufbereiten((string) $b['name'])] = $b;
            $nachId[(string) $b['id']] = $b;
        }

        $id = trim((string) ($rasse['thedogapi_id'] ?? ''));
        if ($id !== '' && isset($nachId[$id])) {
            return ['breed' => $nachId[$id], 'score' => 100, 'grund' => 'schon zugeordnet'];
        }
        $en = self::aufbereiten((string) ($rasse['name_en'] ?? ''));
        if ($en !== '' && isset($nachName[$en])) {
            return ['breed' => $nachName[$en], 'score' => 100, 'grund' => 'englischer Name'];
        }

        $kandidaten = self::kandidaten((string) $rasse['name'], $rasse['alternative_namen'] ?? null);
        foreach ($kandidaten as $k) {
            $ziel = self::MANUELL[$k] ?? null;
            if ($ziel !== null && isset($nachName[self::aufbereiten($ziel)])) {
                return ['breed' => $nachName[self::aufbereiten($ziel)], 'score' => 100, 'grund' => 'feste Zuordnung'];
            }
        }
        foreach ($kandidaten as $k) {
            if (isset($nachName[$k])) {
                return ['breed' => $nachName[$k], 'score' => 98, 'grund' => 'Name gleich'];
            }
        }
        foreach ($kandidaten as $k) {
            $u = self::uebersetzen($k);
            if (isset($nachName[$u])) {
                return ['breed' => $nachName[$u], 'score' => 95, 'grund' => 'übersetzt gleich'];
            }
        }

        // Gleiche Wörter in anderer Reihenfolge, ohne "dog"
        // ("fox terrier smooth" = "Smooth Fox Terrier"). Nur eindeutige.
        $nachWoertern = [];
        foreach ($nachName as $apiName => $b) {
            $nachWoertern[self::wortmenge($apiName)][] = $b;
        }
        foreach ($kandidaten as $k) {
            foreach ([$k, self::uebersetzen($k)] as $variante) {
                $treffer = $nachWoertern[self::wortmenge($variante)] ?? [];
                if (count($treffer) === 1) {
                    return ['breed' => $treffer[0], 'score' => 92, 'grund' => 'gleiche Wörter'];
                }
            }
        }

        // Ähnlichkeit: nie sicher, nur Vorschlag zum Prüfen
        $bester = null;
        $besterScore = 0;
        foreach ($nachName as $apiName => $b) {
            foreach ($kandidaten as $k) {
                foreach ([$k, self::uebersetzen($k)] as $variante) {
                    similar_text($variante, $apiName, $prozent);
                    $score = (int) round(min($prozent, self::SICHER - 1));
                    if ($score > $besterScore) {
                        $besterScore = $score;
                        $bester = $b;
                    }
                }
            }
        }
        if ($bester !== null && $besterScore >= self::SCHWELLE) {
            return ['breed' => $bester, 'score' => $besterScore, 'grund' => 'ähnlich'];
        }
        return null;
    }

    /**
     * Mehrere Vorschläge für eine Rasse ohne Zuordnung: der beste Treffer
     * und danach die ähnlichsten Namen. Die vorhandene Zuordnung zählt
     * hier nicht, damit sich auch eine falsche korrigieren lässt.
     *
     * @param list<array{id: int|string, name: string}> $apiBreeds
     * @return list<array{breed: array, score: int, grund: string}>
     */
    public static function vorschlaege(array $rasse, array $apiBreeds, int $anzahl = 5): array
    {
        $rasse['thedogapi_id'] = null;
        $ergebnis = [];
        $bester = self::bestesMatch($rasse, $apiBreeds);
        if ($bester !== null) {
            $ergebnis[(string) $bester['breed']['id']] = $bester;
        }
        $varianten = [];
        foreach (self::kandidaten((string) $rasse['name'], $rasse['alternative_namen'] ?? null) as $k) {
            $varianten[$k] = true;
            $varianten[self::uebersetzen($k)] = true;
        }
        $en = self::aufbereiten((string) ($rasse['name_en'] ?? ''));
        if ($en !== '') {
            $varianten[$en] = true;
        }
        $aehnlich = [];
        foreach ($apiBreeds as $b) {
            $apiName = self::aufbereiten((string) $b['name']);
            $score = 0;
            foreach (array_keys($varianten) as $v) {
                similar_text((string) $v, $apiName, $prozent);
                $score = max($score, (int) round(min($prozent, self::SICHER - 1)));
            }
            $aehnlich[] = ['breed' => $b, 'score' => $score, 'grund' => 'ähnlich'];
        }
        usort($aehnlich, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($aehnlich as $v) {
            if (count($ergebnis) >= $anzahl) {
                break;
            }
            $ergebnis[(string) $v['breed']['id']] ??= $v;
        }
        return array_values($ergebnis);
    }
}
