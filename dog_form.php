<?php
/**
 * dog_form.php
 *
 * Formular zum Anlegen einer neuen Hundes ODER (bei ?id=...) zum
 * Bearbeiten eines bestehenden Hundes. Portal-Prinzip: nur Ersteller
 * oder Admin dürfen einen bestehenden Hund überhaupt öffnen (siehe
 * Dog::findByIdForUser()).
 *
 * Halter: MEHRFACHAUSWAHL (Checkboxen) — ein Hund kann mehreren
 * Haltern/einer Familie zugeordnet sein (dog_halter-Pivot-Tabelle).
 * Züchter bleibt Einzelauswahl (ein Hund stammt von genau einem
 * Züchter).
 *
 * Beide Dropdown/Checkbox-Quellen zeigen NUR die eigenen Einträge des
 * Nutzers (bzw. bei Admins alle) — kein Nutzer bekommt fremde Halter/
 * Züchter als Auswahloption angezeigt.
 *
 * Farbe: Vorschläge kommen aus breeds.farben der gewählten Rasse.
 * Foto: Upload analog zu admin_add_breed.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Dog.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/models/Halter.php';
require_once __DIR__ . '/models/Zuechter.php';
require_once __DIR__ . '/models/BildVerarbeitung.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$editingId  = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing   = $editingId ? Dog::findByIdForUser($editingId, $currentUserId, $isAdmin) : null;
$isEditMode = $existing !== null;

if ($editingId !== null && $existing === null) {
    http_response_code(404);
    die('Der angeforderte Hund wurde nicht gefunden.');
}

// Bereits zugeordnete Halter-IDs, für die Checkbox-Vorauswahl. Bei
// einem fehlgeschlagenen POST werden sie unten überschrieben, damit
// die zuletzt abgeschickte Auswahl erhalten bleibt (nicht der alte DB-Stand).
$selectedHalterIds = $isEditMode
    ? array_column($existing['halters'] ?? [], 'id')
    : [];

$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $dogData = [
        'name'         => $_POST['name'] ?? '',
        'geburtsdatum' => $_POST['geburtsdatum'] ?? '',
        'farbe'        => $_POST['farbe'] ?? '',
        'breed_id'     => $_POST['breed_id'] ?? '',
        'zuechter_id'  => $_POST['zuechter_id'] ?? '',
    ];
    $postedHalterIds   = array_map('intval', $_POST['halter_ids'] ?? []);
    $selectedHalterIds = $postedHalterIds; // für Wiederherstellung bei Fehler

    $imageResult = BildVerarbeitung::verarbeiteUpload($_FILES['bild'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    if (!$imageResult['success']) {
        $errorMessage = $imageResult['message'];
    } else {
        $image       = $imageResult['blob'] !== null ? ['blob' => $imageResult['blob'], 'mime' => $imageResult['mime']] : null;
        $removeImage = isset($_POST['bild_entfernen']) && $_POST['bild_entfernen'] === '1';

        if ($isEditMode) {
            $result = Dog::update($editingId, $dogData, $postedHalterIds, $image, $removeImage);
        } else {
            $result = Dog::create($dogData, $postedHalterIds, $currentUserId, $image);
        }

        if ($result['success']) {
            header('Location: /dogs.php');
            exit;
        }

        $errorMessage = $result['message'];
    }

    $existing = array_merge($existing ?? [], $dogData);
}

$allBreeds   = Breed::search('', [], [], []); // Rassen sind geteilte Katalogdaten, kein Scoping
$allHalter   = Halter::findAllForUser($currentUserId, $isAdmin);
$allZuechter = Zuechter::findAllForUser($currentUserId, $isAdmin);

$pageTitle = $isEditMode ? 'Hund bearbeiten' : 'Hund anlegen';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dogs.php" class="text-sm text-tanne hover:text-tanneDk underline">&larr; Zurück zur Übersicht</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 mt-4 max-w-2xl">
    <h1 class="text-xl font-extrabold text-fellDk mb-4">
        <?= $isEditMode ? 'Hund bearbeiten' : 'Neuen Hund anlegen' ?>
    </h1>

    <?php if ($errorMessage !== null): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">
            <?= e($errorMessage) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/dog_form.php<?= $isEditMode ? '?id=' . (int) $editingId : '' ?>"
          enctype="multipart/form-data" class="space-y-4" x-data="dogForm()">
        <?= csrf_field() ?>

        <div
            x-data='bildCropper({
                initialPreviewUrl: <?= $isEditMode && !empty($existing['hat_bild']) ? json_encode('/hundebild.php?id=' . (int) $editingId . '&v=' . urlencode((string) ($existing['bild_updated_at'] ?? '')), JSON_HEX_APOS) : 'null' ?>
            })'
            x-init="init()"
        >
            <div class="flex flex-col sm:flex-row gap-4 items-start">
                <div class="w-32 h-32 rounded-xl border border-sand bg-creme overflow-hidden flex items-center justify-center shrink-0 mx-auto sm:mx-0">
                    <img x-show="previewUrl" :src="previewUrl" alt="" class="w-full h-full object-cover">
                    <span x-show="!previewUrl" x-cloak class="text-3xl text-fellDk/20" aria-hidden="true">🐾</span>
                </div>
                <div class="flex-1 w-full space-y-2">
                    <label for="bild" class="block text-sm font-semibold">Foto</label>
                    <input
                        type="file" id="bild" accept="image/jpeg,image/png,image/webp"
                        x-ref="fileInput" @change="onFileSelected($event)"
                        class="block w-full text-sm text-fellDk file:mr-3 file:py-2 file:px-4 file:rounded-full file:border-0 file:bg-pfote/10 file:text-fell file:font-semibold hover:file:bg-pfote/20 file:cursor-pointer cursor-pointer">
                    <p class="text-xs text-fellDk/50">JPG, PNG oder WebP. Nach der Auswahl kannst du den Bildausschnitt per Ziehen festlegen.</p>

                    <div class="flex gap-3">
                        <button type="button" @click="reopenCropper()" x-show="previewUrl" class="text-xs text-tanne hover:text-tanneDk font-semibold">
                            Ausschnitt ändern
                        </button>
                        <button type="button" @click="removeImage()" x-show="previewUrl" class="text-xs text-red-500 hover:text-red-700 font-semibold">
                            Bild entfernen
                        </button>
                    </div>

                    <input type="file" name="bild" x-ref="hiddenFileInput" class="hidden">
                    <input type="hidden" name="bild_entfernen" :value="markedForRemoval ? '1' : '0'">
                </div>
            </div>

            <!-- Crop-Modal: erscheint nach Dateiauswahl, blockt die restliche Seite ab -->
            <div x-show="cropperOpen" x-cloak class="fixed inset-0 bg-fellDk/60 z-50 flex items-center justify-center p-4" style="display: none;">
                <div class="bg-creme rounded-2xl shadow-xl p-6 w-full max-w-md">
                    <h3 class="text-lg font-bold text-fellDk mb-1">Bildausschnitt wählen</h3>
                    <p class="text-xs text-fellDk/60 mb-4">Zum Verschieben ziehen, mit dem Regler zoomen.</p>

                    <div
                        x-ref="cropStage"
                        class="relative w-full aspect-square rounded-xl overflow-hidden border-2 border-tanne bg-fellDk/10 cursor-grab select-none touch-none"
                        @mousedown="startDrag($event)" @mousemove="onDrag($event)" @mouseup="endDrag()" @mouseleave="endDrag()"
                        @touchstart="startDrag($event)" @touchmove.prevent="onDrag($event)" @touchend="endDrag()"
                    >
                        <canvas x-ref="cropCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
                    </div>

                    <div class="flex items-center gap-3 mt-4">
                        <span class="text-xs text-fellDk/60" aria-hidden="true">🔍</span>
                        <input type="range" min="1" max="3" step="0.01" x-model.number="zoom" @input="drawCanvas()" class="flex-1 accent-tanne">
                    </div>

                    <div class="flex gap-3 mt-5">
                        <button type="button" @click="confirmCrop()" class="flex-1 bg-tanne hover:bg-tanneDk text-creme font-bold px-4 py-2.5 rounded-full transition-colors">
                            Übernehmen
                        </button>
                        <button type="button" @click="cancelCrop()" class="text-fellDk/60 hover:text-fellDk font-semibold px-4 py-2.5">
                            Abbrechen
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div>
            <label for="name" class="block text-sm font-semibold mb-1">Name *</label>
            <input type="text" id="name" name="name" required maxlength="120"
                value="<?= e($existing['name'] ?? '') ?>"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
        </div>

        <div>
            <label for="geburtsdatum" class="block text-sm font-semibold mb-1">Geburtsdatum</label>
            <input type="date" id="geburtsdatum" name="geburtsdatum"
                value="<?= e($existing['geburtsdatum'] ?? '') ?>"
                max="<?= e(date('Y-m-d')) ?>"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
        </div>

        <div>
            <label for="breed_id" class="block text-sm font-semibold mb-1">Rasse</label>
            <select id="breed_id" name="breed_id" x-model="breedId" @change="updateColorSuggestions"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                <option value="">— unbekannt / Mischling —</option>
                <?php foreach ($allBreeds as $breed): ?>
                    <option value="<?= (int) $breed['id'] ?>"
                        data-farben="<?= e($breed['farben'] ?? '') ?>"
                        <?= (int) ($existing['breed_id'] ?? 0) === (int) $breed['id'] ? 'selected' : '' ?>>
                        <?= e($breed['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="farbe" class="block text-sm font-semibold mb-1">Farbe</label>
            <input type="text" id="farbe" name="farbe" maxlength="255" list="farben_vorschlaege"
                value="<?= e($existing['farbe'] ?? '') ?>"
                placeholder="z. B. schwarz-loh"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            <datalist id="farben_vorschlaege">
                <template x-for="f in colorSuggestions" :key="f">
                    <option :value="f"></option>
                </template>
            </datalist>
            <p class="text-xs text-fellDk/50 mt-1">Vorschläge stammen aus den Rassefarben, du kannst aber frei eintragen.</p>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1">Halter (Familie)</label>
            <?php if (empty($allHalter)): ?>
                <p class="text-sm text-fellDk/50">Noch keine Halter angelegt.</p>
            <?php else: ?>
                <div class="border border-sand rounded-lg divide-y divide-sand max-h-48 overflow-y-auto">
                    <?php foreach ($allHalter as $halter): ?>
                        <label class="flex items-center gap-3 px-3 py-2.5 text-sm hover:bg-creme cursor-pointer">
                            <input type="checkbox" name="halter_ids[]" value="<?= (int) $halter['id'] ?>"
                                <?= in_array((int) $halter['id'], $selectedHalterIds, true) ? 'checked' : '' ?>
                                class="rounded border-sand w-4 h-4 text-tanne focus:ring-tanne">
                            <?= e($halter['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-fellDk/50 mt-1">Mehrfachauswahl möglich — z. B. für eine ganze Familie als gemeinsame Halter.</p>
            <?php endif; ?>
            <p class="text-xs text-fellDk/50 mt-1">
                Halter fehlt? <a href="/halter_form.php" class="text-tanne hover:text-tanneDk underline">Neuen Halter anlegen</a>
            </p>
        </div>

        <div>
            <label for="zuechter_id" class="block text-sm font-semibold mb-1">Züchter</label>
            <select id="zuechter_id" name="zuechter_id"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                <option value="">— kein Züchter hinterlegt —</option>
                <?php foreach ($allZuechter as $zuechter): ?>
                    <option value="<?= (int) $zuechter['id'] ?>"
                        <?= (int) ($existing['zuechter_id'] ?? 0) === (int) $zuechter['id'] ? 'selected' : '' ?>>
                        <?= e($zuechter['zuchtname']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="text-xs text-fellDk/50 mt-1">
                Züchter fehlt? <a href="/zuechter_form.php" class="text-tanne hover:text-tanneDk underline">Neuen Züchter anlegen</a>
            </p>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 pt-2">
            <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-6 py-2.5 rounded-full transition-colors">
                <?= $isEditMode ? 'Speichern' : 'Hund anlegen' ?>
            </button>
            <a href="/dogs.php" class="text-sm text-fellDk/60 self-center hover:text-fellDk">Abbrechen</a>
        </div>
    </form>
</div>

<script>
    function dogForm() {
        return {
            breedId: <?= json_encode((string) ($existing['breed_id'] ?? '')) ?>,
            colorSuggestions: [],
            updateColorSuggestions() {
                const select = document.getElementById('breed_id');
                const option = select.options[select.selectedIndex];
                const raw = option ? (option.dataset.farben || '') : '';
                this.colorSuggestions = raw
                    ? raw.split(',').map(s => s.trim()).filter(Boolean)
                    : [];
            },
            init() {
                this.updateColorSuggestions();
            }
        };
    }

    /**
     * Alpine-Komponente für den Bild-Crop-Editor. Identisch zur
     * Variante in admin_add_breed.php (siehe dortiger ausführlicher
     * Kommentar).
     */
    function bildCropper({ initialPreviewUrl }) {
        return {
            previewUrl: initialPreviewUrl,
            markedForRemoval: false,
            cropperOpen: false,

            image: null,
            zoom: 1,
            offsetXRel: 0,
            offsetYRel: 0,
            dragging: false,
            dragStartX: 0,
            dragStartY: 0,
            dragOffsetStartXRel: 0,
            dragOffsetStartYRel: 0,

            ZIEL_PX: 800,

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
                if (this.image) {
                    this.cropperOpen = true;
                    this.drawNext();
                    return;
                }

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
                    img.crossOrigin = 'anonymous';
                    img.src = this.previewUrl;
                    return;
                }

                this.$refs.fileInput.click();
            },

            removeImage() {
                this.previewUrl = null;
                this.markedForRemoval = true;
                this.image = null;
                this.$refs.fileInput.value = '';
                this.$refs.hiddenFileInput.value = '';
            },

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
                const stageSize = this.$refs.cropStage.clientWidth;
                this.offsetXRel = this.dragOffsetStartXRel + (point.x - this.dragStartX) / stageSize;
                this.offsetYRel = this.dragOffsetStartYRel + (point.y - this.dragStartY) / stageSize;
                this.drawCanvas();
            },

            endDrag() {
                this.dragging = false;
            },

            drawCanvas() {
                if (!this.image) {
                    return;
                }

                const canvas = this.$refs.cropCanvas;
                const stage  = this.$refs.cropStage;
                const size   = stage.clientWidth;

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

            berechneZeichenflaeche(stageSize) {
                const bildBreite = this.image.naturalWidth;
                const bildHoehe  = this.image.naturalHeight;

                const grundSkalierung = Math.max(stageSize / bildBreite, stageSize / bildHoehe);
                const skalierung = grundSkalierung * this.zoom;

                const drawWidth  = bildBreite * skalierung;
                const drawHeight = bildHoehe * skalierung;

                const maxOffsetXRel = Math.max(0, (drawWidth - stageSize) / 2) / stageSize;
                const maxOffsetYRel = Math.max(0, (drawHeight - stageSize) / 2) / stageSize;
                this.offsetXRel = Math.min(maxOffsetXRel, Math.max(-maxOffsetXRel, this.offsetXRel));
                this.offsetYRel = Math.min(maxOffsetYRel, Math.max(-maxOffsetYRel, this.offsetYRel));

                const drawX = (stageSize - drawWidth) / 2 + this.offsetXRel * stageSize;
                const drawY = (stageSize - drawHeight) / 2 + this.offsetYRel * stageSize;

                return { drawWidth, drawHeight, drawX, drawY };
            },

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

                    const datei = new File([blob], 'foto.jpg', { type: 'image/jpeg' });
                    const transfer = new DataTransfer();
                    transfer.items.add(datei);
                    this.$refs.hiddenFileInput.files = transfer.files;

                    this.releasePreviousObjectUrl();
                    this.previewUrl = URL.createObjectURL(blob);
                    this.markedForRemoval = false;
                    this.cropperOpen = false;
                }, 'image/jpeg', 0.9);
            },

            releasePreviousObjectUrl() {
                if (this.previewUrl && this.previewUrl.startsWith('blob:')) {
                    URL.revokeObjectURL(this.previewUrl);
                }
            },

            cancelCrop() {
                this.cropperOpen = false;
                if (!this.previewUrl) {
                    this.$refs.fileInput.value = '';
                    this.image = null;
                }
            },
        };
    }
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
