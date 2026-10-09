/**
 * **« Est-ce déjà… ? »** — ce que le formulaire « Ajouter une personne » fait des
 * ressemblances, sans DOM et sans réseau.
 *
 * Le défaut réparé : le formulaire déposait une `creation_personne` sans rien
 * vérifier, et créait des doublons — d'une personne **déjà dans l'arbre**, qu'il
 * aurait fallu compléter, ou d'une personne **déjà proposée**, par soi un autre
 * soir ou par un autre membre. Pendant la saisie, deux routes disent ce qui
 * ressemble (§ 5.5 bis, § 6.6 quater) ; ce module décide **quand** les appeler et
 * **quoi** montrer de leurs réponses.
 *
 * ────────────────────────────────────────────────────────────────────
 * **On n'appelle que quand le serveur peut répondre.** Les deux routes rendent
 * `422` sans nom ni nom d'usage de deux lettres, ou sans prénom ni surnom : un
 * `200` vide ferait croire que la personne n'existe pas. `ficheDeComparaison` rend
 * donc `null` tant que la saisie n'y est pas — la règle du serveur, recopiée au
 * même repliement (`replier`, le pendant de `CleDeRecherche::replier()`).
 *
 * **Une date mal écrite n'empêche rien** : elle est simplement omise. Elle a son
 * message sous son champ ; la comparer telle quelle rendrait `422`, et toute la
 * recherche se tairait pour une borne pas encore finie de taper.
 *
 * **La note, les liens et les photos ne relancent rien** (`cleDeComparaison`) : ils
 * ne font pas partie de ce qu'on compare — le serveur refuserait même la clé.
 * ────────────────────────────────────────────────────────────────────
 *
 * Ce module ne cache rien de ce que le serveur rend, et ne reconstruit rien de ce
 * qu'il tait : une proposition **discrète** reste une phrase. Il ne fait que
 * retirer ce que le lecteur a lui-même écarté (« Ce n'est pas elle ») et les
 * personnes qu'il a déjà désignées dans ses liens — le père qu'il vient de choisir
 * n'est pas un doublon du fils qu'il propose.
 */
import type {
  FicheAComparer,
  RessemblanceDansLArbre,
  RessemblanceProposition,
  RessemblancePropositionComplete,
  ValeurDate,
} from '@/lib/api'
import { cleDuChoix, type SaisieBrouillon, type SaisieCreation } from './creation'
import { sansTexte, valeurAEnvoyer, type SaisieDate } from './dates'
import { texteOuNull } from './operations'

/* ------------------------------------------------------------------ */
/* Le repliement                                                       */
/* ------------------------------------------------------------------ */

/** `CleDeRecherche::LIGATURES`, au caractère près. */
const LIGATURES: Record<string, string> = {
  Æ: 'AE', æ: 'ae', Œ: 'OE', œ: 'oe', ß: 'ss', Ĳ: 'IJ', ĳ: 'ij', Ð: 'D', ð: 'd', Þ: 'TH', þ: 'th',
}

/** `CleDeRecherche::ACCENTUES` et `REPLIES` : la table du serveur, sans NFD. */
const ACCENTUES = 'ÀÁÂÃÄÅàáâãäåÈÉÊËèéêëÌÍÎÏìíîïÒÓÔÕÖØòóôõöøÙÚÛÜùúûüÝŸýÿÇçÑñŠšŽžĀāĒēĪīŌōŪūŁłĆćŚśŹźŻż'
const REPLIES = 'AAAAAAaaaaaaEEEEeeeeIIIIiiiiOOOOOOooooooUUUUuuuuYYyyCcNnSsZzAaEeIiOoUuLlCcSsZzZz'
const TABLE = new Map([...ACCENTUES].map((lettre, rang) => [lettre, REPLIES[rang]!]))

/**
 * **La clé de comparaison d'un nom**, celle du serveur : `[A-Z0-9 ]`, accents et
 * ligatures repliés, toute ponctuation réduite à une espace.
 *
 * Recopiée de `Core\Service\CleDeRecherche::replier()` plutôt que réinventée en
 * NFD : c'est elle qui décide, côté serveur, si un nom a ses deux lettres — un
 * repliement plus large ici ferait appeler pour un `422`. Un caractère que la
 * table ignore passe ensuite par la décomposition Unicode : le front est alors un
 * peu plus large que le serveur, jamais plus étroit, et le pire est un appel qui
 * se tait.
 *
 * « Jean-Marie » et « jean marie » donnent la même clé, et c'est voulu : la
 * comparaison des textes de `completion.ts` passe aussi par ici.
 */
export function replier(texte: string): string {
  const deplie = [...texte].map((c) => LIGATURES[c] ?? TABLE.get(c) ?? c).join('')
  const sansDiacritiques = deplie.normalize('NFD').replace(/[̀-ͯ]/g, '')
  return sansDiacritiques
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, ' ')
    .trim()
}

/** Le plancher du nom, en lettres — `LETTRES_MINIMALES_DU_NOM` du serveur. */
export const LETTRES_MINIMALES_DU_NOM = 2

function lettres(texte: string): number {
  return replier(texte).replace(/[^A-Z]/g, '').length
}

/* ------------------------------------------------------------------ */
/* Ce qu'on compare                                                    */
/* ------------------------------------------------------------------ */

/** Une date de la saisie, **omise** si elle ne se compose pas encore. */
function dateAComparer(saisie: SaisieDate): ValeurDate | null {
  const resultat = valeurAEnvoyer(saisie)
  if (!resultat.valide || resultat.valeur === null) return null
  // Cinq clés, jamais `texte` : une reprise peut avoir posé en `origine` un
  // bloc reçu d'une fiche, et `texte` rendrait `422`.
  return sansTexte(resultat.valeur)
}

/**
 * La fiche à comparer, **ou `null` tant que le serveur la refuserait** :
 *  - un nom **ou** un nom d'usage d'au moins deux lettres ;
 *  - un prénom **ou** un surnom.
 *
 * Les clés sont les huit d'un brouillon de `creation_personne`, dans un ordre
 * fixe — `cleDeComparaison` s'en sert —, et rien d'autre : ni note, ni liens.
 */
export function ficheDeComparaison(brouillon: SaisieBrouillon): FicheAComparer | null {
  const surname = texteOuNull(brouillon.surname)
  const marriedName = texteOuNull(brouillon.marriedName)
  const givenNames = texteOuNull(brouillon.givenNames)
  const nickname = texteOuNull(brouillon.nickname)

  const nomSuffisant = [surname, marriedName].some((nom) => nom !== null && lettres(nom) >= LETTRES_MINIMALES_DU_NOM)
  const prenom = [givenNames, nickname].some((valeur) => valeur !== null && replier(valeur) !== '')
  if (!nomSuffisant || !prenom) return null

  return {
    givenNames,
    surname,
    marriedName,
    nickname,
    sex: brouillon.sex,
    deceased: brouillon.deceased,
    birth: dateAComparer(brouillon.naissance),
    death: dateAComparer(brouillon.deces),
  }
}

/**
 * Ce qui, d'une fiche à comparer, **relance** la recherche.
 *
 * Tout, sauf `deceased` : le serveur l'accepte et n'en fait rien (« une case non
 * cochée ne prouve pas qu'une personne vit »), et cocher « décès attesté » ne doit
 * pas refaire deux appels pour la même réponse. La note, les liens et les photos
 * n'y sont pas non plus — ils ne sont pas dans la fiche.
 */
export function cleDeComparaison(fiche: FicheAComparer): string {
  const { deceased: _sansEffet, ...comparee } = fiche
  return JSON.stringify(comparee)
}

/* ------------------------------------------------------------------ */
/* Ce qu'on montre                                                     */
/* ------------------------------------------------------------------ */

export type Ressemblance = RessemblanceDansLArbre | RessemblanceProposition

export function estUneProposition(r: Ressemblance): r is RessemblanceProposition {
  return 'forme' in r
}

/**
 * La clé d'une ressemblance, **au format de `cleDuChoix`** (`creation.ts`) :
 * `personne:12` pour une fiche de l'arbre, `brouillon:34` pour une fiche
 * proposée. C'est ce qui permet d'écarter, d'un même geste, une ressemblance
 * qu'on a déjà désignée dans un lien.
 */
export function cleDeRessemblance(r: Ressemblance): string {
  return estUneProposition(r)
    ? cleDuChoix({ draftPersonId: r.draftPersonId, demandeId: 0, libelle: '' })
    : cleDuChoix({ personId: r.personId, libelle: '' })
}

/** Les personnes désignées dans les liens de la saisie, en clés {@link cleDuChoix}. */
export function designesDansLesLiens(saisie: SaisieCreation): string[] {
  return [
    ...saisie.filiation.parents.map(cleDuChoix),
    ...saisie.unions.map((union) => cleDuChoix(union.personne)),
    ...saisie.enfants.map((enfant) => cleDuChoix(enfant.personne)),
  ]
}

/**
 * Ce qui reste à montrer : ni ce que le lecteur a écarté (« Ce n'est pas elle »),
 * ni ce qu'il a déjà désigné dans ses liens. L'ordre du serveur est gardé — il
 * trie par score, puis nom identique avant nom proche.
 */
export function visibles<T extends Ressemblance>(
  liste: readonly T[],
  ecartees: readonly string[],
  dejaDesignes: readonly string[],
): T[] {
  const masquees = new Set([...ecartees, ...dejaDesignes])
  return liste.filter((r) => !masquees.has(cleDeRessemblance(r)))
}

/**
 * La fiche qu'on est en train de compléter **reste à l'écran**, même si la
 * dernière réponse ne la rend plus : une frappe dans le nom ne doit pas emporter
 * une complétion à moitié cochée. Elle garde sa place si elle y est, et vient en
 * tête sinon.
 */
export function garderLOuverte(
  liste: readonly RessemblanceDansLArbre[],
  ouverte: RessemblanceDansLArbre | null,
): RessemblanceDansLArbre[] {
  if (ouverte === null || liste.some((r) => r.personId === ouverte.personId)) return [...liste]
  return [ouverte, ...liste]
}

/** Le nombre de concordances. Une forme discrète n'a pas de `score` — c'est
 *  par définition le nombre de ses critères. */
export function scoreDe(r: Ressemblance): number {
  return estUneProposition(r) && r.forme === 'discrete' ? r.criteres.length : r.score
}

/**
 * Le seuil d'une ressemblance **forte** : trois concordances.
 *
 * Deux est le plancher de toute réponse — la règle exige un nom proche **et** un
 * prénom en commun —, et dans un arbre de famille c'est le bruit de fond : le
 * même patronyme et les mêmes prénoms reviennent à chaque génération. La
 * troisième concordance (le sexe, une date, le lieu) est ce qui fait d'un
 * homonyme un doublon probable. Une date **incompatible** a déjà écarté la fiche
 * côté serveur : ce qui reste n'est jamais contredit, seulement plus ou moins
 * confirmé. Une forme discrète, qui ne compare que nom, prénoms et sexe, est forte
 * quand les trois concordent — c'est le plus qu'elle puisse dire.
 */
export const SEUIL_DE_RESSEMBLANCE_FORTE = 3

/** Une ressemblance qui mérite qu'on vérifie avant d'envoyer. */
export function estForte(r: Ressemblance): boolean {
  return scoreDe(r) >= SEUIL_DE_RESSEMBLANCE_FORTE
}

/**
 * Une proposition **du lecteur** ? Un brouillon l'est toujours — on ne parcourt
 * que les siens ; une demande en attente l'est si la session la compte parmi les
 * siennes. Faute de quoi on écrit « proposée par » et le nom que le serveur donne :
 * c'est vrai même quand c'est le sien.
 */
export function estDuLecteur(r: RessemblancePropositionComplete, mesDemandes: readonly number[]): boolean {
  return r.statut === 'brouillon' || mesDemandes.includes(r.demandeId)
}

/** Ce que l'arbre cercle en pointillé : les fiches, et les nœuds proposés qu'il porte. */
export interface EnEvidence {
  personIds: number[]
  noeudIds: string[]
}

export function enEvidence(
  arbre: readonly RessemblanceDansLArbre[],
  propositions: readonly RessemblanceProposition[],
): EnEvidence {
  return {
    personIds: arbre.map((r) => r.personId),
    noeudIds: propositions.flatMap((r) => (r.forme === 'complete' && r.noeudId !== null ? [r.noeudId] : [])),
  }
}

/**
 * La phrase de la région vivante (`aria-live="polite"`) : dite quand elle change,
 * sans prendre le focus — on est en train de taper un nom. Vide sans résultat.
 */
export function resumeDesRessemblances(nombreArbre: number, nombrePropositions: number): string {
  const morceaux: string[] = []
  if (nombreArbre > 0) {
    morceaux.push(nombreArbre === 1 ? 'une personne de l’arbre' : `${nombreArbre} personnes de l’arbre`)
  }
  if (nombrePropositions > 0) {
    morceaux.push(
      nombrePropositions === 1 ? 'une proposition en attente' : `${nombrePropositions} propositions en attente`,
    )
  }
  if (morceaux.length === 0) return ''
  const total = nombreArbre + nombrePropositions
  const sujet = morceaux.join(' et ')
  return `${sujet.charAt(0).toUpperCase()}${sujet.slice(1)} ${total > 1 ? 'ressemblent' : 'ressemble'} à votre saisie.`
}
