<?php
/**
 * admin_add_breed.php
 *
 * Formular zum Anlegen einer neuen Rasse ODER (bei ?id=...) zum
 * Bearbeiten einer bestehenden Rasse. Erlaubt das Hinzufügen
 * mehrerer Größenklassen pro Rasse (z.B. "klein" UND "mittel" als
 * Varianten), inkl. Tag- und Aktivitäten-Auswahl.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/models/Tag.php';
require_once __DIR__ . '/models/Activity.php';
require_once __DIR__ . '/models/BildVerarbeitung.php';

secure_session_start();
require_admin();

$editingId   = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing    = $editingId ? Breed::findById($editingId) : null;
$isEditMode  = $existing !== null;

if ($editingId !== null && $existing === null) {
    http_response_code(404);
    die('Die angeforderte Rasse wurde nicht gefunden.');
}

$errorMessage   = null;
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $breedData = [
        'name'               => $_POST['name'] ?? '',
        'alternative_namen'  => $_POST['alternative_namen'] ?? '',
        'namenszusatz'       => $_POST['namenszusatz'] ?? '',
        'ursprungsland'      => $_POST['ursprungsland'] ?? '',
        'farben'             => $_POST['farben'] ?? '',
        'fci_klasse'         => $_POST['fci_klasse'] ?? '',
        'fci_nr'             => $_POST['fci_nr'] ?? '',
        'beschreibung'       => $_POST['beschreibung'] ?? '',
    ];

    // Größenklassen aus dem dynamisch erzeugten Formular-Array einlesen.
    // Erwartetes POST-Format: sizes[0][groesse], sizes[0][ruede_min], ...
    $sizes = [];
    foreach (($_POST['sizes'] ?? []) as $row) {
        if (empty($row['groesse'])) {
            continue; // leere/nicht ausgefüllte Zeile überspringen
        }
        $sizes[] = [
            'groesse'             => $row['groesse'],
            'ruede_min'           => $row['ruede_min']           ?? '',
            'ruede_max'           => $row['ruede_max']           ?? '',
            'haendin_min'         => $row['haendin_min']         ?? '',
            'haendin_max'         => $row['haendin_max']         ?? '',
            'gewicht_ruede_min'   => $row['gewicht_ruede_min']   ?? '',
            'gewicht_ruede_max'   => $row['gewicht_ruede_max']   ?? '',
            'gewicht_haendin_min' => $row['gewicht_haendin_min'] ?? '',
            'gewicht_haendin_max' => $row['gewicht_haendin_max'] ?? '',
        ];
    }

    $tagIds      = array_map('intval', $_POST['tags'] ?? []);
    $activityIds = array_map('intval', $_POST['activities'] ?? []);

    // Bild-Upload verarbeiten (validiert Typ/Größe und verkleinert
    // serverseitig auf max. 1200px Breite). Bei Validierungsfehlern
    // (z.B. falsches Format) wird die Rasse NICHT gespeichert, damit
    // ein fehlgeschlagener Bild-Upload nicht stillschweigend ignoriert
    // wird, sondern der Admin den Fehler sieht und korrigieren kann.
    $imageResult = BildVerarbeitung::verarbeiteUpload($_FILES['bild'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    if (!$imageResult['success']) {
        $errorMessage = $imageResult['message'];
    } else {
        $image       = $imageResult['blob'] !== null ? ['blob' => $imageResult['blob'], 'mime' => $imageResult['mime']] : null;
        $removeImage = isset($_POST['bild_entfernen']) && $_POST['bild_entfernen'] === '1';

        if ($isEditMode) {
            $result = Breed::update($editingId, $breedData, $sizes, $tagIds, $activityIds, $image, $removeImage);
        } else {
            $result = Breed::create($breedData, $sizes, $tagIds, $activityIds, (int) current_user()['id'], $image);
        }

        if ($result['success']) {
            header('Location: /rassen.php');
            exit;
        }

        $errorMessage = $result['message'];
    }

    // Bei Validierungsfehler die eingegebenen Werte für erneute
    // Anzeige im Formular vorhalten (statt sie zu verlieren).
    $existing = $existing ?? [];
    $existing = array_merge($existing, $breedData);
    $existing['sizes']      = array_map(fn($s) => [
        'groesse'                       => $s['groesse'],
        'schulterhoehe_ruede_min_cm'    => $s['ruede_min'],
        'schulterhoehe_ruede_max_cm'    => $s['ruede_max'],
        'schulterhoehe_haendin_min_cm'  => $s['haendin_min'],
        'schulterhoehe_haendin_max_cm'  => $s['haendin_max'],
        'gewicht_ruede_min_kg'          => $s['gewicht_ruede_min'],
        'gewicht_ruede_max_kg'          => $s['gewicht_ruede_max'],
        'gewicht_haendin_min_kg'        => $s['gewicht_haendin_min'],
        'gewicht_haendin_max_kg'        => $s['gewicht_haendin_max'],
    ], $sizes);
    $existing['tags']       = array_map(fn($id) => ['id' => $id], $tagIds);
    $existing['activities'] = array_map(fn($id) => ['id' => $id], $activityIds);
}

$tagGroups     = Tag::findAllGroupedByCategory();
$allActivities = Activity::findAll();

$selectedTagIds = array_map(fn($t) => (int) $t['id'], $existing['tags'] ?? []);
$selectedActIds = array_map(fn($a) => (int) $a['id'], $existing['activities'] ?? []);
$existingSizes  = $existing['sizes'] ?? [];

// Für Alpine.js als JSON vorbereiten, damit die dynamische Größenklassen-
// Liste beim Laden mit vorhandenen Werten vorbefüllt werden kann.
$initialSizesForJs = !empty($existingSizes) ? $existingSizes : [
    [
        'groesse' => '',
        'schulterhoehe_ruede_min_cm' => '', 'schulterhoehe_ruede_max_cm' => '',
        'schulterhoehe_haendin_min_cm' => '', 'schulterhoehe_haendin_max_cm' => '',
        'gewicht_ruede_min_kg' => '', 'gewicht_ruede_max_kg' => '',
        'gewicht_haendin_min_kg' => '', 'gewicht_haendin_max_kg' => '',
    ],
];

$pageTitle = $isEditMode ? 'Rasse bearbeiten' : 'Rasse anlegen';
require __DIR__ . '/views/partials/header.php';
?>

<h1 class="text-2xl font-extrabold text-fellDk mb-6">
    <?= $isEditMode ? '✏️ Rasse bearbeiten' : '➕ Neue Rasse anlegen' ?>
</h1>

<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert">
        <?= e($errorMessage) ?>
    </div>
<?php endif; ?>

<form method="post" action="/admin_add_breed.php<?= $isEditMode ? '?id=' . $editingId : '' ?>" enctype="multipart/form-data" class="space-y-8">
    <?= csrf_field() ?>

    <!-- Bild -->
    <div
        x-data='bildCropper({
            initialPreviewUrl: <?= $isEditMode && !empty($existing['hat_bild']) ? json_encode('/bildausgabe.php?id=' . (int) $editingId . '&v=' . urlencode((string) ($existing['bild_updated_at'] ?? '')), JSON_HEX_APOS) : 'null' ?>
        })'
        x-init="init()"
        class="bg-white/80 rounded-2xl shadow border border-sand p-6"
    >
        <h2 class="text-lg font-bold text-fellDk mb-4">Bild</h2>

        <div class="flex flex-col sm:flex-row gap-5 items-start">
            <div class="w-full sm:w-48 aspect-square rounded-xl border border-sand bg-creme overflow-hidden flex items-center justify-center shrink-0">
                <img x-show="previewUrl" :src="previewUrl" alt="" class="w-full h-full object-cover">
                <span x-show="!previewUrl" x-cloak class="text-4xl text-fellDk/30" aria-hidden="true">🐾</span>
            </div>

            <div class="flex-1">
                <label for="bild" class="block text-sm font-semibold mb-1">Rassebild hochladen</label>
                <input
                    type="file" id="bild" accept="image/jpeg,image/png,image/webp"
                    x-ref="fileInput" @change="onFileSelected($event)"
                    class="block w-full text-sm text-fellDk file:mr-3 file:py-2 file:px-4 file:rounded-full file:border-0 file:bg-pfote/10 file:text-fell file:font-semibold hover:file:bg-pfote/20 file:cursor-pointer cursor-pointer"
                >
                <p class="text-xs text-fellDk/50 mt-2">JPG, PNG oder WebP. Nach der Auswahl kannst du den Bildausschnitt per Ziehen festlegen.</p>

                <div class="flex gap-3 mt-3">
                    <button
                        type="button" @click="reopenCropper()" x-show="previewUrl"
                        class="text-xs text-tanne hover:text-tanneDk font-semibold"
                    >
                        Ausschnitt ändern
                    </button>
                    <button
                        type="button" @click="removeImage()" x-show="previewUrl"
                        class="text-xs text-red-500 hover:text-red-700 font-semibold"
                    >
                        Bild entfernen
                    </button>
                </div>

                <!-- Enthält am Ende entweder den zugeschnittenen Bild-Blob (neuer Upload)
                     oder bleibt leer (kein neuer Upload, evtl. bestehendes Bild behalten) -->
                <input type="file" name="bild" x-ref="hiddenFileInput" class="hidden">
                <input type="hidden" name="bild_entfernen" :value="markedForRemoval ? '1' : '0'">
            </div>
        </div>

        <!-- Crop-Modal: erscheint nach Dateiauswahl, blockt die restliche Seite ab -->
        <div
            x-show="cropperOpen" x-cloak
            class="fixed inset-0 bg-fellDk/60 z-50 flex items-center justify-center p-4"
            style="display: none;"
        >
            <div class="bg-creme rounded-2xl shadow-xl p-6 w-full max-w-md">
                <h3 class="text-lg font-bold text-fellDk mb-1">Bildausschnitt wählen</h3>
                <p class="text-xs text-fellDk/60 mb-4">Zum Verschieben ziehen, mit dem Regler zoomen.</p>

                <div
                    x-ref="cropStage"
                    class="relative w-full aspect-square rounded-xl overflow-hidden border-2 border-tanne bg-fellDk/10 cursor-grab select-none touch-none"
                    @mousedown="startDrag($event)"
                    @mousemove="onDrag($event)"
                    @mouseup="endDrag()"
                    @mouseleave="endDrag()"
                    @touchstart="startDrag($event)"
                    @touchmove.prevent="onDrag($event)"
                    @touchend="endDrag()"
                >
                    <canvas x-ref="cropCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
                </div>

                <div class="flex items-center gap-3 mt-4">
                    <span class="text-xs text-fellDk/60" aria-hidden="true">🔍</span>
                    <input
                        type="range" min="1" max="3" step="0.01"
                        x-model.number="zoom" @input="drawCanvas()"
                        class="flex-1 accent-tanne"
                    >
                </div>

                <div class="flex gap-3 mt-5">
                    <button
                        type="button" @click="confirmCrop()"
                        class="flex-1 bg-tanne hover:bg-tanneDk text-creme font-bold px-4 py-2.5 rounded-full transition-colors"
                    >
                        Übernehmen
                    </button>
                    <button
                        type="button" @click="cancelCrop()"
                        class="text-fellDk/60 hover:text-fellDk font-semibold px-4 py-2.5"
                    >
                        Abbrechen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Strikte Stammdaten -->
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <h2 class="text-lg font-bold text-fellDk mb-4">Stammdaten</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label for="name" class="block text-sm font-semibold mb-1">Name *</label>
                <input
                    type="text" id="name" name="name" required maxlength="120"
                    value="<?= e($existing['name'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="alternative_namen" class="block text-sm font-semibold mb-1">Alternative Bezeichnungen</label>
                <input
                    type="text" id="alternative_namen" name="alternative_namen" maxlength="500"
                    placeholder="z. B. andere gebräuchliche Namen, durch Komma getrennt"
                    value="<?= e($existing['alternative_namen'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="namenszusatz" class="block text-sm font-semibold mb-1">Namenszusatz</label>
                <input
                    type="text" id="namenszusatz" name="namenszusatz" maxlength="255"
                    placeholder="z. B. Kurzhaar, Langhaar, Zuchtlinie"
                    value="<?= e($existing['namenszusatz'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div>
                <label for="ursprungsland" class="block text-sm font-semibold mb-1">Ursprungsland</label>
                <input
                    type="text" id="ursprungsland" name="ursprungsland" maxlength="100"
                    value="<?= e($existing['ursprungsland'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="fci_klasse" class="block text-sm font-semibold mb-1">FCI-Klasse</label>
                    <select
                        id="fci_klasse" name="fci_klasse"
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                    >
                        <option value="">Keine FCI-Klasse</option>
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                            <option value="<?= $i ?>" <?= (isset($existing['fci_klasse']) && (int) $existing['fci_klasse'] === $i) ? 'selected' : '' ?>>
                                Klasse <?= $i ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label for="fci_nr" class="block text-sm font-semibold mb-1">FCI-Nr.</label>
                    <input
                        type="text" id="fci_nr" name="fci_nr" maxlength="20"
                        placeholder="z. B. 166"
                        value="<?= e($existing['fci_nr'] ?? '') ?>"
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                    >
                </div>
            </div>

            <div class="md:col-span-2">
                <label for="farben" class="block text-sm font-semibold mb-1">Farben</label>
                <input
                    type="text" id="farben" name="farben" maxlength="255"
                    placeholder="z. B. schwarz, braun, gestromt"
                    value="<?= e($existing['farben'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                >
            </div>

            <div class="md:col-span-2">
                <label for="beschreibung" class="block text-sm font-semibold mb-1">Beschreibung</label>
                <textarea
                    id="beschreibung" name="beschreibung" rows="14"
                    class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                ><?= e($existing['beschreibung'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Größenklassen (dynamisch, mehrere pro Rasse möglich) -->
    <div
        x-data='{ sizes: <?= json_encode($initialSizesForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?> }'
        class="bg-white/80 rounded-2xl shadow border border-sand p-6"
    >
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-fellDk">Größenklassen</h2>
            <button
                type="button"
                @click="sizes.push({groesse: '', schulterhoehe_ruede_min_cm: '', schulterhoehe_ruede_max_cm: '', schulterhoehe_haendin_min_cm: '', schulterhoehe_haendin_max_cm: '', gewicht_ruede_min_kg: '', gewicht_ruede_max_kg: '', gewicht_haendin_min_kg: '', gewicht_haendin_max_kg: ''})"
                class="text-sm bg-pfote/10 text-fell font-semibold px-3 py-1.5 rounded-full hover:bg-pfote/20 transition-colors"
            >
                + Größenklasse hinzufügen
            </button>
        </div>
        <p class="text-xs text-fellDk/50 mb-4">
            Manche Rassen werden mit mehreren Größenklassen geführt (z. B. „klein: 30–40 cm“ und „mittel: 40–50 cm“). Schulterhöhe in ganzen cm, Gewicht in kg.
        </p>

        <template x-for="(size, index) in sizes" :key="index">
            <div class="border border-sand rounded-lg p-4 mb-3">
                <div class="flex items-end gap-3 mb-3">
                    <div class="w-40 shrink-0">
                        <label class="block text-xs font-semibold mb-1">Größe</label>
                        <select :name="`sizes[${index}][groesse]`" x-model="size.groesse" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                            <option value="">— wählen —</option>
                            <option value="klein">Klein</option>
                            <option value="mittel">Mittel</option>
                            <option value="gross">Groß</option>
                            <option value="sehr_gross">Sehr groß</option>
                        </select>
                    </div>
                    <button
                        type="button"
                        @click="sizes.splice(index, 1)"
                        x-show="sizes.length > 1"
                        class="text-red-500 hover:text-red-700 text-sm font-bold px-2 pb-1.5 ml-auto"
                        title="Größenklasse entfernen"
                    >
                        ✕ Entfernen
                    </button>
                </div>

                <p class="text-xs font-semibold text-fellDk/60 mb-1">Schulterhöhe (cm)</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                    <div>
                        <label class="block text-xs mb-1">Rüde min</label>
                        <input type="number" min="0" max="200" :name="`sizes[${index}][ruede_min]`" x-model="size.schulterhoehe_ruede_min_cm" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Rüde max</label>
                        <input type="number" min="0" max="200" :name="`sizes[${index}][ruede_max]`" x-model="size.schulterhoehe_ruede_max_cm" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Hündin min</label>
                        <input type="number" min="0" max="200" :name="`sizes[${index}][haendin_min]`" x-model="size.schulterhoehe_haendin_min_cm" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Hündin max</label>
                        <input type="number" min="0" max="200" :name="`sizes[${index}][haendin_max]`" x-model="size.schulterhoehe_haendin_max_cm" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                </div>

                <p class="text-xs font-semibold text-fellDk/60 mb-1">Gewicht (kg)</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs mb-1">Rüde min</label>
                        <input type="number" min="0" max="120" step="0.1" :name="`sizes[${index}][gewicht_ruede_min]`" x-model="size.gewicht_ruede_min_kg" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Rüde max</label>
                        <input type="number" min="0" max="120" step="0.1" :name="`sizes[${index}][gewicht_ruede_max]`" x-model="size.gewicht_ruede_max_kg" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Hündin min</label>
                        <input type="number" min="0" max="120" step="0.1" :name="`sizes[${index}][gewicht_haendin_min]`" x-model="size.gewicht_haendin_min_kg" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                    <div>
                        <label class="block text-xs mb-1">Hündin max</label>
                        <input type="number" min="0" max="120" step="0.1" :name="`sizes[${index}][gewicht_haendin_max]`" x-model="size.gewicht_haendin_max_kg" class="w-full rounded-lg border border-sand px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Aktivitäten -->
    <div
        x-data='{
            activities: <?= json_encode(array_map(fn($a) => ['id' => (int) $a['id'], 'name' => $a['name']], $allActivities), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>,
            selected: <?= json_encode($selectedActIds, JSON_HEX_APOS) ?>,
            newName: "",
            saving: false,
            errorMsg: "",
            async addActivity() {
                if (!this.newName.trim()) return;
                this.saving = true;
                this.errorMsg = "";
                try {
                    const resp = await fetch("/ajax_create_taxonomy.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            csrf_token: document.querySelector("input[name=csrf_token]").value,
                            type: "activity",
                            name: this.newName,
                        }),
                    });
                    const data = await resp.json();
                    if (data.success) {
                        this.activities.push(data.item);
                        this.selected.push(data.item.id);
                        this.newName = "";
                    } else {
                        this.errorMsg = data.message;
                    }
                } catch (e) {
                    this.errorMsg = "Verbindungsfehler. Bitte erneut versuchen.";
                } finally {
                    this.saving = false;
                }
            }
        }'
        class="bg-white/80 rounded-2xl shadow border border-sand p-6"
    >
        <h2 class="text-lg font-bold text-fellDk mb-4">Geeignete Aktivitäten</h2>

        <div class="flex flex-wrap gap-3 mb-4">
            <template x-for="activity in activities" :key="activity.id">
                <label class="flex items-center gap-2 text-sm bg-creme border border-sand rounded-full px-3 py-1.5 cursor-pointer">
                    <input type="checkbox" name="activities[]" :value="activity.id" x-model.number="selected" class="rounded border-sand text-tanne focus:ring-tanne">
                    <span x-text="activity.name"></span>
                </label>
            </template>
            <p x-show="activities.length === 0" x-cloak class="text-xs text-fellDk/50">Noch keine Aktivitäten in der Datenbank angelegt.</p>
        </div>

        <div class="flex items-center gap-2 border-t border-sand pt-4">
            <input
                type="text" x-model="newName" @keydown.enter.prevent="addActivity()"
                placeholder="Neue Aktivität, z. B. Fährtenarbeit"
                class="flex-1 rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne"
            >
            <button
                type="button" @click="addActivity()" :disabled="saving"
                class="text-sm bg-pfote/10 text-fell font-semibold px-3 py-1.5 rounded-full hover:bg-pfote/20 transition-colors disabled:opacity-50"
            >
                <span x-show="!saving">+ Neu</span>
                <span x-show="saving" x-cloak>Speichert …</span>
            </button>
        </div>
        <p x-show="errorMsg" x-cloak x-text="errorMsg" class="text-xs text-red-600 mt-2"></p>
    </div>

    <!-- Eigenschaften / Tags -->
    <div
        x-data='{
            tagsByCategory: <?= json_encode($tagGroups, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>,
            selected: <?= json_encode($selectedTagIds, JSON_HEX_APOS) ?>,
            newName: "",
            newCategory: "",
            saving: false,
            errorMsg: "",
            async addTag() {
                if (!this.newName.trim()) return;
                this.saving = true;
                this.errorMsg = "";
                try {
                    const resp = await fetch("/ajax_create_taxonomy.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            csrf_token: document.querySelector("input[name=csrf_token]").value,
                            type: "tag",
                            name: this.newName,
                            category: this.newCategory,
                        }),
                    });
                    const data = await resp.json();
                    if (data.success) {
                        const cat = data.item.category || "Sonstiges";
                        if (!this.tagsByCategory[cat]) this.tagsByCategory[cat] = [];
                        this.tagsByCategory[cat].push(data.item);
                        this.selected.push(data.item.id);
                        this.newName = "";
                    } else {
                        this.errorMsg = data.message;
                    }
                } catch (e) {
                    this.errorMsg = "Verbindungsfehler. Bitte erneut versuchen.";
                } finally {
                    this.saving = false;
                }
            }
        }'
        class="bg-white/80 rounded-2xl shadow border border-sand p-6"
    >
        <h2 class="text-lg font-bold text-fellDk mb-4">Eigenschaften</h2>

        <template x-for="(tags, category) in tagsByCategory" :key="category">
            <div class="mb-4">
                <p class="text-xs font-semibold text-fellDk/60 mb-2" x-text="category"></p>
                <div class="flex flex-wrap gap-3">
                    <template x-for="tag in tags" :key="tag.id">
                        <label class="flex items-center gap-2 text-sm bg-creme border border-sand rounded-full px-3 py-1.5 cursor-pointer">
                            <input type="checkbox" name="tags[]" :value="tag.id" x-model.number="selected" class="rounded border-sand text-tanne focus:ring-tanne">
                            <span x-text="tag.name"></span>
                        </label>
                    </template>
                </div>
            </div>
        </template>
        <p x-show="Object.keys(tagsByCategory).length === 0" x-cloak class="text-xs text-fellDk/50">Noch keine Eigenschaften in der Datenbank angelegt.</p>

        <div class="border-t border-sand pt-4 flex flex-col md:flex-row gap-2 items-stretch md:items-center">
            <input
                type="text" x-model="newName" @keydown.enter.prevent="addTag()"
                placeholder="Neue Eigenschaft, z. B. Wasserliebend"
                class="flex-1 rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne"
            >
            <input
                type="text" x-model="newCategory" @keydown.enter.prevent="addTag()"
                placeholder="Kategorie (optional)"
                list="tag_categories_inline"
                class="w-full md:w-48 rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne"
            >
            <datalist id="tag_categories_inline">
                <?php foreach (array_keys($tagGroups) as $cat): ?>
                    <option value="<?= e($cat) ?>">
                <?php endforeach; ?>
            </datalist>
            <button
                type="button" @click="addTag()" :disabled="saving"
                class="text-sm bg-pfote/10 text-fell font-semibold px-3 py-1.5 rounded-full hover:bg-pfote/20 transition-colors disabled:opacity-50 whitespace-nowrap"
            >
                <span x-show="!saving">+ Neu</span>
                <span x-show="saving" x-cloak>Speichert …</span>
            </button>
        </div>
        <p x-show="errorMsg" x-cloak x-text="errorMsg" class="text-xs text-red-600 mt-2"></p>
    </div>

    <div class="flex gap-3">
        <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold px-8 py-3 rounded-full transition-colors">
            <?= $isEditMode ? 'Änderungen speichern' : 'Rasse anlegen' ?>
        </button>
        <a href="/rassen.php" class="text-fellDk/60 hover:text-fellDk font-semibold px-6 py-3">Abbrechen</a>
    </div>
</form>

<script>
/**
 * Alpine-Komponente für den Bild-Crop-Editor in admin_add_breed.php.
 *
 * Ablauf: Datei wird per <input type="file" id="bild"> ausgewählt
 * (NICHT name="bild" — dieses Feld wird nie direkt abgeschickt).
 * Daraufhin öffnet sich ein Modal mit dem Originalbild auf einem
 * Canvas; per Ziehen (Maus/Touch) verschiebt man den sichtbaren
 * Ausschnitt, ein Regler steuert den Zoom. "Übernehmen" rendert den
 * sichtbaren quadratischen Ausschnitt auf ein zweites Canvas in fester
 * Zielauflösung, wandelt es in einen JPEG-Blob um und schreibt diesen
 * über die DataTransfer-API in das tatsächlich abgesendete
 * <input type="file" name="bild" x-ref="hiddenFileInput"> — der
 * Server bekommt also nur noch den bereits zugeschnittenen Ausschnitt,
 * nicht das Originalbild.
 *
 * Die serverseitige Verkleinerung/Validierung in BildVerarbeitung.php
 * bleibt unverändert als zweite Sicherheitsstufe bestehen (Crop ersetzt
 * sie nicht, sondern läuft davor).
 */
function bildCropper({ initialPreviewUrl }) {
    return {
        previewUrl: initialPreviewUrl,
        markedForRemoval: false,
        cropperOpen: false,

        // Zustand des Crop-Editors
        image: null,        // das geladene <img>-Element (Quelle fürs Canvas)
        zoom: 1,
        // offsetX/offsetY sind bewusst RELATIV gespeichert (als Anteil
        // der Stage-Breite/-Höhe, z.B. 0.1 = 10% der Stage-Größe
        // verschoben), NICHT in absoluten Pixeln. Das Modal zeichnet bei
        // einer anderen Pixelgröße (z.B. 400px) als der finale Export
        // (800px) — mit absoluten Pixel-Offsets würde derselbe Wert in
        // beiden Stages einen unterschiedlichen Bildausschnitt ergeben
        // (genau das war der gemeldete Bug: Vorschau im Modal ≠
        // tatsächlich gespeicherter Ausschnitt). Relativ zur Stage-Größe
        // bleibt der gewählte Ausschnitt unabhängig von der
        // Render-Auflösung konsistent.
        offsetXRel: 0,
        offsetYRel: 0,
        dragging: false,
        dragStartX: 0,
        dragStartY: 0,
        dragOffsetStartXRel: 0,
        dragOffsetStartYRel: 0,

        // Feste Ausgabeauflösung des zugeschnittenen Bilds (quadratisch).
        // 800px reicht für die UI-Größen (Karten-Thumbnail, Detailseite,
        // Formularvorschau sind alle deutlich kleiner) und hält den
        // Upload klein; die serverseitige Stufe verkleinert bei Bedarf
        // ohnehin nochmal auf max. 1200px.
        ZIEL_PX: 800,

        // Hilfsmethode: Canvas nach einem vollständigen Browser-Layout-
        // Zyklus zeichnen. $nextTick reicht nicht, da x-show (display:none
        // -> block) einen Reflow braucht, bevor clientWidth einen Wert > 0
        // hat — requestAnimationFrame läuft garantiert danach.
        drawNext() {
            requestAnimationFrame(() => this.drawCanvas());
        },

        onFileSelected(event) {
            const file = event.target.files[0];
            if (!file) {
                return;
            }

            const img = new Image();
            img.onload = () => {
                this.image = img;
                this.zoom = 1;
                this.offsetXRel = 0;
                this.offsetYRel = 0;
                this.cropperOpen = true;
                this.drawNext();
            };
            img.onerror = () => {
                alert('Dieses Bild konnte nicht gelesen werden. Bitte eine andere Datei wählen.');
            };
            this.releasePreviousObjectUrl();
            img.src = URL.createObjectURL(file);
        },

        reopenCropper() {
            // Fall 1: this.image ist noch im Speicher (Bild wurde in
            // dieser Sitzung bereits ausgewählt/zugeschnitten) — Cropper
            // direkt mit diesem Bild erneut öffnen.
            if (this.image) {
                this.cropperOpen = true;
                this.drawNext();
                return;
            }

            // Fall 2: previewUrl zeigt auf ein bereits gespeichertes
            // Server-Bild (/bildausgabe.php?id=...), this.image aber
            // noch leer (frischer Seitenaufruf im Bearbeiten-Modus,
            // noch keine neue Datei gewählt). Dieses Bild als
            // Cropper-Quelle nachladen, damit der Ausschnitt angepasst
            // werden kann, OHNE neu hochladen zu müssen.
            //
            // Hinweis: Das ist bereits das serverseitig verkleinerte
            // Bild (max. 1200px), nicht mehr das ursprünglich
            // hochgeladene Original — ein zuvor "weggeschnittener"
            // Bildbereich lässt sich dadurch nicht wiederherstellen.
            // Für eine komplette Neupositionierung mit voller
            // Originalqualität muss ein neues Bild hochgeladen werden.
            if (this.previewUrl) {
                const img = new Image();
                img.onload = () => {
                    this.image = img;
                    this.zoom = 1;
                    this.offsetXRel = 0;
                    this.offsetYRel = 0;
                    this.cropperOpen = true;
                    this.drawNext();
                };
                img.onerror = () => {
                    alert('Das gespeicherte Bild konnte nicht geladen werden. Bitte ein neues Bild hochladen.');
                };
                // crossOrigin nicht nötig (gleicher Origin, bildausgabe.php
                // liegt auf demselben Host), aber explizit gesetzt für
                // den Fall einer abweichenden Deployment-Konfiguration —
                // verhindert eine "tainted canvas" beim späteren toBlob().
                img.crossOrigin = 'anonymous';
                img.src = this.previewUrl;
                return;
            }

            // Fall 3: kein previewUrl, kein image -> es gibt nichts zum
            // Anpassen, also direkt die Dateiauswahl öffnen.
            this.$refs.fileInput.click();
        },

        removeImage() {
            this.previewUrl = null;
            this.markedForRemoval = true;
            this.image = null;
            this.$refs.fileInput.value = '';
            this.$refs.hiddenFileInput.value = '';
        },

        // --- Drag-to-Pan ---

        eventPoint(event) {
            const touch = event.touches && event.touches[0];
            return { x: touch ? touch.clientX : event.clientX, y: touch ? touch.clientY : event.clientY };
        },

        startDrag(event) {
            const point = this.eventPoint(event);
            this.dragging = true;
            this.dragStartX = point.x;
            this.dragStartY = point.y;
            this.dragOffsetStartXRel = this.offsetXRel;
            this.dragOffsetStartYRel = this.offsetYRel;
        },

        onDrag(event) {
            if (!this.dragging) {
                return;
            }
            event.preventDefault();
            const point = this.eventPoint(event);
            // Mausbewegung ist in CSS-Pixeln der sichtbaren Modal-Stage.
            // Durch deren aktuelle Breite teilen, um einen Wert zu
            // bekommen, der unabhängig von der Stage-Größe denselben
            // Bildausschnitt beschreibt (siehe Kommentar bei offsetXRel
            // weiter oben).
            const stageSize = this.$refs.cropStage.clientWidth;
            this.offsetXRel = this.dragOffsetStartXRel + (point.x - this.dragStartX) / stageSize;
            this.offsetYRel = this.dragOffsetStartYRel + (point.y - this.dragStartY) / stageSize;
            this.drawCanvas();
        },

        endDrag() {
            this.dragging = false;
        },

        // --- Canvas-Rendering im Modal (Live-Vorschau des Ausschnitts) ---

        drawCanvas() {
            if (!this.image) {
                return;
            }

            const canvas = this.$refs.cropCanvas;
            const stage  = this.$refs.cropStage;
            const size   = stage.clientWidth;

            // Sollte clientWidth noch 0 sein (DOM noch nicht fertig
            // gerendert), nochmal einen Frame warten statt mit 0
            // zu zeichnen — das würde einen unsichtbaren Canvas erzeugen.
            if (size === 0) {
                requestAnimationFrame(() => this.drawCanvas());
                return;
            }

            canvas.width  = size;
            canvas.height = size;

            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, size, size);

            const { drawWidth, drawHeight, drawX, drawY } = this.berechneZeichenflaeche(size);
            ctx.drawImage(this.image, drawX, drawY, drawWidth, drawHeight);
        },

        /**
         * Berechnet, wie das Originalbild (this.image) innerhalb der
         * quadratischen Stage (Seitenlänge stageSize) zu zeichnen ist,
         * unter Berücksichtigung von Zoom und Verschiebung (offsetX/Y).
         * "cover"-Verhalten: das Bild füllt die Stage immer vollständig
         * aus (keine leeren Ränder), überschüssiger Teil wird je nach
         * Verschiebung außerhalb der Stage abgeschnitten.
         */
        berechneZeichenflaeche(stageSize) {
            const bildBreite = this.image.naturalWidth;
            const bildHoehe  = this.image.naturalHeight;

            // Skalierung, die das Bild bei zoom=1 die Stage gerade so
            // füllt (kürzere Seite = Stage-Größe), darüber hinaus
            // multipliziert mit dem Zoom-Faktor.
            const grundSkalierung = Math.max(stageSize / bildBreite, stageSize / bildHoehe);
            const skalierung = grundSkalierung * this.zoom;

            const drawWidth  = bildBreite * skalierung;
            const drawHeight = bildHoehe * skalierung;

            // Clamping-Grenzen als Anteil der Stage-Größe (nicht absolut),
            // damit sie unabhängig von stageSize denselben Bildausschnitt
            // begrenzen — sonst würde z.B. bei stageSize=800 ein anderer
            // relativer Spielraum erlaubt als bei stageSize=400.
            const maxOffsetXRel = Math.max(0, (drawWidth - stageSize) / 2) / stageSize;
            const maxOffsetYRel = Math.max(0, (drawHeight - stageSize) / 2) / stageSize;
            this.offsetXRel = Math.min(maxOffsetXRel, Math.max(-maxOffsetXRel, this.offsetXRel));
            this.offsetYRel = Math.min(maxOffsetYRel, Math.max(-maxOffsetYRel, this.offsetYRel));

            const drawX = (stageSize - drawWidth) / 2 + this.offsetXRel * stageSize;
            const drawY = (stageSize - drawHeight) / 2 + this.offsetYRel * stageSize;

            return { drawWidth, drawHeight, drawX, drawY };
        },

        // --- Bestätigen: finalen Ausschnitt rendern und als Upload-Datei einsetzen ---

        confirmCrop() {
            const ausgabeCanvas = document.createElement('canvas');
            ausgabeCanvas.width  = this.ZIEL_PX;
            ausgabeCanvas.height = this.ZIEL_PX;

            const ctx = ausgabeCanvas.getContext('2d');
            const { drawWidth, drawHeight, drawX, drawY } = this.berechneZeichenflaeche(this.ZIEL_PX);
            ctx.drawImage(this.image, drawX, drawY, drawWidth, drawHeight);

            ausgabeCanvas.toBlob((blob) => {
                if (!blob) {
                    alert('Der Bildausschnitt konnte nicht verarbeitet werden. Bitte erneut versuchen.');
                    return;
                }

                const datei = new File([blob], 'rassebild.jpg', { type: 'image/jpeg' });
                const transfer = new DataTransfer();
                transfer.items.add(datei);
                this.$refs.hiddenFileInput.files = transfer.files;

                this.releasePreviousObjectUrl();
                this.previewUrl = URL.createObjectURL(blob);
                this.markedForRemoval = false;
                this.cropperOpen = false;
            }, 'image/jpeg', 0.9);
        },

        /**
         * Gibt eine zuvor per createObjectURL() erzeugte Blob-URL frei,
         * falls previewUrl gerade eine solche ist (nicht die initiale
         * /bildausgabe.php?...-URL eines bereits gespeicherten Bilds,
         * die ist kein Blob-URL und braucht kein Freigeben).
         */
        releasePreviousObjectUrl() {
            if (this.previewUrl && this.previewUrl.startsWith('blob:')) {
                URL.revokeObjectURL(this.previewUrl);
            }
        },

        cancelCrop() {
            this.cropperOpen = false;
            // Falls noch kein Bild bestätigt wurde (erster Upload-Versuch
            // abgebrochen), auch die Datei-Auswahl zurücksetzen, damit
            // kein "Geisterzustand" entsteht.
            if (!this.previewUrl) {
                this.$refs.fileInput.value = '';
                this.image = null;
            }
        },
    };
}
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>