#!/usr/bin/env node
/**
 * **Combien de cas le travail « Front » a-t-il SAUTÉS ?**
 *
 * ── Pourquoi ce script existe ───────────────────────────────────────────────
 *
 * Le travail API a sa porte « 2 bis » : elle relit la sortie JUnit et exige un
 * décompte, parce qu'un service PostgreSQL monté ne prouve rien si les cas qui en
 * dépendent continuent de se sauter. **Le travail front n'avait pas d'équivalent**,
 * et c'est exactement ce qu'un bloc gardé par une variable d'environnement
 * exploite : `describe.runIf(process.env.HERITAGE_GRAPHE_CLIENT !== undefined)`.
 *
 * Mesuré le 23/09/2026 : **23 cas** — 16 dans `ressorts.test.ts`, 7 dans
 * `insertionLocale.test.ts` — n'ont jamais tourné en intégration continue, parce
 * que `ci.yml` ne pose cette variable nulle part. Ils ne tombent pas : ils
 * **disparaissent du décompte**, et `vitest` rend zéro.
 *
 * Ce n'est pas théorique. En posant la variable sur un relevé servi, un cas échoue
 * sur `main` — `ressorts.test.ts:780`, « TOUTE FRATRIE D'UN COUPLE SÉPARÉ RESTE
 * ENTIÈRE », l'invariant d'où vient la plainte d'origine du commanditaire. Et le
 * même jour, une branche candidate à la fusion (`condition-fratries-voisines`) s'est
 * révélée en introduire **un second** — `insertionLocale.test.ts:647` —, alors
 * qu'elle rendait les cinq portes vertes. On était à un clic de fusionner une
 * régression en lisant du vert.
 *
 * ── Ce que cette porte exige, et ce qu'elle n'exige PAS ─────────────────────
 *
 * Elle **n'exige pas zéro saut**, et c'est délibéré. Les 23 cas demandent un relevé
 * du graphe servi, qui porte des personnes vivantes et **ne peut pas entrer dans le
 * dépôt** (ADR 0006). Exiger qu'ils tournent en CI aujourd'hui, ce serait nommer une
 * exigence qu'on ne peut pas satisfaire — la porte 2 bis de l'API refuse la même
 * chose pour `PlacementBaseImporteeTest`, et pour la même raison.
 *
 * Elle exige donc **un budget déclaré, et son exactitude** :
 *
 *   • un saut dans un fichier non budgété ..................... ROUGE
 *   • plus de sauts que le budget d'un fichier ................ ROUGE  (le 24ᵉ)
 *   • MOINS de sauts que le budget ............................ ROUGE  (budget périmé)
 *   • `HERITAGE_GRAPHE_CLIENT` posée et un seul saut subsiste .. ROUGE
 *
 * Le troisième cas est celui qu'on oublie : sans lui le budget vieillit en silence
 * et finit par autoriser des sauts que plus personne n'a décidés. Le quatrième est
 * ce qui fait de ce script autre chose qu'un constat — **le jour où un relevé
 * synthétique existera, poser la variable suffira, et la porte exigera d'elle-même
 * que les 23 aient réellement tourné.**
 *
 * ── Ce qui reste ouvert, et qu'il ne faut pas croire réglé ──────────────────
 *
 * Cette porte rend les 23 sauts **visibles et déclarés**. Elle ne les fait pas
 * tourner. Tant qu'aucun relevé de plus de cent nœuds portant les pathologies
 * réelles n'existe hors des données du commanditaire, ces 23 cas restent mesurés à
 * la main. Le dépôt n'a aujourd'hui qu'un `arbre-essai.ged` de 11 individus, très
 * en dessous du `expect(g.noeuds.length).toBeGreaterThan(100)` que le bloc s'impose
 * à lui-même.
 */

import { readFileSync } from 'node:fs'
import { relative, resolve, sep } from 'node:path'

const RACINE_APP = resolve(import.meta.dirname, '..', '..', 'app')
const RAPPORT = resolve(RACINE_APP, 'var', 'vitest.json')

/**
 * Les sauts autorisés, fichier par fichier, avec ce qui les lève.
 *
 * **Toute entrée doit nommer la condition qui la ferait tomber à zéro.** Un budget
 * sans condition est une permission perpétuelle.
 */
const BUDGET_DES_SAUTS = {
  // Vide depuis le 30/09/2026 : les 23 cas budgétés vivaient dans
  // `components/arbre/dispositions/` (`ressorts.test.ts`, 16, et
  // `insertionLocale.test.ts`, 7), retirés avec l'ancien arbre `/arbre/ancien`
  // qu'ils éprouvaient. Plus aucun saut n'est donc permis, nulle part.
}

/** Posée ⇒ le bloc gardé doit tourner, donc plus aucun saut n'est permis. */
const RELEVE = process.env.HERITAGE_GRAPHE_CLIENT

const problemes = []

function echouer(message) {
  process.stderr.write(`::error::${message}\n`)
  problemes.push(message)
}

let rapport
try {
  rapport = JSON.parse(readFileSync(RAPPORT, 'utf8'))
} catch (erreur) {
  process.stderr.write(
    `::error::Rapport introuvable ou illisible : ${RAPPORT} (${erreur.message}). ` +
      'La porte 4 doit écrire son rapport JSON AVANT celle-ci ; sans lui, on ne sait ' +
      'pas combien de cas ont tourné, et « on ne croit pas un harnais sur parole ».\n',
  )
  process.exit(1)
}

// Un rapport vide se lirait comme « zéro saut », donc comme un succès. C'est la
// forme d'échec que toute cette porte combat : on exige d'abord qu'il ait mesuré.
if (!Number.isInteger(rapport.numTotalTests) || rapport.numTotalTests < 1) {
  echouer("Le rapport ne déclare aucun cas exécuté : il n'y a rien à vérifier.")
}

if (rapport.numFailedTests > 0 || rapport.success === false) {
  echouer(`${rapport.numFailedTests} cas en échec — la porte 4 aurait dû rougir avant celle-ci.`)
}

/** Les sauts observés, regroupés par chemin de fichier relatif à `app/`. */
const observes = new Map()
for (const fichier of rapport.testResults ?? []) {
  const chemin = relative(RACINE_APP, fichier.name).split(sep).join('/')
  const sautes = (fichier.assertionResults ?? []).filter(
    (cas) => cas.status === 'skipped' || cas.status === 'pending' || cas.status === 'todo',
  )
  if (sautes.length > 0) observes.set(chemin, sautes)
}

const budget = RELEVE === undefined ? BUDGET_DES_SAUTS : {}

if (RELEVE !== undefined) {
  process.stdout.write(
    `HERITAGE_GRAPHE_CLIENT est posée (${RELEVE}) : le bloc « sur le graphe réel » ` +
      'doit tourner, aucun saut n’est permis.\n',
  )
}

// 1. Un saut là où rien ne l'autorise — le cas du 24ᵉ.
for (const [chemin, sautes] of observes) {
  const permis = budget[chemin] ?? 0
  if (sautes.length > permis) {
    const noms = sautes.map((c) => `      · ${c.fullName}`).join('\n')
    echouer(
      `${chemin} : ${sautes.length} cas sautés, ${permis} budgété(s). ` +
        `Un saut non déclaré ne rougit nulle part ailleurs.\n${noms}`,
    )
  }
}

// 2. Un budget que plus rien ne consomme — le cas qu'on oublie.
for (const [chemin, permis] of Object.entries(budget)) {
  const vus = observes.get(chemin)?.length ?? 0
  if (vus < permis) {
    echouer(
      `${chemin} : ${vus} cas sautés pour ${permis} budgété(s). Le budget est périmé — ` +
        'le corriger ici, sinon il autorisera demain des sauts que personne n’a décidés.',
    )
  }
}

const totalSautes = [...observes.values()].reduce((n, s) => n + s.length, 0)
const totalBudgete = Object.values(budget).reduce((n, v) => n + v, 0)

if (problemes.length > 0) {
  process.stderr.write(
    `\n${problemes.length} problème(s). Cas exécutés : ${rapport.numTotalTests}, ` +
      `sautés : ${totalSautes}, budgétés : ${totalBudgete}.\n`,
  )
  process.exit(1)
}

process.stdout.write(
  `Front : ${rapport.numTotalTests} cas, ${rapport.numPassedTests} passés, ` +
    `${totalSautes} sautés — tous déclarés (${totalBudgete} budgétés).\n`,
)
