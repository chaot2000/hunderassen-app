<?php
/**
 * test_form.php
 *
 * Formular zum Anlegen einer neuen Test-Vorlage ODER (bei ?id=...) zum
 * Bearbeiten einer bestehenden. Ein Test besteht aus mehreren Aufgaben,
 * jede Aufgabe wiederum aus mehreren möglichen Ergebnissen (Kategorie:
 * bestanden/neutral/nicht_bestanden) — ein zweistufig verschachtelter
 * Repeater (Aufgaben, und je Aufgabe eigene Ergebnisse), ein Level
 * tiefer als admin_add_breed.php's Größenklassen-Repeater (dort nur
 * eine Ebene). Technik ist dieselbe: Alpine.js x-for in x-for, POST-
 * Feldnamen als aufgaben[i][ergebnisse][j][bezeichnung] etc.
 *
 * Sobald zu einem Test bereits Testdurchführungen existieren
 * (Test::hasDurchfuehrungen()), würde ein Überschreiben der Aufgaben-/
 * Ergebnis-Struktur die RESTRICT-FKs von test_durchfuehrung_ergebnisse
 * verletzen bzw. bestehende Historie kontextlos machen — die Struktur
 * wird dann nur noch lesbar (disabled) angezeigt, editierbar bleiben
 * nur Name/Beschreibung. Test::update() setzt dieselbe Sperre
 * zusätzlich serverseitig durch.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Test.php';

secure_session_start();
require_admin();

$editingId  = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing   = $editingId ? Test::findById($editingId) : null;
$isEditMode = $existing !== null;

if ($editingId !== null && $existing === null) {
    http_response_code(404);
    die('Der angeforderte Test wurde nicht gefunden.');
}

$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $testData = [
        'name'         => $_POST['name'] ?? '',
        'beschreibung' => $_POST['beschreibung'] ?? '',
        'zielgruppe'   => $_POST['zielgruppe'] ?? '',
    ];

    // Verschachteltes Formular-Array einlesen. Erwartetes POST-Format:
    // aufgaben[0][titel], aufgaben[0][beschreibung],
    // aufgaben[0][ergebnisse][0][bezeichnung], aufgaben[0][ergebnisse][0][kategorie], ...
    $aufgaben = [];
    foreach (($_POST['aufgaben'] ?? []) as $aufgabeRow) {
        if (empty($aufgabeRow['titel'])) {
            continue; // leere/nicht ausgefüllte Aufgaben-Zeile überspringen
        }

        $ergebnisse = [];
        foreach (($aufgabeRow['ergebnisse'] ?? []) as $ergebnisRow) {
            if (empty($ergebnisRow['bezeichnung'])) {
                continue; // leere/nicht ausgefüllte Ergebnis-Zeile überspringen
            }
            $ergebnisse[] = [
                'bezeichnung' => $ergebnisRow['bezeichnung'],
                'kategorie'   => $ergebnisRow['kategorie'] ?? '',
            ];
        }

        $aufgaben[] = [
            'titel'        => $aufgabeRow['titel'],
            'beschreibung' => $aufgabeRow['beschreibung'] ?? '',
            'ergebnisse'   => $ergebnisse,
        ];
    }

    if ($isEditMode) {
        $result = Test::update($editingId, $testData, $aufgaben);
    } else {
        $result = Test::create($testData, $aufgaben, (int) current_user()['id']);
    }

    if ($result['success']) {
        header('Location: /admin_manage_tests.php');
        exit;
    }

    $errorMessage = $result['message'];

    // Bei Validierungsfehler die eingegebenen Werte für erneute
    // Anzeige im Formular vorhalten (statt sie zu verlieren) — analog
    // admin_add_breed.php.
    $existing = $existing ?? [];
    $existing = array_merge($existing, $testData);
    $existing['aufgaben'] = array_map(fn($a) => [
        'titel'        => $a['titel'],
        'beschreibung' => $a['beschreibung'],
        'ergebnisse'   => array_map(fn($e) => [
            'bezeichnung' => $e['bezeichnung'],
            'kategorie'   => $e['kategorie'],
        ], $a['ergebnisse']),
    ], $aufgaben);
}

$existingAufgaben = $existing['aufgaben'] ?? [];

// Für Alpine.js als JSON vorbereiten, damit die verschachtelte
// Aufgaben-/Ergebnisliste beim Laden mit vorhandenen Werten
// vorbefüllt werden kann (analog admin_add_breed.php's $initialSizesForJs).
$initialAufgabenForJs = !empty($existingAufgaben) ? array_map(fn($a) => [
    'titel'        => $a['titel'] ?? '',
    'beschreibung' => $a['beschreibung'] ?? '',
    'ergebnisse'   => !empty($a['ergebnisse']) ? array_map(fn($e) => [
        'bezeichnung' => $e['bezeichnung'] ?? '',
        'kategorie'   => $e['kategorie'] ?? 'bestanden',
    ], $a['ergebnisse']) : [['bezeichnung' => '', 'kategorie' => 'bestanden']],
], $existingAufgaben) : [
    ['titel' => '', 'beschreibung' => '', 'ergebnisse' => [['bezeichnung' => '', 'kategorie' => 'bestanden']]],
];

$kategorieLabels  = ['bestanden' => 'Bestanden', 'neutral' => 'Neutral', 'nicht_bestanden' => 'Nicht bestanden'];
$zielgruppenLabels = ['welpe' => 'Welpe', 'erwachsen' => 'Erwachsen', 'beide' => 'Beide'];

// Sobald der Test bereits durchgeführt wurde, darf die Aufgaben-/
// Ergebnis-Struktur nicht mehr verändert werden (siehe Docblock oben).
$strukturGesperrt = $isEditMode && Test::hasDurchfuehrungen($editingId);

$pageTitle = $isEditMode ? 'Test bearbeiten' : 'Test anlegen';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/admin_manage_tests.php" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">
    ← Zur Übersicht
</a>

<h1 class="text-2xl font-extrabold text-fellDk mb-6">
    <?= $isEditMode ? '✏️ Test bearbeiten' : '➕ Neuen Test anlegen' ?>
</h1>

<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert">
        <?= e($errorMessage) ?>
    </div>
<?php endif; ?>

<form method="post" action="/test_form.php<?= $isEditMode ? '?id=' . $editingId : '' ?>" class="space-y-8">
    <?= csrf_field() ?>

    <!-- Stammdaten -->
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-4">Stammdaten</h2>

        <div class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-semibold mb-1">Name *</label>
                <input
                    type="text" id="name" name="name" required maxlength="150"
                    placeholder="z. B. Aggressionstest"
                    value="<?= e($existing['name'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="beschreibung" class="block text-sm font-semibold mb-1">Beschreibung</label>
                <textarea
                    id="beschreibung" name="beschreibung" rows="4"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                ><?= e($existing['beschreibung'] ?? '') ?></textarea>
            </div>

            <div>
                <label for="zielgruppe" class="block text-sm font-semibold mb-1">Zielgruppe *</label>
                <select id="zielgruppe" name="zielgruppe" required
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne">
                    <?php foreach ($zielgruppenLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($existing['zielgruppe'] ?? 'beide') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-fellDk/50 mt-1">Steuert, bei welchen Hunden (Welpe/erwachsen) dieser Test bei der Testdurchführung zur Auswahl steht.</p>
            </div>
        </div>
    </div>

    <!-- Aufgaben (dynamisch, je mit eigenen Ergebnissen) -->
    <div
        x-data='{ aufgaben: <?= json_encode($initialAufgabenForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>, gesperrt: <?= $strukturGesperrt ? 'true' : 'false' ?> }'
        class="bg-white/80 rounded-2xl shadow border border-sand p-6"
    >
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-fellDk">Aufgaben</h2>
            <button
                type="button" x-show="!gesperrt"
                @click="aufgaben.push({titel: '', beschreibung: '', ergebnisse: [{bezeichnung: '', kategorie: 'bestanden'}]})"
                class="text-sm bg-pfote/10 text-fell font-semibold px-3 py-1.5 rounded-full hover:bg-pfote/20 transition-colors"
            >
                + Aufgabe hinzufügen
            </button>
        </div>

        <?php if ($strukturGesperrt): ?>
            <div class="bg-pfote/10 border border-pfote/30 text-fellDk/70 text-xs rounded-lg px-4 py-3 mb-4">
                Dieser Test wurde bereits durchgeführt — die Aufgaben-/Ergebnis-Struktur kann daher nicht mehr verändert werden (nur Name und Beschreibung oben sind noch editierbar). Sie wird hier nur noch schreibgeschützt zur Ansicht angezeigt.
            </div>
        <?php else: ?>
            <p class="text-xs text-fellDk/50 mb-4">
                Jede Aufgabe braucht mindestens ein mögliches Ergebnis mit einer Kategorie (Bestanden / Neutral / Nicht bestanden).
            </p>
        <?php endif; ?>

        <template x-for="(aufgabe, aIndex) in aufgaben" :key="aIndex">
            <div class="border border-sand rounded-lg p-4 mb-4">
                <div class="flex items-start gap-3 mb-3">
                    <div class="flex-1 space-y-2">
                        <div>
                            <label class="block text-xs font-semibold mb-1">Titel der Aufgabe</label>
                            <input type="text" :name="`aufgaben[${aIndex}][titel]`" x-model="aufgabe.titel" :disabled="gesperrt"
                                placeholder="z. B. Reaktion auf fremden Hund"
                                class="w-full rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne disabled:bg-creme disabled:text-fellDk/60">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1">Beschreibung (optional)</label>
                            <textarea :name="`aufgaben[${aIndex}][beschreibung]`" x-model="aufgabe.beschreibung" rows="2" :disabled="gesperrt"
                                class="w-full rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne disabled:bg-creme disabled:text-fellDk/60"></textarea>
                        </div>
                    </div>
                    <button
                        type="button" x-show="!gesperrt"
                        @click="aufgaben.splice(aIndex, 1)"
                        x-show="aufgaben.length > 1 && !gesperrt"
                        class="text-red-500 hover:text-red-700 text-sm font-bold px-2 shrink-0"
                        title="Aufgabe entfernen"
                    >
                        ✕
                    </button>
                </div>

                <div class="pl-4 border-l-2 border-sand">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-fellDk/60">Mögliche Ergebnisse</p>
                        <button
                            type="button" x-show="!gesperrt"
                            @click="aufgabe.ergebnisse.push({bezeichnung: '', kategorie: 'bestanden'})"
                            class="text-xs text-tanne hover:text-tanneDk font-semibold"
                        >
                            + Ergebnis hinzufügen
                        </button>
                    </div>

                    <template x-for="(ergebnis, eIndex) in aufgabe.ergebnisse" :key="eIndex">
                        <div class="flex items-center gap-2 mb-2">
                            <input type="text" :name="`aufgaben[${aIndex}][ergebnisse][${eIndex}][bezeichnung]`" x-model="ergebnis.bezeichnung" :disabled="gesperrt"
                                placeholder="z. B. Hund bellt kurz, beruhigt sich sofort"
                                class="flex-1 rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne disabled:bg-creme disabled:text-fellDk/60">
                            <select :name="`aufgaben[${aIndex}][ergebnisse][${eIndex}][kategorie]`" x-model="ergebnis.kategorie" :disabled="gesperrt"
                                class="rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne disabled:bg-creme disabled:text-fellDk/60">
                                <option value="bestanden">Bestanden</option>
                                <option value="neutral">Neutral</option>
                                <option value="nicht_bestanden">Nicht bestanden</option>
                            </select>
                            <button
                                type="button"
                                @click="aufgabe.ergebnisse.splice(eIndex, 1)"
                                x-show="aufgabe.ergebnisse.length > 1 && !gesperrt"
                                class="text-red-500 hover:text-red-700 text-sm font-bold px-1 shrink-0"
                                title="Ergebnis entfernen"
                            >
                                ✕
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <div class="flex gap-3">
        <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold px-8 py-3 rounded-full transition-colors">
            <?= $isEditMode ? 'Änderungen speichern' : 'Test anlegen' ?>
        </button>
        <a href="/admin_manage_tests.php" class="text-fellDk/60 hover:text-fellDk font-semibold px-6 py-3">Abbrechen</a>
    </div>
</form>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
