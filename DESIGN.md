---
name: Hunderassen-Verwaltung
description: Rassen und Hunde als Akte aus gesteckten Farbbalken auf Salbei-Pergament.
colors:
  creme: "#F2F5EA"
  sand: "#E4DEC8"
  fell: "#B5502E"
  fellDk: "#2E2A22"
  tanne: "#3F5A3F"
  tanneDk: "#2C4130"
  pfote: "#C4D62A"
typography:
  display:
    fontFamily: "Nunito, ui-sans-serif, system-ui, sans-serif"
    fontSize: "clamp(2.25rem, 6vw, 3.75rem)"
    fontWeight: 900
    lineHeight: 0.95
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Nunito, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 900
    lineHeight: 1.25
  title:
    fontFamily: "Nunito, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.25rem"
    fontWeight: 900
    lineHeight: 1.375
  body:
    fontFamily: "Nunito, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.0625rem"
    fontWeight: 400
    lineHeight: 1.75
  label:
    fontFamily: "Nunito, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 700
    lineHeight: 1.25
  mono:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.25
rounded:
  sm: "2px"
  lg: "8px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "20px"
  xl: "40px"
  2xl: "56px"
components:
  button-primary:
    backgroundColor: "{colors.tanne}"
    textColor: "{colors.creme}"
    typography: "{typography.label}"
    rounded: "{rounded.lg}"
    padding: "8px 20px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.tanneDk}"
    textColor: "{colors.creme}"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.fellDk}"
    typography: "{typography.label}"
    rounded: "{rounded.lg}"
    padding: "8px 20px"
    height: "44px"
  button-outline-hover:
    backgroundColor: "{colors.fellDk}"
    textColor: "{colors.creme}"
  fci-balken-gruppe:
    backgroundColor: "{colors.fell}"
    textColor: "{colors.creme}"
    typography: "{typography.title}"
    padding: "16px 40px"
  fci-balken-sektion:
    backgroundColor: "{colors.pfote}"
    textColor: "{colors.fellDk}"
    padding: "12px 32px"
  fci-balken-standard:
    backgroundColor: "{colors.fellDk}"
    textColor: "{colors.creme}"
    padding: "12px 32px"
  abschnitt-block-wesen:
    backgroundColor: "{colors.tanne}"
    textColor: "{colors.creme}"
    typography: "{typography.headline}"
    rounded: "{rounded.sm}"
    padding: "16px 20px"
  sprunglink:
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "8px 12px"
    height: "44px"
  chip-eigenschaft:
    backgroundColor: "{colors.sand}"
    textColor: "{colors.fellDk}"
    rounded: "{rounded.sm}"
    padding: "4px 12px"
---

# Design System: Hunderassen-Verwaltung

## Overview

**Creative North Star: "Der gesteckte Steckbrief"**

Eine Rasse ist ein Stapel Fakten, die ineinandergreifen. Farbbalken tragen die FCI-Einordnung über das Foto, Maße sind echte Balken auf einer festen Skala, und jeder Textabschnitt hat einen festen Farbblock, damit „Wesen“ bei jeder Rasse gleich aussieht. Die Form heißt „Bildbetonte Kachel“ und ist von den modularen Balken bei Studio Dumbar angeregt.

Die Fläche ist Salbei-Pergament. Darauf liegen Blöcke in Tanne, Leder-Rost, Tennisball und Ink. Ecken sind klein (2 bis 8px), Schatten gibt es nicht. Faktenabschnitte beginnen unter einer dicken Ink-Linie. Abgelehnt ist die übliche Karte mit Bild oben und langem Textblock darunter.

Die Form stammt aus einer ausdrücklichen Entscheidung des Betreibers in der Entscheidungsrunde vom 15.10.2026. Der Concept-Seed-Wurf (Schlüssel `e0018f54`) hatte eine andere Struktur zugewiesen („Standardblatt“). Der Betreiber hat stattdessen den Herausforderer „Bildbetonte Kachel“ gewählt, im Wissen, dass 381 von 382 Rassen kein Foto haben. Für diese Form gibt es keinen eigenen Seed-Schlüssel. Deshalb muss die Fläche ohne Foto genauso tragen wie mit.

Erste Umsetzung ist die Rassen-Detailseite (`breed_detail.php`, `views/partials/rassentext.php`). Alle anderen Seiten zeigen noch den alten Look und gelten als Altbestand, nicht als System.

**Key Characteristics:**
- Gesetzte Farbblöcke statt Karten, keine Schatten
- Feste Farbzuordnung je Faktenart und je Textabschnitt
- Zahlen und Maße in Mono, alles andere in Nunito
- Dicke Ink-Linie (4px) über jedem Faktenabschnitt
- Lücken werden benannt, nicht versteckt

## Colors

Fünf satte Blockfarben auf hellem Salbei-Grund, jede mit fester Aufgabe.

### Primary
- **Tanne** (tanne): Hauptblock des Kopfmoduls, Primärbutton, Abschnitt „Wesen“, Balken für Rüde in den Maßen.
- **Dunkle Tanne** (tanneDk): Hover des Primärbuttons, Farbfläche statt Foto, Abschnitte ohne feste Zuordnung, Linkfarbe auf hellem Grund.

### Secondary
- **Leder-Rost** (fell): FCI-Gruppenbalken, Abschnitt „Haltung und Erziehung“, Balken für Hündin, Gruppennummer bei ähnlichen Rassen.

### Tertiary
- **Tennisball** (pfote): Sektionsbalken, Abschnitt „Pflege“, Fokusring auf dunklen Flächen. Einzige Signalfarbe.

### Neutral
- **Salbei-Pergament** (creme): Seitenhintergrund und Text auf dunklen Blöcken.
- **Sand** (sand): gedämpfte Flächen, Skalenhintergrund (60 %), Eigenschafts-Chips, Trennlinien, Abschnitte „Herkunft und Geschichte“ und „Angaben aus dem Standard“.
- **Ink** (fellDk): Haupttext, Linien über Faktenabschnitten, Balken für Arbeitsprüfung und Standardnummer, Abschnitt „Aussehen“. Nebentext mit 80 % Deckkraft.

### Named Rules
**The Feste-Farbe Rule.** Die Farbe hängt am Abschnitt, nie an der Rasse. Wesen = Tanne/Creme, Haltung und Erziehung = Rost/Creme, Pflege = Tennisball/Ink, Aussehen = Ink/Creme, Herkunft und Geschichte = Sand/Ink, Angaben aus dem Standard = Sand/Ink, alles andere = dunkle Tanne/Creme.

**The Ink-auf-Tennisball Rule.** Auf Tennisball steht immer Ink. Weiß oder Creme auf Tennisball fällt durch den Kontrast.

**The Rost-nur-fett Rule.** Creme auf Leder-Rost liegt bei etwa 4,6:1. Das reicht nur für fetten oder großen Text. Kleiner normaler Text auf Rost ist verboten.

## Typography

**Display Font:** Nunito (mit ui-sans-serif, system-ui)
**Body Font:** Nunito
**Label/Mono Font:** JetBrains Mono, Gewicht 500

**Character:** Nunito in Black (900) gibt den Blöcken Gewicht, ohne hart zu wirken. Mono setzt Zahlen ab, damit Maße und Nummern wie Messwerte lesen.

### Hierarchy
- **Display** (900, 2.25rem bis 3.75rem, 0.95): Rassenname im Kopfmodul, `text-wrap: balance`, eng gesperrt.
- **Headline** (900, 1.25rem bis 1.5rem, 1.25): Überschrift im Abschnittsblock.
- **Title** (900, 1.25rem): Überschriften der Faktenabschnitte („Größe und Gewicht“, „Merkmale“) und FCI-Gruppenbalken.
- **Body** (400, 1.0625rem, 1.75): Rassentext, maximal 60ch. Kurzporträt im Kopf 1rem bis 1.125rem.
- **Label** (700, 0.875rem): Buttons, Sprunglinks, Begriffe in Definitionslisten.
- **Mono** (500, 0.75rem bis 0.875rem): Maße, Skalenbeschriftung, FCI-, Sektions- und Standardnummer, große Gruppennummer als Hintergrundzeichen.

### Named Rules
**The Zahlen-in-Mono Rule.** JetBrains Mono nur für Zahlen und Maße. Kein Fließtext, keine Überschrift in Mono.

## Layout

Zwölfspaltiges Raster ab `lg`, darunter eine Spalte. Inhalt maximal 6xl (72rem) breit, Seitenrand 12px, ab `sm` 16px. Das Kopfmodul teilt sich 7 zu 5: links der Tanne-Block mit Zurück-Link, Name, Zweitname und Kurzporträt, rechts Foto oder Farbfläche. Die drei FCI-Balken laufen über die Unterkante (`-mt-24`) und sind versetzt angeordnet: Rost links bündig (78 %), Tennisball rechts (56 %), Ink leicht eingerückt (48 %). Darunter die Aktionszeile.

Steckbrief darunter in 5 zu 7 (Maße, Merkmale), Abstand 40px. Textabschnitte in 4 zu 8: Farbblock links, ab `lg` sticky, Text rechts. Abstände zwischen großen Modulen 40 bis 56px, zwischen Abschnitten 48px.

Am Handy wird ein langes Kurzporträt auf vier Zeilen gekürzt, damit die Aktion im ersten Bild bleibt. Der volle Text steht im DOM, „Weiterlesen“ öffnet ihn.

### Named Rules
**The Feste-Skala Rule.** Maßbalken liegen auf 0 bis 90 cm und 0 bis 90 kg, Striche alle 30 Einheiten. Die Skala wächst nur bei Ausreißern. Der Wert steht immer auch als Text daneben.

## Elevation & Depth

Keine Schatten. Tiefe entsteht nur durch Überlappung: Balken schieben sich über die Kante des Kopfmoduls (`z-10`). Ohne Foto trägt die dunkle Tanne-Fläche eine riesige Gruppennummer mit 15 % Deckkraft und das Signet.

### Named Rules
**The Flach-und-gesteckt Rule.** Ebenen entstehen durch Überlappen und Farbe, nie durch Schatten oder Unschärfe.

## Shapes

Kleine Ecken. Blöcke, Chips, Balken und Sprunglinks 2px, Kopfmodul und Buttons 8px. Die FCI-Balken haben gar keine Rundung. Abschnitte beginnen mit einer 4px Ink-Linie, Listen trennen sich mit 1px Sand. Das Signet (Hundekopf mit Sonnenbrille) ist die einzige organische Form.

## Components

### Buttons
- **Shape:** sanft gerundet (8px), mindestens 44px hoch.
- **Primary:** Tanne mit Creme, fett, 8px 20px. Einzige Hauptaktion: „Hund dieser Rasse anlegen“.
- **Hover / Focus:** Hover dunkle Tanne. Fokus 3px Tennisball-Outline mit 2px Abstand.
- **Outline:** 2px Ink-Rand, Ink-Text, Hover füllt Ink mit Creme-Text. Für „Bearbeiten“.
- **Textlink:** Ink, unterstrichen mit 4px Abstand, Hover dunkle Tanne. Für Nebenaktionen wie „Versionen“.

### Chips
- **Style:** Eigenschaften auf Sand mit Ink, Aktivitäten auf Tanne 10 % mit dunkler Tanne. 2px Ecken, 4px 12px, fett.
- **State:** keine Auswahlzustände, nur Anzeige. Leer: „Noch keine zugeordnet“.

### FCI-Balken
Drei gesetzte Streifen über der Kopfkante: Rost mit FCI-Gruppe, Tennisball mit Sektion, Ink mit Arbeitsprüfung und Standardnummer. Nummern in Mono. Fehlt die Gruppe, steht „FCI-Gruppe nicht hinterlegt“. Beim Laden ein Auftritt: `clip-path: inset(0 35% 0 0)` auf `inset(0)`, 650ms `cubic-bezier(0.16, 1, 0.3, 1)`, je Balken 90ms versetzt. Bei `prefers-reduced-motion` aus. Inhalt ist von Anfang an sichtbar, keine Opacity.

### Maßbalken
Spanne als Balken auf Sand 60 %, Rüde Tanne, Hündin Rost, 12px hoch, 2px Ecken, mindestens 6px breit. Lineal-Striche Ink 30 %. Wert in Mono daneben, Skalenzeile darunter. Fehlende Werte: „Fehlende Werte sind nicht hinterlegt.“

### Abschnittsblock und Sprungleiste
Jeder Textabschnitt bekommt seinen Farbblock als Überschrift (Headline, 16px 20px, 2px Ecken). Ab zwei Abschnitten steht darüber eine Sprungleiste aus denselben Farbblöcken, 44px hoch, Fokus 3px Ink-Outline.

### Faktenabschnitt
4px Ink-Linie oben, 16px Abstand, Title-Überschrift. Inhalt oft als Definitionsliste mit Sand-Trennlinien und Begriffsspalte von 10rem.

### Leere Zustände
Ehrlich und kurz: „keine Angabe“, „Noch keine zugeordnet“, „Für diese Rasse gibt es noch keine Beschreibung.“ Mit Pflegerecht folgt ein Link zum Ergänzen.

## Do's and Don'ts

### Do:
- **Do** jede Faktenart in ihrer festen Farbe setzen, nach der Feste-Farbe Rule.
- **Do** Zahlen und Maße in JetBrains Mono 500 setzen.
- **Do** Faktenabschnitte mit 4px Ink-Linie beginnen.
- **Do** alle Ziele mindestens 44px hoch machen und WCAG 2.1 AA halten.
- **Do** fehlende Daten benennen („keine Angabe“) statt Felder auszublenden.
- **Do** die Fläche ohne Foto mit dunkler Tanne, Gruppennummer und Signet füllen.

### Don't:
- **Don't** Schatten einsetzen.
- **Don't** Ecken über 8px oder Pillenformen verwenden.
- **Don't** Creme oder Weiß auf Tennisball setzen.
- **Don't** kleinen normalen Text auf Leder-Rost setzen.
- **Don't** Emoji als Icons verwenden. Icons sind Inline-SVG.
- **Don't** auf die Karte mit Bild oben und langem Textblock zurückfallen.
- **Don't** Bewegung außer dem einen Balkenauftritt einbauen.
