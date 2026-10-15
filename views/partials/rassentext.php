<?php
/**
 * views/partials/rassentext.php
 *
 * Darstellung eines gegliederten Rassentextes (siehe
 * RassentextGliederung::fuerAnzeige()) im Stil der Rassen-Detailseite:
 * jeder Abschnitt bekommt einen Farbblock als Überschrift, der auf
 * breiten Bildschirmen links neben dem Text mitläuft. Die Farbe hängt am
 * Abschnitt, nicht an der Rasse, damit "Wesen" überall gleich aussieht.
 *
 * Genutzt von breed_detail.php und der Vorschau in admin_rassentexte.php.
 */

declare(strict_types=1);

/**
 * Farbblock je Abschnitt. Alle Paare erfüllen WCAG AA für großen Text,
 * die Überschriften sind fett und mindestens 20px.
 *
 * @return array{block: string, text: string}
 */
function rassentext_farbe(string $titel): array
{
    return match ($titel) {
        'Wesen'                    => ['block' => 'bg-tanne',   'text' => 'text-creme'],
        'Haltung und Erziehung'    => ['block' => 'bg-fell',    'text' => 'text-creme'],
        'Pflege'                   => ['block' => 'bg-pfote',   'text' => 'text-fellDk'],
        'Aussehen'                 => ['block' => 'bg-fellDk',  'text' => 'text-creme'],
        'Herkunft und Geschichte'  => ['block' => 'bg-sand',    'text' => 'text-fellDk'],
        'Angaben aus dem Standard' => ['block' => 'bg-sand',    'text' => 'text-fellDk'],
        default                    => ['block' => 'bg-tanneDk', 'text' => 'text-creme'],
    };
}

function rassentext_anker(string $titel): string
{
    $slug = strtr(mb_strtolower($titel), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return 'abschnitt-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
}

/**
 * Sprungleiste zu den Abschnitten (ab zwei Abschnitten sinnvoll).
 *
 * @param array<int, array{titel: string, absaetze: array<int, string>}> $abschnitte
 */
function render_rassentext_sprungleiste(array $abschnitte): string
{
    if (count($abschnitte) < 2) {
        return '';
    }
    $html = '<nav aria-label="Abschnitte" class="flex flex-wrap gap-2 mb-8">';
    foreach ($abschnitte as $a) {
        $f = rassentext_farbe($a['titel']);
        $html .= '<a href="#' . e(rassentext_anker($a['titel'])) . '" class="' . $f['block'] . ' ' . $f['text']
            . ' text-sm font-bold px-3 py-2 min-h-[44px] inline-flex items-center rounded-sm hover:brightness-110 focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-fellDk transition">'
            . e($a['titel']) . '</a>';
    }
    return $html . '</nav>';
}

/**
 * @param array{kurzportraet: ?string, einleitung: array<int, string>, abschnitte: array<int, array{titel: string, absaetze: array<int, string>}>} $anzeige
 */
function render_rassentext_abschnitte(array $anzeige): string
{
    $html = '';
    if (!empty($anzeige['einleitung'])) {
        $html .= '<div class="lg:grid lg:grid-cols-12 lg:gap-10 mb-10"><div class="lg:col-start-5 lg:col-span-8 space-y-4">';
        foreach ($anzeige['einleitung'] as $p) {
            $html .= '<p class="text-[1.0625rem] leading-[1.75] text-fellDk max-w-[60ch]">' . nl2br(e($p)) . '</p>';
        }
        $html .= '</div></div>';
    }

    foreach ($anzeige['abschnitte'] as $a) {
        $f = rassentext_farbe($a['titel']);
        $anker = rassentext_anker($a['titel']);
        $html .= '<section aria-labelledby="' . e($anker) . '-titel" id="' . e($anker) . '" class="scroll-mt-6 lg:grid lg:grid-cols-12 lg:gap-10 mb-12">'
            . '<div class="lg:col-span-4 mb-4 lg:mb-0">'
            . '<h2 id="' . e($anker) . '-titel" class="' . $f['block'] . ' ' . $f['text'] . ' lg:sticky lg:top-6 text-xl sm:text-2xl font-black leading-tight px-5 py-4 rounded-sm">'
            . e($a['titel']) . '</h2></div>'
            . '<div class="lg:col-span-8 space-y-4">';
        foreach ($a['absaetze'] as $p) {
            $html .= '<p class="text-[1.0625rem] leading-[1.75] text-fellDk max-w-[60ch]">' . nl2br(e($p)) . '</p>';
        }
        $html .= '</div></section>';
    }
    return $html;
}
