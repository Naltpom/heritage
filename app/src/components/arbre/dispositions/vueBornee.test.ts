/**
 * ════════════════════════════════════════════════════════════════════
 * LA VUE BORNÉE RETIRE, ELLE NE DÉPLACE PAS.
 *
 * C'est la propriété qui la rend comparable aux autres dispositions : mise côte à
 * côte avec `/arbre`, **ce qui reste est au même endroit**. Une vue bornée qui
 * recentrerait au passage mélangerait deux effets, et on ne saurait plus lequel
 * on juge.
 *
 * Le reste des cas porte sur le parcours : deux budgets indépendants — monter
 * coûte une génération de haut, descendre une de bas, rejoindre un conjoint ne
 * coûte rien — et ce sont eux qui donnent « mes grands-parents, mes cousins, mes
 * petits-enfants » sans qu'aucune de ces relations soit écrite quelque part.
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { vueBornee } from './vueBornee'
import { optionsDepuis, OPTIONS_PAR_DEFAUT } from './socle'

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

/**
 * Quatre générations autour de l'ego `moi`, avec un conjoint rapporté, une sœur,
 * un enfant, un petit-enfant et une branche d'oncle et cousin.
 *
 * ```
 *  -450   aieul + aieule
 *  -300   pere + mere              oncle
 *  -150   moi + conjointe          soeur          cousin
 *     0   enfant
 *   150   petit
 * ```
 */
const ARBRE: Graphe = {
  empreinte: 'a'.repeat(64),
  ego: 'moi',
  boite: { x: -1000, y: -1000, w: 3000, h: 2000 },
  calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
  noeuds: [
    personne('aieul', 0, -450),
    personne('aieule', 120, -450),
    famille('fAieux', 60, -404),
    personne('pere', 0, -300),
    personne('mere', 120, -300),
    personne('oncle', 400, -300),
    famille('fParents', 60, -254),
    personne('moi', 0, -150),
    personne('conjointe', 120, -150),
    personne('soeur', 240, -150),
    personne('cousin', 400, -150),
    famille('fMoi', 60, -104),
    famille('fOncle', 400, -254),
    personne('enfant', 60, 0),
    famille('fEnfant', 60, 46),
    personne('petit', 60, 150),
  ],
  aretes: [
    u('aieul', 'fAieux'),
    u('aieule', 'fAieux'),
    f('fAieux', 'pere'),
    f('fAieux', 'oncle'),
    u('pere', 'fParents'),
    u('mere', 'fParents'),
    f('fParents', 'moi'),
    f('fParents', 'soeur'),
    u('oncle', 'fOncle'),
    f('fOncle', 'cousin'),
    u('moi', 'fMoi'),
    u('conjointe', 'fMoi'),
    f('fMoi', 'enfant'),
    u('enfant', 'fEnfant'),
    f('fEnfant', 'petit'),
  ],
} as unknown as Graphe

const gardees = (g: Graphe): string[] =>
  g.noeuds
    .filter((n) => n.type === 'personne')
    .map((n) => n.id)
    .sort()

describe('les deux budgets', () => {
  it('à 0/0, ne garde que le lecteur et son conjoint', () => {
    /* Rejoindre un conjoint ne coûte rien : une fenêtre qui montre quelqu'un sans
       son conjoint ne montre pas un couple. C'est le plus petit arbre qu'on
       puisse demander, et `0` est une valeur légitime, pas une erreur. */
    expect(gardees(vueBornee(ARBRE, { haut: 0, bas: 0 }))).toEqual(['conjointe', 'moi'])
  })

  it('à 1/0, ajoute les parents — et pas la sœur', () => {
    /* La sœur coûte une montée **et** une descente. */
    expect(gardees(vueBornee(ARBRE, { haut: 1, bas: 0 }))).toEqual([
      'conjointe',
      'mere',
      'moi',
      'pere',
    ])
  })

  it('à 1/1, ajoute la sœur et l’enfant', () => {
    expect(gardees(vueBornee(ARBRE, { haut: 1, bas: 1 }))).toEqual([
      'conjointe',
      'enfant',
      'mere',
      'moi',
      'pere',
      'soeur',
    ])
  })

  it('à 2/2, atteint les aïeux, l’oncle, le cousin et le petit-enfant', () => {
    /* Le cousin est à (2, 2) : deux montées jusqu'aux aïeux, deux descentes par
       l'oncle. C'est ce que les deux budgets indépendants donnent sans qu'aucune
       relation « cousin » soit écrite nulle part. */
    expect(gardees(vueBornee(ARBRE, { haut: 2, bas: 2 }))).toEqual([
      'aieul',
      'aieule',
      'conjointe',
      'cousin',
      'enfant',
      'mere',
      'moi',
      'oncle',
      'pere',
      'petit',
      'soeur',
    ])
  })
})

describe('elle retire, elle ne déplace pas', () => {
  it('laisse chaque nœud gardé exactement où il était', () => {
    const avant = new Map(ARBRE.noeuds.map((n) => [n.id, { x: n.x, y: n.y }]))

    for (const noeud of vueBornee(ARBRE, OPTIONS_PAR_DEFAUT).noeuds) {
      expect({ x: noeud.x, y: noeud.y }, noeud.id).toEqual(avant.get(noeud.id))
    }
  })

  it('recalcule la boîte sur ce qui reste', () => {
    /* Elle alimente le `viewBox` initial : la garder ouvrirait la vue sur un
       cadre bien plus large que le sous-arbre, et tout paraîtrait minuscule. */
    const borne = vueBornee(ARBRE, { haut: 0, bas: 0 })

    expect(borne.boite.w).toBeLessThan(ARBRE.boite.w)

    for (const noeud of borne.noeuds) {
      expect(noeud.x - noeud.w / 2).toBeGreaterThanOrEqual(borne.boite.x)
      expect(noeud.x + noeud.w / 2).toBeLessThanOrEqual(borne.boite.x + borne.boite.w)
    }
  })
})

describe('ce qui part avec les nœuds retirés', () => {
  it('ne laisse aucune arête dont une extrémité a disparu', () => {
    const borne = vueBornee(ARBRE, { haut: 1, bas: 0 })
    const presents = new Set(borne.noeuds.map((n) => n.id))

    for (const arete of borne.aretes) {
      expect(presents.has(arete.de), `${arete.de} → ${arete.vers}`).toBe(true)
      expect(presents.has(arete.vers), `${arete.de} → ${arete.vers}`).toBe(true)
    }
  })

  it('ne garde aucun losange qui ne joint plus personne', () => {
    const borne = vueBornee(ARBRE, { haut: 0, bas: 0 })
    const personnes = new Set(borne.noeuds.filter((n) => n.type === 'personne').map((n) => n.id))

    for (const noeud of borne.noeuds) {
      if (noeud.type !== 'famille') continue

      const epoux = borne.aretes.filter((a) => a.genre === 'union' && a.vers === noeud.id)

      expect(epoux.length, noeud.id).toBeGreaterThan(0)
      expect(epoux.some((a) => personnes.has(a.de)), noeud.id).toBe(true)
    }
  })
})

describe('les réglages venus de l’adresse', () => {
  it('borne une valeur absurde au lieu de rendre un écran vide', () => {
    expect(optionsDepuis({ haut: '-4', bas: 'oui' })).toEqual({ haut: 0, bas: OPTIONS_PAR_DEFAUT.bas })
    expect(optionsDepuis({ haut: '999' })).toEqual({ haut: 20, bas: OPTIONS_PAR_DEFAUT.bas })
  })

  it('retombe sur le défaut quand rien n’est demandé', () => {
    expect(optionsDepuis({})).toEqual(OPTIONS_PAR_DEFAUT)
  })

  it('accepte `0`, qui est une profondeur et non une absence', () => {
    expect(optionsDepuis({ haut: '0', bas: '0' })).toEqual({ haut: 0, bas: 0 })
  })
})

describe('les cas dégénérés', () => {
  it('rend le graphe tel quel quand l’ego n’y est pas', () => {
    /* Un lecteur rattaché à une fiche supprimée depuis : mieux vaut l'arbre
       entier qu'un écran vide. */
    const sansEgo = { ...ARBRE, ego: 'inconnu' } as Graphe

    expect(vueBornee(sansEgo, OPTIONS_PAR_DEFAUT).noeuds).toHaveLength(ARBRE.noeuds.length)
  })

  it('est idempotente : rejouée sur son résultat, elle ne retire plus rien', () => {
    const une = vueBornee(ARBRE, { haut: 1, bas: 1 })
    const deux = vueBornee(une, { haut: 1, bas: 1 })

    expect(gardees(deux)).toEqual(gardees(une))
  })
})
