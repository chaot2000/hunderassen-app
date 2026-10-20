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
require_once __DIR__ . '/views/partials/bild_zuschneider.php';
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
$groesseVonHand = $isEditMode && RassenDatenSchreiben::groessenklasseVonHand($editingId);

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
    $groesseVonHand = ($_POST['groesse_auto'] ?? '1') !== '1';

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
                RassenDatenSchreiben::speichern($breedId, $merkmalWerte, $texte, $userId, !$groesseVonHand);
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
        x-data='bildZuschneider({
            initialPreviewUrl: <?= $isEditMode && !empty($existing['hat_bild']) ? json_encode('/bildausgabe.php?id=' . (int) $editingId . '&v=' . urlencode((string) ($existing['bild_updated_at'] ?? '')), JSON_HEX_APOS) : 'null' ?>
        })'
        x-init="init()"
        class="bg-white/80 rounded-2xl shadow border border-sand p-6"
    >
        <h2 class="text-lg font-bold text-fellDk mb-4">Bild</h2>

        <div class="flex flex-col sm:flex-row gap-5 items-start">
            <div class="w-full sm:w-64 aspect-[4/3] rounded-xl border border-sand bg-creme overflow-hidden flex items-center justify-center shrink-0">
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

        <?php bild_zuschneider_modal(); ?>
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

    <?php render_steckbrief_formular($definitionen, $merkmalWerte, $groesseVonHand); ?>

    <?php render_texte_formular($texte); ?>

    <div class="flex gap-3">
        <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold px-8 py-3 rounded-full transition-colors">
            <?= $isEditMode ? 'Änderungen speichern' : 'Rasse anlegen' ?>
        </button>
        <a href="/rassen.php" class="text-fellDk/60 hover:text-fellDk font-semibold px-6 py-3">Abbrechen</a>
    </div>
</form>

<?php bild_zuschneider_script(); ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>