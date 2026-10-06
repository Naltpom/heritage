/**
 * **« Attention : cette personne existe déjà, ou est en cours de validation »** —
 * la comparaison d'avant dépôt (§ 6.3 ter).
 *
 * Le besoin, dans les mots du commanditaire : *« on clique sur demander, on a la
 * popup récapitulatif à valider, ou “Attention cette personne existe déjà ou est
 * en cours de validation”, voici les éléments saisis existants, voici ce qui
 * diverge et ce que tu ajoutes ou modifies : est-ce bien une nouvelle personne,
 * ou souhaites-tu mettre à jour la demande existante, ou ne rien faire. »*
 *
 * Ce module ne fait que des fonctions pures : **qui** ressemble (le serveur l'a
 * dit), **ce qui** coïncide ou diverge (calculé ici, champ par champ), et ce
 * qu'on emporte vers l'écran de modification d'une fiche. La modale
 * (`ModaleEnvoiCreation.vue`) ne fait qu'imprimer.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Quatre états, et pas trois.** Une ligne de comparaison est :
 *
 * | État | Sens |
 * |---|---|
 * | `identique` | la saisie dit la même chose que l'existant |
 * | `divergent` | les deux disent autre chose — l'existant **et** la saisie s'affichent |
 * | `ajoute` | la saisie apporte ce que l'existant n'a pas |
 * | `existant` | l'existant porte ce que la saisie n'a pas rempli — rien ne le retire |
 *
 * plus `non_comparable` : une naissance que la réciprocité ne laisse pas voir
 * entière (ADR 0016). On ne dit ni « identique » ni « divergent » d'une date
 * qu'on ne connaît pas — le dire serait la lui faire deviner.
 * ────────────────────────────────────────────────────────────────────
 */

import type {
  BlocDate,
  DoublonsProbables,
  LienDemande,
  MaDemandeDoublon,
  PersonneDoublon,
  ProcheDoublon,
  Sexe,
  ValeurDate,
} from '@/lib/api'
import { LIBELLES_SEXE } from './champs'
import { apercuDeValeur, designeUneDate, saisieVide, valeurAEnvoyer, type SaisieDate } from './dates'
import {
  cleDuChoix,
  type ChoixDePersonne,
  type SaisieCreation,
  type SaisieUnion,
} from './creation'

/* ------------------------------------------------------------------ */
/* Les correspondances                                                 */
/* ------------------------------------------------------------------ */

/** Une personne qui ressemble à la saisie : de l'arbre, ou une de **mes**
 *  demandes en attente. Les demandes des autres ne sont jamais une
 *  correspondance — le serveur n'en dit qu'un drapeau (`enCoursAilleurs`). */
export type Correspondance =
  | { source: 'arbre'; cle: string; personne: PersonneDoublon }
  | { source: 'demande'; cle: string; demande: MaDemandeDoublon }

/**
 * Les correspondances, **la meilleure d'abord**.
 *
 * Le serveur ordonne chaque liste ; entre les deux, on range par nombre de
 * critères concordants, et à égalité l'arbre passe devant : une fiche validée
 * est un fait, une demande en attente une intention.
 */
export function correspondances(reponse: DoublonsProbables): Correspondance[] {
  const toutes: Correspondance[] = [
    ...reponse.personnes.map((personne): Correspondance => ({
      source: 'arbre',
      cle: `arbre:${personne.personId}`,
      personne,
    })),
    ...reponse.mesDemandes.map((demande): Correspondance => ({
      source: 'demande',
      cle: `demande:${demande.demandeId}:${demande.localRef}`,
      demande,
    })),
  ]

  return toutes
    .map((correspondance, rang) => ({ correspondance, rang }))
    .sort((a, b) => criteres(b.correspondance).length - criteres(a.correspondance).length || a.rang - b.rang)
    .map(({ correspondance }) => correspondance)
}

function criteres(correspondance: Correspondance): readonly string[] {
  return correspondance.source === 'arbre'
    ? correspondance.personne.criteres
    : correspondance.demande.criteres
}

/** Y a-t-il de quoi avertir ? Le drapeau seul suffit : il dit qu'un autre
 *  membre a déjà proposé une personne semblable. */
export function aDesDoublons(reponse: DoublonsProbables): boolean {
  return reponse.personnes.length > 0 || reponse.mesDemandes.length > 0 || reponse.enCoursAilleurs
}

/** Le titre de la modale, pour la correspondance affichée — ou pour le seul
 *  drapeau, quand il n'y a rien d'autre. */
export function titreAvertissement(correspondance: Correspondance | null): string {
  return correspondance?.source === 'arbre'
    ? 'Attention : cette personne existe déjà dans l’arbre'
    : 'Attention : cette personne est déjà en cours de validation'
}

/** Le nom à afficher d'une correspondance, dans le sélecteur et l'en-tête. */
export function nomDeCorrespondance(correspondance: Correspondance): string {
  return correspondance.source === 'arbre'
    ? correspondance.personne.displayName
    : correspondance.demande.displayName
}

/** La ligne de dates d'une correspondance, telle que le serveur l'a composée. */
export function datesDeCorrespondance(correspondance: Correspondance): string {
  return correspondance.source === 'arbre'
    ? correspondance.personne.ligneDates
    : correspondance.demande.ligneDates
}

/* ------------------------------------------------------------------ */
/* La comparaison champ par champ                                      */
/* ------------------------------------------------------------------ */

export type EtatComparaison = 'identique' | 'divergent' | 'ajoute' | 'existant' | 'non_comparable'

export interface LigneComparee {
  /** Stable, pour le `:key` et les tests : `givenNames`, `birth`, `parents`… */
  cle: string
  etiquette: string
  /** Ce que l'arbre ou la demande porte déjà ; `''` s'il n'y a rien. */
  existant: string
  /** Ce que le formulaire a saisi ; `''` s'il n'y a rien. */
  saisi: string
  etat: EtatComparaison
}

/** Les libellés d'état, pour l'écran et les lecteurs d'écran. */
export const LIBELLES_ETAT: Record<EtatComparaison, string> = {
  identique: 'Identique',
  divergent: 'Diverge',
  ajoute: 'Ajouté par vous',
  existant: 'Déjà connu',
  non_comparable: 'Non comparable',
}

/** Minuscules, sans accents, espaces réduits : « Marne » et « MARNE » disent
 *  la même chose, « Marne » et « Marne Dolomite » non. */
function normaliser(valeur: string): string {
  return valeur
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function etatTexte(existant: string, saisi: string): EtatComparaison | null {
  if (existant === '' && saisi === '') return null
  if (saisi === '') return 'existant'
  if (existant === '') return 'ajoute'
  return normaliser(existant) === normaliser(saisi) ? 'identique' : 'divergent'
}

function ligneTexte(cle: string, etiquette: string, existant: string | null, saisi: string): LigneComparee | null {
  const avant = (existant ?? '').trim()
  const apres = saisi.trim()
  const etat = etatTexte(avant, apres)
  return etat === null ? null : { cle, etiquette, existant: avant, saisi: apres, etat }
}

/** La date saisie, composée comme elle partirait — ou `null` : vide, ou mal
 *  écrite (la modale ne s'ouvre de toute façon pas sur une saisie invalide). */
function dateSaisie(saisie: SaisieDate): ValeurDate | null {
  const resultat = valeurAEnvoyer(saisie)
  return resultat.valide && designeUneDate(resultat.valeur) ? resultat.valeur : null
}

/** « Le 12/03/1901, à Saule. » sans le point final : une cellule, pas une phrase. */
function texteDeSaisie(valeur: ValeurDate | null): string {
  return valeur === null ? '' : apercuDeValeur(valeur).replace(/\.$/, '')
}

/**
 * Le texte d'un bloc reçu : `texte` (le serveur fait foi, § 14.4) et le lieu.
 * Un bloc `masquee` (ADR 0016) dit ce qui est partagé, ou « Non partagée » —
 * sans distinguer une date inconnue d'une date tue, comme le serveur.
 */
function texteDeBloc(bloc: BlocDate | null | undefined): string {
  if (bloc === null || bloc === undefined) return ''
  if (bloc.qualifier === 'masquee' && bloc.texte === '') return 'Non partagée'
  if (bloc.qualifier !== 'masquee' && !designeUneDate(bloc)) return ''
  if (bloc.texte === '') return bloc.place === null ? '' : `Lieu : ${bloc.place}`
  return bloc.place === null ? bloc.texte : `${bloc.texte}, à ${bloc.place}`
}

/**
 * Deux dates disent-elles la même chose ? La **date** d'abord — qualificatif et
 * bornes, jamais `raw`, que l'import GEDCOM écrit à sa façon —, puis le lieu,
 * seulement s'il est donné des deux côtés.
 */
function memesDates(existant: BlocDate, saisi: ValeurDate): boolean {
  const memeDate = existant.qualifier === saisi.qualifier && existant.min === saisi.min && existant.max === saisi.max
  const lieux = existant.place !== null && saisi.place !== null
  return memeDate && (!lieux || normaliser(existant.place ?? '') === normaliser(saisi.place ?? ''))
}

function ligneDate(cle: string, etiquette: string, existant: BlocDate | null, saisie: SaisieDate): LigneComparee | null {
  const valeur = dateSaisie(saisie)
  const avant = texteDeBloc(existant)
  const apres = texteDeSaisie(valeur)

  if (avant === '' && apres === '') return null
  if (existant?.qualifier === 'masquee') {
    return { cle, etiquette, existant: avant, saisi: apres, etat: apres === '' ? 'existant' : 'non_comparable' }
  }
  if (apres === '') return { cle, etiquette, existant: avant, saisi: '', etat: 'existant' }
  if (existant === null || avant === '') return { cle, etiquette, existant: '', saisi: apres, etat: 'ajoute' }

  return {
    cle,
    etiquette,
    existant: avant,
    saisi: apres,
    etat: valeur !== null && memesDates(existant, valeur) ? 'identique' : 'divergent',
  }
}

/** Un proche, sous une clé comparable à `cleDuChoix()` : `personne:7`,
 *  `brouillon:7`, `famille:7`. */
interface ProcheCompare {
  cle: string
  libelle: string
}

function prochesSaisis(saisie: SaisieCreation): Record<'parents' | 'conjoints' | 'enfants', ProcheCompare[]> {
  const versProche = (choix: ChoixDePersonne): ProcheCompare => ({ cle: cleDuChoix(choix), libelle: choix.libelle })
  const famille = saisie.filiation.famille

  return {
    parents:
      famille !== null
        ? [{ cle: `famille:${famille.familyId}`, libelle: famille.libelle }]
        : saisie.filiation.parents.map(versProche),
    conjoints: saisie.unions.map((union) => versProche(union.personne)),
    enfants: saisie.enfants.map((enfant) => versProche(enfant.personne)),
  }
}

function prochesDeLArbre(proches: readonly ProcheDoublon[]): ProcheCompare[] {
  return proches.map((proche) => ({ cle: `personne:${proche.personId}`, libelle: proche.displayName }))
}

/** Les proches d'une de mes demandes, lus dans ses liens (§ 6.5). */
function prochesDeLaDemande(liens: readonly LienDemande[]): Record<'parents' | 'conjoints' | 'enfants', ProcheCompare[]> {
  const sortie: Record<'parents' | 'conjoints' | 'enfants', ProcheCompare[]> = { parents: [], conjoints: [], enfants: [] }

  const extremite = (personId?: number | null, brouillonId?: number | null, libelle?: string | null): ProcheCompare | null => {
    if (typeof personId === 'number') return { cle: `personne:${personId}`, libelle: libelle ?? '' }
    if (typeof brouillonId === 'number') return { cle: `brouillon:${brouillonId}`, libelle: libelle ?? '' }
    return null
  }

  for (const lien of liens) {
    const role = lien.kind === 'enfant_de' ? 'parents' : lien.kind === 'conjoint_de' ? 'conjoints' : 'enfants'
    if (typeof lien.familyId === 'number') {
      sortie.parents.push({ cle: `famille:${lien.familyId}`, libelle: lien.familyLabel ?? '' })
      continue
    }
    for (const proche of [
      extremite(lien.otherPersonId, lien.otherDraftPersonId, lien.otherLabel),
      extremite(lien.other2PersonId, lien.other2DraftPersonId, lien.other2Label),
    ]) {
      if (proche !== null) sortie[role].push(proche)
    }
  }

  return sortie
}

const ETIQUETTES_PROCHES = { parents: 'Parents', conjoints: 'Conjoints', enfants: 'Enfants' } as const

/**
 * Une ligne par rôle. Les parents sont **une valeur** — d'autres parents que
 * ceux de l'existant divergent ; des conjoints ou des enfants de plus
 * **s'ajoutent**.
 */
function ligneProches(
  role: 'parents' | 'conjoints' | 'enfants',
  existants: readonly ProcheCompare[],
  saisis: readonly ProcheCompare[],
): LigneComparee | null {
  if (existants.length === 0 && saisis.length === 0) return null

  const cles = new Set(existants.map((proche) => proche.cle))
  const nouveaux = saisis.filter((proche) => !cles.has(proche.cle))
  const ligne = {
    cle: role,
    etiquette: ETIQUETTES_PROCHES[role],
    existant: existants.map((proche) => proche.libelle).join(', '),
    saisi: saisis.map((proche) => proche.libelle).join(', '),
  }

  if (saisis.length === 0) return { ...ligne, etat: 'existant' }
  if (existants.length === 0) return { ...ligne, etat: 'ajoute' }
  if (nouveaux.length === 0) return { ...ligne, etat: 'identique' }
  return { ...ligne, etat: role === 'parents' ? 'divergent' : 'ajoute' }
}

/**
 * La comparaison complète d'une saisie à une correspondance, dans l'ordre du
 * formulaire : identité, dates, proches — et, pour une de mes demandes, la note
 * qui s'ajoutera à la sienne.
 */
export function comparer(saisie: SaisieCreation, correspondance: Correspondance): LigneComparee[] {
  const fiche = saisie.brouillon
  const existant = correspondance.source === 'arbre' ? correspondance.personne : correspondance.demande
  const sexeSaisi = fiche.sex === 'inconnu' ? '' : LIBELLES_SEXE[fiche.sex]
  const sexeExistant = existant.sex === 'inconnu' ? '' : LIBELLES_SEXE[existant.sex]

  const proches =
    correspondance.source === 'arbre'
      ? {
          parents: prochesDeLArbre(correspondance.personne.parents),
          conjoints: prochesDeLArbre(correspondance.personne.conjoints),
          enfants: prochesDeLArbre(correspondance.personne.enfants),
        }
      : prochesDeLaDemande(correspondance.demande.liens)
  const saisis = prochesSaisis(saisie)

  const lignes: (LigneComparee | null)[] = [
    ligneTexte('givenNames', 'Prénoms', existant.givenNames, fiche.givenNames),
    ligneTexte('surname', 'Nom', existant.surname, fiche.surname),
    ligneTexte('marriedName', 'Nom d’usage', existant.marriedName, fiche.marriedName),
    ligneTexte('nickname', 'Surnom', existant.nickname, fiche.nickname),
    ligneTexte('sex', 'Sexe', sexeExistant, sexeSaisi),
    ligneTexte('deceased', 'Décès attesté', existant.deceased ? 'Oui' : '', fiche.deceased ? 'Oui' : ''),
    ligneDate('birth', 'Naissance', existant.birth, fiche.naissance),
    ligneDate('death', 'Décès', existant.death, fiche.deces),
    ligneProches('parents', proches.parents, saisis.parents),
    ligneProches('conjoints', proches.conjoints, saisis.conjoints),
    ligneProches('enfants', proches.enfants, saisis.enfants),
  ]

  if (correspondance.source === 'demande') {
    const note = fiche.note.trim()
    const avant = (correspondance.demande.note ?? '').trim()
    if (note !== '' || avant !== '') {
      lignes.push({
        cle: 'note',
        etiquette: 'Note',
        existant: avant,
        saisi: note,
        // La note **s'ajoute** à la précédente, elle ne la remplace jamais.
        etat: note === '' ? 'existant' : normaliser(note) === normaliser(avant) ? 'identique' : 'ajoute',
      })
    }
  }

  return lignes.filter((ligne): ligne is LigneComparee => ligne !== null)
}

/** Les lignes qui diraient quelque chose de neuf à l'existant. */
export function apporteQuelqueChose(lignes: readonly LigneComparee[]): boolean {
  return lignes.some((ligne) => ligne.etat === 'divergent' || ligne.etat === 'ajoute')
}

/* ------------------------------------------------------------------ */
/* Le récapitulatif, quand il n'y a pas de doublon                     */
/* ------------------------------------------------------------------ */

export interface LigneRecapitulatif {
  etiquette: string
  valeur: string
}

/** Ce qui va partir, en français, ligne par ligne — ce que l'administrateur
 *  lira. Les champs vides ne s'affichent pas. */
export function recapitulatifCreation(saisie: SaisieCreation): LigneRecapitulatif[] {
  const fiche = saisie.brouillon
  const saisis = prochesSaisis(saisie)
  const lignes: [string, string][] = [
    ['Prénoms', fiche.givenNames.trim()],
    ['Nom', fiche.surname.trim()],
    ['Nom d’usage', fiche.marriedName.trim()],
    ['Surnom', fiche.nickname.trim()],
    ['Sexe', fiche.sex === 'inconnu' ? '' : LIBELLES_SEXE[fiche.sex]],
    ['Décès attesté', fiche.deceased ? 'Oui' : ''],
    ['Naissance', texteDeSaisie(dateSaisie(fiche.naissance))],
    ['Décès', texteDeSaisie(dateSaisie(fiche.deces))],
    ['Parents', saisis.parents.map((proche) => proche.libelle).join(', ')],
    ['Conjoints', saisis.conjoints.map((proche) => proche.libelle).join(', ')],
    ['Enfants', saisis.enfants.map((proche) => proche.libelle).join(', ')],
    ['Note', fiche.note.trim()],
  ]

  return lignes.filter(([, valeur]) => valeur !== '').map(([etiquette, valeur]) => ({ etiquette, valeur }))
}

/* ------------------------------------------------------------------ */
/* Vers l'écran de modification d'une fiche existante                  */
/* ------------------------------------------------------------------ */

/**
 * Ce que « Proposer une modification de la fiche existante » emporte vers
 * `PropositionModificationView` : **les seuls champs divergents ou ajoutés**, et
 * les proches que la fiche n'a pas encore.
 *
 * Il voyage dans `history.state` (`router.push({ state })`) et non dans
 * l'adresse : une saisie d'état civil n'a rien à faire dans une URL, que
 * l'historique du navigateur et les journaux du serveur gardent. D'où des
 * valeurs simples, clonables, et une relecture défensive
 * ({@link lirePreRemplissage}).
 */
export interface PreRemplissageModification {
  personId: number
  champs: {
    givenNames?: string
    surname?: string
    marriedName?: string
    nickname?: string
    sex?: Sexe
    deceased?: boolean
  }
  naissance: SaisieDate | null
  deces: SaisieDate | null
  /** Seulement si la fiche n'a encore aucun parent : en désigner d'autres
   *  ferait une seconde filiation, ce qui n'est pas « compléter ». */
  parents: ChoixDePersonne[]
  unions: SaisieUnion[]
  enfants: { personne: ChoixDePersonne; rangFratrie: number | null }[]
}

export const CLE_PRE_REMPLISSAGE = 'preRemplissageModification'

export function preRemplissageModification(
  saisie: SaisieCreation,
  personne: PersonneDoublon,
): PreRemplissageModification {
  const lignes = comparer(saisie, { source: 'arbre', cle: '', personne })
  const aReprendre = new Set(
    lignes.filter((ligne) => ligne.etat === 'divergent' || ligne.etat === 'ajoute').map((ligne) => ligne.cle),
  )
  const fiche = saisie.brouillon
  const champs: PreRemplissageModification['champs'] = {}

  if (aReprendre.has('givenNames')) champs.givenNames = fiche.givenNames.trim()
  if (aReprendre.has('surname')) champs.surname = fiche.surname.trim()
  if (aReprendre.has('marriedName')) champs.marriedName = fiche.marriedName.trim()
  if (aReprendre.has('nickname')) champs.nickname = fiche.nickname.trim()
  if (aReprendre.has('sex')) champs.sex = fiche.sex
  if (aReprendre.has('deceased')) champs.deceased = true

  const conjoints = new Set(personne.conjoints.map((proche) => `personne:${proche.personId}`))
  const enfants = new Set(personne.enfants.map((proche) => `personne:${proche.personId}`))

  return {
    personId: personne.personId,
    champs,
    naissance: aReprendre.has('birth') ? fiche.naissance : null,
    deces: aReprendre.has('death') ? fiche.deces : null,
    parents: personne.parents.length === 0 ? saisie.filiation.parents : [],
    unions: saisie.unions.filter((union) => !conjoints.has(cleDuChoix(union.personne))),
    enfants: saisie.enfants
      .filter((enfant) => !enfants.has(cleDuChoix(enfant.personne)))
      .map((enfant) => ({ personne: enfant.personne, rangFratrie: enfant.rangFratrie })),
  }
}

/* --- Relecture défensive de `history.state` ------------------------ */

function objet(valeur: unknown): Record<string, unknown> | null {
  return typeof valeur === 'object' && valeur !== null && !Array.isArray(valeur)
    ? (valeur as Record<string, unknown>)
    : null
}

function lireChoix(valeur: unknown): ChoixDePersonne | null {
  const bloc = objet(valeur)
  if (bloc === null || typeof bloc['libelle'] !== 'string') return null
  if (typeof bloc['personId'] === 'number') return { personId: bloc['personId'], libelle: bloc['libelle'] }
  if (typeof bloc['draftPersonId'] === 'number' && typeof bloc['demandeId'] === 'number') {
    return { draftPersonId: bloc['draftPersonId'], demandeId: bloc['demandeId'], libelle: bloc['libelle'] }
  }
  return null
}

function lireSaisieDate(valeur: unknown): SaisieDate | null {
  const bloc = objet(valeur)
  if (bloc === null) return null
  const vide = saisieVide()
  const forme = bloc['forme']
  const formes: readonly SaisieDate['forme'][] = ['connue', 'environ', 'entre', 'avant', 'apres', 'inconnue']
  return {
    forme: formes.find((connue) => connue === forme) ?? vide.forme,
    debut: typeof bloc['debut'] === 'string' ? bloc['debut'] : '',
    fin: typeof bloc['fin'] === 'string' ? bloc['fin'] : '',
    lieu: typeof bloc['lieu'] === 'string' ? bloc['lieu'] : '',
    // Une origine ne voyage pas : la saisie vient d'un formulaire de création,
    // dont les dates n'ont jamais été lues sur une fiche.
    origine: null,
  }
}

/**
 * Relit ce que `history.state` porte, **pour cette fiche seulement** — un état
 * resté d'une autre navigation ne doit rien pré-remplir.
 */
export function lirePreRemplissage(etat: unknown, personId: number): PreRemplissageModification | null {
  const bloc = objet(objet(etat)?.[CLE_PRE_REMPLISSAGE])
  if (bloc === null || bloc['personId'] !== personId) return null

  const champsBruts = objet(bloc['champs']) ?? {}
  const champs: PreRemplissageModification['champs'] = {}
  for (const cle of ['givenNames', 'surname', 'marriedName', 'nickname'] as const) {
    const valeur = champsBruts[cle]
    if (typeof valeur === 'string') champs[cle] = valeur
  }
  const sexe = champsBruts['sex']
  if (sexe === 'masculin' || sexe === 'feminin' || sexe === 'inconnu') champs.sex = sexe
  if (champsBruts['deceased'] === true) champs.deceased = true

  const liste = (valeur: unknown): unknown[] => (Array.isArray(valeur) ? valeur : [])

  return {
    personId,
    champs,
    naissance: lireSaisieDate(bloc['naissance']),
    deces: lireSaisieDate(bloc['deces']),
    parents: liste(bloc['parents'])
      .map(lireChoix)
      .filter((choix): choix is ChoixDePersonne => choix !== null)
      .slice(0, 2),
    unions: liste(bloc['unions']).flatMap((brut) => {
      const union = objet(brut)
      const personne = lireChoix(union?.['personne'])
      if (union === null || personne === null) return []
      return [
        {
          personne,
          mariage: lireSaisieDate(union['mariage']) ?? saisieVide(),
          rompue: union['rompue'] === true,
          rupture: lireSaisieDate(union['rupture']) ?? saisieVide(),
          note: typeof union['note'] === 'string' ? union['note'] : '',
        },
      ]
    }),
    enfants: liste(bloc['enfants']).flatMap((brut) => {
      const enfant = objet(brut)
      const personne = lireChoix(enfant?.['personne'])
      if (enfant === null || personne === null) return []
      const rang = enfant['rangFratrie']
      return [{ personne, rangFratrie: typeof rang === 'number' ? rang : null }]
    }),
  }
}
