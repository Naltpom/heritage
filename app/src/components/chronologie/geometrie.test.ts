/**
 * La géométrie de la chronologie : l'axe, la projection, la hachure.
 *
 * Deux exigences du dossier se vérifient **ici et nulle part ailleurs**, parce
 * qu'elles ne se voient à l'écran que si on sait déjà ce qu'on cherche :
 *
 * 1. **Les dates incertaines se dessinent comme telles.** La preuve est la
 *    barre de Marne Dolomite Sureau, `BET 1901 AND 1909` : sa hachure de début
 *    couvre du premier jour de 1901 au dernier jour de 1909.
 * 2. **Lignes ↔ colonnes est une bascule d'orientation, pas deux rendus.** Les
 *    mêmes entrées, projetées dans les deux sens, donnent deux rectangles
 *    transposés — au coefficient près, sans autre différence.
 */
import { describe, expect, it } from 'vitest'
import type { BarreChronologie } from '@/lib/api'
import {
  GOUTTIERE_BANDES,
  GOUTTIERE_ENTETE,
  EPAISSEUR_BARRE,
  MARGE,
  UNITE_ANNEE,
  anneeDeFin,
  axeDepuisBandes,
  boiteDuContenu,
  cadrageInitial,
  etendueDIntervalle,
  etiquetteDeBarre,
  etiquettesDeBande,
  filetDeBande,
  longueurDeLAxe,
  memeBoite,
  pasDEtiquettes,
  polygone,
  positionAnnee,
  projeter,
  rectangle,
  rectangleDeBande,
  tracerBarre,
  type AxeTemps,
  type TraceDeBarre,
} from './geometrie'
import { EXEMPLE_BORNES_PARTIELLES, EXEMPLE_CHRONOLOGIE } from './_exemple'

const AXE: AxeTemps = { origine: 1657, finExclusive: 2057 }
const ANNEE_COURANTE = 2037

const SUREAU = EXEMPLE_CHRONOLOGIE.barres[0]
const NATRON = EXEMPLE_CHRONOLOGIE.barres[1]
const SILICE = EXEMPLE_CHRONOLOGIE.barres[3]

/**
 * Le jeu d'essai a la taille qu'on croit.
 *
 * Sans cette assertion, tout test qui **parcourt** un ensemble découvert reste
 * vert le jour où l'ensemble se vide : il ne vérifie plus rien, et il ne le dit
 * pas. Les trois nombres ci-dessous tiennent les parcours de ce fichier.
 */
describe('le jeu d’essai', () => {
  it('porte bien six barres, seize bandes et un jalon', () => {
    expect(EXEMPLE_CHRONOLOGIE.barres).toHaveLength(6)
    expect(EXEMPLE_CHRONOLOGIE.bandes).toHaveLength(16)
    expect(EXEMPLE_CHRONOLOGIE.jalons).toHaveLength(1)
    expect(SUREAU.displayName).toBe('Marne Dolomite Sureau')
    expect(NATRON.ouverte).toBe(true)
    expect(SILICE.displayName).toBe('Silice Bourrache')
  })

  it('porte les formes de bornes de la charge réelle', () => {
    expect(EXEMPLE_BORNES_PARTIELLES.barres).toHaveLength(6)
    expect(EXEMPLE_BORNES_PARTIELLES.bandes).toHaveLength(16)
  })
})

/** `tracerBarre` rend `null` sur une barre sans aucune borne. Les tests qui
 *  suivent portent sur des barres qui en ont : on le dit une fois ici plutôt
 *  que de semer des `!` dans les assertions. */
function tracer(
  barre: BarreChronologie,
  orientation: 'lignes' | 'colonnes' = 'colonnes',
  travers = 0,
  montrerIncertitude = true,
  anneeCourante = ANNEE_COURANTE,
): TraceDeBarre {
  const trace = tracerBarre(barre, AXE, orientation, travers, anneeCourante, montrerIncertitude)
  expect(trace).not.toBeNull()
  return trace as TraceDeBarre
}

/** Combien d'unités pour `n` années. */
function annees(n: number): number {
  return n * UNITE_ANNEE
}

describe('axeDepuisBandes', () => {
  it('prend les deux extrémités des bandes du serveur, sans rien recalculer', () => {
    expect(axeDepuisBandes(EXEMPLE_CHRONOLOGIE.bandes)).toEqual(AXE)
  })

  it('rend null sans bande — il n’y a pas d’axe à fabriquer', () => {
    expect(axeDepuisBandes([])).toBeNull()
  })

  it('englobe la dernière bande, même partielle', () => {
    // `2032–2056` dépasse `bornes.max` (2037) : c'est voulu (§ 9.1), et l'axe
    // va jusqu'au bout de la bande, pas jusqu'à la borne.
    expect(AXE.finExclusive).toBe(2057)
    expect(longueurDeLAxe(AXE)).toBe(annees(400))
  })
})

describe('positionAnnee', () => {
  it('donne le DÉBUT de l’année : une année occupe une case pleine', () => {
    expect(positionAnnee(AXE, 1657)).toBe(0)
    expect(positionAnnee(AXE, 1658)).toBe(UNITE_ANNEE)
    expect(positionAnnee(AXE, 1901)).toBe(annees(244))
  })

  it('la fin d’une année est le début de la suivante', () => {
    // C'est ce qui donne une largeur non nulle à une incertitude d'un seul
    // niveau d'année, et une barre visible à qui naît et meurt la même année.
    expect(positionAnnee(AXE, 1988) - positionAnnee(AXE, 1987)).toBe(UNITE_ANNEE)
  })
})

describe('projeter', () => {
  it('lignes : le temps descend, les pistes s’étalent en largeur', () => {
    expect(projeter('lignes', 100, 20)).toEqual({ x: 20, y: 100 })
  })

  it('colonnes : le temps va vers la droite, les pistes s’empilent', () => {
    expect(projeter('colonnes', 100, 20)).toEqual({ x: 100, y: 20 })
  })

  it('les deux orientations sont transposées l’une de l’autre', () => {
    const a = rectangle('lignes', 10, 50, 3, 16)
    const b = rectangle('colonnes', 10, 50, 3, 16)
    expect(a).toEqual({ x: 3, y: 10, w: 13, h: 40 })
    expect(b).toEqual({ x: 10, y: 3, w: 40, h: 13 })
  })

  it('normalise : les bornes peuvent arriver dans n’importe quel ordre', () => {
    expect(rectangle('colonnes', 50, 10, 16, 3)).toEqual({ x: 10, y: 3, w: 40, h: 13 })
  })
})

describe('polygone', () => {
  it('rend les points dans le repère projeté, à une décimale', () => {
    expect(
      polygone('colonnes', [
        [10, 0],
        [21, 6.5],
        [10, 13],
      ]),
    ).toBe('10,0 21,6.5 10,13')
    expect(
      polygone('lignes', [
        [10, 0],
        [21, 6.5],
      ]),
    ).toBe('0,10 6.5,21')
  })
})

describe('anneeDeFin', () => {
  it('une barre au décès daté s’arrête à `fin.max`', () => {
    expect(anneeDeFin(SUREAU, ANNEE_COURANTE)).toBe(1987)
  })

  it('une barre ouverte s’arrête à l’année courante — c’est le RENDU qui décide', () => {
    // L'API n'invente aucune date de fin (§ 9.1) : `fin` vaut `null`, et c'est
    // ici, au dessin, que l'année courante entre en jeu.
    expect(NATRON.fin).toBeNull()
    expect(anneeDeFin(NATRON, ANNEE_COURANTE)).toBe(2037)
    expect(anneeDeFin(NATRON, 2042)).toBe(2042)
  })
})

describe('tracerBarre — la hachure des dates incertaines', () => {
  it('Sureau, `BET 1901 AND 1909` : la hachure couvre toute la fourchette', () => {
    const trace = tracer(SUREAU)

    expect(trace.hachureDebut).not.toBeNull()
    const hachure = trace.hachureDebut!
    // Du premier jour de 1901 au dernier jour de 1909 : neuf années civiles,
    // c'est-à-dire les huit années d'écart de la fourchette **plus** l'année
    // 1909 tout entière. Aplatir l'un des deux bouts serait aplatir la
    // fourchette, ce que le § 4 du dossier interdit.
    expect(hachure.x).toBe(positionAnnee(AXE, 1901))
    expect(hachure.w).toBe(annees(9))
    expect(hachure.h).toBe(EPAISSEUR_BARRE)
  })

  it('… et son décès, année connue sans jour, se hachure aussi', () => {
    // `certain: false` avec `min === max` : la fourchette fait une seule année
    // civile, et elle se voit quand même. Un modèle qui dessinerait un point
    // n'aurait rien à hachurer ici.
    const trace = tracer(SUREAU)
    expect(trace.hachureFin).not.toBeNull()
    expect(trace.hachureFin!.x).toBe(positionAnnee(AXE, 1987))
    expect(trace.hachureFin!.w).toBe(annees(1))
  })

  it('le corps va de la naissance la plus tôt à la fin de l’année du décès', () => {
    const trace = tracer(SUREAU)
    expect(trace.corps.x).toBe(positionAnnee(AXE, 1901))
    expect(trace.corps.w).toBe(positionAnnee(AXE, 1988) - positionAnnee(AXE, 1901))
    expect(trace.long0).toBe(positionAnnee(AXE, 1901))
    expect(trace.long1).toBe(positionAnnee(AXE, 1988))
  })

  it('la hachure ne déborde jamais du corps', () => {
    const trace = tracer(SUREAU)
    const h = trace.hachureDebut!
    expect(h.x).toBeGreaterThanOrEqual(trace.corps.x)
    expect(h.x + h.w).toBeLessThanOrEqual(trace.corps.x + trace.corps.w)
  })

  it('une date certaine ne se hachure pas', () => {
    const trace = tracer(SILICE)
    expect(trace.hachureDebut).toBeNull()
    expect(trace.hachureFin).toBeNull()
  })

  it('le lecteur peut éteindre l’incertitude — mais il faut le dire', () => {
    // Le paramètre n'a pas de valeur par défaut : on ne peut pas éteindre la
    // hachure par omission (§ 9.2).
    const trace = tracer(SUREAU, 'colonnes', 0, false)
    expect(trace.hachureDebut).toBeNull()
    expect(trace.hachureFin).toBeNull()
    expect(trace.corps.w).toBe(positionAnnee(AXE, 1988) - positionAnnee(AXE, 1901))
  })

  it('les deux orientations tracent la même barre, transposée', () => {
    const colonnes = tracer(SUREAU, 'colonnes', 40)
    const lignes = tracer(SUREAU, 'lignes', 40)
    expect(lignes.corps).toEqual({
      x: colonnes.corps.y,
      y: colonnes.corps.x,
      w: colonnes.corps.h,
      h: colonnes.corps.w,
    })
    expect(lignes.hachureDebut!.h).toBe(colonnes.hachureDebut!.w)
  })
})

describe('tracerBarre — une vie en cours', () => {
  it('la barre ouverte s’arrête à l’année courante et porte une pointe', () => {
    const trace = tracer(NATRON)
    expect(trace.long1).toBe(positionAnnee(AXE, 2038))
    expect(trace.pointe).not.toBeNull()
    expect(trace.hachureFin).toBeNull()
  })

  it('une barre fermée n’a pas de pointe', () => {
    expect(tracer(SUREAU).pointe).toBeNull()
  })
})

describe('tracerBarre — les bornes partielles de la charge réelle', () => {
  const [MARJOLAINE, LAVANDE, VIVANT] = EXEMPLE_BORNES_PARTIELLES.barres
  const SANS_DATE = EXEMPLE_BORNES_PARTIELLES.barres[5]

  it('décès non daté : la barre s’arrête à ce qu’on sait, sans pointe', () => {
    // 53 barres sur 235 sont dans ce cas. Les prolonger jusqu'à l'année
    // courante dirait « vivante » — ce que la donnée dit ne pas savoir.
    const t = tracer(MARJOLAINE)
    expect(t.sansFin).toBe(true)
    expect(t.pointe).toBeNull()
    expect(t.long0).toBe(positionAnnee(AXE, 1791))
    expect(t.long1).toBe(positionAnnee(AXE, 1792))
    expect(t.long1).toBeLessThan(positionAnnee(AXE, ANNEE_COURANTE))
  })

  it('décès seul : la barre couvre le décès, et lui seul', () => {
    const t = tracer(LAVANDE)
    expect(t.sansDebut).toBe(true)
    expect(t.long0).toBe(positionAnnee(AXE, 1995))
    expect(t.long1).toBe(positionAnnee(AXE, 1996))
    // Une seule hachure : le corps entier **est** le décès incertain.
    expect(t.hachureDebut).not.toBeNull()
    expect(t.hachureFin).toBeNull()
    expect(t.hachureDebut?.w).toBe(t.corps.w)
  })

  it('vivante : pointe, pas de bord en tirets', () => {
    const t = tracer(VIVANT)
    expect(t.sansFin).toBe(false)
    expect(t.sansDebut).toBe(false)
    expect(t.pointe).not.toBeNull()
    expect(t.long1).toBe(positionAnnee(AXE, ANNEE_COURANTE + 1))
  })

  it('aucune borne : rien à tracer, et surtout pas une exception', () => {
    // Le § 9.1 exclut ces personnes de `barres`. Si l'une passait quand même,
    // le rendu ne doit ni planter ni la poser à l'année zéro.
    expect(SANS_DATE.debut).toBeNull()
    expect(SANS_DATE.fin).toBeNull()
    expect(tracerBarre(SANS_DATE, AXE, 'colonnes', 0, ANNEE_COURANTE, true)).toBeNull()
  })
})

describe('etendueDIntervalle — une borne nulle ne vaut pas zéro', () => {
  it('rabat la borne manquante sur celle qu’on connaît, et marque le côté ouvert', () => {
    expect(etendueDIntervalle({ min: null, max: 1898, certain: false })).toEqual({
      min: 1898,
      max: 1898,
      ouvertAvant: true,
      ouvertApres: false,
    })
    expect(etendueDIntervalle({ min: 1900, max: null, certain: false })).toEqual({
      min: 1900,
      max: 1900,
      ouvertAvant: false,
      ouvertApres: true,
    })
  })

  it('rend null quand les deux bornes manquent', () => {
    expect(etendueDIntervalle({ min: null, max: null, certain: false })).toBeNull()
  })
})

describe('tracerBarre — les bornes non bornées (`BEF`)', () => {
  const [, , , GLYCINE, CIBOULE] = EXEMPLE_BORNES_PARTIELLES.barres

  it('« † <1899 » : la barre part de 1898, pas de l’origine du monde', () => {
    // Avec `min` pris pour un zéro, `positionAnnee` rendait −13 152 : une barre
    // qui traversait tout le canevas, sans exception ni test rouge. C'est le
    // défaut que ce test empêche de revenir.
    const t = tracer(GLYCINE)
    expect(t.long0).toBe(positionAnnee(AXE, 1898))
    expect(t.long0).toBeGreaterThan(0)
    expect(t.long1).toBe(positionAnnee(AXE, 1899))
    // Et elle s'échappe vers le passé par une pointe, symétrique de celle d'une
    // vie en cours.
    expect(t.pointeAvant).not.toBeNull()
    expect(t.pointe).toBeNull()
  })

  it('« 1878-1879 – <1931 » : l’incertitude de fin couvre toute la barre', () => {
    // Elle est morte entre sa naissance et 1931 : la hachure de fin court d'un
    // bout à l'autre, et c'est exact. Elle ne déborde pas du corps pour autant.
    const t = tracer(CIBOULE)
    expect(t.long0).toBe(positionAnnee(AXE, 1878))
    expect(t.long1).toBe(positionAnnee(AXE, 1932))
    expect(t.hachureFin).not.toBeNull()
    expect(t.hachureFin!.y).toBe(t.corps.y)
    expect(t.hachureFin!.h).toBe(t.corps.h)
    // Naissance bornée des deux côtés : aucune pointe vers le passé.
    expect(t.pointeAvant).toBeNull()
  })
})

describe('anneeDeFin — les trois cas', () => {
  const [MARJOLAINE, , VIVANT] = EXEMPLE_BORNES_PARTIELLES.barres

  it('rend null quand le décès n’est pas daté', () => {
    expect(anneeDeFin(MARJOLAINE, ANNEE_COURANTE)).toBeNull()
  })

  it('rend l’année courante pour une vivante, `fin.max` pour un décès daté', () => {
    expect(anneeDeFin(VIVANT, ANNEE_COURANTE)).toBe(ANNEE_COURANTE)
    expect(anneeDeFin(SUREAU, ANNEE_COURANTE)).toBe(1987)
  })
})

describe('etiquetteDeBarre', () => {
  it('écrit le nom quand la barre est assez longue', () => {
    const trace = tracer(SUREAU)
    const etiquette = etiquetteDeBarre(SUREAU, trace, 'colonnes')
    expect(etiquette?.texte).toBe('Marne Dolomite Sureau')
    expect(etiquette?.rotation).toBeNull()
  })

  it('fait monter le nom le long d’une barre verticale', () => {
    const trace = tracer(SUREAU, 'lignes')
    expect(etiquetteDeBarre(SUREAU, trace, 'lignes')?.rotation).toBe(-90)
  })

  it('n’écrit rien plutôt que de couper un nom', () => {
    // Deux ans de vie : le nom ne tient pas. Il reste dans le `<title>`, où le
    // survol et les lecteurs d'écran le trouvent. Un nom tronqué se lirait
    // comme un autre nom.
    const trace = tracer(SILICE)
    expect(etiquetteDeBarre(SILICE, trace, 'colonnes')).toBeNull()
  })
})

describe('les bandes', () => {
  it('le rectangle d’une bande couvre toute l’épaisseur, gouttière comprise', () => {
    const r = rectangleDeBande(EXEMPLE_CHRONOLOGIE.bandes[0], AXE, 'colonnes', 300)
    expect(r.x).toBe(0)
    expect(r.w).toBe(annees(25))
    expect(r.y).toBe(-GOUTTIERE_BANDES)
    expect(r.h).toBe(300 + GOUTTIERE_BANDES)
  })

  it('le filet se pose à l’entrée de la bande, en travers', () => {
    const filet = filetDeBande(EXEMPLE_CHRONOLOGIE.bandes[1], AXE, 'lignes', 300)
    expect(filet.y1).toBe(positionAnnee(AXE, 1682))
    expect(filet.y2).toBe(filet.y1)
    expect(filet.x1).toBe(-GOUTTIERE_BANDES)
    expect(filet.x2).toBe(300)
  })

  it('un libellé par bande quand la place suffit', () => {
    const etiquettes = etiquettesDeBande(EXEMPLE_CHRONOLOGIE.bandes, AXE, 'colonnes')
    expect(etiquettes).toHaveLength(EXEMPLE_CHRONOLOGIE.bandes.length)
    // Le libellé vient du serveur, il n'est pas recomposé.
    expect(etiquettes[0].texte).toBe('1657–1681')
  })

  it('un libellé sur n quand les bandes sont trop étroites — sans toucher au découpage', () => {
    // À une année par bande, les libellés se chevaucheraient. On en saute ;
    // on ne regroupe pas les bandes, qui viennent du serveur.
    expect(pasDEtiquettes(UNITE_ANNEE, 18)).toBe(3)
    expect(pasDEtiquettes(UNITE_ANNEE, 66)).toBe(9)
    expect(pasDEtiquettes(annees(25), 66)).toBe(1)

    const fines = Array.from({ length: 12 }, (_, k) => ({
      debut: 1900 + k,
      fin: 1900 + k,
      libelle: String(1900 + k),
    }))
    const axeFin: AxeTemps = { origine: 1900, finExclusive: 1912 }
    expect(etiquettesDeBande(fines, axeFin, 'colonnes')).toHaveLength(2)
    expect(etiquettesDeBande(fines, axeFin, 'lignes')).toHaveLength(4)
  })

  it('aucune bande, aucun libellé', () => {
    expect(etiquettesDeBande([], AXE, 'lignes')).toEqual([])
  })
})

describe('boiteDuContenu', () => {
  it('couvre les deux gouttières et la marge', () => {
    const boite = boiteDuContenu('colonnes', 3200, 500)
    expect(boite).toEqual({
      x: -GOUTTIERE_ENTETE - MARGE,
      y: -GOUTTIERE_BANDES - MARGE,
      w: 3200 + GOUTTIERE_ENTETE + 2 * MARGE,
      h: 500 + GOUTTIERE_BANDES + 2 * MARGE,
    })
  })

  it('les deux orientations donnent la même boîte, transposée', () => {
    const colonnes = boiteDuContenu('colonnes', 3200, 500)
    const lignes = boiteDuContenu('lignes', 3200, 500)
    expect(lignes).toEqual({
      x: colonnes.y,
      y: colonnes.x,
      w: colonnes.h,
      h: colonnes.w,
    })
  })
})

describe('cadrageInitial', () => {
  /** Un cadre large : 1 500 × 800, rapport 1,875. */
  const CADRE = { largeur: 1500, hauteur: 800 }
  /** Une chronologie de quatre siècles en mode lignes : haute et étroite. */
  const CONTENU_LIGNES = { x: -162, y: -132, w: 1000, h: 3300 }

  it('lignes : les colonnes de branche remplissent la largeur, le temps défile', () => {
    // Sans cela, `meet` prend la plus petite des deux échelles — c'est la
    // longueur qui commande — et les quatre siècles se réduisent à une colonne
    // étroite entre deux marges vides, noms illisibles et hachure invisible.
    const vb = cadrageInitial(CONTENU_LIGNES, CADRE, 'lignes')
    expect(vb.w).toBe(CONTENU_LIGNES.w)
    expect(vb.h).toBeCloseTo(1000 / (1500 / 800), 6)
    expect(vb.x).toBe(CONTENU_LIGNES.x)
    // On commence par le plus ancien, en haut.
    expect(vb.y).toBe(CONTENU_LIGNES.y)
  })

  it('colonnes : les rangées remplissent la hauteur', () => {
    const contenu = { x: -132, y: -162, w: 3300, h: 1000 }
    const vb = cadrageInitial(contenu, CADRE, 'colonnes')
    expect(vb.h).toBe(contenu.h)
    expect(vb.w).toBeCloseTo(1000 * (1500 / 800), 6)
    expect(vb.x).toBe(contenu.x)
  })

  it('centre plutôt que de coller au bord quand tout tient déjà', () => {
    const court = { x: 0, y: 0, w: 1000, h: 100 }
    const vb = cadrageInitial(court, CADRE, 'lignes')
    expect(vb.h).toBeGreaterThan(court.h)
    expect(vb.y).toBeLessThan(0)
    // Le contenu reste au milieu de la vue.
    expect(vb.y + vb.h / 2).toBeCloseTo(court.y + court.h / 2, 6)
  })

  it('ne divise pas par zéro sur un cadre pas encore mesuré', () => {
    const vb = cadrageInitial(CONTENU_LIGNES, { largeur: 0, hauteur: 0 }, 'lignes')
    expect(Number.isFinite(vb.w)).toBe(true)
    expect(Number.isFinite(vb.h)).toBe(true)
  })
})

describe('memeBoite', () => {
  it('reconnaît deux boîtes identiques — le cadrage du lecteur est conservé', () => {
    expect(memeBoite({ x: 1, y: 2, w: 3, h: 4 }, { x: 1, y: 2, w: 3, h: 4 })).toBe(true)
    expect(memeBoite({ x: 1, y: 2, w: 3, h: 4 }, { x: 1, y: 2, w: 3, h: 5 })).toBe(false)
    expect(memeBoite(null, { x: 1, y: 2, w: 3, h: 4 })).toBe(false)
  })
})

describe('le type refuse ce que la donnée réelle contredit', () => {
  it('`fin` n’est lisible qu’après avoir écarté les deux formes nulles', () => {
    // `barre.fin.max` ne compile pas tant qu'on n'a pas discriminé sur
    // `ouverte` **et** écarté `fin === null`. C'est plus fort que la garantie
    // que le § 9.1 demandait, et c'est nécessaire : un simple
    // `if (!barre.ouverte)` laisserait passer les 53 barres de la charge réelle
    // qui portent `fin: null` avec `ouverte: false`, et le rendu s'y écraserait.
    for (const barre of [...EXEMPLE_CHRONOLOGIE.barres, ...EXEMPLE_BORNES_PARTIELLES.barres]) {
      if (barre.ouverte) {
        expect(barre.fin).toBeNull()
      } else if (barre.fin !== null) {
        expect(barre.fin.max).toBeTypeOf('number')
      } else {
        // Décès non daté : ni vivante, ni fin connue.
        expect(barre.vivant).toBe(false)
      }
    }
  })

  it('les trois formes sont bien toutes représentées dans les jeux d’essai', () => {
    // Sans ce décompte, la boucle ci-dessus resterait verte en ne traversant
    // jamais deux de ses trois branches.
    const toutes: BarreChronologie[] = [
      ...EXEMPLE_CHRONOLOGIE.barres,
      ...EXEMPLE_BORNES_PARTIELLES.barres,
    ]
    expect(toutes).toHaveLength(12)
    expect(toutes.filter((b) => b.ouverte)).toHaveLength(2)
    expect(toutes.filter((b) => !b.ouverte && b.fin === null)).toHaveLength(2)
    expect(toutes.filter((b) => b.debut === null)).toHaveLength(3)
    // Bornes non bornées d'un côté (`BEF`) : deux barres les portent.
    expect(
      toutes.filter((b) => b.debut?.min === null || b.fin?.min === null),
    ).toHaveLength(2)
  })
})
