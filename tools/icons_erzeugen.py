"""Erzeugt Favicon, App-Icons und Kopf-Logo aus der Icon-Vorlage.

Aufruf:  python tools/icons_erzeugen.py <vorlage.webp|png> <app-wurzel>

Die Vorlage ist das 1024er Bild mit dem Low-Poly-Hundekopf auf dem
dunkelgrünen, abgerundeten Quadrat. Die Ausschnitt-Koordinaten unten
passen zu genau diesem Bild; bei einer neuen Vorlage neu ausmessen.

Der Ordner heißt app-icons/, nicht icons/: all-inkl (wie viele Apache-
Installationen) leitet /icons/ serverweit auf Apaches eigene Symbole um.

Schreibt:
  favicon.ico                        16/32/48, enger Ausschnitt nur Kopf
  app-icons/logo-kopf.png            96 px, Kopf-Logo (Anzeige 38 px)
  app-icons/icon-192.png, -512.png   Manifest "any", transparente Ecken
  app-icons/icon-maskable-512.png    Manifest "maskable", vollflächig
  app-icons/apple-touch-icon.png     180 px, vollflächig (iOS rundet selbst)
"""
import sys
from pathlib import Path
from PIL import Image, ImageDraw, ImageEnhance, ImageFilter

SS = 8                 # Überabtastung für glatte Masken-Kanten
BG = (14, 30, 22)      # Dunkelgrün der Fläche, zum Auffüllen der Ecken

# Ganzes Quadrat inkl. heller Kontur: links/oben, Kantenlänge, Eckradius
L, T, S, R = 145, 145, 733, 127
# Enger Ausschnitt nur um den Kopf für 16 bis 48 px. Die feinen
# Konstruktionslinien verschwinden dort ohnehin, mehr Kopf ist lesbarer.
KL, KT, KS = 200, 195, 620


def rund_maske(n, radius):
    m = Image.new('L', (n * SS, n * SS), 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, n * SS - 1, n * SS - 1), int(radius * SS), fill=255)
    return m.resize((n, n), Image.LANCZOS)


def klein(kopf, size):
    i = kopf.resize((size, size), Image.LANCZOS)
    i = ImageEnhance.Contrast(i).enhance(1.25)
    i = ImageEnhance.Color(i).enhance(1.15)
    if size <= 16:
        i = i.filter(ImageFilter.UnsharpMask(radius=0.6, percent=80, threshold=0))
    i.putalpha(rund_maske(size, size * 0.2))
    return i


def main(src, root):
    root = Path(root)
    (root / 'app-icons').mkdir(exist_ok=True)
    im = Image.open(src).convert('RGB')

    # Favicon
    kopf = im.crop((KL, KT, KL + KS, KT + KS))
    k16, k32, k48 = (klein(kopf, s) for s in (16, 32, 48))
    k48.save(root / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)], append_images=[k16, k32])

    # Ganzes Quadrat mit transparenten Ecken
    rgba = im.crop((L, T, L + S, T + S))
    rgba.putalpha(rund_maske(S, R))
    for size, name in ((96, 'logo-kopf.png'), (192, 'icon-192.png'), (512, 'icon-512.png')):
        rgba.resize((size, size), Image.LANCZOS).save(root / 'app-icons' / name, optimize=True)

    # Vollflächig: Innenfläche ohne Kontur, Ecken mit Dunkelgrün gefüllt
    inset = 14
    inner = im.crop((L + inset, T + inset, L + S - inset, T + S - inset))
    n = inner.size[0]
    full = Image.composite(inner, Image.new('RGB', (n, n), BG), rund_maske(n, R - inset))
    full.resize((180, 180), Image.LANCZOS).save(root / 'app-icons' / 'apple-touch-icon.png', optimize=True)

    # maskable: Motiv in die Sicherheitszone (innerer Kreis, 80 %)
    canvas = Image.new('RGB', (n, n), BG)
    k = int(n * 0.86)
    canvas.paste(full.resize((k, k), Image.LANCZOS), ((n - k) // 2, (n - k) // 2))
    canvas.resize((512, 512), Image.LANCZOS).save(root / 'app-icons' / 'icon-maskable-512.png', optimize=True)


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
