<?php
/**
 * views/partials/bild_zuschneider.php
 *
 * Zuschneider für Rassenbilder als gemeinsamer Baustein
 * (admin_add_breed.php, admin_rassenbild.php). Seitenverhältnis
 * einstellbar, für Rassen 4:3 wie auf der Rassenseite.
 *
 * Ablauf: Datei wird über ein Feld OHNE name gewählt, ein Modal zeigt
 * sie auf einem Canvas. Ziehen verschiebt den Ausschnitt, der Regler
 * zoomt. "Übernehmen" rendert den Ausschnitt in fester Zielgröße als
 * JPEG und schreibt ihn per DataTransfer in das abgeschickte Feld
 * <input type="file" name="bild" x-ref="hiddenFileInput">. Der Server
 * bekommt also nur den Ausschnitt. BildVerarbeitung prüft und
 * verkleinert danach wie bei jedem Upload.
 *
 * Verschiebung wird relativ zur Stage gespeichert, nicht in Pixeln:
 * Modal und Export zeichnen in unterschiedlichen Größen, mit
 * Pixelwerten wiche der gespeicherte Ausschnitt von der Vorschau ab.
 *
 * Einbinden: im Formular x-data='bildZuschneider({...})' x-init="init()",
 * darin <?php bild_zuschneider_modal(); ?>, am Seitenende einmal
 * <?php bild_zuschneider_script(); ?>.
 */

declare(strict_types=1);

function bild_zuschneider_modal(): void
{
    ?>
    <!-- Zuschneide-Modal: erscheint nach Dateiauswahl, blockt die restliche Seite ab -->
    <div x-show="cropperOpen" x-cloak class="fixed inset-0 bg-fellDk/60 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="bg-creme rounded-2xl shadow-xl p-6 w-full max-w-lg">
            <h3 class="text-lg font-bold text-fellDk mb-1">Bildausschnitt wählen</h3>
            <p class="text-xs text-fellDk/60 mb-4">Zum Verschieben ziehen, mit dem Regler zoomen.</p>
            <div
                x-ref="cropStage"
                :style="'aspect-ratio: ' + breite + ' / ' + hoehe"
                class="relative w-full rounded-xl overflow-hidden border-2 border-tanne bg-fellDk/10 cursor-grab select-none touch-none"
                @mousedown="startDrag($event)" @mousemove="onDrag($event)" @mouseup="endDrag()" @mouseleave="endDrag()"
                @touchstart="startDrag($event)" @touchmove.prevent="onDrag($event)" @touchend="endDrag()"
            >
                <canvas x-ref="cropCanvas" class="absolute top-0 left-0 w-full h-full pointer-events-none"></canvas>
            </div>
            <div class="flex items-center gap-3 mt-4">
                <span class="text-xs text-fellDk/60" aria-hidden="true">🔍</span>
                <input type="range" min="1" max="3" step="0.01" x-model.number="zoom" @input="drawCanvas()" class="flex-1 accent-tanne" aria-label="Zoom">
            </div>
            <div class="flex gap-3 mt-5">
                <button type="button" @click="confirmCrop()" class="flex-1 bg-tanne hover:bg-tanneDk text-creme font-bold px-4 py-2.5 rounded-full transition-colors">Übernehmen</button>
                <button type="button" @click="cancelCrop()" class="text-fellDk/60 hover:text-fellDk font-semibold px-4 py-2.5">Abbrechen</button>
            </div>
        </div>
    </div>
    <?php
}

function bild_zuschneider_script(): void
{
    ?>
<script>
function bildZuschneider({ initialPreviewUrl = null, breite = 4, hoehe = 3, zielBreite = 1200, dateiname = 'rassebild.jpg' } = {}) {
    return {
        previewUrl: initialPreviewUrl,
        markedForRemoval: false,
        cropperOpen: false,
        breite, hoehe,
        ZIEL_BREITE: zielBreite,

        image: null,
        zoom: 1,
        offsetXRel: 0,
        offsetYRel: 0,
        dragging: false,
        dragStartX: 0,
        dragStartY: 0,
        dragOffsetStartXRel: 0,
        dragOffsetStartYRel: 0,

        init() {},

        // Zeichnen erst nach dem Layout: x-show braucht einen Reflow,
        // bevor clientWidth einen Wert > 0 hat.
        drawNext() {
            requestAnimationFrame(() => this.drawCanvas());
        },

        oeffneMitBild(img) {
            this.image = img;
            this.zoom = 1;
            this.offsetXRel = 0;
            this.offsetYRel = 0;
            this.cropperOpen = true;
            this.drawNext();
        },

        onFileSelected(event) {
            const file = event.target.files[0];
            if (!file) {
                return;
            }
            const img = new Image();
            img.onload = () => this.oeffneMitBild(img);
            img.onerror = () => alert('Dieses Bild konnte nicht gelesen werden. Bitte eine andere Datei wählen.');
            this.releasePreviousObjectUrl();
            img.src = URL.createObjectURL(file);
        },

        // Bild von einer Adresse der eigenen Seite laden und zuschneiden
        // (gespeichertes Bild oder über den Server durchgereichter Kandidat).
        ladeUrl(url) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => this.oeffneMitBild(img);
            img.onerror = () => alert('Das Bild konnte nicht geladen werden.');
            img.src = url;
        },

        reopenCropper() {
            if (this.image) {
                this.cropperOpen = true;
                this.drawNext();
                return;
            }
            // Gespeichertes Bild nachladen. Es ist schon serverseitig
            // verkleinert, weggeschnittene Bereiche kommen nicht zurück.
            if (this.previewUrl) {
                this.ladeUrl(this.previewUrl);
                return;
            }
            this.$refs.fileInput.click();
        },

        removeImage() {
            this.previewUrl = null;
            this.markedForRemoval = true;
            this.image = null;
            if (this.$refs.fileInput) {
                this.$refs.fileInput.value = '';
            }
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
            const stage = this.$refs.cropStage;
            this.offsetXRel = this.dragOffsetStartXRel + (point.x - this.dragStartX) / stage.clientWidth;
            this.offsetYRel = this.dragOffsetStartYRel + (point.y - this.dragStartY) / stage.clientHeight;
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
            const w = this.$refs.cropStage.clientWidth;
            const h = this.$refs.cropStage.clientHeight;
            if (w === 0 || h === 0) {
                requestAnimationFrame(() => this.drawCanvas());
                return;
            }
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, w, h);
            const f = this.berechneZeichenflaeche(w, h);
            ctx.drawImage(this.image, f.drawX, f.drawY, f.drawWidth, f.drawHeight);
        },

        // "cover": Das Bild füllt die Stage immer ganz, Zoom und
        // Verschiebung bestimmen den Ausschnitt. Grenzen relativ zur
        // Stage, damit Modal und Export denselben Ausschnitt zeigen.
        berechneZeichenflaeche(w, h) {
            const skalierung = Math.max(w / this.image.naturalWidth, h / this.image.naturalHeight) * this.zoom;
            const drawWidth = this.image.naturalWidth * skalierung;
            const drawHeight = this.image.naturalHeight * skalierung;
            const maxX = Math.max(0, (drawWidth - w) / 2) / w;
            const maxY = Math.max(0, (drawHeight - h) / 2) / h;
            this.offsetXRel = Math.min(maxX, Math.max(-maxX, this.offsetXRel));
            this.offsetYRel = Math.min(maxY, Math.max(-maxY, this.offsetYRel));
            return {
                drawWidth, drawHeight,
                drawX: (w - drawWidth) / 2 + this.offsetXRel * w,
                drawY: (h - drawHeight) / 2 + this.offsetYRel * h,
            };
        },

        confirmCrop() {
            const w = this.ZIEL_BREITE;
            const h = Math.round(w * this.hoehe / this.breite);
            const ausgabe = document.createElement('canvas');
            ausgabe.width = w;
            ausgabe.height = h;
            const f = this.berechneZeichenflaeche(w, h);
            ausgabe.getContext('2d').drawImage(this.image, f.drawX, f.drawY, f.drawWidth, f.drawHeight);
            ausgabe.toBlob((blob) => {
                if (!blob) {
                    alert('Der Bildausschnitt konnte nicht verarbeitet werden. Bitte erneut versuchen.');
                    return;
                }
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], dateiname, { type: 'image/jpeg' }));
                this.$refs.hiddenFileInput.files = transfer.files;
                this.releasePreviousObjectUrl();
                this.previewUrl = URL.createObjectURL(blob);
                this.markedForRemoval = false;
                this.cropperOpen = false;
                this.$dispatch('bild-zugeschnitten');
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
                if (this.$refs.fileInput) {
                    this.$refs.fileInput.value = '';
                }
                this.image = null;
            }
        },
    };
}
</script>
    <?php
}
