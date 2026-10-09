/**
 * **Le banc de `disposerEnGroupes`** (`/arbre-2`) : chronométrage, croisements et largeur,
 * comparés à `disposerLignees` (`/arbre`) sur les mêmes arbres et les mêmes bascules.
 *
 * Hors de `vitest` (le nom ne finit pas en `.test.ts`) ; il se lance à la main, depuis
 * `app/` :
 *
 *     npx vite-node src/arbre2/groupes.banc.ts
 *
 * Les croisements sont comptés de la même façon pour les deux moteurs : bande par bande,
 * les paires « milieu du couple → enfant » dont l'ordre s'inverse d'une rangée à l'autre.
 */
import type { Graphe } from '@/lib/api'
import { sansFamillesParAlliance } from '@/histoire/alliances'
import { genealogieSynthetique } from '@/histoire/banc/genealogie'
import { sansBranches } from '@/histoire/branches'
import { auFormat } from '@/histoire/cartes'
import { disposerLignees, sansFamilleDuConjoint, type DispositionHistoire } from '@/histoire/lignees'
import { noeudsSansLien, sansPersonnesSansLien } from '@/histoire/sansLien'
import { indexer } from '@/components/arbre/graphe'
import { compterCroisements } from './agencement'
import { disposerEnGroupes } from './groupes'

interface Bascules {
  alliances: boolean
  conjoint: boolean
  sansLien: boolean
  dansLArbre: boolean
}

function filtrer(graphe: Graphe, b: Bascules): Graphe {
  const a = b.alliances ? graphe : sansFamillesParAlliance(graphe)
  const c = b.conjoint ? a : sansFamilleDuConjoint(a)
  const s = b.sansLien ? c : sansPersonnesSansLien(c, noeudsSansLien(graphe))
  return auFormat(sansBranches(s, [], graphe), 'compact')
}

function croisements(graphe: Graphe, d: DispositionHistoire): number {
  const index = indexer(graphe)
  const parBande = new Map<number, { haut: number; bas: number }[]>()
  for (const f of index.familles) {
    const enfants = new Set(index.enfantsParFamille.get(f.id) ?? [])
    const ep = (index.membresParFamille.get(f.id) ?? []).filter((id) => !enfants.has(id)).map((id) => d.parId.get(id)).filter((x) => x !== undefined)
    if (!ep.length) continue
    const g = Math.max(...ep.map((e) => e.rangee))
    const haut = ep.reduce((s, e) => s + e.cx, 0) / ep.length
    for (const id of enfants) {
      const e = d.parId.get(id)
      if (!e || e.rangee !== g + 1) continue
      const l = parBande.get(g)
      if (l) l.push({ haut, bas: e.cx })
      else parBande.set(g, [{ haut, bas: e.cx }])
    }
  }
  let n = 0
  for (const l of parBande.values()) n += compterCroisements(l)
  return n
}

const chrono = <T>(f: () => T): [T, number] => {
  const t = performance.now()
  const r = f()
  return [r, performance.now() - t]
}

const lignes: string[] = []
for (const [personnes, graine] of [
  [1000, 7],
  [4000, 42],
  [10000, 1789],
] as const) {
  const graphe = genealogieSynthetique({ personnes, graine })
  for (const b of [
    { alliances: false, conjoint: true, sansLien: false, dansLArbre: false },
    { alliances: true, conjoint: true, sansLien: true, dansLArbre: false },
    { alliances: true, conjoint: true, sansLien: false, dansLArbre: true },
  ]) {
    const g = filtrer(graphe, b)
    const options = { alliances: b.dansLArbre ? ('dans-l-arbre' as const) : ('a-part' as const) }
    const [avant, tAvant] = chrono(() => disposerLignees(g, options))
    const [apres, tApres] = chrono(() => disposerEnGroupes(g, options))
    lignes.push(
      [
        `${personnes}`.padStart(6),
        `${b.alliances ? 'alliances' : '-'}/${b.sansLien ? 'sansLien' : '-'}/${b.dansLArbre ? 'dansLArbre' : 'aPart'}`.padEnd(30),
        `arbre ${tAvant.toFixed(0).padStart(5)} ms ${String(croisements(g, avant)).padStart(6)} crois. ${(avant.largeur / 1000).toFixed(0).padStart(5)} k px`,
        `arbre-2 ${tApres.toFixed(0).padStart(5)} ms ${String(croisements(g, apres)).padStart(6)} crois. ${(apres.largeur / 1000).toFixed(0).padStart(5)} k px  ${apres.groupes.length} rect.`,
      ].join(' │ '),
    )
  }
}
console.log(lignes.join('\n'))
