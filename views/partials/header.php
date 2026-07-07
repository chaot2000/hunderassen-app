<?php
/**
 * views/partials/header.php
 *
 * Erwartet optional eine Variable $pageTitle (string).
 * Muss NACH secure_session_start() included werden, da is_logged_in()
 * etc. hier verwendet werden.
 *
 * Navigationsstruktur (bewusst in zwei Ebenen):
 *  - Direkt sichtbare Links: die beiden häufig genutzten Kern-Bereiche
 *    (Rassen-Übersicht, Hunde) — für ALLE eingeloggten Nutzer.
 *  - "Verwaltung"-Dropdown: alle Admin-/Pflege-Seiten gebündelt
 *    (Rasse anlegen, Eigenschaften & Aktivitäten, Benutzer verwalten,
 *    TheDogAPI-Abgleich), damit der Header nicht mit Einzellinks
 *    zuwächst, sobald weitere Verwaltungsseiten dazukommen. Nur für
 *    Admins sichtbar.
 */
$pageTitle = $pageTitle ?? 'Hunderassen-Verwaltung';

// Aktueller Pfad für die Hervorhebung des aktiven Nav-Punkts.
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

/**
 * Prüft, ob einer der übergebenen Pfade dem aktuellen Request
 * entspricht — für die "aktiv"-Markierung in der Navigation.
 */
function nav_is_active(string $currentPath, array $paths): bool
{
    return in_array($currentPath, $paths, true);
}

/**
 * Rendert das Signature-Element dieser App: einen Hundekopf mit
 * Sonnenbrille als SVG. Farben werden pro Verwendungsort übergeben,
 * damit der Kopf sich vom jeweiligen Hintergrund abhebt (z.B. heller
 * Fell-Ton im dunklen Header, dunklerer im hellen Footer).
 *
 * @param int    $sizePx     Breite/Höhe in Pixeln
 * @param string $earColor   Farbe der Ohren (Hex)
 * @param string $muzzleColor Farbe von Kopf/Fell (Hex)
 */
function render_dog_icon(int $sizePx, string $earColor, string $muzzleColor): string
{
    return '<svg width="' . $sizePx . '" height="' . $sizePx . '" viewBox="0 0 48 48" aria-hidden="true" class="shrink-0">'
         . '<path d="M9 12 Q2 22 9 32 Q15 27 15 18 Z" fill="' . $earColor . '"/>'
         . '<path d="M39 12 Q46 22 39 32 Q33 27 33 18 Z" fill="' . $earColor . '"/>'
         . '<ellipse cx="24" cy="27" rx="16" ry="14" fill="' . $muzzleColor . '"/>'
         . '<ellipse cx="24" cy="36" rx="9" ry="7" fill="#F2F5EA"/>'
         . '<ellipse cx="24" cy="33.5" rx="3.2" ry="2.4" fill="#2E2A22"/>'
         . '<path d="M24 36 Q24 39 20 39" stroke="#2E2A22" stroke-width="1.6" fill="none" stroke-linecap="round"/>'
         . '<rect x="10" y="20" width="28" height="8" rx="4" fill="#2E2A22"/>'
         . '<rect x="12.5" y="21.5" width="6" height="2.2" rx="1.1" fill="#F2F5EA" opacity="0.55"/>'
         . '<rect x="27" y="21.5" width="6" height="2.2" rx="1.1" fill="#F2F5EA" opacity="0.55"/>'
         . '</svg>';
}

$verwaltungsPfade = [
    '/admin_add_breed.php',
    '/admin_manage_tags.php',
    '/admin_manage_users.php',
    '/admin_add_user.php',
    '/admin_thedogapi_review.php',
    '/admin_thedogapi_apply.php',
    '/admin_manage_tests.php',
    '/test_form.php',
];
$verwaltungAktiv = nav_is_active($currentPath, $verwaltungsPfade);

$rassenPfade = ['/rassen.php', '/breed_detail.php', '/admin_add_breed.php'];
$rassenAktiv = nav_is_active($currentPath, $rassenPfade);

$dashboardAktiv = nav_is_active($currentPath, ['/dashboard.php']);

$meineEintraegePfade = [
    '/dogs.php', '/dog_detail.php', '/dog_form.php',
    '/halter.php', '/halter_detail.php', '/halter_form.php',
    '/zuechter.php', '/zuechter_detail.php', '/zuechter_form.php',
];
$meineEintraegeAktiv = nav_is_active($currentPath, $meineEintraegePfade);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> &middot; Hunderassen-Verwaltung</title>

    <!-- Tailwind CSS via CDN (kein Build-Tool auf Shared-Hosting verfügbar) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        // Design-Richtung: "Kennel-Karteikarte" (Farben) +
                        // Hundekopf-mit-Sonnenbrille als Signature-Icon
                        // (siehe render_dog_icon() unten). Bewusst weg vom
                        // generischen Creme+Terrakotta-Look hin zu Salbei-
                        // Pergament, Tanne, Leder-Rost und Tennisball-Gelbgrün
                        // als einzige Signalfarbe.
                        creme:   '#F2F5EA', // Seitenhintergrund — blasses Salbei-Pergament
                        sand:    '#E4DEC8', // Ränder / gedämpfte Flächen
                        fell:    '#B5502E', // Leder-Rost — Zweitakzent (Tags, Badges)
                        fellDk:  '#2E2A22', // Ink — Haupttext, Überschriften
                        tanne:   '#3F5A3F', // Tanne — primär (Header, Buttons)
                        tanneDk: '#2C4130', // dunklere Tanne — Hover-Zustand
                        pfote:   '#C4D62A', // Tennisball — EINZIGE Signalfarbe (aktive Navigation, Auszeichnungen)
                    },
                    fontFamily: {
                        sans: ['"Nunito"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
                    },
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">

    <!-- Alpine.js via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

    <style>
        body { font-family: 'Nunito', ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="bg-creme text-fellDk min-h-screen flex flex-col">

<header class="bg-tanne text-creme shadow-md relative z-20">
    <div class="max-w-6xl mx-auto px-3 sm:px-4 py-3 flex flex-wrap items-center justify-between gap-y-2">
        <a href="<?= is_logged_in() ? '/dashboard.php' : '/index.php' ?>" class="flex items-center gap-2.5 text-lg sm:text-xl font-extrabold tracking-tight whitespace-nowrap">
            <?= render_dog_icon(38, '#2C4130', '#C4D62A') ?>
            <span class="hidden sm:inline">Hunderassen-Verwaltung</span>
        </a>

        <?php if (is_logged_in()): ?>
            <nav class="flex flex-wrap items-center gap-1 sm:gap-4 text-sm font-semibold" x-data="{ verwaltungOpen: false, meineOpen: false }">
                <a href="/dashboard.php"
                   class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg transition-colors <?= $dashboardAktiv ? 'bg-creme/15 text-creme' : 'hover:text-pfote' ?>">
                    <?php if ($dashboardAktiv): ?>
                        <span class="w-1.5 h-1.5 rounded-full bg-pfote" aria-hidden="true"></span>
                    <?php endif; ?>
                    Übersicht
                </a>

                <a href="/rassen.php"
                   class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg transition-colors <?= $rassenAktiv ? 'bg-creme/15 text-creme' : 'hover:text-pfote' ?>">
                    <?php if ($rassenAktiv): ?>
                        <span class="w-1.5 h-1.5 rounded-full bg-pfote" aria-hidden="true"></span>
                    <?php endif; ?>
                    Rassen
                </a>

                <div class="relative" @click.outside="meineOpen = false">
                    <button type="button" @click="meineOpen = !meineOpen"
                        class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg transition-colors <?= $meineEintraegeAktiv ? 'bg-creme/15 text-creme' : 'hover:text-pfote' ?>">
                        <?php if ($meineEintraegeAktiv): ?>
                            <span class="w-1.5 h-1.5 rounded-full bg-pfote" aria-hidden="true"></span>
                        <?php endif; ?>
                        Meine Einträge
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 transition-transform" :class="meineOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <div x-show="meineOpen" x-transition
                         class="absolute left-0 right-auto sm:left-auto sm:right-0 mt-2 w-56 max-w-[90vw] bg-white text-fellDk rounded-xl shadow-lg border border-sand overflow-hidden max-h-[80vh] overflow-y-auto"
                         style="display: none;">
                        <p class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-wide text-fellDk/50">
                            <?= is_admin() ? 'Portal (Admin sieht alles)' : 'Nur eigene Einträge' ?>
                        </p>
                        <a href="/dogs.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">🐕 Hunde</a>
                        <a href="/halter.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">🧑 Halter</a>
                        <a href="/zuechter.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">🏠 Züchter</a>
                    </div>
                </div>

                <?php if (is_admin()): ?>
                    <div class="relative" @click.outside="verwaltungOpen = false">
                        <button type="button" @click="verwaltungOpen = !verwaltungOpen"
                            class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg transition-colors <?= $verwaltungAktiv ? 'bg-creme/15 text-creme' : 'hover:text-pfote' ?>">
                            <?php if ($verwaltungAktiv): ?>
                                <span class="w-1.5 h-1.5 rounded-full bg-pfote" aria-hidden="true"></span>
                            <?php endif; ?>
                            Verwaltung
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 transition-transform" :class="verwaltungOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </button>

                        <div x-show="verwaltungOpen" x-transition
                             class="absolute left-0 right-auto sm:left-auto sm:right-0 mt-2 w-64 max-w-[90vw] bg-white text-fellDk rounded-xl shadow-lg border border-sand overflow-hidden max-h-[80vh] overflow-y-auto"
                             style="display: none;">
                            <p class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-wide text-fellDk/50">Rassen (geteilter Katalog)</p>
                            <a href="/admin_add_breed.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">Rasse anlegen</a>
                            <a href="/admin_manage_tags.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">Eigenschaften &amp; Aktivitäten</a>
                            <a href="/admin_thedogapi_review.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">TheDogAPI-Abgleich</a>

                            <p class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-wide text-fellDk/50 border-t border-sand mt-1">Tests (geteilter Katalog)</p>
                            <a href="/admin_manage_tests.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">Tests verwalten</a>

                            <p class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-wide text-fellDk/50 border-t border-sand mt-1">Benutzer</p>
                            <a href="/admin_manage_users.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">Benutzer verwalten</a>
                            <a href="/admin_add_user.php" class="block px-4 py-2.5 text-sm hover:bg-creme transition-colors">Benutzer anlegen</a>
                        </div>
                    </div>
                <?php endif; ?>

                <span class="hidden md:inline text-creme/70 ml-2">
                    Hallo, <a href="/account.php" class="hover:text-creme hover:underline"><?= e(current_user()['username'] ?? '') ?></a>
                    <?php if (is_admin()): ?><span class="ml-1 px-2 py-0.5 rounded-full bg-pfote/30 text-xs">Admin</span><?php endif; ?>
                </span>
                <a href="/account.php" class="md:hidden flex items-center gap-1.5 px-2 py-1.5 rounded-lg transition-colors <?= nav_is_active($currentPath, ['/account.php']) ? 'bg-creme/15 text-creme' : 'hover:text-pfote' ?>">
                    Konto
                </a>
                <a href="/logout.php" class="bg-fell hover:bg-fellDk transition-colors px-3 py-1.5 rounded-full whitespace-nowrap">Abmelden</a>
            </nav>
        <?php endif; ?>
    </div>
</header>

<main class="flex-1 w-full max-w-6xl mx-auto px-3 sm:px-4 py-6 sm:py-8">