<?php
/**
 * tools/vdh_metadaten_erzeugen.php
 *
 * Erzeugt daten/vdh_metadaten.php aus DB_Generator/hunderassen_all_vdh.json:
 * je Rassenname die FCI-Standardnummer, den Link ins VDH-Rasselexikon
 * und das Abrufdatum. admin_rassentexte.php trägt diese Werte bei der
 * Umstellung in leere Felder ein. Die JSON-Datei selbst kommt nicht auf
 * den Server, deshalb diese kleine Ableitung.
 *
 * Aufruf (lokal, CLI): php tools/vdh_metadaten_erzeugen.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$quelle = __DIR__ . '/../DB_Generator/hunderassen_all_vdh.json';
$ziel   = __DIR__ . '/../daten/vdh_metadaten.php';

$rassen = json_decode((string) file_get_contents($quelle), true, 512, JSON_THROW_ON_ERROR);

$daten = [];
foreach ($rassen as $r) {
    $name = trim((string) ($r['name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $nr = trim((string) ($r['standard_nummer'] ?? ''));
    $url = trim((string) ($r['url'] ?? ''));
    $stand = substr((string) ($r['scraped_at'] ?? ''), 0, 10);
    $daten[$name] = [
        'fci_nr'       => $nr !== '' ? $nr : null,
        'quelle_url'   => str_starts_with($url, 'https://') ? $url : null,
        'quelle_stand' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $stand) ? $stand : null,
    ];
}
ksort($daten, SORT_STRING);

$php = "<?php\n// Erzeugt von tools/vdh_metadaten_erzeugen.php aus DB_Generator/hunderassen_all_vdh.json.\n"
     . "// Nicht von Hand bearbeiten.\nreturn " . var_export($daten, true) . ";\n";

if (!is_dir(dirname($ziel))) {
    mkdir(dirname($ziel), 0755, true);
}
file_put_contents($ziel, $php);
echo count($daten) . " Rassen nach {$ziel} geschrieben.\n";
