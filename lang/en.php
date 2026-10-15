<?php
/**
 * lang/en.php: Texte der Oberfläche, Englisch.
 *
 * Gleiche Schlüssel wie lang/de.php. Fehlt hier einer, zeigt die Seite
 * den deutschen Text (siehe t() in config/i18n.php).
 */

declare(strict_types=1);

return [
    // Kopf und Fuß
    'kopf.titel'               => 'Dog Breed Manager',
    'kopf.hallo'               => 'Hi,',
    'kopf.admin'               => 'Admin',
    'fuss.text'                => 'Dog Breed Manager · internal',

    // Navigation
    'nav.uebersicht'           => 'Overview',
    'nav.rassen'               => 'Breeds',
    'nav.meine_eintraege'      => 'My entries',
    'nav.portal_admin'         => 'Portal (admins see everything)',
    'nav.nur_eigene'           => 'Only my entries',
    'nav.hunde'                => 'Dogs',
    'nav.halter'               => 'Owners',
    'nav.zuechter'             => 'Breeders',
    'nav.verwaltung'           => 'Administration',
    'nav.gruppe_rassen'        => 'Breeds (shared catalogue)',
    'nav.rasse_anlegen'        => 'Add breed',
    'nav.tags'                 => 'Traits & activities',
    'nav.rassentexte'          => 'Structure breed texts',
    'nav.thedogapi'            => 'TheDogAPI sync',
    'nav.gruppe_tests'         => 'Tests (organisation catalogue)',
    'nav.tests'                => 'Manage tests',
    'nav.gruppe_benutzer'      => 'Users',
    'nav.benutzer_verwalten'   => 'Manage users',
    'nav.benutzer_anlegen'     => 'Add user',
    'nav.gruppe_mandanten'     => 'Organisations',
    'nav.mandanten'            => 'Manage organisations',
    'nav.konto'                => 'Account',
    'nav.abmelden'             => 'Log out',

    // Sprachwahl
    'sprache.umschalter'       => 'Language',
    'sprache.wechseln_zu'      => 'Switch to {sprache}',
    'sprache.ungueltig'        => 'This language is not available.',

    // Login
    'login.titel'              => 'Sign in',
    'login.willkommen'         => 'Welcome back!',
    'login.bitte'              => 'Please sign in to continue.',
    'login.mandant'            => 'Organisation',
    'login.mandant_anzeige'    => 'Organisation:',
    'login.mandant_hinweis'    => 'Short code of your club or team. Leave empty if you did not get one.',
    'login.benutzername'       => 'Username',
    'login.passwort'           => 'Password',
    'login.knopf'              => 'Sign in',
    'login.kein_zugang'        => 'No account yet? Please contact an administrator.',
    'login.falsch'             => 'Username or password is incorrect.',
    'login.gesperrt'           => 'Too many failed sign-in attempts. Please wait about {minuten} minute(s).',
    'login.deaktiviert'        => 'This account has been deactivated. Please contact an administrator.',

    // Mein Konto
    'konto.titel'              => 'My account',
    'konto.angemeldet_als'     => 'Signed in as {name}',
    'konto.sprache_titel'      => 'Language',
    'konto.sprache_hinweis'    => 'Applies to menus, buttons and messages. Breed content appears in this language once it has been translated.',
    'konto.sprache_speichern'  => 'Save language',
    'konto.passwort_titel'     => 'Change password',
    'konto.passwort_aktuell'   => 'Current password',
    'konto.passwort_neu'       => 'New password',
    'konto.passwort_min'       => 'At least 10 characters.',
    'konto.passwort_wdh'       => 'Repeat new password',
    'konto.passwort_knopf'     => 'Change password',
    'konto.passwort_geaendert' => 'Your password has been changed.',
    'konto.fehler_benutzer'    => 'User not found.',
    'konto.fehler_aktuell'     => 'The current password is not correct.',
    'konto.fehler_gleich'      => 'The two new passwords do not match.',
    'konto.fehler_min'         => 'The new password must be at least 10 characters long.',
    'konto.fehler_unveraendert' => 'The new password must be different from the current one.',
];
