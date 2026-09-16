/**
 * **Les deux `409` aux conseils opposés** — § 6.8, § 15.4.
 *
 * C'est le piège le plus coûteux du projet, et il ne se voit pas : les deux
 * réponses portent le même code HTTP, la même enveloppe, et des conduites
 * contraires.
 *
 * | | `verrou_concurrent` | `demande_caduque` |
 * |---|---|---|
 * | Écrit dans l'arbre | non | non |
 * | **Le serveur a-t-il écrit ?** | **non, rien** | **oui** — la demande passe `caduque`, l'auteur est notifié, le journal reçoit l'événement |
 * | La demande reste ouverte | oui, `en_attente` | non |
 * | `details.rejouable` | `true` | `false` |
 * | Conduite | **réessayer** | **refaire** (§ 6.10) |
 *
 * « Un front qui afficherait un bouton "réessayer" sur les deux ferait boucler
 * l'administrateur sur une demande caduque. » Et pire que boucler : **rejouer
 * une caduque écrit deux fois**. Ce n'est donc pas un bogue d'affichage, c'en
 * est un de données.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi un type et pas un `if`.**
 *
 * `ConflitDeDemande` est une union discriminée dont les deux branches n'ont
 * **aucune surface commune au-delà du strict nécessaire** : `champs`, `cible` et
 * `demandeId` n'existent que sur la branche caduque. Écrire `conflit.champs`
 * sans avoir discriminé ne compile pas, et une branche unique qui prétendrait
 * traiter les deux cas ne peut donc rien en afficher de spécifique.
 *
 * C'est la même exigence que celle appliquée à `CercleDeSession`, où
 * `cercle.taille === 0` a été rendu non compilable : quand une distinction porte
 * une règle, on la confie au typeur plutôt qu'à la vigilance. `conflit.test.ts`
 * en garde la preuve sous forme de `@ts-expect-error` — si la distinction
 * s'efface un jour, c'est la **compilation** qui échoue, pas un test.
 * ────────────────────────────────────────────────────────────────────
 */

import {
  CODE_CIBLE_DISPARUE,
  CODE_DEMANDE_CADUQUE,
  CODE_VERROU_CONCURRENT,
  estErreurApi,
  estRejouable,
  type ErreurApi,
} from '@/lib/api'
import { descriptionDeChamp, type ChampPersonne } from './champs'

/* ------------------------------------------------------------------ */
/* Ce que la caducité rapporte                                         */
/* ------------------------------------------------------------------ */

/** Qui a bougé la valeur sous la demande, quand le serveur le sait. */
export interface AuteurDuChangement {
  demandeId: number | null
  auteur: string | null
  le: string | null
}

/**
 * Un champ devenu caduc (§ 6.8, `details.champs[]`).
 *
 * Trois valeurs, et il en faut bien trois : ce qui était proposé, ce qu'il y
 * avait au dépôt, ce qu'il y a maintenant. Sans la valeur intermédiaire,
 * l'auteur ne peut pas voir *ce qui a bougé* — il ne voit que deux valeurs qui
 * diffèrent, ce qui ressemble à un refus.
 */
export interface ChampCaduc {
  field: string
  /** Le libellé français quand le champ appartient à la surface éditable. */
  etiquette: string
  propose: unknown
  valeurALaSoumission: unknown
  valeurCourante: unknown
  modifieePar: AuteurDuChangement | null
}

/* ------------------------------------------------------------------ */
/* Les deux branches                                                   */
/* ------------------------------------------------------------------ */

/**
 * `verrou_concurrent` — deux transactions se sont croisées. **Rien n'a été
 * écrit, la demande reste `en_attente`, et réessayer est la bonne action.**
 *
 * Aucun détail de champ ici, et c'est exact : rien n'a changé, il n'y a donc
 * rien à montrer. Le type le dit à la place du commentaire.
 */
export interface ConflitRejouable {
  sorte: 'rejouable'
  code: string
  message: string
  conduite: 'reessayer'
  libelleAction: 'Réessayer'
}

/**
 * `demande_caduque` / `cible_disparue` — **le monde a bougé sous la demande**.
 *
 * `caduque` n'est pas `refusee` : personne n'a refusé (ADR 0004). Et c'est
 * « une `409` qui écrit » : la demande a changé de statut côté serveur. Le geste
 * à proposer est **refaire**, jamais réessayer.
 */
export interface ConflitCaduque {
  sorte: 'caduque'
  code: string
  message: string
  conduite: 'refaire'
  libelleAction: 'Refaire cette demande'
  demandeId: number | null
  /** Ce qui a bougé, champ par champ. Vide sur `cible_disparue`. */
  champs: ChampCaduc[]
  /** La personne ou la famille visée, quand c'est elle qui a disparu. */
  cible: { kind: string; id: number; label: string } | null
}

export type ConflitDeDemande = ConflitRejouable | ConflitCaduque

/* ------------------------------------------------------------------ */
/* Reconnaissance                                                      */
/* ------------------------------------------------------------------ */

/**
 * Les seuls codes que ce module prétend interpréter.
 *
 * Un `409` d'une autre nature — `demande_deja_decidee`,
 * `vue_comme_membre_active` — retombe sur le traitement générique de
 * `MessageErreur`, qui sait déjà quoi en faire. Le silence vaut mieux qu'une
 * conduite inventée : rangé par défaut du côté « non rejouable », un code
 * inconnu proposerait « Refaire cette demande » sur une demande bien vivante.
 *
 * **Une table indexée par un type, pas un tableau de chaînes.** Les valeurs
 * viennent des constantes du socle — aucune faute de frappe possible — et la
 * clé est leur type littéral : ajouter un code à `CodeDeConflit` sans lui
 * donner d'entrée ne compile pas. C'est le motif qui a remplacé le
 * `readonly string[]` de `reprise.ts`, où trois qualificatifs manquaient sans
 * que rien ne le signale.
 */
type CodeDeConflit =
  | typeof CODE_VERROU_CONCURRENT
  | typeof CODE_DEMANDE_CADUQUE
  | typeof CODE_CIBLE_DISPARUE

const CODES_CONNUS: Record<CodeDeConflit, true> = {
  [CODE_VERROU_CONCURRENT]: true,
  [CODE_DEMANDE_CADUQUE]: true,
  [CODE_CIBLE_DISPARUE]: true,
}

function nombreOuNull(valeur: unknown): number | null {
  return typeof valeur === 'number' && Number.isFinite(valeur) ? valeur : null
}

function chaineOuNull(valeur: unknown): string | null {
  return typeof valeur === 'string' && valeur.length > 0 ? valeur : null
}

function lireAuteur(valeur: unknown): AuteurDuChangement | null {
  if (typeof valeur !== 'object' || valeur === null) return null
  const objet = valeur as Record<string, unknown>
  return {
    demandeId: nombreOuNull(objet['demandeId']),
    auteur: chaineOuNull(objet['auteur']),
    le: chaineOuNull(objet['le']),
  }
}

function lireChamps(valeur: unknown): ChampCaduc[] {
  if (!Array.isArray(valeur)) return []
  const champs: ChampCaduc[] = []
  for (const brut of valeur) {
    if (typeof brut !== 'object' || brut === null) continue
    const objet = brut as Record<string, unknown>
    const field = chaineOuNull(objet['field'])
    if (field === null) continue
    champs.push({
      field,
      etiquette: descriptionDeChamp(field as ChampPersonne).etiquette,
      propose: objet['propose'] ?? null,
      valeurALaSoumission: objet['valeurALaSoumission'] ?? null,
      valeurCourante: objet['valeurCourante'] ?? null,
      modifieePar: lireAuteur(objet['modifieePar']),
    })
  }
  return champs
}

function lireCible(valeur: unknown): ConflitCaduque['cible'] {
  if (typeof valeur !== 'object' || valeur === null) return null
  const objet = valeur as Record<string, unknown>
  const id = nombreOuNull(objet['id'])
  const kind = chaineOuNull(objet['kind'])
  if (id === null || kind === null) return null
  return { kind, id, label: chaineOuNull(objet['label']) ?? '' }
}

/**
 * Traduit une erreur attrapée en conflit typé, ou rend `null`.
 *
 * **`details.rejouable` est lu en premier, la table des codes seulement en
 * repli** : le contrat pose le booléen « pour que le front n'ait pas à connaître
 * la liste des codes » (§ 15.4). `estRejouable()` du socle applique déjà cet
 * ordre — on ne le réécrit pas ici, sous peine de voir les deux versions
 * diverger le jour où le contrat ajoutera un code.
 */
export function conflitDeDemande(cause: unknown): ConflitDeDemande | null {
  if (!estErreurApi(cause)) return null
  const erreur: ErreurApi = cause
  if (erreur.statut !== 409 || !Object.hasOwn(CODES_CONNUS, erreur.code)) return null

  if (estRejouable(erreur)) {
    return {
      sorte: 'rejouable',
      code: erreur.code,
      message: erreur.message,
      conduite: 'reessayer',
      libelleAction: 'Réessayer',
    }
  }

  const details = erreur.details ?? {}
  return {
    sorte: 'caduque',
    code: erreur.code,
    message: erreur.message,
    conduite: 'refaire',
    libelleAction: 'Refaire cette demande',
    demandeId: nombreOuNull(details['demandeId']),
    champs: lireChamps(details['champs']),
    cible: lireCible(details['cible']),
  }
}

/**
 * Impose deux branches distinctes.
 *
 * Le seul moyen d'employer un conflit est d'en traiter les deux cas, chacun
 * recevant un objet **dont l'autre branche n'a pas les clés**. Un appelant qui
 * voudrait « faire pareil des deux côtés » n'aurait, dans la branche rejouable,
 * ni `champs` ni `cible` à afficher : le type le lui rappelle avant l'exécution.
 */
export function selonConflit<T>(
  conflit: ConflitDeDemande,
  branches: {
    rejouable: (conflit: ConflitRejouable) => T
    caduque: (conflit: ConflitCaduque) => T
  },
): T {
  return conflit.sorte === 'rejouable'
    ? branches.rejouable(conflit)
    : branches.caduque(conflit)
}

/* ------------------------------------------------------------------ */
/* Rendu d'une valeur d'op                                             */
/* ------------------------------------------------------------------ */

function estBlocDate(valeur: unknown): valeur is Record<string, unknown> {
  return typeof valeur === 'object' && valeur !== null && 'qualifier' in valeur
}

/**
 * Une valeur d'op en français, pour le tableau « ce qui a bougé ».
 *
 * **Une fourchette ne s'aplatit pas**, y compris ici : un bloc `entre` se rend
 * « entre … et … », jamais par sa seule borne basse. Quand le serveur a fourni
 * `texte`, c'est lui qui fait foi — il vient de `UncertainDate::format()` et le
 * front ne le recompose pas (§ 14.4).
 */
export function valeurLisible(valeur: unknown): string {
  if (valeur === null || valeur === undefined) return '—'
  if (typeof valeur === 'boolean') return valeur ? 'oui' : 'non'
  if (typeof valeur === 'number') return String(valeur)
  if (typeof valeur === 'string') return valeur.length > 0 ? valeur : '—'

  if (estBlocDate(valeur)) {
    const texte = valeur['texte']
    if (typeof texte === 'string' && texte.length > 0) return texte
    const brut = valeur['raw']
    if (typeof brut === 'string' && brut.length > 0) return brut

    // Sans prose ni provenance, on rend l'intervalle — jamais un point.
    const min = chaineOuNull(valeur['min'])
    const max = chaineOuNull(valeur['max'])
    if (min !== null && max !== null) return min === max ? min : `${min} → ${max}`
    if (min !== null) return `à partir du ${min}`
    if (max !== null) return `jusqu’au ${max}`
    return 'date inconnue'
  }

  return '—'
}
