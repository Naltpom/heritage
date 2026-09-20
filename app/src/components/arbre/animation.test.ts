/**
 * ════════════════════════════════════════════════════════════════════
 * **CE QUI EST ÉPROUVÉ : QUE L'ANIMATION FINIT, ET QU'ELLE FINIT AU BON ENDROIT.**
 *
 * Une animation qui s'arrête à mi-chemin laisse un arbre faux à l'écran, et le
 * défaut ne se voit pas — il ressemble à une mise en page. Les trois gardes :
 *
 * 1. **elle se termine** — nombre de pas borné par la durée, pas par une
 *    condition à atteindre ;
 * 2. **elle finit sur l'état CONSTRUIT**, pas sur son dernier pas : la dernière
 *    chose qu'elle fait est d'effacer les `transform` ;
 * 3. **annulée, elle finit quand même au bon endroit** — un changement de route
 *    ne doit pas laisser l'arbre de travers.
 *
 * L'horloge est injectée : aucun `requestAnimationFrame` réel, donc un test qui
 * ne dépend ni du temps qui passe ni d'un navigateur.
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { Graphe, NoeudGraphe } from '@/lib/api'
import type { PiloteCanevas } from './canevas'
import { adoucir, animerLArrivee, interpoler } from './animation'

function noeud(id: string, x: number, y = 0): NoeudGraphe {
  return { type: 'personne', id, x, y, w: 100, h: 40 } as unknown as NoeudGraphe
}

function graphe(noeuds: NoeudGraphe[]): Graphe {
  return { empreinte: 'e', ego: 'A', boite: { x: 0, y: 0, w: 0, h: 0 }, noeuds, aretes: [] } as unknown as Graphe
}

/** Un pilote de théâtre : il n'écrit nulle part, il note ce qu'on lui demande. */
function piloteEspion(): PiloteCanevas & { vus: number[][]; figeages: number } {
  const vus: number[][] = []
  let figeages = 0

  return {
    appliquerPositions: (g: Graphe) => vus.push(g.noeuds.map((n) => n.x)),
    figerLesPositions: () => {
      figeages += 1
    },
    get vus() {
      return vus
    },
    get figeages() {
      return figeages
    },
  } as unknown as PiloteCanevas & { vus: number[][]; figeages: number }
}

/** Une horloge de théâtre : les images n'arrivent que lorsqu'on les appelle. */
function horlogeDeTheatre(mouvementReduit = false) {
  const files: ((instant: number) => void)[] = []
  let annulations = 0

  return {
    horloge: {
      maintenant: () => 0,
      prochaineImage: (suite: (instant: number) => void) => {
        files.push(suite)
        return files.length
      },
      annuler: () => {
        annulations += 1
      },
      mouvementReduit: () => mouvementReduit,
    },
    /** Joue une image à l'instant donné. */
    jouer(instant: number): boolean {
      const suite = files.shift()
      if (suite === undefined) return false
      suite(instant)
      return true
    },
    get annulations() {
      return annulations
    },
  }
}

/* ------------------------------------------------------------------ */

describe('l’adoucissement', () => {
  it('part de zéro et arrive à un', () => {
    expect(adoucir(0)).toBe(0)
    expect(adoucir(1)).toBe(1)
  })

  it('est vif au départ et lent à l’arrivée', () => {
    expect(adoucir(0.5)).toBeGreaterThan(0.5)
    expect(adoucir(0.9)).toBeGreaterThan(0.99)
  })

  it('borne une horloge qui saute, dans les deux sens', () => {
    expect(adoucir(-3)).toBe(0)
    expect(adoucir(12)).toBe(1)
  })

  it('croît sans jamais reculer', () => {
    let precedent = -1
    for (let t = 0; t <= 1.0001; t += 0.05) {
      const v = adoucir(t)
      expect(v).toBeGreaterThanOrEqual(precedent)
      precedent = v
    }
  })
})

describe('l’interpolation', () => {
  const depart = graphe([noeud('A', 0), noeud('B', 100)])
  const arrivee = graphe([noeud('A', 200), noeud('B', 100)])

  it('rend le DÉPART à t = 0', () => {
    expect(interpoler(depart, arrivee, 0).noeuds.map((n) => n.x)).toEqual([0, 100])
  })

  it('rend l’ARRIVÉE à t = 1', () => {
    expect(interpoler(depart, arrivee, 1).noeuds.map((n) => n.x)).toEqual([200, 100])
  })

  it('reste strictement entre les deux au milieu', () => {
    const x = interpoler(depart, arrivee, 0.5).noeuds[0]!.x
    expect(x).toBeGreaterThan(0)
    expect(x).toBeLessThan(200)
  })

  it('ne touche JAMAIS aux ordonnées', () => {
    const hauts = graphe([noeud('A', 0, 150)])
    const bas = graphe([noeud('A', 200, 150)])
    expect(interpoler(hauts, bas, 0.5).noeuds[0]!.y).toBe(150)
  })

  it('laisse en place un nœud que le départ ne connaît pas', () => {
    const neuf = graphe([noeud('A', 0), noeud('NEUF', 999)])
    expect(interpoler(depart, neuf, 0).noeuds[1]!.x).toBe(999)
  })

  it('ne mute pas les graphes reçus', () => {
    const copie = JSON.parse(JSON.stringify(depart))
    interpoler(depart, arrivee, 0.5)
    expect(depart).toEqual(copie)
  })
})

describe('l’arrivée', () => {
  const depart = graphe([noeud('A', 0)])
  const arrivee = graphe([noeud('A', 300)])

  it('SE TERMINE, et sa dernière action est de figer', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre()

    animerLArrivee(pilote, depart, arrivee, scene.horloge, 1000)

    let images = 0
    for (let t = 0; t <= 1200; t += 100) {
      if (!scene.jouer(t)) break
      images += 1
    }

    expect(images).toBeLessThan(20) // borne : elle ne tourne pas indéfiniment
    expect(pilote.figeages).toBe(1)
    expect(scene.jouer(9999)).toBe(false) // plus rien n'est en file
  })

  it('ne dépasse jamais l’arrivée en chemin', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre()

    animerLArrivee(pilote, depart, arrivee, scene.horloge, 1000)
    for (let t = 0; t <= 1200; t += 100) if (!scene.jouer(t)) break

    for (const [x] of pilote.vus) {
      expect(x).toBeGreaterThanOrEqual(0)
      expect(x).toBeLessThanOrEqual(300)
    }
  })

  it('pose la PREMIÈRE image au départ, pas à l’arrivée', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre()

    animerLArrivee(pilote, depart, arrivee, scene.horloge, 1000)

    expect(pilote.vus[0]).toEqual([0])
  })

  it('ANNULÉE, elle fige quand même — jamais d’arbre arrêté au tiers', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre()

    const arreter = animerLArrivee(pilote, depart, arrivee, scene.horloge, 1000)
    scene.jouer(300)
    arreter()

    expect(pilote.figeages).toBe(1)
    expect(scene.annulations).toBe(1)
  })

  it('annulée DEUX fois, ne fige qu’une', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre()

    const arreter = animerLArrivee(pilote, depart, arrivee, scene.horloge, 1000)
    arreter()
    arreter()

    expect(pilote.figeages).toBe(1)
  })

  it('n’écrit plus rien après l’annulation', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre()

    const arreter = animerLArrivee(pilote, depart, arrivee, scene.horloge, 1000)
    arreter()
    const apres = pilote.vus.length
    scene.jouer(500)

    expect(pilote.vus.length).toBe(apres)
  })

  it('NE FAIT RIEN quand le lecteur a demandé moins de mouvement', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre(true)

    animerLArrivee(pilote, depart, arrivee, scene.horloge, 1000)

    // Ni image posée, ni figeage : le canevas est déjà dans son état final.
    expect(pilote.vus).toEqual([])
    expect(pilote.figeages).toBe(0)
  })

  it('NE FAIT RIEN pour une durée nulle', () => {
    const pilote = piloteEspion()
    const scene = horlogeDeTheatre()

    animerLArrivee(pilote, depart, arrivee, scene.horloge, 0)

    expect(pilote.vus).toEqual([])
  })
})
