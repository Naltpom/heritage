/**
 * ════════════════════════════════════════════════════════════════════
 * LA MINIMISATION DE CROISEMENTS NE REND JAMAIS UN ARBRE PIRE.
 *
 * C'est la seule promesse qu'un algorithme de ce genre peut tenir, et c'est
 * précisément celle qu'on oublie de vérifier. Les balayages de barycentre
 * **oscillent** : la suite n'est pas monotone, et garder le dernier ordre au lieu
 * du meilleur rencontré produit régulièrement un résultat moins bon que le point
 * de départ — sans que rien ne le signale, puisque l'arbre a changé de forme et
 * qu'on n'a plus l'ancienne sous les yeux.
 *
 * D'où la garde centrale : **le nombre de croisements ne remonte jamais.**
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { compterLesCroisements, croisementsMinimises } from './croisements'
import { mesurerLisibilite, OPTIONS_PAR_DEFAUT } from './socle'

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

function graphe(noeuds: NoeudGraphe[], aretes: AreteGraphe[]): Graphe {
  return {
    empreinte: 'a'.repeat(64),
    ego: noeuds[0]?.id ?? 'p1',
    boite: { x: -1000, y: -1000, w: 4000, h: 2000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  } as unknown as Graphe
}

/**
 * Deux couples qui se croisent, et **aucun des quatre n'est rapporté** : c'est le
 * cas que la disposition « conjoints accolés » refuse de toucher, parce qu'aucun
 * échange local ne peut le résoudre.
 *
 * ```
 *  -150   pA1 pA2          pB1 pB2        les deux couples de parents
 *     0   a1   b1   a2   b2               a1,a2 enfants de A ; b1,b2 de B
 *         └── a1 × b2 ──┘
 *              └── b1 × a2 ──┘
 * ```
 */
const CROISE = graphe(
  [
    personne('pA1', 0, -150),
    personne('pA2', 120, -150),
    personne('pB1', 400, -150),
    personne('pB2', 520, -150),
    famille('fA', 60, -104),
    famille('fB', 460, -104),
    personne('a1', 0, 0),
    personne('b1', 120, 0),
    personne('a2', 240, 0),
    personne('b2', 360, 0),
    famille('fAB1', 180, 46),
    famille('fAB2', 180, 46),
  ],
  [
    u('pA1', 'fA'),
    u('pA2', 'fA'),
    f('fA', 'a1'),
    f('fA', 'a2'),
    u('pB1', 'fB'),
    u('pB2', 'fB'),
    f('fB', 'b1'),
    f('fB', 'b2'),
    u('a1', 'fAB1'),
    u('b2', 'fAB1'),
    u('b1', 'fAB2'),
    u('a2', 'fAB2'),
  ],
)

describe('la promesse centrale', () => {
  it('ne remonte jamais le nombre de croisements', () => {
    /*
     * LA GARDE. Les balayages oscillent : garder le dernier ordre au lieu du
     * meilleur rencontré produit régulièrement un résultat moins bon que le point
     * de départ, et rien ne le signale puisque l'arbre a changé de forme.
     */
    const avant = compterLesCroisements(CROISE)
    const apres = compterLesCroisements(croisementsMinimises(CROISE, OPTIONS_PAR_DEFAUT))

    expect(apres).toBeLessThanOrEqual(avant)
  })

  it('rapproche des couples que nul échange local ne pourrait rapprocher', () => {
    /* Les quatre époux sont enfants d'une famille de l'arbre : c'est exactement
       ce que « conjoints accolés » laisse en place, faute de pouvoir déplacer
       quiconque sans le détacher de sa fratrie. */
    const avant = mesurerLisibilite(CROISE)
    const apres = mesurerLisibilite(croisementsMinimises(CROISE, OPTIONS_PAR_DEFAUT))

    expect(avant.separes).toBeGreaterThan(0)
    expect(apres.accoles).toBeGreaterThanOrEqual(avant.accoles)
  })
})

describe('ce qu’elle ne touche pas', () => {
  it('ne change aucune ordonnée : les générations sont une propriété de la topologie', () => {
    const avant = new Map(CROISE.noeuds.map((n) => [n.id, n.y]))

    for (const noeud of croisementsMinimises(CROISE, OPTIONS_PAR_DEFAUT).noeuds) {
      expect(noeud.y, noeud.id).toBe(avant.get(noeud.id))
    }
  })

  it('n’ajoute ni ne retire aucun nœud ni aucune arête', () => {
    const apres = croisementsMinimises(CROISE, OPTIONS_PAR_DEFAUT)

    expect(apres.noeuds).toHaveLength(CROISE.noeuds.length)
    expect(apres.aretes).toEqual(CROISE.aretes)
  })

  it('recalcule la boîte pour qu’elle contienne tout', () => {
    const apres = croisementsMinimises(CROISE, OPTIONS_PAR_DEFAUT)

    for (const noeud of apres.noeuds) {
      expect(noeud.x - noeud.w / 2).toBeGreaterThanOrEqual(apres.boite.x)
      expect(noeud.x + noeud.w / 2).toBeLessThanOrEqual(apres.boite.x + apres.boite.w)
    }
  })
})

describe('les écarts', () => {
  it('ne laisse jamais deux fiches d’une rangée se chevaucher', () => {
    /* Le tassement se fait dans les deux sens, faute de quoi une couche dérive
       vers la droite à chaque passe et le résultat dépend du nombre d'itérations
       plutôt que de la structure. */
    const apres = croisementsMinimises(CROISE, OPTIONS_PAR_DEFAUT)
    const parY = new Map<number, NoeudGraphe[]>()

    for (const noeud of apres.noeuds) {
      const couche = parY.get(noeud.y)
      if (couche === undefined) parY.set(noeud.y, [noeud])
      else couche.push(noeud)
    }

    for (const couche of parY.values()) {
      const triee = [...couche].sort((a, b) => a.x - b.x)

      for (let i = 1; i < triee.length; i += 1) {
        const gauche = triee[i - 1]!
        const droite = triee[i]!

        expect(
          droite.x - droite.w / 2,
          `${gauche.id} et ${droite.id} se chevauchent`,
        ).toBeGreaterThanOrEqual(gauche.x + gauche.w / 2 - 0.001)
      }
    }
  })
})

describe('les cas dégénérés', () => {
  it('rend tel quel un graphe d’une seule couche', () => {
    const plat = graphe([personne('a', 0, 0), personne('b', 120, 0)], [])

    expect(croisementsMinimises(plat, OPTIONS_PAR_DEFAUT).noeuds.map((n) => n.x)).toEqual([0, 120])
  })

  it('traverse un graphe vide', () => {
    expect(croisementsMinimises(graphe([], []), OPTIONS_PAR_DEFAUT).noeuds).toEqual([])
  })

  it('est stable : rejouée sur son résultat, elle ne dégrade pas', () => {
    /* Elle n'est pas idempotente — un algorithme de tassement ne l'est jamais
       tout à fait —, mais elle ne doit pas osciller entre deux formes dont l'une
       serait pire. */
    const une = croisementsMinimises(CROISE, OPTIONS_PAR_DEFAUT)
    const deux = croisementsMinimises(une, OPTIONS_PAR_DEFAUT)

    expect(compterLesCroisements(deux)).toBeLessThanOrEqual(compterLesCroisements(une))
  })
})
