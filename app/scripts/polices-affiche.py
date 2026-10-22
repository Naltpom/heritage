# -*- coding: utf-8 -*-
"""Les polices de l'affiche généalogique (`app/src/affiche/polices/`) : comment on les refait.

L'affiche mesure ses textes sur les avances des glyphes (`app/src/affiche/polices.ts`), comme
le faisait l'affiche d'origine avec PyMuPDF : les fichiers servis doivent donc porter
**exactement** les métriques des instances qu'elle employait. Ce script les refait depuis les
polices variables publiées, puis les réduit aux écritures latines.

Sources, toutes deux sous SIL Open Font License 1.1 :

- Source Serif 4, Adobe — `SourceSerif4[opsz,wght].ttf` et `SourceSerif4-Italic[opsz,wght].ttf`,
  version 4.004, https://github.com/adobe-fonts/source-serif
- Cormorant Garamond, The Cormorant Project — `CormorantGaramond[wght].ttf`, version 4.001,
  https://github.com/CatharsisFonts/Cormorant

« Source » est un **nom de police réservé** (Reserved Font Name) : une version modifiée — un
sous-ensemble en est une — ne peut pas le porter. Les noms internes sont donc remplacés par la
clé de la police (`SS-Txt-Reg`…) ; le copyright et la licence restent (noms 0, 13 et 14), et le
texte de la licence est livré à côté des fichiers (`OFL.txt`).

Usage (fontTools requis : `pip install fonttools`) :

    python app/scripts/polices-affiche.py DOSSIER_DES_POLICES_VARIABLES

Le dossier doit contenir `SourceSerif4.ttf`, `SourceSerif4-Italic.ttf` et
`CormorantGaramond.ttf` (les fichiers variables, renommés sans leurs axes).
"""
import pathlib
import sys

from fontTools import subset
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer

ICI = pathlib.Path(__file__).resolve().parent
SORTIE = ICI.parent / 'src' / 'affiche' / 'polices'

# Les coordonnées des instances de l'affiche d'origine, retrouvées en comparant leurs avances à
# celles des instances refaites : `Cap` est la taille optique 8, `Txt` 16, `Dis` 48.
INSTANCES = {
    'SS-Cap-Reg': ('SourceSerif4.ttf', {'opsz': 8, 'wght': 400}),
    'SS-Cap-Semi': ('SourceSerif4.ttf', {'opsz': 8, 'wght': 600}),
    'SS-Cap-Ital': ('SourceSerif4-Italic.ttf', {'opsz': 8, 'wght': 400}),
    'SS-Txt-Reg': ('SourceSerif4.ttf', {'opsz': 16, 'wght': 400}),
    'SS-Txt-Semi': ('SourceSerif4.ttf', {'opsz': 16, 'wght': 600}),
    'SS-Txt-Ital': ('SourceSerif4-Italic.ttf', {'opsz': 16, 'wght': 400}),
    'SS-Dis-Semi': ('SourceSerif4.ttf', {'opsz': 48, 'wght': 600}),
    'SS-Dis-Ital': ('SourceSerif4-Italic.ttf', {'opsz': 48, 'wght': 400}),
    'CG-Bold': ('CormorantGaramond.ttf', {'wght': 700}),
}

# Les écritures latines : ASCII, Latin-1, Latin étendu A et B (les noms venus de toute
# l'Europe), la ponctuation typographique, et les signes de l'affiche (° † ∞ ◆ · — –). Les
# noms arrivent normalisés en NFC (`donnees.ts`) : pas besoin des diacritiques combinants. Le
# latin additionnel (vietnamien…) coûterait un tiers de plus pour aucun nom de l'arbre ; un
# caractère absent se mesure à la largeur du glyphe `.notdef` et se dessine dans la police de
# repli du navigateur.
PLAGES = [
    (0x0020, 0x007E), (0x00A0, 0x024F), (0x2010, 0x2027), (0x2030, 0x203A), (0x2044, 0x2044),
    (0x20AC, 0x20AC), (0x2116, 0x2116), (0x2122, 0x2122), (0x2212, 0x2212), (0x221E, 0x221E),
    (0x25C6, 0x25C6),
]


def instance(source, coordonnees):
    police = TTFont(source)
    return instancer.instantiateVariableFont(police, coordonnees)


def reduire(police):
    options = subset.Options()
    # Les mêmes fonctions OpenType que l'affiche d'origine (crénage et ligatures au rendu ; la
    # mesure, elle, ne lit que les avances).
    options.layout_features = ['kern', 'liga', 'lnum', 'pnum']
    options.name_IDs = ['*']
    options.hinting = False
    options.notdef_outline = True
    sous = subset.Subsetter(options)
    sous.populate(unicodes=[c for a, b in PLAGES for c in range(a, b + 1)])
    sous.subset(police)
    return police


def renommer(police, cle):
    """Plus aucun nom réservé : la famille et le nom PostScript deviennent la clé."""
    noms = police['name']
    for enregistrement in list(noms.names):
        if enregistrement.nameID in (1, 3, 4, 6, 16, 17, 21, 22, 25):
            noms.removeNames(nameID=enregistrement.nameID)
    style = 'Italic' if cle.endswith('Ital') else 'Regular'
    for ident, valeur in ((1, cle), (2, style), (3, f'Heritage:{cle}'), (4, cle), (6, cle)):
        noms.setName(valeur, ident, 3, 1, 0x409)
        noms.setName(valeur, ident, 1, 0, 0)


def main(dossier):
    dossier = pathlib.Path(dossier)
    SORTIE.mkdir(parents=True, exist_ok=True)
    for cle, (fichier, coordonnees) in INSTANCES.items():
        police = reduire(instance(dossier / fichier, coordonnees))
        renommer(police, cle)
        chemin = SORTIE / f'{cle}.ttf'
        police.save(chemin)
        print(f'{cle}.ttf : {chemin.stat().st_size // 1024} Ko')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
