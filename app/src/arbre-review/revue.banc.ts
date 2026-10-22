/**
 * **Le banc de `/arbre-review`** : la même mesure (`mesures.ts`) sur le dessin de
 * `/arbre` (`arbre2/groupes.ts`) et sur celui de `/arbre-review` (`disposition.ts`),
 * pour les mêmes arbres et les mêmes bascules, avec le temps de calcul.
 *
 * Hors de `vitest` (le nom ne finit pas en `.test.ts`) ; il se lance à la main, depuis
 * `app/` :
 *
 *     npx vite-node src/arbre-review/revue.banc.ts
 *
 * Les arbres : des généalogies synthétiques à graine (`histoire/banc/genealogie.ts`), et
 * — pour la mesure seulement — l'arbre réel, si `BANC_REEL` nomme un fichier de
 * STRUCTURE relevé hors du dépôt (ADR 0006 : ni le fichier ni ses résultats bruts n'y
 * entrent ; seuls des totaux agrégés se rapportent). Ce fichier ne porte aucun nom ni
 * aucune date complète : une ligne par personne (`P|id|sexe|génération|branche|année|
 * décédé|longueur du nom|longueur de la date de naissance|longueur de la date de décès`),
 * par famille (`F|id|mari|femme|divorcé`) et par filiation (`C|famille|enfant|rang`),
 * relevées en lecture seule (`SELECT`) sur `tree_person`, `tree_family`, `tree_child`.
 */
import { existsSync, readFileSync } from 'node:fs'
import type { Graphe } from '@/lib/api'
import { sansFamillesParAlliance } from '@/histoire/alliances'
import { genealogieSynthetique } from '@/histoire/banc/genealogie'
import { sansBranches } from '@/histoire/branches'
import { auFormat } from '@/histoire/cartes'
import { sansFamilleDuConjoint, type DispositionHistoire } from '@/histoire/lignees'
import { noeudsSansLien, sansPersonnesSansLien } from '@/histoire/sansLien'
import { disposerEnGroupes, type DispositionGroupes } from '@/arbre2/groupes'
import { disposerEnRevue, type ReglagesDuMoteur } from './disposition'
import type { VarianteDeSablier } from './ordre'
import { mesurer, type Mesures } from './mesures'
import { lireStructure } from './banc/structure'
import { brancherCommeLeServeur } from './banc/branches'

interface Bascules {
  alliances: boolean
  conjoint: boolean
  sansLien: boolean
  dansLArbre: boolean
  format: 'compact' | 'portrait'
}

/** Le chemin de l'écran (`ArbreGroupesView.vue › disposer`) : alliances, conjoint, sans lien, puis le format. */
function filtrer(graphe: Graphe, b: Bascules): Graphe {
  const a = b.alliances ? graphe : sansFamillesParAlliance(graphe)
  const c = b.conjoint ? a : sansFamilleDuConjoint(a)
  const s = b.sansLien ? c : sansPersonnesSansLien(c, noeudsSansLien(graphe))
  return auFormat(sansBranches(s, [], graphe), b.format)
}

type Moteur = (g: Graphe, o: { alliances: 'a-part' | 'dans-l-arbre' }) => DispositionHistoire & Partial<DispositionGroupes>

function chrono<T>(f: () => T): [T, number] {
  const t = performance.now()
  const r = f()
  return [r, performance.now() - t]
}

function mesurerMoteur(moteur: Moteur, g: Graphe, b: Bascules): Mesures & { temps: number } {
  const options = { alliances: b.dansLArbre ? ('dans-l-arbre' as const) : ('a-part' as const) }
  // Un premier passage à vide : le JIT, pour un temps qui dise l'algorithme et pas le démarrage.
  moteur(g, options)
  const [d, temps] = chrono(() => moteur(g, options))
  return { ...mesurer(d, d.groupes ?? []), temps: Math.round(temps) }
}

const COLONNES: [keyof Mesures | 'temps', string][] = [
  ['personnes', 'pers.'],
  ['croisements', 'crois.'],
  ['chevauchements', 'chev.'],
  ['contacts', 'cont.'],
  ['sousUneCarte', 'sous carte'],
  ['traverseUnGroupe', 'trav. gr.'],
  ['dansUnGroupeEtranger', 'gr. étr.'],
  ['decalageTotal', 'décal. tot.'],
  ['decalageMax', 'décal. max'],
  ['longueurTracee', 'long. tracée'],
  ['ecartMinFiches', 'écart min'],
  ['ecartMedianFiches', 'écart méd.'],
  ['ecartMinRangees', 'interl. min'],
  ['couplesSepares', 'couples sép.'],
  ['fratriesCoupees', 'fratr. coup.'],
  ['surface', 'surface Mpx²'],
  ['temps', 'ms'],
]

function ligne(nom: string, m: Mesures & { temps: number }): string {
  return [nom.padEnd(26), ...COLONNES.map(([k]) => String(m[k as keyof typeof m]).padStart(12))].join('')
}

/** `BANC_MOTEURS=arbre,review:sablier,review` : les variantes à comparer (mise au point). */
const moteurs: [string, Moteur][] = (process.env['BANC_MOTEURS'] ?? 'arbre,review').split(',').map((nom) => {
  if (nom === 'arbre') return [nom, disposerEnGroupes]
  // `review:sablier` : sans affinage ; `review::servi+naissance` : les départs essayés.
  const [, variante, departs] = nom.split(':')
  const reglages: ReglagesDuMoteur = {
    ...(variante === 'sablier' ? { affiner: false } : {}),
    ...(departs ? { departs: departs.split('+') as VarianteDeSablier[] } : {}),
  }
  return [nom, (g, o) => disposerEnRevue(g, o, reglages)]
})

const arbres: [string, Graphe][] = []
for (const [personnes, graine] of [
  [1000, 7],
  [4000, 42],
  [10000, 1789],
] as const)
  arbres.push([`synthétique ${personnes}`, brancherCommeLeServeur(genealogieSynthetique({ personnes, graine }))])
const reel = process.env['BANC_REEL']
if (reel && existsSync(reel)) arbres.push(['réel', lireStructure(readFileSync(reel, 'utf8'))])

const BASCULES: [string, Bascules][] = [
  ['défaut', { alliances: false, conjoint: true, sansLien: false, dansLArbre: false, format: 'compact' }],
  ['tout, à part', { alliances: true, conjoint: true, sansLien: true, dansLArbre: false, format: 'compact' }],
  ['portrait', { alliances: false, conjoint: true, sansLien: false, dansLArbre: false, format: 'portrait' }],
]

const filtreArbre = process.env['BANC_ARBRES']
const filtreBascules = process.env['BANC_BASCULES']
console.log(['arbre / bascules / moteur'.padEnd(26), ...COLONNES.map(([, t]) => t.padStart(12))].join(''))
for (const [nomArbre, graphe] of arbres) {
  if (filtreArbre && !nomArbre.includes(filtreArbre)) continue
  for (const [nomBascules, b] of BASCULES) {
    if (filtreBascules && !nomBascules.includes(filtreBascules)) continue
    const g = filtrer(graphe, b)
    for (const [nomMoteur, moteur] of moteurs) console.log(ligne(`${nomArbre.slice(0, 14)} ${nomBascules.slice(0, 6)} ${nomMoteur}`, mesurerMoteur(moteur, g, b)))
  }
}
