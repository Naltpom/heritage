/**
 * Le `viewBox` : zoom, déplacement, centrage, cadrage.
 *
 * Ces quatre calculs sont ceux qui, faux, ne se voient qu'à l'usage — « ça
 * zoome à côté du curseur », « la fiche part sous le panneau » — et se
 * corrigent alors à tâtons, en rechargeant la page à chaque essai. D'où des
 * fonctions pures et des attentes chiffrées.
 *
 * Les valeurs de référence viennent de `arbre.html` : boîte d'origine
 * `-7770.4 -1730 15824 1810`, molette `exp(deltaY × 0.0016)`, butées à
 * 2,5 × et 1/500ᵉ.
 */
import { describe, expect, it } from 'vitest'
import {
  cadrer,
  centrerSur,
  deplacer,
  echelle,
  versContenu,
  viewBoxEnTexte,
  zoomer,
  type Boite,
  type CadreEcran,
} from './geometrie'

const BOITE: Boite = { x: -7770.4, y: -1730, w: 15824, h: 1810 }
/** Un cadre de 1 600 × 800 dont le coin haut-gauche est à (0, 46) — l'en-tête. */
const CADRE: CadreEcran = { largeur: 1600, hauteur: 800, gauche: 0, haut: 46 }

describe('viewBoxEnTexte', () => {
  it('rend les quatre nombres dans l’ordre attendu par SVG', () => {
    expect(viewBoxEnTexte({ x: -1, y: -2, w: 3, h: 4 })).toBe('-1 -2 3 4')
  })
})

describe('echelle', () => {
  it('prend la plus petite des deux — c’est ce que fait `meet`', () => {
    // 1600/15824 ≈ 0.101 contre 800/1810 ≈ 0.442 : la largeur contraint.
    expect(echelle(BOITE, CADRE)).toBeCloseTo(1600 / 15824, 10)
  })
})

describe('versContenu', () => {
  it('rend le centre du contenu pour le centre du cadre', () => {
    const p = versContenu(BOITE, CADRE, 800, 446)
    expect(p.x).toBeCloseTo(BOITE.x + BOITE.w / 2, 6)
    expect(p.y).toBeCloseTo(BOITE.y + BOITE.h / 2, 6)
  })

  it('retire les bandes de centrage — sans quoi le zoom dérive', () => {
    // Le contenu est bien plus large que haut : il reste des bandes en haut et
    // en bas. Le bord supérieur du cadre n'est donc PAS le haut du contenu.
    const haut = versContenu(BOITE, CADRE, 800, CADRE.haut)
    expect(haut.y).toBeLessThan(BOITE.y)
  })

  it('tient compte de l’origine du cadre', () => {
    const sansDecalage = versContenu(BOITE, { ...CADRE, gauche: 0, haut: 0 }, 400, 300)
    const avecDecalage = versContenu(BOITE, { ...CADRE, gauche: 100, haut: 46 }, 500, 346)
    expect(avecDecalage.x).toBeCloseTo(sansDecalage.x, 6)
    expect(avecDecalage.y).toBeCloseTo(sansDecalage.y, 6)
  })
})

describe('zoomer', () => {
  it('garde le point sous le curseur immobile', () => {
    const avant = versContenu(BOITE, CADRE, 1200, 300)
    const apres = zoomer(BOITE, BOITE, CADRE, 1200, 300, -120)
    const point = versContenu(apres, CADRE, 1200, 300)
    expect(point.x).toBeCloseTo(avant.x, 4)
    expect(point.y).toBeCloseTo(avant.y, 4)
  })

  it('rapproche sur une molette négative, éloigne sur une positive', () => {
    expect(zoomer(BOITE, BOITE, CADRE, 800, 446, -120).w).toBeLessThan(BOITE.w)
    expect(zoomer(BOITE, BOITE, CADRE, 800, 446, 120).w).toBeGreaterThan(BOITE.w)
  })

  it('applique exactement exp(deltaY × 0.0016)', () => {
    const vb = zoomer(BOITE, BOITE, CADRE, 800, 446, 100)
    expect(vb.w).toBeCloseTo(BOITE.w * Math.exp(100 * 0.0016), 6)
  })

  it('conserve le rapport largeur/hauteur', () => {
    const vb = zoomer(BOITE, BOITE, CADRE, 900, 300, -240)
    expect(vb.w / vb.h).toBeCloseTo(BOITE.w / BOITE.h, 9)
  })

  it('bute à 2,5 fois la boîte d’origine en dézoom', () => {
    let vb = BOITE
    for (let i = 0; i < 60; i++) vb = zoomer(vb, BOITE, CADRE, 800, 446, 400)
    expect(vb.w).toBeCloseTo(BOITE.w * 2.5, 6)
  })

  it('bute au 1/500ᵉ en zoom', () => {
    let vb = BOITE
    for (let i = 0; i < 120; i++) vb = zoomer(vb, BOITE, CADRE, 800, 446, -400)
    expect(vb.w).toBeCloseTo(BOITE.w / 500, 6)
  })
})

describe('deplacer', () => {
  it('suit la souris : tirer vers la droite fait reculer le viewBox', () => {
    const k = echelle(BOITE, CADRE)
    const vb = deplacer(BOITE, CADRE, 100, -50)
    expect(vb.x).toBeCloseTo(BOITE.x - 100 / k, 6)
    expect(vb.y).toBeCloseTo(BOITE.y + 50 / k, 6)
    expect(vb.w).toBe(BOITE.w)
    expect(vb.h).toBe(BOITE.h)
  })

  it('déplace d’autant plus de contenu que l’on est dézoomé', () => {
    const serree: Boite = { ...BOITE, w: BOITE.w / 10, h: BOITE.h / 10 }
    const large = deplacer(BOITE, CADRE, 100, 0)
    const serre = deplacer(serree, CADRE, 100, 0)
    expect(Math.abs(large.x - BOITE.x)).toBeGreaterThan(Math.abs(serre.x - serree.x))
  })
})

describe('centrerSur', () => {
  const noeud = { x: 1840, y: -300, w: 133, h: 40 }

  it('cadre à 1 700 unités de large par défaut', () => {
    expect(centrerSur(noeud, BOITE, CADRE).w).toBe(1700)
  })

  it('ne dépasse jamais la boîte du graphe', () => {
    const petite: Boite = { x: 0, y: 0, w: 900, h: 400 }
    expect(centrerSur(noeud, petite, CADRE).w).toBe(900)
  })

  it('décale vers la droite pour compenser le panneau latéral', () => {
    const vb = centrerSur(noeud, BOITE, CADRE)
    const centre = vb.x + vb.w / 2
    // Le nœud doit se retrouver à GAUCHE du centre de la vue, donc dégagé de
    // la bande de 340 px qu'occupe le panneau.
    expect(noeud.x).toBeLessThan(centre)
    expect(vb.x).toBeCloseTo(noeud.x - 1700 / 2 + ((340 / 1600) * 1700) / 2, 6)
  })

  it('n’applique aucun biais sur un écran étroit — le panneau couvre tout', () => {
    const etroit: CadreEcran = { largeur: 420, hauteur: 780, gauche: 0, haut: 46 }
    const vb = centrerSur(noeud, BOITE, etroit)
    expect(vb.x + vb.w / 2).toBeCloseTo(noeud.x, 6)
  })

  it('centre verticalement sur le nœud', () => {
    const vb = centrerSur(noeud, BOITE, CADRE)
    expect(vb.y + vb.h / 2).toBeCloseTo(noeud.y, 6)
  })
})

describe('cadrer', () => {
  it('rend null sur un ensemble vide plutôt qu’un viewBox infini', () => {
    expect(cadrer([], CADRE)).toBeNull()
  })

  it('englobe tous les nœuds, marge comprise', () => {
    const noeuds = [
      { x: -1000, y: -450, w: 120, h: 40 },
      { x: 800, y: 0, w: 160, h: 40 },
    ]
    const vb = cadrer(noeuds, CADRE)
    expect(vb).not.toBeNull()
    if (vb === null) return
    for (const n of noeuds) {
      expect(n.x - n.w / 2).toBeGreaterThanOrEqual(vb.x)
      expect(n.x + n.w / 2).toBeLessThanOrEqual(vb.x + vb.w)
      expect(n.y - n.h / 2).toBeGreaterThanOrEqual(vb.y)
      expect(n.y + n.h / 2).toBeLessThanOrEqual(vb.y + vb.h)
    }
  })

  it('impose un plancher de 900 unités sur un nœud seul', () => {
    // Cadre assez haut pour que la marge verticale tienne dans les 900 unités
    // de large : le plancher s'applique alors tel quel. Cadrer une fiche de
    // 120 unités sans plancher donnerait un zoom absurde, la fiche occupant
    // tout l'écran.
    const haut: CadreEcran = { largeur: 800, hauteur: 600, gauche: 0, haut: 0 }
    expect(cadrer([{ x: 0, y: 0, w: 120, h: 40 }], haut)?.w).toBe(900)
  })

  it('élargit au-delà du plancher quand la hauteur du cadre l’exige', () => {
    // 1600 × 800 : 900 de large donnent 450 de haut, insuffisants pour les
    // 40 + 2 × 240 = 520 unités demandées. La hauteur reprend la main et la
    // largeur suit le rapport du cadre — 520 × 2 = 1040.
    const vb = cadrer([{ x: 0, y: 0, w: 120, h: 40 }], CADRE)
    expect(vb?.h).toBe(520)
    expect(vb?.w).toBe(1040)
  })

  it('élargit quand la hauteur ne suffit pas — le cas de la fratrie verticale', () => {
    // Douze générations empilées : 1 650 unités de haut pour 120 de large.
    const colonne = Array.from({ length: 12 }, (_, i) => ({
      x: 0,
      y: -150 * i,
      w: 120,
      h: 40,
    }))
    const vb = cadrer(colonne, CADRE)
    expect(vb).not.toBeNull()
    if (vb === null) return
    expect(vb.h).toBeGreaterThanOrEqual(1650 + 2 * 240 - 1)
    // La largeur a suivi le rapport du cadre, elle ne vaut plus le plancher.
    expect(vb.w).toBeCloseTo(vb.h * (CADRE.largeur / CADRE.hauteur), 6)
  })

  it('centre le cadrage sur le barycentre des extrêmes', () => {
    const vb = cadrer(
      [
        { x: -500, y: -300, w: 100, h: 40 },
        { x: 500, y: -300, w: 100, h: 40 },
      ],
      CADRE,
    )
    expect(vb).not.toBeNull()
    if (vb === null) return
    expect(vb.x + vb.w / 2).toBeCloseTo(0, 6)
    expect(vb.y + vb.h / 2).toBeCloseTo(-300, 6)
  })
})
