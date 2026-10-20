<?php
/**
 * admin_rassenbilder.php
 *
 * Übersicht der Rassenbilder für den Super-Admin: alle Rassen mit Suche
 * und Filtern nach Status (ohne Bild, ungeprüft, mit Bild, zurückgestellt)
 * und Herkunft des Bildes. Je Rasse führt "Bild wählen" zu
 * admin_rassenbild.php, dort stehen die Quellen (TheDogAPI, Wikimedia,
 * Hochladen).
 *
 * Die Liste fragt keine fremde Schnittstelle ab. Alle Rassen kommen auf
 * einmal (knapp 400), gesucht und gefiltert wird im Browser, damit das
 * Ergebnis schon beim Tippen erscheint. Suche und Filter stehen in der
 * Adresse, so führt "Zur Liste" von der Auswahlseite an dieselbe Stelle.
 *
 * "Passt" bestätigt ein Bild ohne Neuladen der Seite: POST mit
 * aktion=geprueft|ungeprueft und id, Antwort als JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/RassenBild.php';

secure_session_start();
// Der Rassenkatalog ist für alle Organisationen gemeinsam.
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $aktion = (string) ($_POST['aktion'] ?? '');
    $ok = in_array($aktion, ['geprueft', 'ungeprueft'], true)
        && RassenBild::geprueft((int) ($_POST['id'] ?? 0), $aktion === 'geprueft');
    $rasse = $ok ? RassenBild::finde((int) $_POST['id']) : null;
    http_response_code($ok ? 200 : 400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'       => $ok,
        'geprueft' => $rasse && $rasse['bild_geprueft'] ? date('d.m.Y', (int) strtotime((string) $rasse['bild_geprueft'])) : '',
    ]);
    exit;
}

[$meldungOk, $meldung] = $_SESSION['rassenbilder_meldung'] ?? [null, null];
unset($_SESSION['rassenbilder_meldung']);

$zeilen = [];
foreach (RassenBild::liste() as $r) {
    $status = $r['bild_zurueckgestellt'] !== null ? 'zurueck' : ($r['hat_bild'] ? 'mit' : 'ohne');
    $quelle = $r['hat_bild'] ? (RassenBild::quellenArt($r['bild_quelle']) ?: 'unbekannt') : '';
    $zeilen[] = [
        'id'      => (int) $r['id'],
        'name'    => (string) $r['name'],
        'neben'   => trim(implode(' · ', array_filter([(string) $r['alternative_namen'], (string) $r['name_en']]))),
        'suche'   => mb_strtolower($r['name'] . ' ' . $r['alternative_namen'] . ' ' . $r['name_en']),
        'status'  => $status,
        'quelle'  => $quelle,
        'tdapi'   => (string) $r['thedogapi_id'],
        'wd'      => (string) $r['wikidata_id'],
        'bild'    => $r['hat_bild'] ? '/bildausgabe.php?id=' . (int) $r['id'] . '&v=' . urlencode((string) $r['bild_updated_at']) : '',
        'seit'    => $r['bild_zurueckgestellt'] ? date('d.m.Y', (int) strtotime((string) $r['bild_zurueckgestellt'])) : '',
        'geprueft' => $r['hat_bild'] && $r['bild_geprueft'] ? date('d.m.Y', (int) strtotime((string) $r['bild_geprueft'])) : '',
    ];
}

$statusNamen = ['ohne' => 'Ohne Bild', 'ungeprueft' => 'Ungeprüft', 'mit' => 'Mit Bild', 'zurueck' => 'Zurückgestellt', 'alle' => 'Alle'];
$quellenNamen = ['' => 'Jede Herkunft'] + RassenBild::QUELLEN + ['unbekannt' => 'Herkunft unbekannt'];

$pageTitle = 'Rassenbilder';
require __DIR__ . '/views/partials/header.php';
?>

<div class="max-w-5xl" x-data="rassenbilderListe(<?= e(json_encode($zeilen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)" x-init="init()">
    <h1 class="text-2xl font-extrabold text-fellDk mb-2">🖼️ Rassenbilder</h1>
    <p class="text-fellDk mb-4 max-w-[70ch]">
        Alle Rassen mit ihrem Bild. Über „Bild wählen“ suchst du ein Bild bei TheDogAPI oder Wikimedia Commons oder lädst ein eigenes hoch.
        Rassen, für die es nichts Passendes gibt, kannst du dort zurückstellen.
        Unter „Ungeprüft“ bestätigst du vorhandene Bilder mit „Passt“, ein Bild aus der Bildauswahl gilt gleich als geprüft.
    </p>

    <?php if ($meldung !== null): ?>
        <p class="<?= $meldungOk ? 'bg-tanne/10 border-tanne/40 text-tanneDk' : 'bg-red-50 border-red-200 text-red-700' ?> border rounded-lg px-4 py-3 mb-4" role="<?= $meldungOk ? 'status' : 'alert' ?>"><?= e($meldung) ?></p>
    <?php endif; ?>

    <div class="flex flex-wrap items-end gap-3 mb-3">
        <div class="flex-1 min-w-[14rem]">
            <label for="suche" class="block text-sm font-semibold mb-1">Rasse suchen</label>
            <input type="search" id="suche" x-model.debounce.150ms="q" @input.debounce.200ms="merken()" placeholder="Name, alternativer oder englischer Name"
                   class="w-full border border-sand rounded-lg px-3 py-2 bg-white">
        </div>
        <div>
            <label for="quelle" class="block text-sm font-semibold mb-1">Herkunft</label>
            <select id="quelle" x-model="quelle" @change="merken()" class="border border-sand rounded-lg px-3 py-2 bg-white">
                <?php foreach ($quellenNamen as $wert => $label): ?>
                    <option value="<?= e($wert) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <nav class="flex flex-wrap gap-2 mb-4" aria-label="Status">
        <?php foreach ($statusNamen as $s => $label): ?>
            <button type="button" @click="status = '<?= $s ?>'; merken()"
                    :class="status === '<?= $s ?>' ? 'bg-tanne text-creme border-tanne' : 'border-sand hover:bg-sand/30'"
                    class="text-sm font-semibold rounded-full px-4 py-1.5 border"><?= e($label) ?> (<span x-text="anzahl('<?= $s ?>')"></span>)</button>
        <?php endforeach; ?>
    </nav>

    <p class="text-sm text-fellDk/60 mb-2" aria-live="polite"><span x-text="treffer().length"></span> Rassen</p>

    <ul class="bg-white/80 rounded-2xl shadow border border-sand divide-y divide-sand">
        <template x-for="r in treffer().slice(0, grenze)" :key="r.id">
            <li class="flex items-center gap-4 px-4 py-3">
                <div :class="status === 'ungeprueft' ? 'w-40 sm:w-56' : 'w-24'" class="aspect-[4/3] rounded-lg overflow-hidden bg-creme border border-sand shrink-0 flex items-center justify-center">
                    <template x-if="r.bild"><a :href="r.bild" target="_blank" rel="noopener" class="block w-full h-full" title="Bild groß öffnen"><img :src="r.bild" alt="" loading="lazy" class="w-full h-full object-cover"></a></template>
                    <template x-if="!r.bild"><span class="text-2xl text-fellDk/30" aria-hidden="true">🐾</span></template>
                </div>
                <div class="flex-1 min-w-0">
                    <a :href="'/breed_detail.php?id=' + r.id" class="font-semibold hover:underline" x-text="r.name"></a>
                    <p class="text-xs text-fellDk/50 truncate" x-text="r.neben"></p>
                    <p class="text-xs mt-1 flex flex-wrap gap-1.5">
                        <span x-show="r.tdapi" class="border border-sand rounded-full px-2 py-0.5" x-text="'TheDogAPI ' + r.tdapi"></span>
                        <span x-show="r.wd" class="border border-sand rounded-full px-2 py-0.5" x-text="'Wikidata ' + r.wd"></span>
                        <span x-show="r.quelle" class="bg-sand/40 rounded-full px-2 py-0.5" x-text="quellenName(r.quelle)"></span>
                        <span x-show="r.seit" class="bg-pfote/15 rounded-full px-2 py-0.5" x-text="'zurückgestellt seit ' + r.seit"></span>
                        <span x-show="r.geprueft" class="bg-tanne/10 text-tanneDk rounded-full px-2 py-0.5" x-text="'geprüft ' + r.geprueft"></span>
                    </p>
                </div>
                <div x-show="r.bild" class="shrink-0 text-sm">
                    <button type="button" x-show="!r.geprueft" @click="pruefen(r, true)" :disabled="r.laeuft"
                            class="font-bold bg-tanne hover:bg-tanneDk text-white rounded-lg px-3 py-1.5 disabled:opacity-50">Passt</button>
                    <button type="button" x-show="r.geprueft" @click="pruefen(r, false)" :disabled="r.laeuft"
                            class="text-xs text-fellDk/60 hover:text-fellDk underline">zurücknehmen</button>
                </div>
                <a :href="'/admin_rassenbild.php?id=' + r.id + '&zurueck=' + encodeURIComponent(adresse())"
                   class="shrink-0 text-sm font-bold text-tanne hover:text-tanneDk" x-text="r.bild ? 'Bild ersetzen' : 'Bild wählen'"></a>
            </li>
        </template>
        <li x-show="treffer().length === 0" class="px-4 py-6 text-fellDk/60">Keine Rasse passt zur Suche.</li>
    </ul>
    <button type="button" x-show="treffer().length > grenze" @click="grenze += 100" class="mt-3 text-sm font-semibold text-tanne underline">Weitere anzeigen</button>

    <noscript>
        <p class="mt-4">Die Liste braucht JavaScript. Ohne JavaScript erreichst du die Bildauswahl über <a href="/rassen.php" class="underline">die Rassenliste</a>.</p>
    </noscript>
</div>

<script>
function rassenbilderListe(zeilen) {
    const ohneAkzente = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    zeilen.forEach((r) => { r.sucheNorm = ohneAkzente(r.suche); });
    const quellen = <?= json_encode($quellenNamen, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const csrf = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>;
    const passt = (r, s) => s === 'alle' || (s === 'ungeprueft' ? r.status === 'mit' && !r.geprueft : r.status === s);
    zeilen.forEach((r) => { r.laeuft = false; });
    return {
        zeilen,
        q: '',
        status: 'ohne',
        quelle: '',
        grenze: 100,
        init() {
            const p = new URLSearchParams(location.search);
            this.q = p.get('q') || '';
            this.status = ['ohne', 'ungeprueft', 'mit', 'zurueck', 'alle'].includes(p.get('status')) ? p.get('status') : 'ohne';
            this.quelle = p.get('quelle') || '';
        },
        treffer() {
            const woerter = ohneAkzente(this.q).split(/\s+/).filter(Boolean);
            return this.zeilen.filter((r) =>
                passt(r, this.status)
                && (this.quelle === '' || r.quelle === this.quelle)
                && woerter.every((w) => r.sucheNorm.includes(w)));
        },
        anzahl(s) {
            return this.zeilen.filter((r) => passt(r, s)).length;
        },
        async pruefen(r, ok) {
            r.laeuft = true;
            const daten = new FormData();
            daten.append('csrf_token', csrf);
            daten.append('aktion', ok ? 'geprueft' : 'ungeprueft');
            daten.append('id', r.id);
            try {
                const antwort = await fetch('/admin_rassenbilder.php', { method: 'POST', body: daten, headers: { Accept: 'application/json' } });
                const j = await antwort.json();
                if (!j.ok) throw new Error();
                r.geprueft = j.geprueft;
            } catch (e) {
                alert('Speichern hat nicht geklappt. Bitte Seite neu laden.');
            }
            r.laeuft = false;
        },
        quellenName(q) {
            return quellen[q] || q;
        },
        adresse() {
            const p = new URLSearchParams();
            if (this.q) p.set('q', this.q);
            if (this.status !== 'ohne') p.set('status', this.status);
            if (this.quelle) p.set('quelle', this.quelle);
            const s = p.toString();
            return '/admin_rassenbilder.php' + (s ? '?' + s : '');
        },
        merken() {
            this.grenze = 100;
            history.replaceState(null, '', this.adresse());
        },
    };
}
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
