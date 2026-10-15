<?php
/**
 * models/TheDogApiFelder.php
 *
 * Welche TheDogAPI-Werte in welches Feld von breeds gehen. Gemeinsame
 * Liste für admin_thedogapi_review.php und admin_thedogapi_apply.php.
 *
 * TheDogAPI ist englisch, deshalb landen Texte in den *_en-Feldern.
 * Das deutsche Temperament entsteht daraus über das Wörterbuch
 * (models/Uebersetzung.php), alles andere übersetzt man von Hand.
 */

declare(strict_types=1);

class TheDogApiFelder
{
    /** Unser Feld => Beschriftung */
    public const TEXT = [
        'name_en'         => 'Name (englisch)',
        'temperament_en'  => 'Temperament (englisch)',
        'lebenserwartung' => 'Lebenserwartung',
        'zuchtzweck_en'   => 'Zuchtzweck (englisch)',
        'beschreibung_en' => 'Beschreibung (englisch)',
    ];

    /** Wert aus einer TheDogAPI-Rasse für eines unserer Felder. */
    public static function wert(array $api, string $feld): string
    {
        $t = fn(string $schluessel): string => trim((string) ($api[$schluessel] ?? ''));
        return match ($feld) {
            'name_en'         => $t('name'),
            'temperament_en'  => $t('temperament'),
            'lebenserwartung' => $t('life_span'),
            'zuchtzweck_en'   => $t('bred_for'),
            'beschreibung_en' => trim($t('description') . "\n\n" . $t('history')),
            default           => '',
        };
    }
}
