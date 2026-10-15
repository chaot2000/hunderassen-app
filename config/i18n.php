<?php
/**
 * config/i18n.php
 *
 * Sprache der Oberfläche, Deutsch oder Englisch.
 *
 * Texte stehen in lang/de.php und lang/en.php als Schlüssel => Text.
 * In Seiten: <?= e(t('nav.rassen')) ?>. Platzhalter in geschweiften
 * Klammern: t('kopf.hallo', ['name' => $name]).
 *
 * Welche Sprache gilt:
 *  1. eingeloggt: users.sprache (liegt nach dem Login in der Session)
 *  2. nicht eingeloggt: Auswahl über den Umschalter (Session)
 *  3. sonst die bevorzugte Sprache des Browsers (Accept-Language)
 *  4. sonst Deutsch
 *
 * Fehlt ein Text auf Englisch, kommt der deutsche. Fehlt er ganz, steht
 * der Schlüssel da, damit die Lücke beim Testen auffällt.
 */

declare(strict_types=1);

const SPRACHEN = ['de' => 'Deutsch', 'en' => 'English'];
const STANDARD_SPRACHE = 'de';

function aktuelle_sprache(): string
{
    $kandidaten = [
        $_SESSION['user']['sprache'] ?? null,
        $_SESSION['sprache'] ?? null,
        browser_sprache(),
    ];
    foreach ($kandidaten as $sprache) {
        if (is_string($sprache) && isset(SPRACHEN[$sprache])) {
            return $sprache;
        }
    }
    return STANDARD_SPRACHE;
}

/** Erste unterstützte Sprache aus Accept-Language, z. B. "en-US,en;q=0.9,de;q=0.8" → "en". */
function browser_sprache(): ?string
{
    $kopf = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $gewichtet = [];
    foreach (explode(',', $kopf) as $i => $teil) {
        if (!preg_match('/^\s*([a-z]{2})(?:-[A-Za-z0-9]+)?\s*(?:;\s*q=([0-9.]+))?\s*$/i', $teil, $m)) {
            continue;
        }
        $code = strtolower($m[1]);
        $q = isset($m[2]) && $m[2] !== '' ? (float) $m[2] : 1.0;
        // Reihenfolge als zweites Kriterium, bei gleichem q gewinnt die frühere.
        if (isset(SPRACHEN[$code]) && !isset($gewichtet[$code])) {
            $gewichtet[$code] = [$q, -$i];
        }
    }
    if ($gewichtet === []) {
        return null;
    }
    arsort($gewichtet);
    return array_key_first($gewichtet);
}

/** @return array<string, string> */
function sprachtexte(string $sprache): array
{
    static $cache = [];
    if (!isset($cache[$sprache])) {
        $datei = __DIR__ . '/../lang/' . $sprache . '.php';
        $cache[$sprache] = isset(SPRACHEN[$sprache]) && is_file($datei) ? require $datei : [];
    }
    return $cache[$sprache];
}

/**
 * Text zum Schlüssel in der aktuellen Sprache. Ergebnis ist
 * unescaped, also bei der Ausgabe e() verwenden.
 *
 * @param array<string, string|int> $platzhalter
 */
function t(string $schluessel, array $platzhalter = [], ?string $sprache = null): string
{
    $sprache ??= aktuelle_sprache();
    $text = sprachtexte($sprache)[$schluessel]
        ?? sprachtexte(STANDARD_SPRACHE)[$schluessel]
        ?? $schluessel;
    foreach ($platzhalter as $name => $wert) {
        $text = str_replace('{' . $name . '}', (string) $wert, $text);
    }
    return $text;
}
