/**
 * Explorer › Tableau — la logique de l'écran, sans Vue : colonnes, filtres, tri, export CSV.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Tout se fait ici, sur la charge du serveur, et seulement sur elle.** Le serveur
 * (`GET /api/arbre/tableau`, § 5.5 ter) a déjà retiré les vivants hors cercle et réduit
 * les naissances à ce que la réciprocité permet : filtrer, trier ou exporter ne peut donc
 * rien révéler de plus que ce que l'écran affiche. Le CSV est fait des **mêmes** valeurs
 * que les cellules — jamais d'une clé que l'écran ne montre pas.
 *
 * **La syntaxe d'un filtre de colonne** — une case de texte, comme phpMyAdmin :
 *
 * | Saisie | Texte | Nombre, date (année) |
 * |---|---|---|
 * | `abc` / `1900` | contient | égal |
 * | `=abc` | égal | égal |
 * | `!abc` | ne contient pas | différent |
 * | `>1900`, `<=3` | — | comparaison |
 * | `1850-1900` | — | entre, bornes comprises |
 * | `=` | vide | vide |
 * | `!` | renseigné | renseigné |
 *
 * Les colonnes à choix (sexe, branche…) prennent une liste à la place de la case.
 * ────────────────────────────────────────────────────────────────────
 */
import type { LigneDuTableau } from '@/lib/api'
import { coteDeBranche } from '@/components/statistiques/format'
import { generationLisible } from '@/components/personne/champs'

export type TypeDeColonne = 'texte' | 'nombre' | 'date' | 'choix'

export interface Choix {
  valeur: string
  libelle: string
}

export interface Colonne {
  cle: string
  libelle: string
  type: TypeDeColonne
  /** Ce que la cellule affiche et ce que le CSV porte ; `null` : vide. */
  texte: (ligne: LigneDuTableau) => string | null
  /**
   * Ce sur quoi on trie et on filtre : un nombre pour `nombre` et `date` (l'année), la
   * valeur brute pour `choix`, le texte replié sinon. Par défaut, `texte`.
   */
  valeur?: (ligne: LigneDuTableau) => string | number | null
  /** Pour `date` : la clé de tri complète (`AAAA-MM-JJ`), plus fine que l'année. */
  tri?: (ligne: LigneDuTableau) => string | null
  choix?: readonly Choix[]
  /** Affichée sans rien régler. */
  parDefaut: boolean
  /** Aligne à droite (chiffres). */
  aDroite?: boolean
}

/** `"1901-01-01"` → 1901 ; une date inconnue → `null`. */
function annee(cle: string | null): number | null {
  if (cle === null) return null
  const n = Number.parseInt(cle.slice(0, 4), 10)
  return Number.isNaN(n) ? null : n
}

function liste(valeurs: readonly string[]): string | null {
  return valeurs.length === 0 ? null : valeurs.join(', ')
}

/** « 27/10/2026 » — une horodatation du serveur, en date courte. */
export function dateCourte(iso: string): string {
  const [a, m, j] = iso.slice(0, 10).split('-')
  return a !== undefined && m !== undefined && j !== undefined ? `${j}/${m}/${a}` : iso
}

const SEXES: readonly Choix[] = [
  { valeur: 'masculin', libelle: 'Homme' },
  { valeur: 'feminin', libelle: 'Femme' },
  { valeur: 'inconnu', libelle: 'Inconnu' },
]

const ETATS: readonly Choix[] = [
  { valeur: 'vivant', libelle: 'Vivant' },
  { valeur: 'decede', libelle: 'Décédé' },
]

const BRANCHES: readonly Choix[] = (['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm'] as const).map((b) => ({
  valeur: b,
  libelle: coteDeBranche(b),
}))

/** Les libellés du `QUAY`, ceux de la fiche (`CitationQuality::label()` côté serveur). */
const QUALITES: readonly Choix[] = [
  { valeur: '3', libelle: '3 · preuve directe' },
  { valeur: '2', libelle: '2 · acte ou registre' },
  { valeur: '1', libelle: '1 · index ou déduction' },
  { valeur: '0', libelle: '0 · recopie non vérifiée' },
]

const VERIFICATION: readonly Choix[] = [
  { valeur: 'oui', libelle: 'Vérifiée' },
  { valeur: 'non', libelle: 'Non vérifiée' },
]

function libelleDe(choix: readonly Choix[], valeur: string | null): string | null {
  if (valeur === null) return null
  return choix.find((c) => c.valeur === valeur)?.libelle ?? valeur
}

/** « Vérifiée » veut dire *appuyée sur un acte ou un registre* — `QUAY >= 2`, comme sur l'arbre. */
function verifiee(ligne: LigneDuTableau): string {
  return ligne.bestQuay !== null && ligne.bestQuay >= 2 ? 'oui' : 'non'
}

/** Les colonnes, dans l'ordre du tableau et du CSV. */
export const COLONNES: readonly Colonne[] = [
  { cle: 'id', libelle: 'N°', type: 'nombre', texte: (l) => String(l.personId), valeur: (l) => l.personId, parDefaut: true, aDroite: true },
  { cle: 'xref', libelle: 'Réf. GEDCOM', type: 'texte', texte: (l) => l.gedcomXref, parDefaut: false },
  { cle: 'nom', libelle: 'Nom affiché', type: 'texte', texte: (l) => l.displayName, parDefaut: true },
  { cle: 'patronyme', libelle: 'Patronyme', type: 'texte', texte: (l) => l.surname || null, parDefaut: false },
  { cle: 'prenoms', libelle: 'Prénoms', type: 'texte', texte: (l) => l.givenNames || null, parDefaut: false },
  { cle: 'nomMarital', libelle: 'Nom d’usage', type: 'texte', texte: (l) => l.marriedName, parDefaut: false },
  { cle: 'surnom', libelle: 'Surnom', type: 'texte', texte: (l) => l.nickname, parDefaut: false },
  { cle: 'sexe', libelle: 'Sexe', type: 'choix', choix: SEXES, texte: (l) => libelleDe(SEXES, l.sex), valeur: (l) => l.sex, parDefaut: true },
  {
    cle: 'etat',
    libelle: 'État',
    type: 'choix',
    choix: ETATS,
    texte: (l) => (l.vivant ? 'Vivant' : 'Décédé'),
    valeur: (l) => (l.vivant ? 'vivant' : 'decede'),
    parDefaut: true,
  },
  {
    cle: 'naissance',
    libelle: 'Naissance',
    type: 'date',
    texte: (l) => l.naissance,
    valeur: (l) => annee(l.naissanceTri),
    tri: (l) => l.naissanceTri,
    parDefaut: true,
  },
  { cle: 'lieuNaissance', libelle: 'Lieu de naissance', type: 'texte', texte: (l) => l.lieuNaissance, parDefaut: true },
  { cle: 'deces', libelle: 'Décès', type: 'date', texte: (l) => l.deces, valeur: (l) => annee(l.decesTri), tri: (l) => l.decesTri, parDefaut: true },
  { cle: 'lieuDeces', libelle: 'Lieu de décès', type: 'texte', texte: (l) => l.lieuDeces, parDefaut: true },
  {
    cle: 'generation',
    libelle: 'Génération',
    type: 'nombre',
    texte: (l) => (l.generation === null ? null : generationLisible(l.generation)),
    valeur: (l) => l.generation,
    parDefaut: true,
    aDroite: true,
  },
  {
    cle: 'branche',
    libelle: 'Branche',
    type: 'choix',
    choix: BRANCHES,
    texte: (l) => libelleDe(BRANCHES, l.branch),
    valeur: (l) => l.branch,
    parDefaut: false,
  },
  { cle: 'parente', libelle: 'Lien avec moi', type: 'texte', texte: (l) => l.parente, parDefaut: true },
  { cle: 'professions', libelle: 'Professions', type: 'texte', texte: (l) => liste(l.professions), parDefaut: true },
  { cle: 'parents', libelle: 'Parents', type: 'texte', texte: (l) => liste(l.parents.map((p) => p.displayName)), parDefaut: true },
  { cle: 'conjoints', libelle: 'Conjoints', type: 'texte', texte: (l) => liste(l.conjoints.map((p) => p.displayName)), parDefaut: true },
  { cle: 'enfants', libelle: 'Enfants', type: 'nombre', texte: (l) => String(l.enfants), valeur: (l) => l.enfants, parDefaut: true, aDroite: true },
  { cle: 'sources', libelle: 'Sources', type: 'nombre', texte: (l) => String(l.citationCount), valeur: (l) => l.citationCount, parDefaut: true, aDroite: true },
  {
    cle: 'qualite',
    libelle: 'Meilleure source',
    type: 'choix',
    choix: QUALITES,
    texte: (l) => libelleDe(QUALITES, l.bestQuay === null ? null : String(l.bestQuay)),
    valeur: (l) => (l.bestQuay === null ? null : String(l.bestQuay)),
    parDefaut: false,
  },
  {
    cle: 'verifiee',
    libelle: 'Vérifiée',
    type: 'choix',
    choix: VERIFICATION,
    texte: (l) => libelleDe(VERIFICATION, verifiee(l)),
    valeur: verifiee,
    parDefaut: true,
  },
  { cle: 'creee', libelle: 'Ajoutée le', type: 'date', texte: (l) => dateCourte(l.createdAt), valeur: (l) => annee(l.createdAt), tri: (l) => l.createdAt, parDefaut: false },
  { cle: 'modifiee', libelle: 'Modifiée le', type: 'date', texte: (l) => dateCourte(l.updatedAt), valeur: (l) => annee(l.updatedAt), tri: (l) => l.updatedAt, parDefaut: false },
]

export const COLONNES_PAR_DEFAUT: readonly string[] = COLONNES.filter((c) => c.parDefaut).map((c) => c.cle)

/** Sans accents, en minuscules : « Élise » se trouve en tapant « elise ». */
export function replier(texte: string): string {
  return texte.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim()
}

function valeurDe(colonne: Colonne, ligne: LigneDuTableau): string | number | null {
  return colonne.valeur !== undefined ? colonne.valeur(ligne) : colonne.texte(ligne)
}

// ── Filtres ──────────────────────────────────────────────────────────

/** Un test sur une valeur, lu une fois pour toutes les lignes. */
type Test = (valeur: string | number | null) => boolean

/** Un filtre de colonne, prêt à passer sur les lignes. */
export type Critere = (ligne: LigneDuTableau) => boolean

function vide(valeur: string | number | null): boolean {
  return valeur === null || valeur === ''
}

function testDeTexte(saisie: string): Test {
  if (saisie === '=') return vide
  if (saisie === '!') return (v) => !vide(v)
  if (saisie.startsWith('=')) {
    const attendu = replier(saisie.slice(1))
    return (v) => !vide(v) && replier(String(v)) === attendu
  }
  if (saisie.startsWith('!')) {
    const exclu = replier(saisie.slice(1))
    return (v) => vide(v) || !replier(String(v)).includes(exclu)
  }
  const cherche = replier(saisie)
  return (v) => !vide(v) && replier(String(v)).includes(cherche)
}

const NOMBRE = '(-?\\d+(?:[.,]\\d+)?)'
const COMPARAISON = new RegExp(`^(<=|>=|<|>|=|!)?\\s*${NOMBRE}$`)
const INTERVALLE = new RegExp(`^${NOMBRE}\\s*(?:-|à|\\.\\.)\\s*${NOMBRE}$`)

function nombre(texte: string): number {
  return Number(texte.replace(',', '.'))
}

/** `null` : la saisie ne se lit pas comme un nombre — on retombe alors sur le texte affiché. */
function testDeNombre(saisie: string): Test | null {
  if (saisie === '=') return vide
  if (saisie === '!') return (v) => !vide(v)

  const intervalle = INTERVALLE.exec(saisie)
  if (intervalle !== null) {
    const a = nombre(intervalle[1] as string)
    const b = nombre(intervalle[2] as string)
    const [min, max] = a <= b ? [a, b] : [b, a]
    return (v) => typeof v === 'number' && v >= min && v <= max
  }

  const comparaison = COMPARAISON.exec(saisie)
  if (comparaison === null) return null
  const n = nombre(comparaison[2] as string)
  switch (comparaison[1]) {
    case '<':
      return (v) => typeof v === 'number' && v < n
    case '<=':
      return (v) => typeof v === 'number' && v <= n
    case '>':
      return (v) => typeof v === 'number' && v > n
    case '>=':
      return (v) => typeof v === 'number' && v >= n
    case '!':
      return (v) => typeof v !== 'number' || v !== n
    default:
      return (v) => typeof v === 'number' && v === n
  }
}

/** La valeur d'une liste à choix qui demande les lignes **sans** valeur. */
export const CHOIX_VIDE = '∅'

export function critere(colonne: Colonne, saisie: string): Critere {
  // Le signe moins typographique (« −3 », celui des générations) vaut le tiret.
  const s = saisie.trim().replaceAll('−', '-')
  if (s === '') return () => true

  if (colonne.type === 'choix') {
    return s === CHOIX_VIDE ? (l) => vide(valeurDe(colonne, l)) : (l) => valeurDe(colonne, l) === s
  }

  if (colonne.type === 'nombre' || colonne.type === 'date') {
    const test = testDeNombre(s)
    if (test !== null) return (l) => test(valeurDe(colonne, l))
  }

  // Sinon, le texte affiché : « Saint » trouve « Saint-Lô », et « ~ » les dates « vers ».
  const test = testDeTexte(s)
  return (l) => test(colonne.texte(l))
}

export interface Filtres {
  /** La recherche globale : chaque mot doit se trouver dans l'une des colonnes affichées. */
  recherche: string
  /** Par clé de colonne, la saisie de sa case. */
  colonnes: Record<string, string>
}

/**
 * Les lignes qui passent la recherche et les filtres **des colonnes affichées** : une
 * colonne masquée ne filtre plus — un filtre qu'on ne voit pas retirerait des lignes sans
 * que rien à l'écran ne dise pourquoi.
 */
export function filtrer(lignes: readonly LigneDuTableau[], filtres: Filtres, affichees: readonly Colonne[]): LigneDuTableau[] {
  const actifs: Critere[] = []
  for (const colonne of affichees) {
    const saisie = filtres.colonnes[colonne.cle]
    if (saisie !== undefined && saisie.trim() !== '') actifs.push(critere(colonne, saisie))
  }
  const mots = replier(filtres.recherche).split(/\s+/).filter((m) => m.length > 0)
  const signature = affichees.map((c) => c.cle).join(',')

  return lignes.filter((ligne) => {
    for (const test of actifs) {
      if (!test(ligne)) return false
    }
    if (mots.length === 0) return true
    const texte = texteDeRecherche(ligne, affichees, signature)
    return mots.every((mot) => texte.includes(mot))
  })
}

/**
 * Le texte replié d'une ligne, mémorisé tant que les colonnes affichées ne changent pas :
 * six mille lignes à replier à chaque frappe se sentent au clavier d'un téléphone.
 */
const TEXTES = new WeakMap<LigneDuTableau, { signature: string; texte: string }>()

function texteDeRecherche(ligne: LigneDuTableau, affichees: readonly Colonne[], signature: string): string {
  const connu = TEXTES.get(ligne)
  if (connu !== undefined && connu.signature === signature) return connu.texte
  const texte = replier(affichees.map((c) => c.texte(ligne) ?? '').join(' '))
  TEXTES.set(ligne, { signature, texte })
  return texte
}

// ── Tri ──────────────────────────────────────────────────────────────

export type Sens = 'asc' | 'desc'

export interface Tri {
  cle: string
  sens: Sens
}

const COMPARATEUR = new Intl.Collator('fr', { sensitivity: 'base', numeric: true })

/** Le tri suivant au clic sur un en-tête : croissant, décroissant, puis plus de tri. */
export function triSuivant(actuel: Tri | null, cle: string): Tri | null {
  if (actuel === null || actuel.cle !== cle) return { cle, sens: 'asc' }
  return actuel.sens === 'asc' ? { cle, sens: 'desc' } : null
}

/**
 * Trie sans toucher à l'entrée. **Les cases vides vont toujours à la fin**, dans les deux
 * sens : trier les naissances par ordre décroissant ne doit pas ouvrir sur trois cents
 * lignes sans date.
 */
export function trier(lignes: readonly LigneDuTableau[], tri: Tri | null): LigneDuTableau[] {
  const copie = [...lignes]
  if (tri === null) return copie
  const colonne = COLONNES.find((c) => c.cle === tri.cle)
  if (colonne === undefined) return copie

  const cle = (l: LigneDuTableau): string | number | null => (colonne.tri !== undefined ? colonne.tri(l) : colonne.type === 'nombre' ? valeurDe(colonne, l) : colonne.texte(l))
  const signe = tri.sens === 'asc' ? 1 : -1

  return copie.sort((a, b) => {
    const va = cle(a)
    const vb = cle(b)
    if (vide(va) && vide(vb)) return 0
    if (vide(va)) return 1
    if (vide(vb)) return -1
    const ordre = typeof va === 'number' && typeof vb === 'number' ? va - vb : COMPARATEUR.compare(String(va), String(vb))
    return ordre * signe
  })
}

// ── Export ───────────────────────────────────────────────────────────

/**
 * Une cellule CSV. Guillemets quand il le faut, et **une apostrophe devant un texte qui
 * commencerait par `=`, `+`, `-` ou `@`** : un tableur l'exécuterait comme une formule
 * (une note de fiche saisie par un membre suffit). Les nombres restent des nombres.
 */
function cellule(valeur: string | null, colonne: Colonne): string {
  if (valeur === null) return ''
  let texte = valeur
  if (colonne.type === 'texte' && /^[=+\-@\t\r]/.test(texte)) texte = `'${texte}`
  return /[;"\r\n]/.test(texte) ? `"${texte.replaceAll('"', '""')}"` : texte
}

/**
 * Le CSV des lignes données, colonnes données — séparateur `;` et marque d'ordre des
 * octets, pour qu'un tableur réglé en français l'ouvre en colonnes et avec ses accents.
 */
export function enCsv(lignes: readonly LigneDuTableau[], colonnes: readonly Colonne[]): string {
  const entete = colonnes.map((c) => cellule(c.libelle, { ...c, type: 'texte' })).join(';')
  // Un nombre part brut (« -3 », pas « −3 ») : le tableur doit pouvoir le compter.
  const contenu = (c: Colonne, l: LigneDuTableau): string | null => {
    if (c.type !== 'nombre') return c.texte(l)
    const v = valeurDe(c, l)
    return v === null ? null : String(v)
  }
  const corps = lignes.map((l) => colonnes.map((c) => cellule(contenu(c, l), c)).join(';'))
  return `﻿${[entete, ...corps].join('\r\n')}\r\n`
}

/** « personnes-2026-10-28.csv ». */
export function nomDuFichier(maintenant: Date = new Date()): string {
  const j = (n: number): string => String(n).padStart(2, '0')
  return `personnes-${maintenant.getFullYear()}-${j(maintenant.getMonth() + 1)}-${j(maintenant.getDate())}.csv`
}
