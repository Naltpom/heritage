/**
 * ════════════════════════════════════════════════════════════════════
 * CE QUI RESTE EST UN ARBRE BINAIRE, ET C'EST TOUTE LA PROPRIÉTÉ.
 *
 * Un arbre binaire dessiné par niveaux **ne peut pas** avoir de croisement : c'est
 * une propriété de sa forme, pas un résultat d'algorithme. Le seul point à tenir
 * est donc que ce qui sort en est bien un — personne n'y a plus de deux parents,
 * et rien n'y descend du lecteur.
 *
 * Le reste des cas porte sur les bords : l'implexe, que les vieilles familles
 * produisent en abondance, et le losange dont un seul époux serait ancêtre.
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { ascendanceSeule } from './ascendanceSeule'

function personne(id: string, x: number, y: number): NoeudGraphe {
  return {
    type: 'personne',
    id,
    personId: null,
    displayName: id,
    etiquetteNom: id,
    ligneDates: '',
    sexe: 'inconnu',
    vivant: false,
    branche: null,
    x,
    y,
    w: 100,
    h: 40,
  } as unknown as NoeudGraphe
}

function famille(id: string, x: number, y: number): NoeudGraphe {
  return { type: 'famille', id, familyId: null, divorced: false, x, y, w: 22, h: 14 } as NoeudGraphe
}

const u = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'union' }) as AreteGraphe
const f = (de: string, vers: string): AreteGraphe =>
  ({ de, vers, genre: 'filiation' }) as AreteGraphe

function graphe(noeuds: NoeudGraphe[], aretes: AreteGraphe[], ego = 'moi'): Graphe {
  return {
    empreinte: 'a'.repeat(64),
    ego,
    boite: { x: -1000, y: -1000, w: 4000, h: 2000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  } as unknown as Graphe
}

/**
 * L'ego, ses parents, ses quatre grands-parents — et tout ce qui doit partir :
 * une sœur, un conjoint rapporté, un enfant, un oncle et un cousin.
 */
const ARBRE = graphe(
  [
    personne('gpP', 0, -300),
    personne('gmP', 120, -300),
    personne('gpM', 400, -300),
    personne('gmM', 520, -300),
    famille('fGP', 60, -254),
    famille('fGM', 460, -254),
    personne('pere', 60, -150),
    personne('mere', 460, -150),
    personne('oncle', 700, -150),
    famille('fParents', 260, -104),
    personne('moi', 200, 0),
    personne('soeur', 320, 0),
    personne('conjointe', 80, 0),
    personne('cousin', 700, 0),
    famille('fMoi', 140, 46),
    famille('fOncle', 700, -104),
    personne('enfant', 140, 150),
  ],
  [
    u('gpP', 'fGP'),
    u('gmP', 'fGP'),
    f('fGP', 'pere'),
    f('fGP', 'oncle'),
    u('gpM', 'fGM'),
    u('gmM', 'fGM'),
    f('fGM', 'mere'),
    u('pere', 'fParents'),
    u('mere', 'fParents'),
    f('fParents', 'moi'),
    f('fParents', 'soeur'),
    u('moi', 'fMoi'),
    u('conjointe', 'fMoi'),
    f('fMoi', 'enfant'),
    u('oncle', 'fOncle'),
    f('fOncle', 'cousin'),
  ],
)

const restants = (g: Graphe): string[] =>
  g.noeuds
    .filter((n) => n.type === 'personne')
    .map((n) => n.id)
    .sort()

describe('ce qui reste', () => {
  it('garde le lecteur et ses ancêtres, et rien d’autre', () => {
    expect(restants(ascendanceSeule(ARBRE))).toEqual([
      'gmM',
      'gmP',
      'gpM',
      'gpP',
      'mere',
      'moi',
      'pere',
    ])
  })

  it('retire la fratrie, les conjoints non-ancêtres, les descendants et les cousins', () => {
    /* Ce sont exactement les quatre sortes de nœuds qui fabriquent des
       croisements : la fratrie et les cousins élargissent les rangées, le
       conjoint rapporté n'a aucun trait qui monte, et un descendant ramène de la
       largeur sous le lecteur. */
    const gardes = new Set(restants(ascendanceSeule(ARBRE)))

    for (const parti of ['soeur', 'conjointe', 'enfant', 'oncle', 'cousin']) {
      expect(gardes.has(parti), parti).toBe(false)
    }
  })

  it('garde entier un couple d’ancêtres, losange compris', () => {
    const g = ascendanceSeule(ARBRE)
    const ids = new Set(g.noeuds.map((n) => n.id))

    expect(ids.has('fGP')).toBe(true)
    expect(ids.has('fParents')).toBe(true)
  })

  it('ne garde aucun losange dont un seul époux serait ancêtre', () => {
    /* `fMoi` joint l'ego à sa conjointe, qui n'est l'ancêtre de personne. Le
       garder y ferait pendre un trait vers quelqu'un qu'on ne dessine plus. */
    const ids = new Set(ascendanceSeule(ARBRE).noeuds.map((n) => n.id))

    expect(ids.has('fMoi')).toBe(false)
    expect(ids.has('fOncle')).toBe(false)
  })
})

describe('la propriété qui justifie tout', () => {
  it('ne laisse à personne plus de deux parents', () => {
    /*
     * LA PROPRIÉTÉ. C'est elle qui rend le croisement impossible, et non un
     * algorithme de placement : un arbre binaire dessiné par niveaux n'en a pas.
     */
    const g = ascendanceSeule(ARBRE)
    const epoux = new Map<string, number>()

    for (const arete of g.aretes) {
      if (arete.genre === 'union') epoux.set(arete.vers, (epoux.get(arete.vers) ?? 0) + 1)
    }

    for (const [famille, nombre] of epoux) {
      expect(nombre, famille).toBeLessThanOrEqual(2)
    }

    const filiations = new Map<string, number>()

    for (const arete of g.aretes) {
      if (arete.genre === 'filiation') {
        filiations.set(arete.vers, (filiations.get(arete.vers) ?? 0) + 1)
      }
    }

    for (const [personne, nombre] of filiations) {
      expect(nombre, personne).toBe(1)
    }
  })

  it('ne laisse aucune arête pointer dans le vide', () => {
    const g = ascendanceSeule(ARBRE)
    const presents = new Set(g.noeuds.map((n) => n.id))

    for (const arete of g.aretes) {
      expect(presents.has(arete.de) && presents.has(arete.vers), `${arete.de} → ${arete.vers}`).toBe(
        true,
      )
    }
  })

  it('ne déplace rien : elle retire, comme la vue bornée', () => {
    const avant = new Map(ARBRE.noeuds.map((n) => [n.id, { x: n.x, y: n.y }]))

    for (const noeud of ascendanceSeule(ARBRE).noeuds) {
      expect({ x: noeud.x, y: noeud.y }, noeud.id).toEqual(avant.get(noeud.id))
    }
  })
})

describe('les bords', () => {
  it('ne dessine qu’une fois un ancêtre atteint par deux chemins', () => {
    /*
     * L'IMPLEXE. Sur les vieilles familles, deux cousins se marient et leurs
     * descendants ont le même ancêtre par deux branches. Il ne doit apparaître
     * qu'une fois, sinon le compte des fiches ment.
     */
    const implexe = graphe(
      [
        personne('souche', 0, -300),
        famille('fSouche', 0, -254),
        personne('pere', -100, -150),
        personne('mere', 100, -150),
        famille('fParents', 0, -104),
        personne('moi', 0, 0),
      ],
      [
        u('souche', 'fSouche'),
        f('fSouche', 'pere'),
        f('fSouche', 'mere'),
        u('pere', 'fParents'),
        u('mere', 'fParents'),
        f('fParents', 'moi'),
      ],
    )

    expect(restants(ascendanceSeule(implexe))).toEqual(['mere', 'moi', 'pere', 'souche'])
  })

  it('rend le graphe tel quel quand le lecteur n’y est pas', () => {
    /* Un rattachement vers une fiche supprimée : mieux vaut l'arbre entier qu'un
       écran vide. */
    const sansEgo = { ...ARBRE, ego: 'inconnu' } as Graphe

    expect(ascendanceSeule(sansEgo).noeuds).toHaveLength(ARBRE.noeuds.length)
  })

  it('garde le lecteur seul quand il n’a aucun parent connu', () => {
    const orphelin = graphe([personne('moi', 0, 0)], [])

    expect(restants(ascendanceSeule(orphelin))).toEqual(['moi'])
  })

  it('est idempotente', () => {
    const une = ascendanceSeule(ARBRE)

    expect(restants(ascendanceSeule(une))).toEqual(restants(une))
  })
})
