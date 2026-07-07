<?php
/**
 * testdurchfuehrung_form.php
 *
 * Formular zum Erfassen einer neuen Testdurchführung für einen Hund
 * (?dog_id=X) ODER zum Bearbeiten einer bestehenden (?id=X). Anders
 * als test_form.php ist die Aufgabenliste hier FIX (durch den
 * gewählten Test vorgegeben, kein Hinzufügen/Entfernen) — pro Aufgabe
 * wird nur ein Ergebnis-Dropdown gerendert. Deutlich einfacher als der
 * zweistufige Repeater in test_form.php.
 *
 * Die Aufgaben-Blöcke aller Tests werden serverseitig vorgerendert und
 * per Alpine x-show anhand des gewählten Tests ein-/ausgeblendet —
 * das erspart einen AJAX-Request beim Wechsel des Tests.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Dog.php';
require_once __DIR__ . '/models/Test.php';
require_once __DIR__ . '/models/TestDurchfuehrung.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();

$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing  = null;

if ($editingId !== null) {
    $ownerDogId = TestDurchfuehrung::findDogIdById($editingId);
    $dog = $ownerDogId !== null ? Dog::findByIdForUser($ownerDogId, $currentUserId, $isAdmin) : null;

    if ($dog === null) {
        http_response_code(404);
        die('Die angeforderte Testdurchführung wurde nicht gefunden.');
    }

    $existing = TestDurchfuehrung::findByIdForDog($editingId, (int) $dog['id']);
    if ($existing === null) {
        http_response_code(404);
        die('Die angeforderte Testdurchführung wurde nicht gefunden.');
    }
} else {
    $dogId = isset($_GET['dog_id']) ? (int) $_GET['dog_id'] : 0;
    $dog   = $dogId ? Dog::findByIdForUser($dogId, $currentUserId, $isAdmin) : null;

    if ($dog === null) {
        http_response_code(404);
        die('Der angeforderte Hund wurde nicht gefunden.');
    }
}

$isEditMode = $existing !== null;

$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    // Im Bearbeiten-Modus ist der Test fix (siehe Docblock oben) —
    // test_id kommt in dem Fall aus der geladenen Durchführung, nicht
    // aus dem POST, damit ein manipuliertes Formularfeld keinen
    // Test-Wechsel nach dem Anlegen erzwingen kann.
    $testId = $isEditMode ? (int) $existing['test_id'] : (int) ($_POST['test_id'] ?? 0);

    $durchfuehrungData = [
        'durchfuehrungsdatum' => $_POST['durchfuehrungsdatum'] ?? '',
        'status'              => $_POST['status'] ?? '',
        'notizen'             => $_POST['notizen'] ?? '',
    ];

    // Erwartetes POST-Format: ergebnisse[<aufgabe_id>][ergebnis_id], ergebnisse[<aufgabe_id>][notizen]
    $ergebnisse = [];
    foreach (($_POST['ergebnisse'] ?? []) as $aufgabeId => $row) {
        $ergebnisse[] = [
            'aufgabe_id'  => (int) $aufgabeId,
            'ergebnis_id' => $row['ergebnis_id'] ?? '',
            'notizen'     => $row['notizen'] ?? '',
        ];
    }

    if ($isEditMode) {
        $result = TestDurchfuehrung::update($editingId, $testId, $durchfuehrungData, $ergebnisse);
    } else {
        $result = TestDurchfuehrung::create((int) $dog['id'], $testId, $durchfuehrungData, $ergebnisse, $currentUserId);
    }

    if ($result['success']) {
        $zielId = $isEditMode ? $editingId : $result['durchfuehrung_id'];
        header('Location: /testdurchfuehrung_detail.php?id=' . $zielId);
        exit;
    }

    $errorMessage = $result['message'];

    // Bei Validierungsfehler die eingegebenen Werte für erneute Anzeige
    // im Formular vorhalten (statt sie zu verlieren) — analog
    // test_form.php/admin_add_breed.php. Ohne dieses Re-Hydrieren
    // würden Status/Datum/Notizen sowie alle bereits ausgewählten
    // Aufgaben-Ergebnisse beim Neu-Rendern nach einem Fehler verloren
    // gehen (Notizen zeigen sonst leere Felder, Datum würde sogar
    // stillschweigend durch das heutige Datum ersetzt).
    $existing = $existing ?? [];
    $existing['test_id']              = $testId;
    $existing['durchfuehrungsdatum']  = $durchfuehrungData['durchfuehrungsdatum'];
    $existing['status']               = $durchfuehrungData['status'];
    $existing['notizen']              = $durchfuehrungData['notizen'];
    $existing['ergebnisse']           = $ergebnisse;
}

$alleTests = Test::findAll();

// Für jeden Test die volle Aufgaben-/Ergebnis-Struktur laden, damit die
// passenden Aufgaben-Blöcke serverseitig vorgerendert werden können
// (siehe Docblock oben). Bei der überschaubaren Anzahl an Tests in
// diesem Katalog ist das unproblematisch (analog zum N+1-Muster in
// Breed::getFilterCounts()).
$testDetails = [];
foreach ($alleTests as $test) {
    $testDetails[(int) $test['id']] = Test::findById((int) $test['id']);
}

$selectedTestId = (int) ($existing['test_id'] ?? ($_POST['test_id'] ?? 0));

// Bereits erfasste Ergebnisse nach aufgabe_id indiziert, fürs
// Vorbefüllen der Dropdowns/Notizen — sowohl im Bearbeiten-Modus
// (aus der DB geladen) als auch nach einem Validierungsfehler (aus
// dem soeben abgeschickten, aber abgelehnten POST rekonstruiert;
// siehe Re-Hydrierung weiter oben) — ohne diese Vereinheitlichung
// würden nach einem Fehler alle bereits gewählten Ergebnisse aus den
// Dropdowns verschwinden.
$existingErgebnisseByAufgabe = [];
if (!empty($existing['ergebnisse'])) {
    foreach ($existing['ergebnisse'] as $ergebnis) {
        $existingErgebnisseByAufgabe[(int) $ergebnis['aufgabe_id']] = $ergebnis;
    }
}

$statusLabels    = ['offen' => 'Offen', 'bestanden' => 'Bestanden', 'nicht_bestanden' => 'Nicht bestanden'];
$kategorieLabels = ['bestanden' => 'Bestanden', 'neutral' => 'Neutral', 'nicht_bestanden' => 'Nicht bestanden'];

$pageTitle = $isEditMode ? 'Testdurchführung bearbeiten' : 'Testdurchführung erfassen';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dog_detail.php?id=<?= (int) $dog['id'] ?>" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">
    ← Zurück zu <?= e($dog['name']) ?>
</a>

<h1 class="text-2xl font-extrabold text-fellDk mb-6">
    <?= $isEditMode ? '✏️ Testdurchführung bearbeiten' : '➕ Testdurchführung erfassen' ?>
    <span class="text-base font-normal text-fellDk/50">für <?= e($dog['name']) ?></span>
</h1>

<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert">
        <?= e($errorMessage) ?>
    </div>
<?php endif; ?>

<?php if (empty($alleTests)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
        <p class="text-sm text-fellDk/60">
            Es wurden noch keine Tests im Test-Katalog angelegt.
            <?php if ($isAdmin): ?>
                <a href="/test_form.php" class="text-tanne underline hover:text-tanneDk">Jetzt einen Test anlegen</a>.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <form method="post" action="/testdurchfuehrung_form.php<?= $isEditMode ? '?id=' . $editingId : '?dog_id=' . (int) $dog['id'] ?>"
          x-data='{
              selectedTestId: <?= $selectedTestId ?>,
              zielgruppeFilter: "alle",
              // Blendet <option>-Elemente im Test-Select anhand des
              // gewählten Filters ein/aus. Bewusst KEIN <template x-for>
              // für die Optionsliste (siehe Docblock oben) — Alpine würde
              // x-model auf dem <select> beim ersten Rendern verarbeiten,
              // BEVOR die per x-for erzeugten <option>-Elemente im DOM
              // existieren, wodurch der Browser mangels passender Option
              // auf "— Test wählen —" zurückfällt (bekanntes Alpine-
              // Timing-Problem bei x-model + dynamisch erzeugten Options).
              // Die Optionen werden daher serverseitig direkt gerendert
              // (inkl. korrektem "selected"), hier wird nur noch ihre
              // Sichtbarkeit umgeschaltet.
              wendeZielgruppenFilterAn() {
                  this.$refs.testSelect.querySelectorAll("option[data-zielgruppe]").forEach(opt => {
                      const passt = this.zielgruppeFilter === "alle" || opt.dataset.zielgruppe === this.zielgruppeFilter || opt.dataset.zielgruppe === "beide";
                      opt.hidden = !passt;
                  });
              },
              statusManuellGeaendert: false,
              aktualisiereStatusVorschlag() {
                  if (this.statusManuellGeaendert) return;
                  let bestanden = 0, nichtBestanden = 0;
                  this.$root.querySelectorAll("select[data-ergebnis-select]").forEach(sel => {
                      // Nur Selects im aktuell sichtbaren Test-Block zählen — die
                      // anderen Tests Blöcke sind per x-show verborgen
                      // (display:none), aber weiterhin im DOM vorhanden.
                      if (sel.offsetParent === null) return;
                      const gewaehlteOption = sel.options[sel.selectedIndex];
                      const kategorie = gewaehlteOption ? gewaehlteOption.dataset.kategorie : null;
                      if (kategorie === "bestanden") bestanden++;
                      else if (kategorie === "nicht_bestanden") nichtBestanden++;
                      // "neutral" und leere Auswahl zählen bewusst nicht mit.
                  });
                  if (bestanden === 0 && nichtBestanden === 0) return;
                  if (bestanden > nichtBestanden) this.$refs.statusSelect.value = "bestanden";
                  else if (nichtBestanden > bestanden) this.$refs.statusSelect.value = "nicht_bestanden";
                  // Bei Gleichstand auf "offen" zurücksetzen statt eine frühere,
                  // durch den Gleichstand nicht mehr gültige Mehrheits-Vorschlag-
                  // Auswahl stehen zu lassen (sähe sonst so aus, als würde nur das
                  // zuerst gewählte Ergebnis gezählt).
                  else this.$refs.statusSelect.value = "offen";
              }
          }' class="space-y-8">
        <?= csrf_field() ?>

        <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
            <h2 class="text-lg font-bold text-fellDk mb-4">Test</h2>

            <?php if ($isEditMode): ?>
                <p class="text-sm font-semibold"><?= e($existing['test_name']) ?></p>
                <p class="text-xs text-fellDk/50 mt-1">Der zugrunde liegende Test kann bei einer bestehenden Durchführung nicht mehr geändert werden.</p>
            <?php else: ?>
                <label class="block text-sm font-semibold mb-1">Zielgruppe filtern</label>
                <select x-model="zielgruppeFilter" @change="selectedTestId = 0; wendeZielgruppenFilterAn()"
                        class="w-full rounded-lg border border-sand px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-tanne mb-3">
                    <option value="alle">Alle Tests</option>
                    <option value="welpe">Nur Welpen-Tests</option>
                    <option value="erwachsen">Nur Tests für erwachsene Hunde</option>
                </select>

                <label for="test_id" class="block text-sm font-semibold mb-1">Welcher Test wurde durchgeführt? *</label>
                <select id="test_id" name="test_id" required x-ref="testSelect" x-model.number="selectedTestId"
                        class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne">
                    <option value="0">— Test wählen —</option>
                    <?php foreach ($alleTests as $test): ?>
                        <option value="<?= (int) $test['id'] ?>" data-zielgruppe="<?= e($test['zielgruppe']) ?>" <?= $selectedTestId === (int) $test['id'] ? 'selected' : '' ?>><?= e($test['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <?php foreach ($testDetails as $testId => $testDetail): ?>
            <div x-show="selectedTestId === <?= $testId ?>" x-cloak class="bg-white/80 rounded-2xl shadow border border-sand p-6">
                <h2 class="text-lg font-bold text-fellDk mb-4">Aufgaben-Ergebnisse</h2>

                <?php if (empty($testDetail['aufgaben'])): ?>
                    <p class="text-sm text-fellDk/50">Dieser Test hat keine Aufgaben.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($testDetail['aufgaben'] as $aufgabe):
                            $aufgabeId = (int) $aufgabe['id'];
                            $vorhandenesErgebnis = $existingErgebnisseByAufgabe[$aufgabeId] ?? null;
                        ?>
                            <div class="border border-sand rounded-lg p-4">
                                <p class="font-semibold text-sm mb-2"><?= e($aufgabe['titel']) ?></p>
                                <?php if (!empty($aufgabe['beschreibung'])): ?>
                                    <p class="text-xs text-fellDk/50 mb-2"><?= e($aufgabe['beschreibung']) ?></p>
                                <?php endif; ?>

                                <label class="block text-xs font-semibold mb-1">Beobachtetes Ergebnis</label>
                                <select name="ergebnisse[<?= $aufgabeId ?>][ergebnis_id]"
                                        data-ergebnis-select @change="aktualisiereStatusVorschlag()"
                                        class="w-full rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne mb-2">
                                    <option value="">— Ergebnis wählen —</option>
                                    <?php foreach ($aufgabe['ergebnisse'] as $ergebnis):
                                        $ergebnisId = (int) $ergebnis['id'];
                                        $ausgewaehlt = $vorhandenesErgebnis !== null && (int) $vorhandenesErgebnis['ergebnis_id'] === $ergebnisId;
                                    ?>
                                        <option value="<?= $ergebnisId ?>" data-kategorie="<?= e($ergebnis['kategorie']) ?>" <?= $ausgewaehlt ? 'selected' : '' ?>>
                                            <?= e($ergebnis['bezeichnung']) ?> (<?= e($kategorieLabels[$ergebnis['kategorie']] ?? $ergebnis['kategorie']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <label class="block text-xs font-semibold mb-1">Notiz (optional)</label>
                                <input type="text" name="ergebnisse[<?= $aufgabeId ?>][notizen]"
                                       value="<?= e($vorhandenesErgebnis['notizen'] ?? '') ?>"
                                       class="w-full rounded-lg border border-sand px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-tanne">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="bg-white/80 rounded-2xl shadow border border-sand p-6">
            <h2 class="text-lg font-bold text-fellDk mb-4">Gesamtbewertung</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="durchfuehrungsdatum" class="block text-sm font-semibold mb-1">Durchführungsdatum *</label>
                    <input type="date" id="durchfuehrungsdatum" name="durchfuehrungsdatum" required
                           max="<?= e(date('Y-m-d')) ?>"
                           value="<?= e($existing['durchfuehrungsdatum'] ?? date('Y-m-d')) ?>"
                           class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne">
                </div>
                <div>
                    <label for="status" class="block text-sm font-semibold mb-1">Gesamtstatus *</label>
                    <select id="status" name="status" required x-ref="statusSelect" @change="statusManuellGeaendert = true"
                            class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne">
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($existing['status'] ?? 'offen') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-fellDk/50 mt-1">Wird anhand der Mehrheit der Ergebnisse vorgeschlagen (Neutral zählt nicht mit), bleibt aber änderbar.</p>
                </div>
            </div>

            <label for="notizen" class="block text-sm font-semibold mb-1">Notizen (optional)</label>
            <textarea id="notizen" name="notizen" rows="4"
                      class="w-full rounded-lg border border-sand px-3 py-2 focus:outline-none focus:ring-2 focus:ring-tanne"><?= e($existing['notizen'] ?? '') ?></textarea>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold px-8 py-3 rounded-full transition-colors">
                <?= $isEditMode ? 'Änderungen speichern' : 'Testdurchführung speichern' ?>
            </button>
            <a href="/dog_detail.php?id=<?= (int) $dog['id'] ?>" class="text-fellDk/60 hover:text-fellDk font-semibold px-6 py-3">Abbrechen</a>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
