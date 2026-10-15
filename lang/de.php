<?php
/**
 * lang/de.php: Texte der Oberfläche, Deutsch (Standard und Rückfall).
 *
 * Schlüssel nach Bereich gruppiert (kopf, nav, login, konto, sprache).
 * Jeder Schlüssel hier braucht ein Gegenstück in lang/en.php, das prüft
 * tests/SprachdateienTest.php.
 */

declare(strict_types=1);

return [
    // Kopf und Fuß
    'kopf.titel'               => 'Hunderassen-Verwaltung',
    'kopf.hallo'               => 'Hallo,',
    'kopf.admin'               => 'Admin',
    'fuss.text'                => 'Hunderassen-Verwaltung · intern',

    // Navigation
    'nav.uebersicht'           => 'Übersicht',
    'nav.rassen'               => 'Rassen',
    'nav.meine_eintraege'      => 'Meine Einträge',
    'nav.portal_admin'         => 'Portal (Admin sieht alles)',
    'nav.nur_eigene'           => 'Nur eigene Einträge',
    'nav.hunde'                => 'Hunde',
    'nav.halter'               => 'Halter',
    'nav.zuechter'             => 'Züchter',
    'nav.verwaltung'           => 'Verwaltung',
    'nav.gruppe_rassen'        => 'Rassen (geteilter Katalog)',
    'nav.rasse_anlegen'        => 'Rasse anlegen',
    'nav.tags'                 => 'Eigenschaften & Aktivitäten',
    'nav.rassentexte'          => 'Rassentexte gliedern',
    'nav.thedogapi'            => 'TheDogAPI-Abgleich',
    'nav.gruppe_tests'         => 'Tests (Katalog des Mandanten)',
    'nav.tests'                => 'Tests verwalten',
    'nav.gruppe_benutzer'      => 'Benutzer',
    'nav.benutzer_verwalten'   => 'Benutzer verwalten',
    'nav.benutzer_anlegen'     => 'Benutzer anlegen',
    'nav.gruppe_mandanten'     => 'Mandanten',
    'nav.mandanten'            => 'Mandanten verwalten',
    'nav.konto'                => 'Konto',
    'nav.abmelden'             => 'Abmelden',

    // Sprachwahl
    'sprache.umschalter'       => 'Sprache',
    'sprache.wechseln_zu'      => 'Auf {sprache} umstellen',
    'sprache.ungueltig'        => 'Diese Sprache gibt es nicht.',

    // Login
    'login.titel'              => 'Anmelden',
    'login.willkommen'         => 'Willkommen zurück!',
    'login.bitte'              => 'Bitte melde dich an, um fortzufahren.',
    'login.mandant'            => 'Mandant',
    'login.mandant_anzeige'    => 'Mandant:',
    'login.mandant_hinweis'    => 'Kürzel deines Vereins oder Teams. Leer lassen, wenn du keins bekommen hast.',
    'login.benutzername'       => 'Benutzername',
    'login.passwort'           => 'Passwort',
    'login.knopf'              => 'Anmelden',
    'login.kein_zugang'        => 'Noch keinen Zugang? Bitte wende dich an eine Administratorin oder einen Administrator.',
    'login.falsch'             => 'Benutzername oder Passwort ist falsch.',
    'login.gesperrt'           => 'Zu viele fehlgeschlagene Login-Versuche. Bitte warte noch ca. {minuten} Minute(n).',
    'login.deaktiviert'        => 'Dieses Konto wurde deaktiviert. Bitte wende dich an eine Administratorin oder einen Administrator.',

    // Mein Konto
    'konto.titel'              => 'Mein Konto',
    'konto.angemeldet_als'     => 'Angemeldet als {name}',
    'konto.sprache_titel'      => 'Sprache',
    'konto.sprache_hinweis'    => 'Gilt für Menüs, Knöpfe und Meldungen. Rasseninhalte erscheinen in dieser Sprache, sobald sie übersetzt sind.',
    'konto.sprache_speichern'  => 'Sprache speichern',
    'konto.passwort_titel'     => 'Passwort ändern',
    'konto.passwort_aktuell'   => 'Aktuelles Passwort',
    'konto.passwort_neu'       => 'Neues Passwort',
    'konto.passwort_min'       => 'Mindestens 10 Zeichen.',
    'konto.passwort_wdh'       => 'Neues Passwort wiederholen',
    'konto.passwort_knopf'     => 'Passwort ändern',
    'konto.passwort_geaendert' => 'Dein Passwort wurde erfolgreich geändert.',
    'konto.fehler_benutzer'    => 'Benutzer nicht gefunden.',
    'konto.fehler_aktuell'     => 'Das aktuelle Passwort ist nicht korrekt.',
    'konto.fehler_gleich'      => 'Die beiden neuen Passwörter stimmen nicht überein.',
    'konto.fehler_min'         => 'Das neue Passwort muss mindestens 10 Zeichen lang sein.',
    'konto.fehler_unveraendert' => 'Das neue Passwort muss sich vom aktuellen unterscheiden.',
];
