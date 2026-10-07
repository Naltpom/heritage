/**
 * **Le banc de `disposerLignees`** : instantanés à l'octet, et chronométrage.
 *
 * Hors de `vitest` (le nom ne finit pas en `.test.ts`) : à 10 000 personnes, une
 * combinaison prend plusieurs secondes, et la suite par défaut doit rester rapide. Il se
 * lance à la main, depuis `app/` :
 *
 *     npx vite-node src/histoire/banc/lignees.banc.ts -- instantanes <dossier>
 *     npx vite-node src/histoire/banc/lignees.banc.ts -- verifier <dossier>
 *     npx vite-node src/histoire/banc/lignees.banc.ts -- chrono
 *
 * **La règle qu'il fait tenir** : une optimisation de `lignees.ts` ne change RIEN au
 * résultat. Pas « à peu près » : la disposition sérialisée (toutes les fiches, tous les
 * traits, toutes les étiquettes, dans leur ordre) doit avoir la même empreinte SHA-256
 * avant et après, pour chaque arbre et chaque combinaison de bascules. `instantanes`
 * relève ces empreintes sur la version de référence ; `verifier` les recalcule sur la
 * version courante et nomme le premier écart.
 *
 * Les arbres : des généalogies synthétiques à graine (`genealogie.ts` — 1 000, 4 000 et
 * 10 000 personnes, avec remariages, alliances, îlots, divorces), et, si
 * `BANC_CAPTURES` nomme un dossier, les graphes que les tests de l'application passent à
 * `disposerLignees` (un fichier `{ graphe, options }` par appel, en JSON ; on les relève
 * une fois avec un `writeFileSync` provisoire en tête de `disposerLignees`, le temps d'un
 * `npx vitest run src/histoire`, sans le garder). Chaque arbre passe par le MÊME chemin
 * que l'écran (`HistoireView.vue`, `disposer()`) : alliances, famille du conjoint,
 * personnes sans lien, quarts masqués, format des cartes, puis les options.
 */
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Graphe } from '@/lib/api'
import { sansFamillesParAlliance } from '../alliances'
import { sansBranches } from '../branches'
import { auFormat, type FormatDeCarte } from '../cartes'
import { disposerLignees, sansFamilleDuConjoint, type BrancheDeQuart, type OptionsDeDisposition } from '../lignees'
import { noeudsSansLien, sansPersonnesSansLien } from '../sansLien'
import { genealogieSynthetique } from './genealogie'

/** Les bascules de l'écran, toutes celles qui changent l'entrée de `disposerLignees`. */
interface Combinaison {
  alliances: boolean
  conjoint: boolean
  sansLien: boolean
  masquees: BrancheDeQuart[]
  format: FormatDeCarte
  dansLArbre: boolean
  fratries: boolean
}

function toutesLesCombinaisons(): Combinaison[] {
  const l: Combinaison[] = []
  for (const alliances of [false, true])
    for (const conjoint of [true, false])
      for (const sansLien of [false, true])
        for (const masquees of [[], ['pm'], ['mp', 'cpp']] as BrancheDeQuart[][])
          for (const format of ['compact', 'portrait'] as const)
            for (const dansLArbre of [false, true])
              for (const fratries of [false, true]) l.push({ alliances, conjoint, sansLien, masquees, format, dansLArbre, fratries })
  return l
}

const nomDe = (c: Combinaison): string =>
  [
    c.alliances ? 'alliances' : '-',
    c.conjoint ? 'conjoint' : '-',
    c.sansLien ? 'sansLien' : '-',
    c.masquees.join('+') || '-',
    c.format,
    c.dansLArbre ? 'dansLArbre' : 'aPart',
    c.fratries ? 'naissance' : 'exterieur',
  ].join(' ')

/** Le chemin de `HistoireView.vue` → `disposer()`, hors ligne seule. */
function disposerCommeLEcran(graphe: Graphe, c: Combinaison): ReturnType<typeof disposerLignees> {
  const sansAlliances = c.alliances ? graphe : sansFamillesParAlliance(graphe)
  const sansConjoint = c.conjoint ? sansAlliances : sansFamilleDuConjoint(sansAlliances)
  const avecLesLiens = c.sansLien ? sansConjoint : sansPersonnesSansLien(sansConjoint, noeudsSansLien(graphe))
  const filtre = sansBranches(avecLesLiens, c.masquees, graphe)
  return disposerLignees(auFormat(filtre, c.format), {
    alliances: c.dansLArbre ? 'dans-l-arbre' : 'a-part',
    fratriesParNaissance: c.fratries,
  })
}

/** Sérialisation complète et ordonnée : une `Map` devient la liste de ses entrées. */
function serialiser(valeur: unknown): string {
  return JSON.stringify(valeur, (_cle, v: unknown) => (v instanceof Map ? { __map: [...v] } : v instanceof Set ? { __set: [...v] } : v))
}
const empreinte = (s: string): string => createHash('sha256').update(s).digest('hex')

interface Cas {
  nom: string
  calculer: () => unknown
}

function lesCas(): Cas[] {
  const cas: Cas[] = []
  const toutes = toutesLesCombinaisons()
  // Les grands arbres ne passent que par un échantillon : chaque bascule y change au moins une fois.
  const echantillon = (n: number): Combinaison[] => toutes.filter((_, i) => i % n === 0 || i === toutes.length - 1)
  const arbres: [string, number, number, Combinaison[]][] = [
    ['synthetique-300', 300, 11, toutes],
    ['synthetique-1000', 1000, 7, toutes],
    ['synthetique-1000b', 1000, 2024, echantillon(3)],
    ['synthetique-4000', 4000, 42, echantillon(8)],
    ['synthetique-10000', 10000, 1789, echantillon(24)],
  ]
  for (const [nom, personnes, graine, combinaisons] of arbres) {
    const graphe = genealogieSynthetique({ personnes, graine })
    for (const c of combinaisons) cas.push({ nom: `${nom} | ${nomDe(c)}`, calculer: () => disposerCommeLEcran(graphe, c) })
  }
  // Les graphes des tests, tels que les tests les passent, puis sous chaque jeu d'options.
  const dossier = process.env['BANC_CAPTURES']
  if (dossier && existsSync(dossier)) {
    for (const fichier of readdirSync(dossier).filter((f) => f.endsWith('.json')).sort()) {
      const { graphe, options } = JSON.parse(readFileSync(join(dossier, fichier), 'utf8')) as {
        graphe: Graphe
        options: OptionsDeDisposition
      }
      cas.push({ nom: `${fichier} | tel quel`, calculer: () => disposerLignees(graphe, options) })
      for (const alliances of ['a-part', 'dans-l-arbre'] as const)
        for (const fratriesParNaissance of [false, true])
          cas.push({
            nom: `${fichier} | ${alliances} ${fratriesParNaissance ? 'naissance' : 'exterieur'}`,
            calculer: () => disposerLignees(graphe, { ...options, alliances, fratriesParNaissance }),
          })
    }
  }
  return cas
}

function instantanes(dossier: string): void {
  mkdirSync(dossier, { recursive: true })
  const empreintes: Record<string, string> = {}
  for (const c of lesCas()) empreintes[c.nom] = empreinte(serialiser(c.calculer()))
  writeFileSync(join(dossier, 'empreintes.json'), JSON.stringify(empreintes, null, 1))
  console.log(`${Object.keys(empreintes).length} instantanés écrits dans ${dossier}`)
}

function verifier(dossier: string): void {
  const attendues = JSON.parse(readFileSync(join(dossier, 'empreintes.json'), 'utf8')) as Record<string, string>
  let ecarts = 0
  let vus = 0
  for (const c of lesCas()) {
    const attendue = attendues[c.nom]
    if (attendue === undefined) {
      console.log(`SANS INSTANTANÉ  ${c.nom}`)
      ecarts++
      continue
    }
    vus++
    if (empreinte(serialiser(c.calculer())) !== attendue) {
      console.log(`ÉCART  ${c.nom}`)
      ecarts++
    }
  }
  if (vus !== Object.keys(attendues).length) {
    console.log(`${Object.keys(attendues).length - vus} instantanés sans cas correspondant`)
    ecarts++
  }
  console.log(ecarts ? `${ecarts} écart(s) sur ${vus} cas` : `${vus} cas identiques à l'octet`)
  if (ecarts) process.exitCode = 1
}

function chrono(): void {
  const parDefaut: Combinaison = {
    alliances: false,
    conjoint: true,
    sansLien: false,
    masquees: [],
    format: 'compact',
    dansLArbre: false,
    fratries: false,
  }
  const toutAffiche: Combinaison = { ...parDefaut, alliances: true, sansLien: true }
  for (const [personnes, graine] of [
    [4000, 42],
    [10000, 1789],
  ] as const) {
    const graphe = genealogieSynthetique({ personnes, graine })
    const n = graphe.noeuds.filter((x) => x.type === 'personne').length
    for (const [libelle, c] of [
      ['défaut', parDefaut],
      ['tout affiché', toutAffiche],
    ] as const) {
      const mesures: number[] = []
      // Un tour à blanc : le premier paie la compilation à la volée.
      let fiches = disposerCommeLEcran(graphe, c).fiches.length
      for (let i = 0; i < 7; i++) {
        const t0 = performance.now()
        fiches = disposerCommeLEcran(graphe, c).fiches.length
        mesures.push(performance.now() - t0)
      }
      mesures.sort((a, b) => a - b)
      console.log(
        `${n} personnes, ${libelle} (${fiches} fiches) : médiane ${mesures[3]!.toFixed(0)} ms (min ${mesures[0]!.toFixed(0)}, max ${mesures[6]!.toFixed(0)})`,
      )
    }
  }
}

const [commande, dossier] = process.argv.slice(2).filter((a) => a !== '--')
if (commande === 'instantanes' && dossier) instantanes(dossier)
else if (commande === 'verifier' && dossier) verifier(dossier)
else if (commande === 'chrono') chrono()
else console.log('usage : instantanes <dossier> | verifier <dossier> | chrono')
