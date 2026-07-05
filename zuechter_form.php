<?php
/**
 * zuechter_form.php
 *
 * Formular zum Anlegen/Bearbeiten eines Züchters. Portal-Prinzip: nur
 * Ersteller oder Admin dürfen einen bestehenden Züchter öffnen.
 *
 * Felder: Zuchtname (Pflicht), Ansprechpartner (Vor-/Nachname),
 * Adresse, Kontaktdaten (Telefon, E-Mail, Webseite), Notizen.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Zuechter.php';
require_once __DIR__ . '/models/BildVerarbeitung.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$editingId  = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing   = $editingId ? Zuechter::findByIdForUser($editingId, $currentUserId, $isAdmin) : null;
$isEditMode = $existing !== null;

if ($editingId !== null && $existing === null) {
    http_response_code(404);
    die('Der angeforderte Züchter wurde nicht gefunden.');
}

$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $zuechterData = [
        'zuchtname'                => $_POST['zuchtname'] ?? '',
        'ansprechpartner_vorname'  => $_POST['ansprechpartner_vorname'] ?? '',
        'ansprechpartner_nachname' => $_POST['ansprechpartner_nachname'] ?? '',
        'adresse'                  => $_POST['adresse'] ?? '',
        'telefon'                  => $_POST['telefon'] ?? '',
        'email'                    => $_POST['email'] ?? '',
        'webseite'                 => $_POST['webseite'] ?? '',
        'notizen'                  => $_POST['notizen'] ?? '',
    ];

    $imageResult = BildVerarbeitung::verarbeiteUpload($_FILES['bild'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
    if (!$imageResult['success']) {
        $errorMessage = $imageResult['message'];
    } else {
        $image       = $imageResult['blob'] !== null ? ['blob' => $imageResult['blob'], 'mime' => $imageResult['mime']] : null;
        $removeImage = isset($_POST['bild_entfernen']) && $_POST['bild_entfernen'] === '1';

        if ($isEditMode) {
            $result = Zuechter::update($editingId, $zuechterData, $image, $removeImage);
        } else {
            $result = Zuechter::create($zuechterData, $currentUserId, $image);
        }

        if ($result['success']) {
            header('Location: /zuechter.php');
            exit;
        }

        $errorMessage = $result['message'];
    }

    $existing = array_merge($existing ?? [], $zuechterData);
}

$pageTitle = $isEditMode ? 'Züchter bearbeiten' : 'Züchter anlegen';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/zuechter.php" class="text-sm text-tanne hover:text-tanneDk underline">&larr; Zurück zur Übersicht</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 mt-4 max-w-xl">
    <h1 class="text-xl font-extrabold text-fellDk mb-4">
        <?= $isEditMode ? 'Züchter bearbeiten' : 'Neuen Züchter anlegen' ?>
    </h1>

    <?php if ($errorMessage !== null): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-4">
            <?= e($errorMessage) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/zuechter_form.php<?= $isEditMode ? '?id=' . (int) $editingId : '' ?>"
          enctype="multipart/form-data" class="space-y-4">
        <?= csrf_field() ?>

        <div
            x-data='bildCropper({
                initialPreviewUrl: <?= $isEditMode && !empty($existing['hat_bild']) ? json_encode('/zuechterbild.php?id=' . (int) $editingId . '&v=' . urlencode((string) ($existing['bild_updated_at'] ?? '')), JSON_HEX_APOS) : 'null' ?>
            })'
            x-init="init()"
        >
            <div class="flex flex-col sm:flex-row gap-4 items-start">
                <div class="w-32 h-32 rounded-xl border border-sand bg-creme overflow-hidden flex items-center justify-center shrink-0 mx-auto sm:mx-0">
                    <img x-show="previewUrl" :src="previewUrl" alt="" class="w-full h-full object-cover">
                    <span x-show="!previewUrl" x-cloak class="text-3xl text-fellDk/20" aria-hidden="true">🏠</span>
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
            <label for="zuchtname" class="block text-sm font-semibold mb-1">Zuchtname *</label>
            <input type="text" id="zuchtname" name="zuchtname" required maxlength="150"
                value="<?= e($existing['zuchtname'] ?? '') ?>"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="ansprechpartner_vorname" class="block text-sm font-semibold mb-1">Ansprechpartner — Vorname</label>
                <input type="text" id="ansprechpartner_vorname" name="ansprechpartner_vorname" maxlength="100"
                    value="<?= e($existing['ansprechpartner_vorname'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            </div>
            <div>
                <label for="ansprechpartner_nachname" class="block text-sm font-semibold mb-1">Ansprechpartner — Nachname</label>
                <input type="text" id="ansprechpartner_nachname" name="ansprechpartner_nachname" maxlength="100"
                    value="<?= e($existing['ansprechpartner_nachname'] ?? '') ?>"
                    class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            </div>
        </div>

        <div>
            <label for="adresse" class="block text-sm font-semibold mb-1">Adresse</label>
            <input type="text" id="adresse" name="adresse" maxlength="255"
                value="<?= e($existing['adresse'] ?? '') ?>"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
        </div>

        <div class="pt-2 border-t border-sand">
            <p class="text-xs font-bold uppercase tracking-wide text-fellDk/50 mb-3 mt-3">Kontaktdaten</p>
        </div>

        <div>
            <label for="telefon" class="block text-sm font-semibold mb-1">Telefon</label>
            <input type="tel" id="telefon" name="telefon" maxlength="50" autocomplete="tel"
                value="<?= e($existing['telefon'] ?? '') ?>"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
        </div>

        <div>
            <label for="email" class="block text-sm font-semibold mb-1">E-Mail</label>
            <input type="email" id="email" name="email" maxlength="150" autocomplete="email"
                value="<?= e($existing['email'] ?? '') ?>"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
        </div>

        <div>
            <label for="webseite" class="block text-sm font-semibold mb-1">Webseite</label>
            <input type="text" id="webseite" name="webseite" maxlength="255"
                value="<?= e($existing['webseite'] ?? '') ?>"
                placeholder="z. B. www.meine-zucht.de"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
            <p class="text-xs text-fellDk/50 mt-1">"https://" wird automatisch ergänzt, falls du es weglässt.</p>
        </div>

        <div>
            <label for="notizen" class="block text-sm font-semibold mb-1">Notizen</label>
            <textarea id="notizen" name="notizen" rows="3"
                class="w-full rounded-lg border border-sand px-3 py-2.5 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-tanne"><?= e($existing['notizen'] ?? '') ?></textarea>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 pt-2">
            <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-6 py-2.5 rounded-full transition-colors">
                <?= $isEditMode ? 'Speichern' : 'Züchter anlegen' ?>
            </button>
            <a href="/zuechter.php" class="text-sm text-fellDk/60 self-center hover:text-fellDk">Abbrechen</a>
        </div>
    </form>
</div>

<script>
/**
 * Alpine-Komponente für den Bild-Crop-Editor. Identisch zur Variante
 * in admin_add_breed.php (siehe dortiger ausführlicher Kommentar).
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
