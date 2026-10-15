<?php
/**
 * models/Uebersetzung.php
 *
 * Übersetzt englische Rasseninhalte ins Deutsche, ohne externe Dienste.
 * Bisher nur Temperament: eine Kommaliste einzelner Begriffe, die sich
 * über das feste Wörterbuch in daten/woerterbuch_temperament.php
 * übersetzen lässt. Alles andere (Zuchtzweck, Beschreibung) wird von
 * Hand übersetzt, siehe admin_add_breed.php.
 */

declare(strict_types=1);

class Uebersetzung
{
    private static ?array $woerterbuch = null;

    /** @return array<string, string> */
    public static function woerterbuch(): array
    {
        return self::$woerterbuch ??= require __DIR__ . '/../daten/woerterbuch_temperament.php';
    }

    /**
     * "Confident, alert, playful" → "selbstbewusst, aufmerksam, verspielt".
     *
     * text ist null, sobald ein Begriff fehlt. Eine halbe Übersetzung
     * mit englischen Resten soll nicht im deutschen Feld landen.
     *
     * @return array{text: ?string, unbekannt: list<string>}
     */
    public static function temperament(?string $englisch): array
    {
        $begriffe = array_values(array_filter(
            array_map(fn($b) => mb_strtolower(trim($b)), explode(',', (string) $englisch)),
            fn($b) => $b !== ''
        ));
        if ($begriffe === []) {
            return ['text' => null, 'unbekannt' => []];
        }

        $wb = self::woerterbuch();
        $deutsch = [];
        $unbekannt = [];
        foreach ($begriffe as $b) {
            if (isset($wb[$b])) {
                // Doppelte nach der Übersetzung weglassen (smart/clever → klug).
                if (!in_array($wb[$b], $deutsch, true)) {
                    $deutsch[] = $wb[$b];
                }
            } else {
                $unbekannt[] = $b;
            }
        }

        return ['text' => $unbekannt ? null : implode(', ', $deutsch), 'unbekannt' => $unbekannt];
    }
}
