<?php
/**
 * index.php
 *
 * Öffentliche Startseite. Kein require_login() — bewusst für
 * unangemeldete Besucher gedacht (Werbetext + Login-Link). Bereits
 * eingeloggte Nutzer sehen dieselbe Seite (kein Auto-Redirect,
 * bewusst so entschieden), können aber direkt über die Kopfzeile
 * bzw. den Button ins Dashboard.
 *
 * secure_session_start() wird trotzdem aufgerufen, weil
 * views/partials/header.php is_logged_in() nutzt, um den Header für
 * eingeloggte Nutzer korrekt darzustellen (Navigation statt nichts).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';

secure_session_start();

$pageTitle = 'Willkommen';
require __DIR__ . '/views/partials/header.php';
?>

<div class="max-w-4xl mx-auto text-center py-10 sm:py-16">
    <div class="flex justify-center mb-6">
        <?= render_dog_icon(84, '#2C4130', '#3F5A3F') ?>
    </div>

    <h1 class="text-3xl sm:text-5xl font-extrabold text-fellDk leading-tight">
        Alles rund um deine Hunde,<br class="hidden sm:block"> an einem Ort.
    </h1>

    <p class="text-base sm:text-lg text-fellDk/70 mt-5 max-w-2xl mx-auto">
        Die Hunderassen-Verwaltung bündelt Rassen-Wissen, deine eigenen Hunde,
        deren Halter und Züchter in einem übersichtlichen Portal &mdash;
        privat für dich, mit dem geteilten Rassen-Katalog als Nachschlagewerk.
    </p>

    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 mt-8">
        <?php if (is_logged_in()): ?>
            <a href="/dashboard.php"
               class="bg-tanne hover:bg-tanneDk text-creme font-bold px-8 py-3 rounded-full transition-colors text-base shadow-md">
                Zum Dashboard →
            </a>
        <?php else: ?>
            <a href="/login.php"
               class="bg-tanne hover:bg-tanneDk text-creme font-bold px-8 py-3 rounded-full transition-colors text-base shadow-md">
                Anmelden
            </a>
            <a href="#funktionen"
               class="border border-tanne text-tanne hover:bg-tanne hover:text-creme font-bold px-8 py-3 rounded-full transition-colors text-base">
                Was kann die App?
            </a>
        <?php endif; ?>
    </div>
</div>

<div id="funktionen" class="grid grid-cols-1 sm:grid-cols-3 gap-5 max-w-5xl mx-auto pb-10 sm:pb-16">
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6 text-left">
        <span class="text-3xl" aria-hidden="true">📖</span>
        <h2 class="font-extrabold text-fellDk mt-3 mb-1">Rassen-Katalog</h2>
        <p class="text-sm text-fellDk/70">
            Ausführliche Steckbriefe zu zahlreichen Hunderassen &mdash;
            durchsuchbar nach Größe, Aktivitätslevel und Eigenschaften.
        </p>
    </div>
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6 text-left">
        <span class="text-3xl" aria-hidden="true">🐕</span>
        <h2 class="font-extrabold text-fellDk mt-3 mb-1">Deine Hunde</h2>
        <p class="text-sm text-fellDk/70">
            Lege deine eigenen Hunde mit Foto, Rasse und Geburtsdatum an
            &mdash; privat und nur für dich sichtbar.
        </p>
    </div>
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6 text-left">
        <span class="text-3xl" aria-hidden="true">🧑</span>
        <h2 class="font-extrabold text-fellDk mt-3 mb-1">Halter &amp; Züchter</h2>
        <p class="text-sm text-fellDk/70">
            Kontaktdaten von Haltern und Züchtern übersichtlich verwaltet
            und direkt mit den passenden Hunden verknüpft.
        </p>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
