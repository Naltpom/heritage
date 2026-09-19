import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudGraphe, NoeudPersonne } from '@/lib/api'
import { indexer, tronc } from './graphe'

/**
 * Le **tronc** du lecteur — ce que le téléphone cadre au premier affichage.
 *
 * ```
 *        f2 (p5 × p6)
 *             │
 *            p2 ──── f1 ──── p3
 *                     │
 *              ┌──────┴──────┐
 *           p1 (ego)        p4          f3 (p1 × p7) → p8
 * ```
 */
function personne(id: string): NoeudPersonne {
  return { id, type: 'personne', personId: Number(id.slice(1)), x: 0, y: 0, w: 120, h: 40 } as unknown as NoeudPersonne
}

function famille(id: string): NoeudFamille {
  return { id, type: 'famille', familyId: Number(id.slice(1)), x: 0, y: 0, w: 22, h: 14 } as unknown as NoeudFamille
}

function grapheEssai(): Graphe {
  const noeuds: NoeudGraphe[] = [
    personne('p1'), personne('p2'), personne('p3'), personne('p4'),
    personne('p5'), personne('p6'), personne('p7'), personne('p8'),
    famille('f1'), famille('f2'), famille('f3'),
  ]
  const aretes: AreteGraphe[] = [
    { de: 'p2', vers: 'f1', genre: 'union', divorce: false },
    { de: 'p3', vers: 'f1', genre: 'union', divorce: false },
    { de: 'f1', vers: 'p1', genre: 'filiation' },
    { de: 'f1', vers: 'p4', genre: 'filiation' },
    { de: 'p5', vers: 'f2', genre: 'union', divorce: false },
    { de: 'p6', vers: 'f2', genre: 'union', divorce: false },
    { de: 'f2', vers: 'p2', genre: 'filiation' },
    { de: 'p1', vers: 'f3', genre: 'union', divorce: false },
    { de: 'p7', vers: 'f3', genre: 'union', divorce: false },
    { de: 'f3', vers: 'p8', genre: 'filiation' },
  ]
  return {
    empreinte: '0'.repeat(64),
    ego: 'p1',
    boite: { x: 0, y: 0, w: 1, h: 1 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

describe('tronc', () => {
  const index = indexer(grapheEssai())

  it('contient la personne, ses parents, sa fratrie, son conjoint et ses enfants', () => {
    expect([...tronc(index, 'p1')].sort()).toEqual(['p1', 'p2', 'p3', 'p4', 'p7', 'p8'])
  })

  it('exclut les grands-parents : le tronc s’arrête à deux sauts', () => {
    const t = tronc(index, 'p1')
    expect(t).not.toContain('p5')
    expect(t).not.toContain('p6')
  })

  it('est vide pour une personne inconnue — le canevas garde alors l’arbre entier', () => {
    expect(tronc(index, 'inconnu')).toEqual([])
  })
})

describe('le premier cadrage sur téléphone', () => {
  const source = (chemin: string): string => readFileSync(resolve(__dirname, chemin), 'utf8')

  it('est demandé par la vue selon la largeur d’écran, et par rien d’autre', () => {
    expect(source('../../views/ArbreView.vue')).toContain(`:cadrage-initial="estMobile ? 'tronc' : 'tout'"`)
  })

  it('est appliqué par le canevas à la construction, après l’arbre entier', () => {
    const canevas = source('./CanevasArbre.vue')
    expect(canevas).toMatch(
      /recadrer\(\)\s*\n\s*const cible = cadrageInitial === 'tronc' \? tronc\(pilote\.value\.index, graphe\.ego\) : \[\]\s*\n\s*if \(cible\.length > 0\) cadrerQuandLeCadreAUneTaille\(cible\)/,
    )
  })

  it('attend que le cadre ait une taille — la vue tient le canevas sous `v-show`', () => {
    // Le `watch` sur le graphe court après le rendu, sinon il mesure un cadre en
    // `display: none` ; et le cadrage se fait à la première taille non nulle.
    const canevas = source('./CanevasArbre.vue')
    expect(canevas).toMatch(/construire\(\)\s*\n\s*},\s*\n\s*\{ flush: 'post' \},/)
    expect(canevas).toContain('new ResizeObserver(')
    expect(source('../../views/ArbreView.vue')).toMatch(/<CanevasArbre\s*\n\s*v-show="graphe !== null"/)
  })
})
