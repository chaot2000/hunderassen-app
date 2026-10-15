# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Heute nutzt nur der Betreiber die App (privat, mit Familie). Ziel ist ein bezahltes Angebot für Assistenzhundetrainer und Hundeschulen, je Organisation ein eigener Mandant. Sie verwalten viele Hunde von Kunden oder Teams, dazu Halter, Züchter und Wesenstests, und schlagen Rassen nach.

## Product Purpose

Hunde und alles, was zu ihnen gehört, an einem Ort verwalten: Stammdaten, Foto, Rasse, Halter, Züchter, Wesenstests mit Ergebnissen. Dazu ein geteilter Rassenkatalog als Nachschlagewerk. Erfolg heißt: Ein Trainer findet zu jedem Hund in Sekunden, was er braucht, und kann Tests ohne Papier dokumentieren.

Zukunft (noch nicht gebaut, nicht zusagen): Eignungseinschätzung je Rasse (Assistenzhund, Familienhund), Spiele, kurze Videolektionen.

## Positioning

Verbindet Rassenwissen mit der Arbeit am einzelnen Hund: Wesenstests mit eigenen Aufgaben und Ergebniskategorien, je Organisation getrennt, auf einem gemeinsamen Rassenkatalog.

## Operating Context

- Hauptaufgabe: Hunde verwalten und die zugehörigen Infos pflegen.
- Wesenstests werden auch draußen erfasst, also auf dem Handy.
- Mandanten: geteilter Katalog (Rassen, Eigenschaften, Aktivitäten), alles andere je Mandant getrennt. Super-Admin pflegt Mandanten und Katalog.

## Capabilities and Constraints

- PHP ohne Framework, MariaDB 10.11, Hosting all-inkl (Shared Hosting, Deploy per FTP und phpMyAdmin). Tailwind und Alpine.js.
- Rassenkatalog: 382 Rassen. Texte stammen von welpen.vdh.de (Scrape vom 14.06.2026). Für ein kommerzielles Angebot ist das ohne Erlaubnis des VDH nicht nutzbar: Lizenz klären oder eigene Texte, bevor zahlende Mandanten den Katalog sehen.
- Texte sind versioniert (content_revisions), der ursprüngliche Text bleibt in breeds.beschreibung_original.
- Rassentexte werden automatisch gegliedert (Wesen, Haltung, Aussehen, Herkunft …). Die Zuordnung ist ein Vorschlag und wird je Rasse geprüft.
- Nur 1 von 382 Rassen hat ein Bild. Maße nur für einen Teil der Rassen.

## Brand Commitments

- Name in der App: „Hunderassen-Verwaltung“ (Arbeitsname).
- Hundekopf mit Sonnenbrille als Signet.
- Farbwelt bleibt (Salbei-Pergament, Tanne, Leder-Rost, Tennisball-Gelbgrün), entschieden am 15.10.2026. Richtung des Redesigns: „Karteikarte“, also Struktur einer Akte.
- Ansprache: duzen, kurz, freundlich.

## Evidence on Hand

- Echte Daten: Prod-Dump vom 15.10.2026 (nicht im Repo), VDH-Quelldatei `DB_Generator/hunderassen_all_vdh.json`.
- Keine Kunden, Testimonials, Preise oder Kennzahlen vorhanden. Nicht erfinden.

## Product Principles

1. Der einzelne Hund steht im Mittelpunkt, der Katalog liefert Kontext.
2. Nichts geht verloren: Texte und Daten bleiben wiederherstellbar.
3. Mandanten sehen nie die Daten anderer.
4. Draußen bedienbar: wichtige Aufgaben funktionieren einhändig am Handy.
5. Ehrlich mit Lücken: fehlende Daten sichtbar machen statt verstecken oder auffüllen.

## Accessibility & Inclusion

WCAG 2.1 AA ist Ziel (Kontrast, Tastatur, Screenreader). Zielgruppe schließt Assistenzhund-Halter mit Einschränkungen ein.
