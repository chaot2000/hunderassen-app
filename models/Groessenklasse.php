<?php
/**
 * models/Groessenklasse.php
 *
 * Größenklasse einer Rasse aus der Schulterhöhe. Die unteren Grenzen
 * folgen den Agility-Klassen von FCI und VDH (Small unter 35 cm,
 * Medium und Intermediate bis unter 48 cm), "sehr groß" ab 65 cm ist
 * eine eigene Grenze, weil Agility ab 48 cm nicht mehr unterscheidet.
 *
 * Reicht die Spanne über eine Grenze, gehören beide Klassen dazu. Eine
 * Grenze gehört zur oberen Klasse: 35 cm ist mittel.
 *
 * Ohne Datenbank, geschrieben wird in RassenDatenSchreiben.
 */

declare(strict_types=1);

class Groessenklasse
{
    /** Schlüssel in merkmal_werte => [ab cm, unter cm], null = offen */
    public const GRENZEN_CM = [
        'klein'      => [null, 35],
        'mittel'     => [35, 48],
        'gross'      => [48, 65],
        'sehr_gross' => [65, null],
    ];

    /**
     * Klassen für alle Spannen einer Rasse (Rüde, Hündin, beide zusammen).
     *
     * @param iterable<array{0: mixed, 1: mixed}> $spannen [von, bis], Zahlen oder Zahlentexte, null = fehlt
     * @return list<string> Schlüssel aus GRENZEN_CM, leer ohne Angabe
     */
    public static function ausSpannen(iterable $spannen): array
    {
        $zahlen = [];
        foreach ($spannen as $spanne) {
            foreach ([$spanne[0] ?? null, $spanne[1] ?? null] as $z) {
                if ($z !== null && $z !== '' && is_numeric($z) && (float) $z > 0) {
                    $zahlen[] = (float) $z;
                }
            }
        }
        if (!$zahlen) {
            return [];
        }
        [$min, $max] = [min($zahlen), max($zahlen)];
        $klassen = [];
        foreach (self::GRENZEN_CM as $klasse => [$ab, $unter]) {
            if (($unter === null || $min < $unter) && ($ab === null || $max >= $ab)) {
                $klassen[] = $klasse;
            }
        }
        sort($klassen);
        return $klassen;
    }

    /** "klein unter 35 cm, mittel 35 bis unter 48 cm, …" mit den Namen aus der Merkmal-Verwaltung */
    public static function beschreibung(array $namen = []): string
    {
        $teile = [];
        foreach (self::GRENZEN_CM as $klasse => [$ab, $unter]) {
            $name = $namen[$klasse] ?? $klasse;
            $teile[] = $name . ' ' . match (true) {
                $ab === null => 'unter ' . $unter . ' cm',
                $unter === null => 'ab ' . $ab . ' cm',
                default => $ab . ' bis unter ' . $unter . ' cm',
            };
        }
        return implode(', ', $teile);
    }
}
