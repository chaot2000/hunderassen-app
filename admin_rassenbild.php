<?php
/**
 * admin_rassenbild.php
 *
 * Bild für eine Rasse wählen (Super-Admin). Oben das aktuelle Bild mit
 * Herkunft und Nachweis, "Zurückstellen" und "Bild entfernen". Darunter
 * Reiter je Quelle, abgefragt wird nur der geöffnete:
 *
 *  - TheDogAPI: Kandidaten zur zugeordneten TheDogAPI-Rasse
 *    (models/TheDogApiBilder.php). Ohne Zuordnung oder zum Ändern:
 *    Vorschläge aus dem Abgleich und Suche über alle TheDogAPI-Rassen,
 *    Zuordnen wird als Version gespeichert.
 *  - Hochladen: eigene Datei, im Browser auf 4:3 zugeschnitten
 *    (views/partials/bild_zuschneider.php), Urheber freiwillig.
 *
 * Nach dem Speichern eines Bildes geht es zurück zur Liste, mit der
 * Suche und den Filtern von vorher (Parameter "zurueck").
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/RassenBild.php';
require_once __DIR__ . '/models/TheDogApiBilder.php';
require_once __DIR__ . '/models/TheDogApiAbgleich.php';
require_once __DIR__ . '/models/BildVerarbeitung.php';
require_once __DIR__ . '/views/partials/bild_zuschneider.php';

secure_session_start();
require_super_admin();
set_time_limit(120);

const RB_REITER = ['thedogapi' => 'TheDogAPI', 'hochladen' => 'Hochladen'];

$rasse = RassenBild::finde((int) ($_REQUEST['id'] ?? 0));
if ($rasse === null) {
    http_response_code(404);
    die('Rasse nicht gefunden.');
}
$id = (int) $rasse['id'];
$reiter = array_key_exists($_REQUEST['reiter'] ?? '', RB_REITER) ? $_REQUEST['reiter'] : 'thedogapi';
// Nur Rücksprünge zur eigenen Liste, keine beliebigen Adressen.
$zurueck = (string) ($_REQUEST['zurueck'] ?? '');
if (!preg_match('#^/admin_rassenbilder\.php(\?[^\s"<>]*)?$#', $zurueck)) {
    $zurueck = '/admin_rassenbilder.php';
}
$hier = fn(string $r = '') => '/admin_rassenbild.php?id=' . $id . '&reiter=' . ($r ?: $reiter) . '&zurueck=' . rawurlencode($zurueck);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $userId = (int) current_user()['id'];
    $ziel = $hier();
    $meldung = null;
    $ok = true;

    switch ($_POST['aktion'] ?? '') {
        case 'zurueckstellen':
            RassenBild::zurueckstellen($id);
            $meldung = $rasse['name'] . ' ist zurückgestellt.';
            $ziel = $zurueck;
            break;
        case 'anbieten':
            RassenBild::anbieten($id);
            $meldung = $rasse['name'] . ' wird wieder als offen geführt.';
            break;
        case 'entfernen':
            RassenBild::entfernen($id);
            $meldung = 'Bild entfernt.';
            break;
        case 'zuordnen':
            $neu = trim((string) ($_POST['thedogapi_id'] ?? ''));
            $bekannt = array_column(TheDogApiFelder::breeds()['breeds'], 'name', 'id');
            if ($neu !== '' && !isset($bekannt[$neu])) {
                [$ok, $meldung] = [false, 'Unbekannte TheDogAPI-Rasse.'];
            } elseif (RassenBild::theDogApiZuordnen($id, $neu, $userId)) {
                $meldung = $neu === '' ? 'TheDogAPI-Zuordnung entfernt.' : 'Zugeordnet: TheDogAPI ' . $neu . ' · ' . $bekannt[$neu] . '.';
            } else {
                [$ok, $meldung] = [false, 'Zuordnung nicht gespeichert.'];
            }
            $ziel = $hier('thedogapi');
            break;
        case 'thedogapi_bild':
            $kandidat = TheDogApiBilder::kandidat((string) $rasse['thedogapi_id'], (string) ($_POST['bild_id'] ?? ''));
            $bild = $kandidat ? TheDogApiBilder::laden($kandidat['url']) : ['success' => false, 'message' => 'Bild gehört nicht zu dieser Rasse.'];
            if ($bild['success']) {
                RassenBild::speichern($id, $bild['blob'], $bild['mime'], TheDogApiBilder::quelle($kandidat['id']));
                $meldung = 'Bild für ' . $rasse['name'] . ' übernommen.';
                $ziel = $zurueck;
            } else {
                [$ok, $meldung] = [false, $bild['message']];
            }
            break;
        case 'hochladen':
            $bild = BildVerarbeitung::verarbeiteUpload($_FILES['bild'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            if (!$bild['success']) {
                [$ok, $meldung] = [false, $bild['message']];
            } elseif ($bild['blob'] === null) {
                [$ok, $meldung] = [false, 'Keine Datei ausgewählt.'];
            } else {
                RassenBild::speichern($id, $bild['blob'], $bild['mime'], 'Upload', ['urheber' => (string) ($_POST['urheber'] ?? '')]);
                $meldung = 'Bild für ' . $rasse['name'] . ' gespeichert.';
                $ziel = $zurueck;
            }
            $ziel = $ok ? $ziel : $hier('hochladen');
            break;
        default:
            [$ok, $meldung] = [false, 'Unbekannte Aktion.'];
    }
    $_SESSION[$ziel === $zurueck ? 'rassenbilder_meldung' : 'rassenbild_meldung'] = [$ok, $meldung];
    header('Location: ' . $ziel);
    exit;
}

[$meldungOk, $meldung] = $_SESSION['rassenbild_meldung'] ?? [null, null];
unset($_SESSION['rassenbild_meldung']);

// Daten nur für den geöffneten Reiter
$apiFehler = null;
$kandidaten = [];
$vorschlaege = [];
$apiListe = [];
$apiName = '';
$gleiche = [];
if ($reiter === 'thedogapi') {
    $apiBreeds = TheDogApiFelder::breeds();
    $apiFehler = $apiBreeds['fehler'];
    foreach ($apiBreeds['breeds'] as $b) {
        $apiListe[] = ['id' => (string) $b['id'], 'name' => (string) $b['name']];
    }
    $tdapi = (string) $rasse['thedogapi_id'];
    if ($tdapi !== '') {
        $apiName = array_column($apiListe, 'name', 'id')[$tdapi] ?? '';
        $gleiche = RassenBild::gleicheTheDogApi($id, $tdapi);
        $k = TheDogApiBilder::kandidaten($tdapi);
        $kandidaten = $k['bilder'];
        $apiFehler ??= $k['fehler'];
    }
    if ($apiBreeds['breeds']) {
        $vorschlaege = TheDogApiAbgleich::vorschlaege($rasse, $apiBreeds['breeds']);
    }
}

$quelleText = match (RassenBild::quellenArt($rasse['bild_quelle'])) {
    'thedogapi' => 'TheDogAPI, Bild ' . substr((string) $rasse['bild_quelle'], strlen('TheDogAPI:')),
    'upload'    => 'eigenes Bild',
    default     => $rasse['hat_bild'] ? 'Herkunft unbekannt' : '',
};
$knopf = 'text-sm font-semibold rounded-full px-4 py-2 border border-sand hover:bg-sand/30';

$pageTitle = 'Bild: ' . $rasse['name'];
require __DIR__ . '/views/partials/header.php';
?>

<a href="<?= e($zurueck) ?>" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">← Zur Liste</a>

<div class="max-w-5xl">
    <section class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 flex flex-col sm:flex-row gap-5">
        <div class="w-full sm:w-72 aspect-[4/3] rounded-xl overflow-hidden bg-creme border border-sand shrink-0 flex items-center justify-center">
            <?php if ($rasse['hat_bild']): ?>
                <img src="/bildausgabe.php?id=<?= $id ?>&amp;v=<?= urlencode((string) $rasse['bild_updated_at']) ?>" alt="Aktuelles Bild" class="w-full h-full object-cover">
            <?php else: ?>
                <span class="text-5xl text-fellDk/30" aria-hidden="true">🐾</span>
            <?php endif; ?>
        </div>
        <div class="flex-1 min-w-0">
            <h1 class="text-2xl font-extrabold text-fellDk break-words"><?= e($rasse['name']) ?></h1>
            <?php if ($rasse['alternative_namen'] || $rasse['name_en']): ?>
                <p class="text-sm text-fellDk/60"><?= e(trim(implode(' · ', array_filter([(string) $rasse['alternative_namen'], (string) $rasse['name_en']])))) ?></p>
            <?php endif; ?>
            <dl class="text-sm mt-3 space-y-1">
                <div><dt class="inline text-fellDk/60">Bild:</dt> <dd class="inline"><?= $rasse['hat_bild'] ? e($quelleText) : 'keins' ?></dd></div>
                <?php if ($rasse['bild_urheber']): ?>
                    <div><dt class="inline text-fellDk/60">Urheber:</dt> <dd class="inline"><?= e($rasse['bild_urheber']) ?><?= $rasse['bild_lizenz'] ? ', ' . e($rasse['bild_lizenz']) : '' ?></dd></div>
                <?php endif; ?>
                <?php if ($rasse['bild_zurueckgestellt']): ?>
                    <div><dt class="inline text-fellDk/60">Zurückgestellt seit:</dt> <dd class="inline"><?= e(date('d.m.Y', (int) strtotime((string) $rasse['bild_zurueckgestellt']))) ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="flex flex-wrap gap-2 mt-4">
                <form method="post" action="<?= e($hier()) ?>">
                    <?= csrf_field() ?>
                    <?php if ($rasse['bild_zurueckgestellt']): ?>
                        <input type="hidden" name="aktion" value="anbieten">
                        <button type="submit" class="<?= $knopf ?>">Wieder als offen führen</button>
                    <?php else: ?>
                        <input type="hidden" name="aktion" value="zurueckstellen">
                        <button type="submit" class="<?= $knopf ?>" title="Keine Quelle hat ein passendes Bild">Zurückstellen</button>
                    <?php endif; ?>
                </form>
                <?php if ($rasse['hat_bild']): ?>
                    <form method="post" action="<?= e($hier()) ?>" onsubmit="return confirm(<?= e(json_encode('Bild von ' . $rasse['name'] . ' wirklich entfernen?', JSON_UNESCAPED_UNICODE)) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="entfernen">
                        <button type="submit" class="text-sm font-semibold rounded-full px-4 py-2 text-red-700 hover:bg-red-50">Bild entfernen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($meldung !== null): ?>
        <p class="<?= $meldungOk ? 'bg-tanne/10 border-tanne/40 text-tanneDk' : 'bg-red-50 border-red-200 text-red-700' ?> border rounded-lg px-4 py-3 mt-4" role="<?= $meldungOk ? 'status' : 'alert' ?>"><?= e($meldung) ?></p>
    <?php endif; ?>

    <nav class="flex gap-1 mt-6 border-b border-sand" aria-label="Quellen">
        <?php foreach (RB_REITER as $r => $label): ?>
            <a href="<?= e($hier($r)) ?>" <?= $reiter === $r ? 'aria-current="page"' : '' ?>
               class="px-4 py-2 text-sm font-semibold rounded-t-lg <?= $reiter === $r ? 'bg-white/80 border border-sand border-b-white -mb-px text-fellDk' : 'text-fellDk/60 hover:text-fellDk' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="bg-white/80 rounded-b-2xl rounded-tr-2xl shadow border border-t-0 border-sand p-4 sm:p-6">
    <?php if ($reiter === 'thedogapi'): ?>
        <?php if ($apiFehler !== null): ?>
            <p class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 mb-4" role="alert"><?= e($apiFehler) ?></p>
        <?php endif; ?>

        <div x-data="{ aendern: <?= $rasse['thedogapi_id'] ? 'false' : 'true' ?> }">
            <?php if ($rasse['thedogapi_id']): ?>
                <p class="text-sm">
                    Zugeordnet: <strong>TheDogAPI <?= e((string) $rasse['thedogapi_id']) ?><?= $apiName !== '' ? ' · ' . e($apiName) : '' ?></strong>
                    <button type="button" @click="aendern = !aendern" class="ml-2 text-tanne underline">Zuordnung ändern</button>
                </p>
                <?php if ($gleiche): ?>
                    <p class="text-xs text-fellDk/60 mt-1">Dieselbe TheDogAPI-Rasse haben auch: <?= e(implode(', ', $gleiche)) ?>.</p>
                <?php endif; ?>

                <form method="post" action="<?= e($hier()) ?>" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mt-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="thedogapi_bild">
                    <?php foreach ($kandidaten as $k): ?>
                        <button type="submit" name="bild_id" value="<?= e($k['id']) ?>"
                                class="text-left border border-sand rounded-lg p-2 hover:ring-2 hover:ring-tanne focus:ring-2 focus:ring-tanne"
                                <?= $rasse['hat_bild'] ? 'onclick="return confirm(\'Vorhandenes Bild ersetzen?\');"' : '' ?>>
                            <img src="<?= e($k['url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" class="w-full aspect-[4/3] object-cover rounded">
                            <span class="block text-xs mt-1"><?= (int) $k['breite'] ?>×<?= (int) $k['hoehe'] ?></span>
                            <span class="block text-xs text-fellDk/60 truncate" title="Rasse laut TheDogAPI"><?= e($k['rasse'] ?? '') ?></span>
                            <span class="block text-xs font-bold text-tanne mt-1">Übernehmen</span>
                        </button>
                    <?php endforeach; ?>
                </form>
                <?php if (!$kandidaten && $apiFehler === null): ?>
                    <p class="text-sm text-fellDk/60 mt-4">TheDogAPI hat zu dieser Rasse keine Bilder mit passender Rassenangabe.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-sm">Noch keine TheDogAPI-Zuordnung. Wähle die passende TheDogAPI-Rasse, danach erscheinen ihre Bilder.</p>
            <?php endif; ?>

            <div x-show="aendern" x-cloak class="mt-6 border-t border-sand pt-4">
                <h2 class="font-bold text-fellDk mb-2">TheDogAPI-Rasse zuordnen</h2>
                <?php if ($vorschlaege): ?>
                    <p class="text-sm text-fellDk/60 mb-2">Vorschläge</p>
                    <ul class="space-y-1 mb-4">
                        <?php foreach ($vorschlaege as $v): ?>
                            <li class="flex items-center gap-3 text-sm">
                                <form method="post" action="<?= e($hier('thedogapi')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="aktion" value="zuordnen">
                                    <input type="hidden" name="thedogapi_id" value="<?= e((string) $v['breed']['id']) ?>">
                                    <button type="submit" class="font-semibold text-tanne hover:text-tanneDk">Zuordnen</button>
                                </form>
                                <span><?= e((string) $v['breed']['name']) ?> <span class="text-fellDk/50">(<?= e((string) $v['breed']['id']) ?>, <?= (int) $v['score'] ?> %, <?= e($v['grund']) ?>)</span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($apiListe): ?>
                    <div x-data="{ q: '', liste: <?= e(json_encode($apiListe, JSON_UNESCAPED_UNICODE)) ?>,
                                   treffer() { const w = this.q.toLowerCase().trim(); return w.length < 2 ? [] : this.liste.filter(b => b.name.toLowerCase().includes(w) || b.id === w).slice(0, 15); } }">
                        <label for="api_suche" class="block text-sm text-fellDk/60 mb-1">Alle TheDogAPI-Rassen durchsuchen (englische Namen)</label>
                        <input type="search" id="api_suche" x-model="q" placeholder="z. B. hound, spaniel, 234" class="w-full sm:w-96 border border-sand rounded-lg px-3 py-2 bg-white">
                        <ul class="mt-2 space-y-1">
                            <template x-for="b in treffer()" :key="b.id">
                                <li class="flex items-center gap-3 text-sm">
                                    <form method="post" action="<?= e($hier('thedogapi')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="aktion" value="zuordnen">
                                        <input type="hidden" name="thedogapi_id" :value="b.id">
                                        <button type="submit" class="font-semibold text-tanne hover:text-tanneDk">Zuordnen</button>
                                    </form>
                                    <span x-text="b.name + ' (' + b.id + ')'"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($rasse['thedogapi_id']): ?>
                    <form method="post" action="<?= e($hier('thedogapi')) ?>" class="mt-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="zuordnen">
                        <input type="hidden" name="thedogapi_id" value="">
                        <button type="submit" class="text-sm text-red-700 hover:underline">Zuordnung entfernen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($reiter === 'hochladen'): ?>
        <form method="post" action="<?= e($hier()) ?>" enctype="multipart/form-data"
              x-data='bildZuschneider({ breite: 4, hoehe: 3 })' x-init="init()" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="hochladen">
            <div class="flex flex-col sm:flex-row gap-5 items-start">
                <div class="w-full sm:w-72 aspect-[4/3] rounded-xl border border-sand bg-creme overflow-hidden flex items-center justify-center shrink-0">
                    <img x-show="previewUrl" :src="previewUrl" alt="" class="w-full h-full object-cover">
                    <span x-show="!previewUrl" class="text-4xl text-fellDk/30" aria-hidden="true">🐾</span>
                </div>
                <div class="flex-1 space-y-3">
                    <div>
                        <label for="bilddatei" class="block text-sm font-semibold mb-1">Bilddatei</label>
                        <input type="file" id="bilddatei" accept="image/jpeg,image/png,image/webp" x-ref="fileInput" @change="onFileSelected($event)"
                               class="block w-full text-sm file:mr-3 file:py-2 file:px-4 file:rounded-full file:border-0 file:bg-pfote/10 file:text-fell file:font-semibold">
                        <p class="text-xs text-fellDk/50 mt-1">JPG, PNG oder WebP. Danach den Ausschnitt im Format 4:3 festlegen.</p>
                        <button type="button" x-show="previewUrl" @click="reopenCropper()" class="text-xs text-tanne font-semibold mt-1">Ausschnitt ändern</button>
                        <input type="file" name="bild" x-ref="hiddenFileInput" class="hidden">
                    </div>
                    <div>
                        <label for="urheber" class="block text-sm font-semibold mb-1">Urheber / Quelle <span class="font-normal text-fellDk/50">(freiwillig)</span></label>
                        <input type="text" id="urheber" name="urheber" maxlength="255" placeholder="z. B. eigenes Foto, Name des Fotografen" class="w-full sm:w-96 border border-sand rounded-lg px-3 py-2 bg-white">
                    </div>
                    <button type="submit" :disabled="!previewUrl" class="bg-tanne hover:bg-tanneDk disabled:opacity-40 text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors"
                            <?= $rasse['hat_bild'] ? 'onclick="return confirm(\'Vorhandenes Bild ersetzen?\');"' : '' ?>>Bild speichern</button>
                </div>
            </div>
            <?php bild_zuschneider_modal(); ?>
        </form>
    <?php endif; ?>
    </section>
</div>

<?php bild_zuschneider_script(); ?>
<?php require __DIR__ . '/views/partials/footer.php'; ?>
