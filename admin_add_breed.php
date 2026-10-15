<?php
/**
 * admin_add_breed.php
 *
 * Formular zum Anlegen einer neuen Rasse ODER (bei ?id=...) zum
 * Bearbeiten einer bestehenden Rasse: Bild, Stammdaten (breeds),
 * Steckbrief und Texte je Abschnitt (Merkmalsmodell, PLAN.md
 * Abschnitt 10, models/RassenDatenSchreiben.php).
 *
 * Die alten Felder (breed_sizes, Tags, Aktivitäten, beschreibung,
 * Temperament …) bearbeitet dieses Formular nicht mehr. Sie werden beim
 * Speichern unverändert durchgereicht und nach der Bewährung des neuen
 * Modells entfernt (Schritt 5).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/models/BildVerarbeitung.php';
require_once __DIR__ . '/models/RassenDaten.php';
require_once __DIR__ . '/models/RassenDatenSchreiben.php';
require_once __DIR__ . '/views/partials/rasse_formular.php';

secure_session_start();
require_manage_breeds();

$editingId   = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing    = $editingId ? Breed::findById($editingId) : null;
$isEditMode  = $existing !== null;

if ($editingId !== null && $existing === null) {
    http_response_code(404);
    die('Die angeforderte Rasse wurde nicht gefunden.');
}

$errorMessage   = null;

// Steckbrief und Texte: vorhandene Rasse vorher ins neue Modell übernehmen
if ($isEditMode) {
    RassenDaten::uebernehmenWennNoetig($editingId);
}
$definitionen = RassenDatenSchreiben::definitionen();
$merkmalWerte = $isEditMode ? RassenDatenSchreiben::formularWerte($editingId) : [];
$texte        = $isEditMode ? RassenDaten::alleTexte($editingId) : ['de' => [], 'en' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $breedData = [];
    foreach (['name', 'name_en', 'alternative_namen', 'namenszusatz', 'fci_klasse', 'fci_nr', 'fci_sektion', 'fci_sektion_name'] as $feld) {
        $breedData[$feld] = (string) ($_POST[$feld] ?? '');
    }
    // Alte Felder unverändert durchreichen (siehe Kopfkommentar)
    foreach (array_merge(['ursprungsland', 'farben', 'arbeitspruefung', 'beschreibung', 'gliederung'], array_diff(Breed::INHALT_FELDER, ['name_en'])) as $feld) {
        $breedData[$feld] = (string) ($existing[$feld] ?? '');
    }
    $sizes = array_map(fn($s) => [
        'groesse'             => $s['groesse'],
        'ruede_min'           => $s['schulterhoehe_ruede_min_cm'] ?? '',
        'ruede_max'           => $s['schulterhoehe_ruede_max_cm'] ?? '',
        'haendin_min'         => $s['schulterhoehe_haendin_min_cm'] ?? '',
        'haendin_max'         => $s['schulterhoehe_haendin_max_cm'] ?? '',
        'gewicht_ruede_min'   => $s['gewicht_ruede_min_kg'] ?? '',
        'gewicht_ruede_max'   => $s['gewicht_ruede_max_kg'] ?? '',
        'gewicht_haendin_min' => $s['gewicht_haendin_min_kg'] ?? '',
        'gewicht_haendin_max' => $s['gewicht_haendin_max_kg'] ?? '',
    ], $existing['sizes'] ?? []);
    $tagIds      = array_map(fn($t) => (int) $t['id'], $existing['tags'] ?? []);
    $activityIds = array_map(fn($a) => (int) $a['id'], $existing['activities'] ?? []);

    $eingabe      = RassenDatenSchreiben::ausEingabe(is_array($_POST['merkmal'] ?? null) ? $_POST['merkmal'] : []);
    $merkmalWerte = $eingabe['werte'];
    $texte        = RassenDatenSchreiben::texteAusEingabe(is_array($_POST['text'] ?? null) ? $_POST['text'] : []);

    // Bild-Upload verarbeiten (validiert Typ/Größe und verkleinert
    // serverseitig auf max. 1200px Breite). Bei Validierungsfehlern wird
    // die Rasse NICHT gespeichert, damit der Fehler sichtbar bleibt.
    $imageResult = BildVerarbeitung::verarbeiteUpload($_FILES['bild'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    if ($eingabe['fehler']) {
        $errorMessage = implode(' ', $eingabe['fehler']);
    } elseif (!$imageResult['success']) {
        $errorMessage = $imageResult['message'];
    } else {
        $image       = $imageResult['blob'] !== null ? ['blob' => $imageResult['blob'], 'mime' => $imageResult['mime']] : null;
        $removeImage = isset($_POST['bild_entfernen']) && $_POST['bild_entfernen'] === '1';
        $userId      = (int) current_user()['id'];

        $result = $isEditMode
            ? Breed::update($editingId, $breedData, $sizes, $tagIds, $activityIds, $image, $removeImage, $userId)
            : Breed::create($breedData, $sizes, $tagIds, $activityIds, $userId, $image);

        if ($result['success']) {
            $breedId = $isEditMode ? $editingId : (int) $result['breed_id'];
            try {
                RassenDatenSchreiben::speichern($breedId, $merkmalWerte, $texte, $userId);
                header('Location: /breed_detail.php?id=' . $breedId);
                exit;
            } catch (Throwable $e) {
                error_log('Steckbrief/Texte speichern, Rasse ' . $breedId . ': ' . $e->getMessage());
                $errorMessage = 'Die Stammdaten sind gespeichert, Steckbrief und Texte aber nicht. Bitte erneut speichern.';
                if (!$isEditMode) {
                    header('Location: /admin_add_breed.php?id=' . $breedId);
                    exit;
                }
            }
        } else {
            $errorMessage = $result['message'];
        }
    }

    // Bei einem Fehler die Eingaben im Formular behalten
    $existing = array_merge($existing ?? [], $breedData);
}

$pageTitle = $isEditMode ? 'Rasse bearbeiten' : 'Rasse anlegen';
require __DIR__ . '/views/partials/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="text-2xl font-extrabold text-fellDk">
        <?= $isEditMode ? '✏️ Rasse bearbeiten' : '➕ Neue Rasse anlegen' ?>
    </h1>
    <?php if ($isEditMode): ?>
        <a href="/content_versionen.php?typ=rasse&amp;id=<?= (int) $editingId ?>"
           class="text-sm font-semibold text-tanne hover:text-tanneDk border border-tanne/40 hover:border-tanne rounded-lg px-3 py-1.5 transition-colors">
            Versionen (<?= ContentRevision::zaehle('breeds', (int) $editingId, null) ?>)
        </a>
    <?php endif; ?>
</div>

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
                <label for="name_en" class="block text-sm font-semibold mb-1">Name (englisch)</label>
                <input
                    type="text" id="name_en" name="name_en" maxlength="120" lang="en"
                    placeholder="wie bei TheDogAPI, z. B. Great Dane"
                    value="<?= e($existing['name_en'] ?? '') ?>"
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

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="fci_klasse" class="block text-sm font-semibold mb-1">FCI-Gruppe</label>
                    <select
                        id="fci_klasse" name="fci_klasse"
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                    >
                        <option value="">Keine FCI-Gruppe</option>
                        <?php foreach (Breed::FCI_GRUPPEN as $i => $gruppenName): ?>
                            <option value="<?= $i ?>" <?= (isset($existing['fci_klasse']) && (int) $existing['fci_klasse'] === $i) ? 'selected' : '' ?>>
                                <?= $i ?> · <?= e($gruppenName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="fci_nr" class="block text-sm font-semibold mb-1">FCI-Standard-Nr.</label>
                    <input
                        type="text" id="fci_nr" name="fci_nr" maxlength="20"
                        placeholder="z. B. 166"
                        value="<?= e($existing['fci_nr'] ?? '') ?>"
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                    >
                </div>
            </div>

            <div class="grid grid-cols-[6rem_1fr] gap-3">
                <div>
                    <label for="fci_sektion" class="block text-sm font-semibold mb-1">Sektion</label>
                    <input
                        type="text" id="fci_sektion" name="fci_sektion" maxlength="10"
                        placeholder="z. B. 1.1"
                        value="<?= e($existing['fci_sektion'] ?? '') ?>"
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                    >
                </div>
                <div>
                    <label for="fci_sektion_name" class="block text-sm font-semibold mb-1">Name der Sektion</label>
                    <input
                        type="text" id="fci_sektion_name" name="fci_sektion_name" maxlength="150"
                        placeholder="z. B. Pinscher und Schnauzer"
                        value="<?= e($existing['fci_sektion_name'] ?? '') ?>"
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"
                    >
                </div>
            </div>

        </div>
    </div>

    <?php render_steckbrief_formular($definitionen, $merkmalWerte); ?>

    <?php render_texte_formular($texte); ?>

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