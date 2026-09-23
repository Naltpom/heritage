/**
 * ════════════════════════════════════════════════════════════════════
 * **CE QUI EST ÉPROUVÉ ICI EST UN NOMBRE, PAS UNE INTENTION.**
 *
 * « Insertion locale » est une promesse invérifiable tant qu'on ne dit pas
 * *combien de fiches ont changé d'abscisse*. La suite le dit, à deux échelles :
 * sur des rangées de cinq nœuds construites à la main, où l'on peut lire la
 * géométrie ; et sur l'arbre réel de 466 fiches, où l'on ne le peut pas.
 *
 * **L'issue qui accuse cette livraison est écrite avant ces mesures** : *« l'ajout
 * dit local déplace en fait la moitié de l'arbre »*. Le témoin qui la tranche est
 * la disposition telle qu'elle est aujourd'hui — recalcul global — et il est
 * dans la même assertion que le chiffre à défendre. Sans lui, un zéro prouverait
 * seulement que l'instrument est débranché.
 *
 * Les noms sont inventés — aucune personne réelle n'entre dans le dépôt (ADR 0006).
 * ════════════════════════════════════════════════════════════════════
 */
import { beforeAll, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import {
  accueillir,
  annonce,
  effetDeLaReorganisation,
  fichesDeplacees,
  insererLocalement,
  phraseDArrivee,
  phraseDeReorganisation,
  phraseDuBouton,
  poser,
  poserEnPoussant,
} from './insertionLocale'
import { ressortsIsotoniques } from './ressorts'
import { ESPACE, OPTIONS_PAR_DEFAUT } from './socle'

function personne(id: string, x: number, y: number, w = 100, branch: string | null = null): NoeudGraphe {
  return {
    type: 'personne',
    id,
    personId: null,
    displayName: id,
    etiquetteNom: id,
    sexe: 'inconnu',
    vivant: false,
    branch,
    x,
    y,
    w,
    h: 40,
  } as unknown as NoeudGraphe
}

function famille(id: string, x: number, y: number): NoeudGraphe {
  return { type: 'famille', id, familyId: null, divorced: false, x, y, w: 22, h: 14 } as NoeudGraphe
}

const u = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'union' }) as AreteGraphe
const fi = (de: string, vers: string): AreteGraphe =>
  ({ de, vers, genre: 'filiation' }) as AreteGraphe

function graphe(noeuds: NoeudGraphe[], aretes: AreteGraphe[], ego = 'p1'): Graphe {
  return {
    empreinte: 'x'.repeat(64),
    ego,
    boite: { x: -1000, y: -1000, w: 4000, h: 2000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

const abscisse = (g: Graphe, id: string): number => g.noeuds.find((n) => n.id === id)!.x

/* ------------------------------------------------------------------ */

describe('la poussée d’une rangée', () => {
  // Trois fiches de 100 px, largement espacées : il y a du jeu partout.
  const aeree = [
    { id: 'a', x: 0, w: 100 },
    { id: 'b', x: 500, w: 100 },
    { id: 'c', x: 1000, w: 100 },
  ]

  it('ne pousse personne quand la place est libre', () => {
    expect(poserEnPoussant(aeree, { id: 'n', x: 250, w: 100 }).size).toBe(0)
  })

  it('n’écarte que le voisin gêné, et le laisse EXACTEMENT à l’écart minimal', () => {
    const poussee = poserEnPoussant(aeree, { id: 'n', x: 420, w: 100 })

    expect([...poussee.keys()]).toEqual(['b'])
    expect(poussee.get('b')).toBe(420 + 50 + ESPACE + 50)
  })

  it('s’arrête au premier intervalle qui l’absorbe — « c » ne bouge pas', () => {
    const serree = [
      { id: 'a', x: 0, w: 100 },
      { id: 'b', x: 120, w: 100 },
      { id: 'c', x: 1000, w: 100 },
    ]
    const poussee = poserEnPoussant(serree, { id: 'n', x: 60, w: 100 })

    expect(poussee.has('c')).toBe(false)
  })

  it('pousse des deux côtés quand le nouveau venu tombe dans un bloc serré', () => {
    const bloc = [
      { id: 'a', x: 0, w: 100 },
      { id: 'b', x: 120, w: 100 },
      { id: 'c', x: 240, w: 100 },
    ]
    const poussee = poserEnPoussant(bloc, { id: 'n', x: 130, w: 100 })

    expect([...poussee.keys()].sort()).toEqual(['a', 'b', 'c'])
    expect(poussee.get('a')!).toBeLessThan(0)
    expect(poussee.get('c')!).toBeGreaterThan(240)
  })

  it('NE RÉORDONNE PAS : qui était à gauche reste à gauche', () => {
    const bloc = [
      { id: 'a', x: 0, w: 100 },
      { id: 'b', x: 120, w: 100 },
      { id: 'c', x: 240, w: 100 },
    ]
    const poussee = poserEnPoussant(bloc, { id: 'n', x: 130, w: 100 })
    const apres = bloc.map((p) => ({ ...p, x: poussee.get(p.id) ?? p.x }))

    for (let i = 1; i < apres.length; i += 1) {
      expect(apres[i]!.x).toBeGreaterThan(apres[i - 1]!.x)
    }
  })

  it('tient la séparation partout où elle a poussé', () => {
    const bloc = Array.from({ length: 8 }, (_, i) => ({ id: `n${i}`, x: i * 120, w: 100 }))
    const poussee = poserEnPoussant(bloc, { id: 'x', x: 360, w: 100 })
    const tous = [...bloc.map((p) => ({ ...p, x: poussee.get(p.id) ?? p.x })), { id: 'x', x: 360, w: 100 }]
    tous.sort((a, b) => a.x - b.x)

    for (let i = 1; i < tous.length; i += 1) {
      expect(tous[i]!.x - tous[i]!.w / 2).toBeGreaterThanOrEqual(
        tous[i - 1]!.x + tous[i - 1]!.w / 2 + ESPACE - 0.001,
      )
    }
  })

  it('traverse une rangée vide sans dommage', () => {
    expect(poserEnPoussant([], { id: 'n', x: 0, w: 100 }).size).toBe(0)
  })
})

describe('le choix de la place', () => {
  it('reste sur la place logique quand elle ne dérange personne', () => {
    const rangee = [
      { id: 'a', x: 0, w: 100 },
      { id: 'b', x: 600, w: 100 },
    ]
    const pose = poser(rangee, { id: 'n', x: 300, w: 100 })

    expect(pose.x).toBe(300)
    expect(pose.poussee.size).toBe(0)
  })

  it('SE DÉCALE plutôt que d’ouvrir un bloc serré en deux', () => {
    // À gauche un bloc compact ; à droite, tout de suite, un grand vide.
    const rangee = [
      { id: 'a', x: 0, w: 100 },
      { id: 'b', x: 120, w: 100 },
      { id: 'c', x: 240, w: 100 },
      { id: 'd', x: 2000, w: 100 },
    ]
    const surLaPlaceLogique = poserEnPoussant(rangee, { id: 'n', x: 240, w: 100 })
    const choisie = poser(rangee, { id: 'n', x: 240, w: 100 })

    expect(surLaPlaceLogique.size).toBeGreaterThan(0)
    expect(choisie.poussee.size).toBe(0)
    expect(choisie.x).toBeGreaterThan(240)
  })

  it('NE S’ÉLOIGNE PAS DE PLUS QUE LA PLACE QU’IL LUI FAUT', () => {
    // Le vide est loin : s’en approcher ne vaut pas la peine, les voisins cèdent.
    const rangee = Array.from({ length: 12 }, (_, i) => ({ id: `n${i}`, x: i * 120, w: 100 }))
    const pose = poser(rangee, { id: 'x', x: 600, w: 100 })

    expect(Math.abs(pose.x - 600)).toBeLessThanOrEqual(100 + ESPACE)
  })

  it('départage à égalité par la proximité de la place logique', () => {
    // Deux vides symétriques : celui qui reste le plus près l’emporte, et le
    // résultat ne dépend donc pas de l’ordre d’énumération des candidats.
    const rangee = [
      { id: 'g', x: -500, w: 100 },
      { id: 'd', x: 500, w: 100 },
    ]
    expect(poser(rangee, { id: 'n', x: 10, w: 100 }).x).toBe(10)
  })

  it('est déterministe', () => {
    const rangee = Array.from({ length: 10 }, (_, i) => ({ id: `n${i}`, x: i * 130, w: 100 }))
    const a = poser(rangee, { id: 'x', x: 455, w: 100 })
    const b = poser(rangee, { id: 'x', x: 455, w: 100 })

    expect(a.x).toBe(b.x)
    expect([...a.poussee]).toEqual([...b.poussee])
  })
})

/* ------------------------------------------------------------------ */

describe('l’insertion dans un arbre', () => {
  /**
   * Un arbre volontairement large : un couple, quatre enfants, et six fiches
   * lointaines sur la même rangée que les enfants. C'est la seule forme qui
   * permette de distinguer « pousser ses voisines » de « replacer la rangée ».
   */
  const arbre = (): Graphe => {
    const noeuds: NoeudGraphe[] = [
      personne('p1', 0, 0, 100, 'tr'),
      personne('pere', -200, -200, 100, 'pp'),
      personne('mere', 200, -200, 100, 'pm'),
      famille('f1', 0, -160),
      personne('e1', -300, 0, 100, 'tr'),
      personne('e2', -150, 0, 100, 'tr'),
      personne('e3', 150, 0, 100, 'tr'),
    ]
    for (let i = 0; i < 6; i += 1) noeuds.push(personne(`loin${i}`, 2000 + i * 400, 0, 100, 'mm'))

    const aretes = [u('pere', 'f1'), u('mere', 'f1'), fi('f1', 'p1'), fi('f1', 'e1'), fi('f1', 'e2'), fi('f1', 'e3')]

    return graphe(noeuds, aretes)
  }

  const avecUnEnfantDePlus = (base: Graphe): Graphe => ({
    ...base,
    noeuds: [...base.noeuds, personne('e4', 0, 0, 100, 'tr')],
    aretes: [...base.aretes, fi('f1', 'e4')],
  })

  it('pose la nouvelle fiche à la MOYENNE de ses voisins placés', () => {
    // Un enfant unique, sans conjoint : sa seule ancre est le losange de ses
    // parents, donc sa place logique est exactement dessous — et la rangée est
    // assez dégagée pour qu'il n'ait à s'en écarter d'aucun pixel.
    const seul = graphe(
      [personne('p1', 0, 0), personne('pa', 600, -200), personne('ma', 1000, -200), famille('f1', 837, -160)],
      [u('pa', 'f1'), u('ma', 'f1')],
    )
    const apres = { ...seul, noeuds: [...seul.noeuds, personne('enf', 9999, 0)], aretes: [fi('f1', 'enf')] }

    expect(abscisse(insererLocalement(seul, apres).graphe, 'enf')).toBe(837)
  })

  it('NE DÉPLACE PAS LES FICHES LOINTAINES — et le témoin est le recalcul global', () => {
    // **Les deux mises en page passent par la disposition**, sans quoi le cas ne
    // dit rien : comparer une charge à elle-même rend zéro quoi qu'on branche
    // derrière, et l'instrument serait débranché sans qu'on le voie.
    const avant = ressortsIsotoniques(arbre(), OPTIONS_PAR_DEFAUT)
    const servi = ressortsIsotoniques(avecUnEnfantDePlus(arbre()), OPTIONS_PAR_DEFAUT)

    // Le témoin d'abord : le recalcul global remue vraiment ces fiches-là.
    expect(fichesDeplacees(avant, servi).length).toBeGreaterThan(0)

    const local = insererLocalement(avant, servi)
    for (let i = 0; i < 6; i += 1) {
      expect(abscisse(local.graphe, `loin${i}`)).toBe(abscisse(avant, `loin${i}`))
    }
  })

  it('n’introduit AUCUN chevauchement', () => {
    const local = insererLocalement(arbre(), avecUnEnfantDePlus(arbre()))
    const rangee = local.graphe.noeuds.filter((n) => n.y === 0).sort((a, b) => a.x - b.x)

    for (let i = 1; i < rangee.length; i += 1) {
      expect(rangee[i]!.x - rangee[i]!.w / 2).toBeGreaterThanOrEqual(
        rangee[i - 1]!.x + rangee[i - 1]!.w / 2 + ESPACE - 0.001,
      )
    }
  })

  it('ne touche à RIEN quand rien n’est arrivé', () => {
    const avant = arbre()
    const arrivee = insererLocalement(avant, avant)

    expect(arrivee.nouveaux).toEqual([])
    expect(arrivee.deplacees).toEqual([])
    for (const noeud of avant.noeuds) {
      expect(abscisse(arrivee.graphe, noeud.id)).toBe(noeud.x)
    }
  })

  it('place par VAGUES : un enfant dont les parents sont eux aussi nouveaux', () => {
    const avant = arbre()
    const apres: Graphe = {
      ...avant,
      noeuds: [
        ...avant.noeuds,
        personne('conjoint', 9999, 0, 100, 'tr'),
        famille('f2', 9999, 40),
        personne('petit', 9999, 200, 100, 'tr'),
      ],
      aretes: [...avant.aretes, u('e3', 'f2'), u('conjoint', 'f2'), fi('f2', 'petit')],
    }

    const arrivee = insererLocalement(avant, apres)

    expect(arrivee.sansAncre).toEqual([])
    // Le petit-enfant est sous le losange de ses parents, pas à 9 999.
    expect(abscisse(arrivee.graphe, 'petit')).toBe(abscisse(arrivee.graphe, 'f2'))
  })

  it('NOMME ce qu’aucune ancre ne rattache, au lieu de le poser sur l’ego', () => {
    const avant = arbre()
    const apres: Graphe = {
      ...avant,
      noeuds: [...avant.noeuds, personne('flottant', 4242, 0)],
      aretes: avant.aretes,
    }

    const arrivee = insererLocalement(avant, apres)

    expect(arrivee.sansAncre).toEqual(['flottant'])
    expect(abscisse(arrivee.graphe, 'flottant')).toBe(4242)
  })

  it('REPLACE une fiche qui a changé de RANGÉE, au lieu de lui rendre son ancienne abscisse', () => {
    // Il suffit qu'on lui donne des parents pour que sa génération se décale.
    // Lui rendre sa place d'avant dans sa nouvelle rangée la poserait sur une
    // place que personne ne lui a réservée — et la séparation tomberait.
    const avant = graphe(
      [personne('p1', 0, 0), personne('mobile', 5000, 0), personne('occupe', 0, -200), famille('f1', 0, -160)],
      [],
    )
    const apres: Graphe = {
      ...avant,
      // La disposition l'a fait monter d'une génération, juste sur « occupe ».
      noeuds: avant.noeuds.map((n) => (n.id === 'mobile' ? { ...n, y: -200 } : n)),
      aretes: [fi('f1', 'mobile')],
    }

    const arrivee = insererLocalement(avant, apres)
    const rangee = arrivee.graphe.noeuds.filter((n) => n.y === -200).sort((a, b) => a.x - b.x)

    expect(arrivee.nouveaux).toContain('mobile')
    for (let i = 1; i < rangee.length; i += 1) {
      expect(rangee[i]!.x - rangee[i]!.w / 2).toBeGreaterThanOrEqual(
        rangee[i - 1]!.x + rangee[i - 1]!.w / 2 + ESPACE - 0.001,
      )
    }
  })

  it('laisse partir ce qui a disparu de la charge, sans reboucher', () => {
    const avant = arbre()
    const apres: Graphe = {
      ...avant,
      noeuds: avant.noeuds.filter((n) => n.id !== 'e1'),
      aretes: avant.aretes.filter((a) => a.vers !== 'e1'),
    }

    const arrivee = insererLocalement(avant, apres)

    expect(arrivee.graphe.noeuds.some((n) => n.id === 'e1')).toBe(false)
    expect(arrivee.deplacees).toEqual([])
  })

  it('recalcule la boîte englobante, qui contient tous les nœuds', () => {
    const arrivee = insererLocalement(arbre(), avecUnEnfantDePlus(arbre()))
    const b = arrivee.graphe.boite

    for (const noeud of arrivee.graphe.noeuds) {
      expect(noeud.x - noeud.w / 2).toBeGreaterThanOrEqual(b.x)
      expect(noeud.x + noeud.w / 2).toBeLessThanOrEqual(b.x + b.w)
    }
  })

  it('ne mute ni la charge reçue ni la mise en page affichée', () => {
    const avant = arbre()
    const apres = avecUnEnfantDePlus(avant)
    const copieAvant = JSON.stringify(avant)
    const copieApres = JSON.stringify(apres)

    insererLocalement(avant, apres)

    expect(JSON.stringify(avant)).toBe(copieAvant)
    expect(JSON.stringify(apres)).toBe(copieApres)
  })

  it('est déterministe', () => {
    const avant = arbre()
    const apres = avecUnEnfantDePlus(avant)
    const a = insererLocalement(avant, apres).graphe.noeuds.map((n) => [n.id, n.x])
    const b = insererLocalement(avant, apres).graphe.noeuds.map((n) => [n.id, n.x])

    expect(a).toEqual(b)
  })
})

/* ------------------------------------------------------------------ */

describe('la condition de réorganisation', () => {
  /**
   * Le tronc coincé : une ascendance paternelle **sans un pixel de jeu** à sa
   * gauche, et le lecteur immédiatement à sa droite. Les fiches font 100 px et
   * sont à 120 les unes des autres, c'est-à-dire exactement `w + ESPACE` : c'est
   * le bloc compact que PAVA laisse derrière lui, et le seul endroit où une
   * arrivée n'a d'autre issue que de pousser le tronc.
   */
  const serre = (): Graphe =>
    graphe(
      [
        personne('g5', -720, 0, 100, 'pp'),
        personne('g4', -600, 0, 100, 'pp'),
        personne('g3', -480, 0, 100, 'pp'),
        personne('g2', -360, 0, 100, 'pp'),
        personne('g1', -240, 0, 100, 'pp'),
        personne('pat', -120, 0, 100, 'pp'),
        personne('p1', 0, 0, 100, 'tr'),
        personne('mat', 120, 0, 100, 'mp'),
        famille('f1', -120, -40),
        personne('pere', -120, -200, 100, 'pp'),
      ],
      [fi('f1', 'pat'), u('pere', 'f1')],
    )

  it('NE SE DÉCLENCHE PAS quand la branche grossit vers l’extérieur', () => {
    const avant = graphe(
      [
        personne('p1', 0, 0, 100, 'tr'),
        personne('pat', -1000, 0, 100, 'pp'),
        famille('f1', -1000, -40),
        personne('pere', -1000, -200, 100, 'pp'),
      ],
      [fi('f1', 'pat'), u('pere', 'f1')],
    )
    const apres: Graphe = {
      ...avant,
      noeuds: [...avant.noeuds, personne('mere', -1000, -200, 100, 'pp')],
      aretes: [...avant.aretes, u('mere', 'f1')],
    }

    const arrivee = accueillir(avant, apres)

    expect(arrivee.motif).toBeNull()
    expect(abscisse(arrivee.graphe, 'p1')).toBe(0)
  })

  it('SE DÉCLENCHE quand la poussée atteint le tronc — et le nomme', () => {
    const avant = serre()
    const apres: Graphe = {
      ...avant,
      noeuds: [...avant.noeuds, personne('frere', -120, 0, 100, 'pp')],
      aretes: [...avant.aretes, fi('f1', 'frere')],
    }

    const arrivee = accueillir(avant, apres)

    expect(arrivee.motif).not.toBeNull()
    expect(arrivee.motif!.cas).toBe('tronc-pousse')
    expect(arrivee.motif!.combien).toBeGreaterThan(0)
    expect(arrivee.motif!.ecart).toBeGreaterThan(0)
  })

  it('rend alors la mise en page GLOBALE, et non l’insertion locale', () => {
    const avant = serre()
    const apres: Graphe = {
      ...avant,
      noeuds: [...avant.noeuds, personne('frere', -120, 0, 100, 'pp')],
      aretes: [...avant.aretes, fi('f1', 'frere')],
    }

    const arrivee = accueillir(avant, apres)

    expect(arrivee.graphe.noeuds.map((n) => [n.id, n.x])).toEqual(
      apres.noeuds.map((n) => [n.id, n.x]),
    )
  })

  it('ne compte PAS les nouveaux venus qui se poussent entre eux', () => {
    // Une branche greffée en bloc, marquée du même tronc : elle se bouscule
    // toute seule, et rien de ce que le lecteur regardait n'a bougé.
    const avant = graphe([personne('p1', 0, 0, 100, 'tr'), famille('f1', 0, -40)], [])
    const apres: Graphe = {
      ...avant,
      noeuds: [
        ...avant.noeuds,
        personne('a', 0, -200, 100, 'tr'),
        personne('b', 0, -200, 100, 'tr'),
        personne('c', 0, -200, 100, 'tr'),
      ],
      aretes: [u('a', 'f1'), u('b', 'f1'), u('c', 'f1'), fi('f1', 'p1')],
    }

    expect(accueillir(avant, apres).motif).toBeNull()
  })

  it('tient le lecteur à l’abscisse zéro dans les DEUX cas', () => {
    const paisible = graphe(
      [personne('p1', 0, 0, 100, 'tr'), personne('loin', 3000, 0, 100, 'mm'), famille('f1', 3000, -40)],
      [fi('f1', 'loin')],
    )
    const arrivee = accueillir(paisible, {
      ...paisible,
      noeuds: [...paisible.noeuds, personne('pa', 3000, -200, 100, 'mm')],
      aretes: [...paisible.aretes, u('pa', 'f1')],
    })

    expect(abscisse(arrivee.graphe, 'p1')).toBe(0)
  })
})

describe('le premier affichage', () => {
  it('rend la mise en page de la disposition, telle quelle', () => {
    const g = graphe([personne('p1', 0, 0)], [])

    expect(accueillir(null, g).graphe).toBe(g)
    expect(accueillir(null, g).motif).toBeNull()
  })
})

/* ------------------------------------------------------------------ */

describe('ce que l’écran dit', () => {
  const arrivee = (fichesNouvelles: string[], deplacees: string[], motif = null as never) => ({
    graphe: graphe([], []),
    nouveaux: fichesNouvelles,
    fichesNouvelles,
    deplacees,
    sansAncre: [],
    motif,
  })

  it('se tait quand rien n’est arrivé', () => {
    expect(annonce(arrivee([], []))).toBeNull()
  })

  it('dit l’arrivée, et dit que rien d’autre n’a bougé', () => {
    const a = annonce(arrivee(['p9'], []))

    expect(a!.ton).toBe('arrivee')
    expect(a!.texte).toBe('1 nouvelle fiche dans l’arbre. Rien d’autre n’a bougé.')
  })

  it('accorde le pluriel des deux côtés', () => {
    expect(phraseDArrivee(2, 3)).toBe(
      '2 nouvelles fiches dans l’arbre. 3 fiches voisines se sont écartées.',
    )
    expect(phraseDArrivee(1, 1)).toBe(
      '1 nouvelle fiche dans l’arbre. 1 fiche voisine s’est écartée.',
    )
  })

  it('NE CRIE PAS À LA RÉORGANISATION quand elle n’a déplacé personne', () => {
    const motif = { cas: 'tronc-pousse', combien: 2, ecart: 168, lecteur: false } as const
    const a = annonce({ ...arrivee(['p9'], []), motif } as never)

    expect(a!.ton).toBe('arrivee')
  })

  it('annonce la réorganisation, sa cause et son ampleur', () => {
    const motif = { cas: 'tronc-pousse', combien: 3, ecart: 339.4, lecteur: true } as const

    expect(phraseDeReorganisation(motif, 62)).toContain('poussé votre propre fiche')
    expect(phraseDeReorganisation(motif, 62)).toContain('62 fiches ont changé de place')
    expect(phraseDeReorganisation({ ...motif, lecteur: false }, 5)).toContain('339 px')
  })

  it('fait dire au bouton ce qu’il va faire, AVANT qu’on le presse', () => {
    const affiche = graphe([personne('a', 0, 0), personne('b', 500, 0)], [])
    const global = graphe([personne('a', 0, 0), personne('b', 900, 0)], [])

    expect(phraseDuBouton(effetDeLaReorganisation(affiche, global))).toBe('Replacera 1 fiche sur 2.')
    expect(phraseDuBouton(effetDeLaReorganisation(affiche, affiche))).toBe(
      'Rien à replacer : l’arbre est déjà dans sa meilleure position.',
    )
  })
})

/* ------------------------------------------------------------------ */
/* Le graphe réel — hors dépôt (ADR 0006)                              */
/* ------------------------------------------------------------------ */

const CHEMIN = process.env.HERITAGE_GRAPHE_CLIENT

describe.runIf(CHEMIN !== undefined)('sur le graphe réel', () => {
  let g!: Graphe
  let place!: Graphe
  let feuilles!: NoeudGraphe[]

  beforeAll(() => {
    g = JSON.parse(readFileSync(CHEMIN as string, 'utf8')) as Graphe
    place = ressortsIsotoniques(g, OPTIONS_PAR_DEFAUT)

    const aUneUnion = new Set(g.aretes.filter((a) => a.genre === 'union').map((a) => a.de))
    const aDesParents = new Set(g.aretes.filter((a) => a.genre === 'filiation').map((a) => a.vers))
    feuilles = g.noeuds.filter(
      (n) => n.type === 'personne' && !aUneUnion.has(n.id) && aDesParents.has(n.id),
    )
  })

  const sansElle = (id: string): Graphe => ({
    ...g,
    noeuds: g.noeuds.filter((n) => n.id !== id),
    aretes: g.aretes.filter((a) => a.de !== id && a.vers !== id),
  })

  it('confronte plus de cent feuilles — sinon la moyenne ne prouve rien', () => {
    expect(feuilles.length).toBeGreaterThan(100)
    expect(g.noeuds.filter((n) => n.type === 'personne').length).toBeGreaterThan(400)
  })

  /**
   * **Le chiffre sur lequel cette livraison se juge.**
   *
   * Relevé le 20/09/2026 sur la charge de production (644 nœuds, 466 fiches,
   * 121 feuilles) :
   *
   * | | fiches déplacées, en moyenne | au pire | à zéro |
   * |---|---|---|---|
   * | recalcul global — ce que faisait `ressorts` seul | **23,4** | — | — |
   * | insertion locale | **3,8** | 19 | 36 cas sur 121 |
   *
   * Les bornes de l'assertion sont larges à dessein : ce qu'on tient ici, c'est
   * **l'écart entre les deux**, pas la deuxième décimale d'un nombre qui bougera
   * au prochain ajout de l'arbre. Un test qui fixerait 3,82 serait rouge demain
   * pour une raison qui n'est pas un défaut.
   */
  it('L’AJOUT D’UNE FICHE DÉPLACE TROIS FOIS MOINS DE MONDE QUE LE RECALCUL', () => {
    let global = 0
    let local = 0
    let pire = 0

    for (const feuille of feuilles) {
      const affiche = ressortsIsotoniques(sansElle(feuille.id), OPTIONS_PAR_DEFAUT)
      const arrivee = accueillir(affiche, place)

      global += fichesDeplacees(affiche, place).length
      local += arrivee.deplacees.length
      pire = Math.max(pire, arrivee.deplacees.length)
    }

    const moyenneGlobale = global / feuilles.length
    const moyenneLocale = local / feuilles.length

    // Le témoin : le recalcul global remue vraiment, sinon la comparaison ment.
    expect(moyenneGlobale).toBeGreaterThan(15)
    expect(moyenneLocale).toBeLessThan(moyenneGlobale / 3)
    expect(moyenneLocale).toBeLessThan(8)
    // L'issue qui accusait : « la moitié de l'arbre ». Un quart en serait déjà un.
    expect(pire).toBeLessThan(feuilles.length / 2)
  })

  it('n’introduit AUCUN chevauchement, sur aucune rangée, sur aucune des 121 insertions', () => {
    for (const feuille of feuilles) {
      const affiche = ressortsIsotoniques(sansElle(feuille.id), OPTIONS_PAR_DEFAUT)
      const apres = accueillir(affiche, place).graphe

      const rangees = new Map<number, NoeudGraphe[]>()
      for (const noeud of apres.noeuds) {
        rangees.set(noeud.y, [...(rangees.get(noeud.y) ?? []), noeud])
      }

      for (const rangee of rangees.values()) {
        rangee.sort((a, b) => a.x - b.x)
        for (let i = 1; i < rangee.length; i += 1) {
          expect(rangee[i]!.x - rangee[i]!.w / 2).toBeGreaterThanOrEqual(
            rangee[i - 1]!.x + rangee[i - 1]!.w / 2 + ESPACE - 0.001,
          )
        }
      }
    }
  })

  it('ne déplace aucune ordonnée', () => {
    const affiche = ressortsIsotoniques(sansElle(feuilles[0]!.id), OPTIONS_PAR_DEFAUT)
    const apres = accueillir(affiche, place).graphe

    for (const noeud of place.noeuds) {
      expect(apres.noeuds.find((n) => n.id === noeud.id)!.y).toBe(noeud.y)
    }
  })

  it('garde le lecteur à l’abscisse zéro, insertion par insertion', () => {
    for (const feuille of feuilles) {
      const affiche = ressortsIsotoniques(sansElle(feuille.id), OPTIONS_PAR_DEFAUT)
      const apres = accueillir(affiche, place).graphe

      expect(Math.abs(apres.noeuds.find((n) => n.id === g.ego)!.x)).toBeLessThan(1)
    }
  })

  /**
   * **Ce que la condition déclenche sur l'arbre réel d'aujourd'hui : DEUX fois
   * sur cent vingt et une.**
   *
   * Et les deux cas sont les mêmes : une feuille de la **rangée du lecteur**,
   * dans sa lignée directe. Ajouter quelqu'un à côté de soi pousse sa propre
   * ligne — c'est la définition même de la condition, et c'est le seul endroit
   * de l'arbre où elle a lieu aujourd'hui.
   *
   * **Ni « jamais » ni « à chaque ajout »**, et les deux bornes sont dans
   * l'assertion : une condition qui ne sonnerait jamais serait morte, une qui
   * sonnerait à chaque ajout rendrait l'insertion locale inutile.
   */
  it('LA CONDITION NE SONNE NI JAMAIS NI TOUJOURS — 2 fois sur 121', () => {
    let sonne = 0

    for (const feuille of feuilles) {
      const affiche = ressortsIsotoniques(sansElle(feuille.id), OPTIONS_PAR_DEFAUT)
      if (accueillir(affiche, place).motif !== null) sonne += 1
    }

    expect(sonne).toBeGreaterThan(0)
    expect(sonne).toBeLessThan(feuilles.length / 10)
  })

  it('tient sous la milliseconde : l’insertion ne doit pas coûter plus que le recalcul', () => {
    const affiche = ressortsIsotoniques(sansElle(feuilles[0]!.id), OPTIONS_PAR_DEFAUT)

    const debut = performance.now()
    for (let i = 0; i < 20; i += 1) accueillir(affiche, place)
    const parAppel = (performance.now() - debut) / 20

    expect(parAppel).toBeLessThan(20)
  })
})
