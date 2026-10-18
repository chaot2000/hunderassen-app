<?php
/**
 * models/WikimediaBilder.php
 *
 * Rassenbilder aus Wikimedia Commons über Wikidata (admin_rassenbild.php,
 * Reiter Wikimedia). Beide Schnittstellen brauchen keinen Schlüssel,
 * verlangen aber einen aussagekräftigen User-Agent.
 *
 * 1. Zuordnung: Rasse → Wikidata-Eintrag. Vorschläge kommen über den
 *    deutschen Wikipedia-Titel (Name, Namensteile, alternative Namen),
 *    den englischen Titel (name_en) und die Wikidata-Suche. Einträge vom
 *    Typ Hunderasse (P31 = Q39367) stehen vorne, nur über einen Titel
 *    gefundene Hunderassen gelten als sicher.
 * 2. Kandidaten: Hauptbild(er) des Eintrags (P18) und die Dateien der
 *    Commons-Kategorie (P373) samt einiger Unterkategorien. Angeboten
 *    werden nur Fotos (JPEG, PNG, WebP, TIFF) unter freier Lizenz:
 *    gemeinfrei, CC0, CC BY, CC BY-SA. Je Bild kommen Urheber, Lizenz
 *    und Bildseite mit, sie bilden den Bildnachweis.
 *
 * Kandidaten werden je Eintrag eine Woche in cache_wikimedia_bilder.json
 * gehalten. Beim Übernehmen kommt die Bildadresse aus diesem Cache.
 */

declare(strict_types=1);

require_once __DIR__ . '/ExternesBild.php';

class WikimediaBilder
{
    public const HUNDERASSE = 'Q39367';
    private const WIKIDATA = 'https://www.wikidata.org/w/api.php';
    private const COMMONS = 'https://commons.wikimedia.org/w/api.php';
    private const CACHE_DATEI = __DIR__ . '/../cache_wikimedia_bilder.json';
    private const CACHE_MAX_ALTER_SEK = 7 * 86400;
    private const CACHE_VERSION = 1;
    private const MAX_KATEGORIE = 50;
    private const MAX_UNTERKATEGORIEN = 6;
    private const MAX_JE_UNTERKATEGORIE = 12;
    private const MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/tiff'];
    private const META = 'Artist|LicenseShortName|LicenseUrl|UsageTerms';

    /** Gültige Wikidata-ID wie "Q21080"? */
    public static function istQid(string $qid): bool
    {
        return (bool) preg_match('/^Q[1-9]\d{0,11}$/', $qid);
    }

    public static function istWikimediaUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return parse_url($url, PHP_URL_SCHEME) === 'https' && (bool) preg_match('/(^|\.)(wikimedia|wikipedia)\.org$/', $host);
    }

    /**
     * Einträge mit Bezeichnung, Typ, Bildern, Kategorie und deutschem Artikel.
     *
     * @param list<string> $qids
     * @return array{eintraege: array<string, array>, fehler: ?string}
     */
    public static function eintraege(array $qids): array
    {
        $qids = array_values(array_filter(array_unique($qids), [self::class, 'istQid']));
        if (!$qids) {
            return ['eintraege' => [], 'fehler' => null];
        }
        $daten = self::api(self::WIKIDATA, ['action' => 'wbgetentities', 'ids' => implode('|', array_slice($qids, 0, 50))] + self::ENTITY_PARAMS);
        if ($daten === null) {
            return ['eintraege' => [], 'fehler' => 'Wikidata ist gerade nicht erreichbar.'];
        }
        return ['eintraege' => self::entitaetenAuswerten($daten), 'fehler' => null];
    }

    private const ENTITY_PARAMS = [
        'props' => 'claims|sitelinks|labels|descriptions', 'languages' => 'de|en', 'sitefilter' => 'dewiki',
    ];

    /** Antwort von wbgetentities in schlichte Einträge übersetzen. */
    public static function entitaetenAuswerten(array $daten): array
    {
        $ergebnis = [];
        foreach ($daten['entities'] ?? [] as $qid => $e) {
            if (!is_array($e) || isset($e['missing']) || !self::istQid((string) ($e['id'] ?? ''))) {
                continue;
            }
            $werte = function (string $p) use ($e): array {
                $liste = [];
                foreach ($e['claims'][$p] ?? [] as $c) {
                    $v = $c['mainsnak']['datavalue']['value'] ?? null;
                    $liste[] = is_array($v) ? (string) ($v['id'] ?? '') : (string) $v;
                }
                return array_values(array_filter($liste, fn($v) => $v !== ''));
            };
            $label = $e['labels']['de']['value'] ?? $e['labels']['en']['value'] ?? $e['id'];
            $ergebnis[(string) $e['id']] = [
                'id'           => (string) $e['id'],
                'label'        => (string) $label,
                'beschreibung' => (string) ($e['descriptions']['de']['value'] ?? $e['descriptions']['en']['value'] ?? ''),
                'rasse'        => in_array(self::HUNDERASSE, $werte('P31'), true),
                'bilder'       => $werte('P18'),
                'kategorie'    => $werte('P373')[0] ?? '',
                'wikipedia_de' => (string) ($e['sitelinks']['dewiki']['title'] ?? ''),
            ];
        }
        return $ergebnis;
    }

    /**
     * Vorschläge für die Zuordnung einer Rasse.
     *
     * @param array{name: string, name_en?: ?string, alternative_namen?: ?string} $rasse
     * @return array{vorschlaege: list<array>, fehler: ?string}
     */
    public static function vorschlaege(array $rasse): array
    {
        $titel = self::titel((string) $rasse['name'], $rasse['alternative_namen'] ?? null);
        $ueberTitel = [];
        $fehler = null;
        foreach ([['dewiki', $titel], ['enwiki', array_filter([trim((string) ($rasse['name_en'] ?? ''))])]] as [$site, $liste]) {
            if (!$liste) {
                continue;
            }
            $daten = self::api(self::WIKIDATA, ['action' => 'wbgetentities', 'sites' => $site, 'titles' => implode('|', array_slice($liste, 0, 50))] + self::ENTITY_PARAMS);
            if ($daten === null) {
                $fehler = 'Wikidata ist gerade nicht erreichbar.';
                continue;
            }
            $ueberTitel += self::entitaetenAuswerten($daten);
        }

        $gesucht = [];
        foreach ([[$titel[0] ?? '', 'de'], [(string) ($rasse['name_en'] ?? ''), 'en']] as [$text, $sprache]) {
            if (trim($text) === '') {
                continue;
            }
            $daten = self::api(self::WIKIDATA, ['action' => 'wbsearchentities', 'search' => $text, 'language' => $sprache, 'uselang' => 'de', 'type' => 'item', 'limit' => 7]);
            foreach ($daten['search'] ?? [] as $s) {
                $gesucht[] = (string) ($s['id'] ?? '');
            }
        }
        $ueberSuche = self::eintraege(array_diff($gesucht, array_keys($ueberTitel)))['eintraege'];

        $alle = [];
        foreach ($ueberTitel as $e) {
            $alle[] = $e + ['sicher' => $e['rasse'], 'weg' => 'Wikipedia-Titel'];
        }
        foreach ($ueberSuche as $e) {
            $alle[] = $e + ['sicher' => false, 'weg' => 'Suche'];
        }
        // Hunderassen zuerst, sonst Reihenfolge der Fundstellen
        usort($alle, fn($a, $b) => [$b['sicher'], $b['rasse']] <=> [$a['sicher'], $a['rasse']]);
        return ['vorschlaege' => array_slice($alle, 0, 8), 'fehler' => $fehler];
    }

    /**
     * Mögliche Wikipedia-Titel aus Name und alternativen Namen: ganzer
     * Name, Teile vor und nach dem Komma, ohne Klammerzusatz.
     *
     * @return list<string>
     */
    public static function titel(string $name, ?string $alternativeNamen = null): array
    {
        $roh = array_merge([$name], explode(',', $name), preg_split('/[,;]/', (string) $alternativeNamen) ?: []);
        $titel = [];
        foreach ($roh as $t) {
            foreach ([$t, preg_replace('/\s*\([^)]*\)/u', '', $t)] as $v) {
                $v = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
                if ($v !== '' && mb_strlen($v) <= 120) {
                    $titel[$v] = true;
                }
            }
        }
        return array_keys($titel);
    }

    /**
     * Bildkandidaten zu einem Wikidata-Eintrag.
     *
     * @return array{bilder: list<array>, fehler: ?string}
     */
    public static function kandidaten(string $qid): array
    {
        if (!self::istQid($qid)) {
            return ['bilder' => [], 'fehler' => 'Ungültige Wikidata-ID.'];
        }
        $cache = self::cacheLesen();
        $eintrag = $cache[$qid] ?? null;
        if (is_array($eintrag) && ($eintrag['v'] ?? 0) === self::CACHE_VERSION
            && time() - (int) ($eintrag['zeit'] ?? 0) < self::CACHE_MAX_ALTER_SEK) {
            return ['bilder' => $eintrag['bilder'], 'fehler' => null];
        }

        $e = self::eintraege([$qid]);
        if ($e['fehler'] !== null) {
            return ['bilder' => [], 'fehler' => $e['fehler']];
        }
        $info = $e['eintraege'][$qid] ?? null;
        if ($info === null) {
            return ['bilder' => [], 'fehler' => 'Wikidata-Eintrag nicht gefunden.'];
        }

        $seiten = [];
        if ($info['bilder']) {
            $seiten = array_merge($seiten, self::bildinfo(['titles' => implode('|', array_map(fn($d) => 'File:' . $d, array_slice($info['bilder'], 0, 10)))]));
        }
        if ($info['kategorie'] !== '') {
            $kategorie = 'Category:' . $info['kategorie'];
            $seiten = array_merge($seiten, self::bildinfo(['generator' => 'categorymembers', 'gcmtitle' => $kategorie, 'gcmtype' => 'file', 'gcmlimit' => self::MAX_KATEGORIE]));
            $unter = self::api(self::COMMONS, ['action' => 'query', 'list' => 'categorymembers', 'cmtitle' => $kategorie, 'cmtype' => 'subcat', 'cmlimit' => self::MAX_UNTERKATEGORIEN]);
            foreach ($unter['query']['categorymembers'] ?? [] as $u) {
                $seiten = array_merge($seiten, self::bildinfo(['generator' => 'categorymembers', 'gcmtitle' => (string) $u['title'], 'gcmtype' => 'file', 'gcmlimit' => self::MAX_JE_UNTERKATEGORIE]));
            }
        }

        $bilder = self::auswerten($seiten, $info['bilder']);
        $cache[$qid] = ['v' => self::CACHE_VERSION, 'zeit' => time(), 'bilder' => $bilder];
        file_put_contents(self::CACHE_DATEI, json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return ['bilder' => $bilder, 'fehler' => null];
    }

    /** Seiten mit Bildinformationen aus einer Commons-Abfrage. */
    private static function bildinfo(array $abfrage): array
    {
        $daten = self::api(self::COMMONS, ['action' => 'query', 'prop' => 'imageinfo', 'iiprop' => 'url|size|mime|extmetadata',
            'iiurlwidth' => 1200, 'iiextmetadatafilter' => self::META] + $abfrage);
        return array_values($daten['query']['pages'] ?? []);
    }

    /**
     * Commons-Seiten in Kandidaten übersetzen: nur Fotos unter freier
     * Lizenz, Hauptbilder zuerst, jede Datei einmal.
     *
     * @param list<string> $hauptbilder Dateinamen aus P18
     * @return list<array{id: string, url: string, vorschau: string, breite: int, hoehe: int, urheber: string, lizenz: string, lizenz_url: string, seite: string, haupt: bool}>
     */
    public static function auswerten(array $seiten, array $hauptbilder = []): array
    {
        $haupt = array_map(fn($d) => str_replace('_', ' ', $d), $hauptbilder);
        $bilder = [];
        foreach ($seiten as $s) {
            $ii = $s['imageinfo'][0] ?? null;
            $name = preg_replace('/^File:/', '', (string) ($s['title'] ?? ''));
            if (!is_array($ii) || $name === '' || isset($bilder[$name]) || !in_array($ii['mime'] ?? '', self::MIME, true)) {
                continue;
            }
            $url = (string) ($ii['thumburl'] ?? $ii['url'] ?? '');
            $meta = $ii['extmetadata'] ?? [];
            $lizenz = trim((string) ($meta['LicenseShortName']['value'] ?? ''));
            if (!self::istWikimediaUrl($url) || !self::lizenzErlaubt($lizenz)) {
                continue;
            }
            $bilder[$name] = [
                'id'         => $name,
                'url'        => $url,
                'vorschau'   => (string) preg_replace('#/\d+px-#', '/330px-', $url),
                'breite'     => (int) ($ii['width'] ?? 0),
                'hoehe'      => (int) ($ii['height'] ?? 0),
                'urheber'    => self::urheber((string) ($meta['Artist']['value'] ?? '')),
                'lizenz'     => $lizenz,
                'lizenz_url' => (string) ($meta['LicenseUrl']['value'] ?? ''),
                'seite'      => (string) ($ii['descriptionurl'] ?? ''),
                'haupt'      => in_array($name, $haupt, true),
            ];
        }
        $bilder = array_values($bilder);
        usort($bilder, fn($a, $b) => $b['haupt'] <=> $a['haupt']);
        return $bilder;
    }

    /** Gemeinfrei, CC0, CC BY oder CC BY-SA, keine NC- oder ND-Varianten. */
    public static function lizenzErlaubt(string $kurz): bool
    {
        $l = strtolower(trim(preg_replace('/[\s_-]+/', ' ', $kurz)));
        if ($l === '' || str_contains($l, ' nc') || str_contains($l, ' nd')) {
            return false;
        }
        return $l === 'public domain' || str_starts_with($l, 'pd') || str_starts_with($l, 'cc0')
            || (bool) preg_match('/^cc by( sa)? \d/', $l);
    }

    /** Urheber aus dem HTML von Commons: nur Text, eine Zeile, höchstens 255 Zeichen. */
    public static function urheber(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return $text === '' ? 'Unbekannt' : mb_substr($text, 0, 255);
    }

    /** Ein Kandidat aus dem Cache, nur wenn er zum Eintrag gehört. */
    public static function kandidat(string $qid, string $datei): ?array
    {
        foreach (self::kandidaten($qid)['bilder'] as $b) {
            if ($b['id'] === $datei) {
                return $b;
            }
        }
        return null;
    }

    public static function laden(string $url): array
    {
        return self::istWikimediaUrl($url)
            ? ExternesBild::laden($url)
            : ['success' => false, 'message' => 'Bildadresse ist nicht von Wikimedia.'];
    }

    /** Wert für breeds.bild_quelle. */
    public static function quelle(string $datei): string
    {
        return 'Wikimedia:' . $datei;
    }

    /** Bildseite bei Commons zu einem Dateinamen. */
    public static function dateiseite(string $datei): string
    {
        return 'https://commons.wikimedia.org/wiki/File:' . rawurlencode(str_replace(' ', '_', $datei));
    }

    /** @param list<string> $qids */
    public static function vergessen(array $qids): void
    {
        $cache = self::cacheLesen();
        foreach ($qids as $q) {
            unset($cache[$q]);
        }
        file_put_contents(self::CACHE_DATEI, json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function api(string $basis, array $params): ?array
    {
        $ch = curl_init($basis . '?' . http_build_query($params + ['format' => 'json', 'formatversion' => 2]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => ExternesBild::USER_AGENT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $antwort = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $daten = is_string($antwort) && $status === 200 ? json_decode($antwort, true) : null;
        return is_array($daten) && !isset($daten['error']) ? $daten : null;
    }

    private static function cacheLesen(): array
    {
        $daten = is_file(self::CACHE_DATEI) ? json_decode((string) file_get_contents(self::CACHE_DATEI), true) : null;
        return is_array($daten) ? $daten : [];
    }
}
