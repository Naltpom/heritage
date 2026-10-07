/**
 * **Le mode édition de la fiche** — ce qui change, ce qui partira, et en quelles
 * demandes.
 *
 * Un module pur, éprouvé par `edition.test.ts` sans monter de composant : les
 * composants (`EditionIdentite`, `EditionParente`, `ModaleCorrection`) ne font que
 * dessiner l'état décrit ici, et `PageFiche` que le tenir.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Rien ne s'écrit : tout part en demandes
 *
 * Le crayon ne rend pas la fiche modifiable, il rend **la proposition** plus
 * directe : on corrige sur la fiche elle-même au lieu d'un formulaire à part, et
 * « Corriger » dépose ce qui a changé, comme l'écran de modification le faisait
 * déjà. Une demande porte **une** nature, si bien qu'une session d'édition en
 * dépose jusqu'à trois :
 *
 * | Ce qui a changé | Nature | Forme |
 * |---|---|---|
 * | des champs d'identité | `modification_personne` | un op par champ (`operations.ts`) |
 * | des liens retirés | `retrait_lien` | **une** demande, un retrait par lien |
 * | des liens ajoutés | `ajout_lien` | **une** demande, un lien par ajout (`liens.ts`) |
 * | les dates d'une union existante | `modification_famille` | **une** demande par union, un op par champ |
 *
 * Trois demandes et non une par lien : l'administrateur décide d'une correction,
 * pas de cinq morceaux qu'il pourrait valider à moitié. Et pas une seule pour le
 * tout, parce que les trois natures ne vivent pas dans les mêmes tables (§ 5.7).
 *
 * ## Le cliché est pris à l'entrée, et il ne bouge plus
 *
 * Même règle que l'écran de modification (`operations.ts`, § 9.6) : `baseVersion`
 * et `oldValue` voyagent tels que l'auteur les a **lus** en cliquant sur le
 * crayon. Les champs repartent des valeurs **validées**, jamais du calque
 * « en attente ».
 *
 * ## Les liens, et ce que chaque croix veut dire
 *
 * - **un parent** : « ce n'est pas son parent ». L'enfant perd **ce** parent et
 *   reste celui de l'autre (`enfant_de` + `parentId`, § 6.4 quinquies). Les deux
 *   parents d'un même couple barrés se replient en un seul retrait, sans
 *   `parentId` : il n'est l'enfant ni de l'un ni de l'autre ;
 * - **un conjoint** : « ils n'étaient pas unis ». Le conjoint quitte l'union ; ses
 *   enfants y restent, avec la fiche pour seul parent, et l'union ne disparaît que
 *   si elle ne porte plus rien — c'est le serveur qui le mesure ;
 * - **un enfant** (sous une union) : « il n'est pas l'enfant de ce couple ». Il
 *   quitte l'union, et perd les deux parents qu'elle nomme ;
 * - **un frère ou une sœur** : pas de croix. La fratrie **se déduit** des parents
 *   — « enfant du même couple » (§ 6.4) — et n'a pas de lien à elle : on retire le
 *   frère depuis sa propre fiche, en retirant ses parents. L'écran le dit.
 *
 * ## Les dates d'une union : mariage (date, lieu) et divorce (date)
 *
 * Chaque union — existante ou ajoutée — porte trois cases : date du mariage,
 * lieu du mariage, date du divorce. **Une date de divorce vide veut dire « pas
 * divorcés »** ; une date saisie coche le divorce. Seule exception, pour ne
 * rien effacer qu'on n'a pas touché : une union déjà marquée divorcée **sans**
 * date le reste tant qu'on ne touche pas à sa case.
 * ────────────────────────────────────────────────────────────────────
 */

import type {
  BlocDate,
  CorpsAjoutLien,
  CorpsModificationFamille,
  CorpsModificationPersonne,
  CorpsRetraitLien,
  FichePersonneComplete,
  OpProposee,
  RetraitPropose,
  UnionDeFiche,
  ValeurDate,
} from '@/lib/api'
import { LIBELLES_SEXE, descriptionDeChamp, estChampDate, type ChampPersonne } from '@/components/proposition/champs'
import { cleDuChoix, type ChoixDePersonne, type ErreurSaisie } from '@/components/proposition/creation'
import {
  designeUneDate,
  memeValeurDate,
  sansTexte,
  saisieDepuisBloc,
  valeurAEnvoyer,
  videVautAucuneDate,
  type ResultatSaisieDate,
  type SaisieDate,
} from '@/components/proposition/dates'
import { corpsAjoutLien, liensVides, type SaisieLiens } from '@/components/proposition/liens'
import {
  construireOps,
  corpsModificationPersonne,
  instantaneDeFiche,
  saisieDepuisFiche,
  valeursDepuisSaisie,
  type InstantaneFiche,
  type SaisieModification,
} from '@/components/proposition/operations'

/* ------------------------------------------------------------------ */
/* L'état                                                              */
/* ------------------------------------------------------------------ */

/**
 * Un lien que la fiche affiche et qu'une croix peut retirer.
 *
 * `cle` est stable d'un rendu à l'autre — c'est elle que l'état retient —, et
 * `familleId` est **la famille du lien**, pas une famille déduite : c'est celle
 * que la fiche a reçue (§ 5.3), donc celle que le serveur reconnaîtra.
 */
export type LienAffiche =
  | { type: 'parent'; cle: string; personId: number; libelle: string; familleId: number }
  | { type: 'conjoint'; cle: string; personId: number; libelle: string; familleId: number }
  | { type: 'enfant'; cle: string; personId: number; libelle: string; familleId: number }

export function cleDeParent(personId: number, familleId: number): string {
  return `parent:${personId}:${familleId}`
}
export function cleDeConjoint(personId: number, familleId: number): string {
  return `conjoint:${personId}:${familleId}`
}
export function cleDEnfant(personId: number, familleId: number): string {
  return `enfant:${personId}:${familleId}`
}

/** Un enfant à rattacher : dans une union de la fiche, ou d'un parent seul. */
export interface EnfantAjoute {
  personne: ChoixDePersonne
  /** L'union de la fiche où il entre, ou `null` : la fiche seule pour parent. */
  familleId: number | null
}

export interface AjoutsDeLiens {
  parents: ChoixDePersonne[]
  conjoints: ChoixDePersonne[]
  enfants: EnfantAjoute[]
}

/** Les trois cases d'une union : le mariage (date et lieu), le divorce (date). */
export interface DatesDUnion {
  mariage: SaisieDate
  divorce: SaisieDate
}

/** Une union telle que le crayon l'a lue — le cliché de `modification_famille`. */
export interface UnionInitiale {
  familleId: number
  version: number
  marriage: ValeurDate | null
  divorced: boolean
  divorce: ValeurDate | null
}

export interface EtatEdition {
  /** Le cliché du crayon : jamais rafraîchi tant qu'on édite (§ 9.6). */
  instantane: InstantaneFiche
  saisie: SaisieModification
  /** Les clés des liens barrés ({@link LienAffiche}). */
  retraits: string[]
  ajouts: AjoutsDeLiens
  /** Les unions de la fiche à l'entrée en édition — même règle que `instantane`. */
  unionsInitiales: UnionInitiale[]
  /** Les dates saisies, par {@link cleDUnion} (union existante) ou
   *  {@link cleDUnionAjoutee} (conjoint ajouté). Une clé absente vaut des cases vides. */
  unions: Record<string, DatesDUnion>
}

export function cleDUnion(familleId: number): string {
  return `famille:${familleId}`
}
export function cleDUnionAjoutee(conjoint: ChoixDePersonne): string {
  return `ajout:${cleDuChoix(conjoint)}`
}

/**
 * Une date d'union **s'ouvre sur la case de date** : un bloc vide ou sans date
 * passe en « Date connue », que {@link videVautAucuneDate} ramène à « pas de
 * date » si on la laisse vide. Le lieu seul est conservé.
 */
export function saisieDeDateDUnion(bloc: BlocDate | ValeurDate | null): SaisieDate {
  const saisie = saisieDepuisBloc(bloc)
  return saisie.forme === 'inconnue' ? { ...saisie, forme: 'connue' } : saisie
}

export function datesDUnionVides(): DatesDUnion {
  return { mariage: saisieDeDateDUnion(null), divorce: saisieDeDateDUnion(null) }
}

function datesDepuisUnion(union: UnionDeFiche): DatesDUnion {
  return { mariage: saisieDeDateDUnion(union.marriage), divorce: saisieDeDateDUnion(union.divorce) }
}

export function entrerEnEdition(fiche: FichePersonneComplete): EtatEdition {
  return {
    instantane: instantaneDeFiche(fiche),
    saisie: saisieDepuisFiche(fiche),
    retraits: [],
    ajouts: { parents: [], conjoints: [], enfants: [] },
    unionsInitiales: fiche.unions.map((union) => ({
      familleId: union.familyId,
      version: union.version,
      marriage: union.marriage === null ? null : sansTexte(union.marriage),
      divorced: union.divorced,
      divorce: union.divorce === null ? null : sansTexte(union.divorce),
    })),
    unions: Object.fromEntries(fiche.unions.map((union) => [cleDUnion(union.familyId), datesDepuisUnion(union)])),
  }
}

/** Tous les liens qu'une croix peut retirer, dans l'ordre de la fiche. */
export function liensAffiches(fiche: FichePersonneComplete): LienAffiche[] {
  const liens: LienAffiche[] = []

  for (const parent of fiche.parents) {
    liens.push({
      type: 'parent',
      cle: cleDeParent(parent.personId, parent.familyId),
      personId: parent.personId,
      libelle: parent.displayName,
      familleId: parent.familyId,
    })
  }

  for (const union of fiche.unions) {
    if (union.conjoint !== null) {
      liens.push({
        type: 'conjoint',
        cle: cleDeConjoint(union.conjoint.personId, union.familyId),
        personId: union.conjoint.personId,
        libelle: union.conjoint.displayName,
        familleId: union.familyId,
      })
    }
    for (const enfant of union.enfants) {
      liens.push({
        type: 'enfant',
        cle: cleDEnfant(enfant.personId, union.familyId),
        personId: enfant.personId,
        libelle: enfant.displayName,
        familleId: union.familyId,
      })
    }
  }

  return liens
}

/** Barre ou débarre un lien. Rend un nouveau tableau : l'état reste immuable. */
export function basculerRetrait(retraits: readonly string[], cle: string): string[] {
  return retraits.includes(cle) ? retraits.filter((c) => c !== cle) : [...retraits, cle]
}

/**
 * Combien de parents la fiche aura après l'édition — au plus deux : c'est un
 * couple, pas une liste. Sert à éteindre la recherche d'un troisième.
 */
export function parentsApresEdition(fiche: FichePersonneComplete, etat: Pick<EtatEdition, 'retraits' | 'ajouts'>): number {
  const restants = fiche.parents.filter((p) => !etat.retraits.includes(cleDeParent(p.personId, p.familyId)))
  return restants.length + etat.ajouts.parents.length
}

/**
 * Les personnes qu'on ne peut plus désigner dans une recherche : la fiche, et
 * tous ceux qui lui sont déjà liés ou déjà ajoutés. Le sélecteur les affiche,
 * éteintes (`SelecteurPersonne`, `dejaChoisis`).
 */
export function dejaLies(fiche: FichePersonneComplete, etat: Pick<EtatEdition, 'retraits' | 'ajouts'>): string[] {
  const personnes: ChoixDePersonne[] = [
    { personId: fiche.id, libelle: fiche.displayName },
    ...fiche.parents.map((p) => ({ personId: p.personId, libelle: p.displayName })),
    ...fiche.unions.flatMap((u) => [
      ...(u.conjoint === null ? [] : [{ personId: u.conjoint.personId, libelle: u.conjoint.displayName }]),
      ...u.enfants.map((e) => ({ personId: e.personId, libelle: e.displayName })),
    ]),
    ...etat.ajouts.parents,
    ...etat.ajouts.conjoints,
    ...etat.ajouts.enfants.map((e) => e.personne),
  ]
  return [...new Set(personnes.map(cleDuChoix))]
}

/* ------------------------------------------------------------------ */
/* Les retraits, en corps de demande                                   */
/* ------------------------------------------------------------------ */

/**
 * Les retraits du corps `retrait_lien`, depuis les croix.
 *
 * **Les deux parents d'un même couple se replient en un retrait sans
 * `parentId`** : « il n'est l'enfant ni de l'un ni de l'autre » est un seul
 * geste, et deux retraits à `parentId` ne le diraient pas — le second
 * rattacherait l'enfant au parent que le premier vient de lui retirer.
 */
export function retraitsDemandes(fiche: FichePersonneComplete, retraits: readonly string[]): RetraitPropose[] {
  const barres = liensAffiches(fiche).filter((lien) => retraits.includes(lien.cle))
  const sortie: RetraitPropose[] = []

  const parentsParFamille = new Map<number, number[]>()
  for (const lien of barres) {
    if (lien.type !== 'parent') continue
    parentsParFamille.set(lien.familleId, [...(parentsParFamille.get(lien.familleId) ?? []), lien.personId])
  }

  for (const [familleId, barresDeLaFamille] of parentsParFamille) {
    const tous = fiche.parents.filter((p) => p.familyId === familleId).map((p) => p.personId)
    if (barresDeLaFamille.length >= tous.length || tous.length < 2) {
      sortie.push({ kind: 'enfant_de', personId: fiche.id, familyId: familleId })
    } else {
      for (const parentId of barresDeLaFamille) {
        sortie.push({ kind: 'enfant_de', personId: fiche.id, familyId: familleId, parentId })
      }
    }
  }

  for (const lien of barres) {
    if (lien.type === 'conjoint') {
      sortie.push({ kind: 'conjoint_de', personId: lien.personId, familyId: lien.familleId })
    } else if (lien.type === 'enfant') {
      sortie.push({ kind: 'enfant_de', personId: lien.personId, familyId: lien.familleId })
    }
  }

  return sortie
}

/* ------------------------------------------------------------------ */
/* Les ajouts, en corps de demande                                     */
/* ------------------------------------------------------------------ */

/** Les ajouts dans la forme de `liens.ts`, qui sait déjà composer `ajout_lien`. */
export function saisieDesAjouts(
  fiche: FichePersonneComplete,
  ajouts: AjoutsDeLiens,
  unions: Readonly<Record<string, DatesDUnion>> = {},
): SaisieLiens {
  return {
    ...liensVides(fiche.id),
    parents: [...ajouts.parents],
    unions: ajouts.conjoints.map((personne) => {
      const dates = unions[cleDUnionAjoutee(personne)] ?? datesDUnionVides()
      const divorce = valeurDUnion(dates.divorce)
      return {
        personne,
        mariage: videVautAucuneDate(dates.mariage),
        // Une date de divorce coche le divorce ; une date illisible aussi, pour
        // que l'erreur remonte au lieu d'être tue.
        rompue: !divorce.valide || divorce.valeur !== null,
        rupture: videVautAucuneDate(dates.divorce),
        note: '',
      }
    }),
    enfants: ajouts.enfants.map((enfant) => ({ personne: enfant.personne, familleId: enfant.familleId, rangFratrie: null })),
  }
}

export function nombreDAjouts(ajouts: AjoutsDeLiens): number {
  return ajouts.parents.length + ajouts.conjoints.length + ajouts.enfants.length
}

/* ------------------------------------------------------------------ */
/* Les dates d'union, en corps de demande                              */
/* ------------------------------------------------------------------ */

/** La valeur d'une case de date d'union : vide, c'est « pas de date ». */
export function valeurDUnion(saisie: SaisieDate): ResultatSaisieDate {
  return valeurAEnvoyer(videVautAucuneDate(saisie))
}

/** Le nom du conjoint d'une union, pour les messages et le récapitulatif. */
function libelleDUnion(fiche: FichePersonneComplete, familleId: number): string {
  const conjoint = fiche.unions.find((u) => u.familyId === familleId)?.conjoint
  return conjoint ? `avec ${conjoint.displayName}` : 'conjoint inconnu'
}

interface DatesConverties {
  marriage: ValeurDate | null
  divorced: boolean
  divorce: ValeurDate | null
}

/**
 * Convertit les trois cases, ou pousse ce qui ne va pas dans `erreurs`.
 *
 * `avant` est l'union telle qu'elle était lue (`null` pour un conjoint ajouté) :
 * c'est lui qui garde « divorcés, date inconnue » quand on ne touche à rien.
 */
function convertirDates(
  dates: DatesDUnion,
  avant: UnionInitiale | null,
  libelle: string,
  erreurs: ErreurSaisie[],
  prefixe: string,
): DatesConverties | null {
  const mariage = valeurDUnion(dates.mariage)
  const divorce = valeurDUnion(dates.divorce)
  if (!mariage.valide) erreurs.push({ champ: `${prefixe}.mariage`, message: `Mariage ${libelle} : ${mariage.message}` })
  if (!divorce.valide) erreurs.push({ champ: `${prefixe}.divorce`, message: `Divorce ${libelle} : ${divorce.message}` })
  if (!mariage.valide || !divorce.valide) return null

  // `ck_tree_family_divorce_apres_mariage` : dit ici plutôt qu'en erreur de base.
  const debutMariage = mariage.valeur?.min ?? null
  const debutDivorce = divorce.valeur?.min ?? null
  if (debutMariage !== null && debutDivorce !== null && debutDivorce < debutMariage) {
    erreurs.push({ champ: `${prefixe}.divorce`, message: `Divorce ${libelle} : il ne peut pas précéder le mariage.` })
    return null
  }

  const avecDate = divorce.valeur !== null && designeUneDate(divorce.valeur)
  const inchange = avant !== null && memeValeurDate(blocDOp(divorce.valeur), blocDOp(avant.divorce))
  return {
    marriage: mariage.valeur,
    divorced: avecDate || (inchange && avant.divorced),
    divorce: divorce.valeur,
  }
}

/**
 * « Pas de date », tel qu'un op le transporte : le serveur exige toujours un bloc
 * à cinq clés et refuse `null` (`NormaliseurDeValeur::blocDate`). C'est aussi ce
 * qu'il relit pour une date inconnue, donc ce que `oldValue` doit annoncer.
 */
const AUCUNE_DATE: ValeurDate = { raw: null, qualifier: 'inconnue', min: null, max: null, place: null }

function blocDOp(valeur: ValeurDate | null): ValeurDate {
  return valeur ?? AUCUNE_DATE
}

/** Les ops d'une union existante : un par champ qui a changé. */
function opsDUnion(avant: UnionInitiale, apres: DatesConverties): OpProposee[] {
  const ops: OpProposee[] = []
  const op = (field: string, valueKind: string, oldValue: OpProposee['oldValue'], newValue: OpProposee['newValue']) =>
    ops.push({
      seq: ops.length + 1,
      opKind: 'champ',
      subjectKind: 'famille',
      subjectId: avant.familleId,
      field,
      valueKind,
      oldValue,
      newValue,
      baseVersion: avant.version,
    })

  const date = (field: string, ancienne: ValeurDate | null, nouvelle: ValeurDate | null) => {
    if (!memeValeurDate(blocDOp(ancienne), blocDOp(nouvelle))) {
      op(field, 'date_incertaine', blocDOp(ancienne), blocDOp(nouvelle))
    }
  }
  date('marriage', avant.marriage, apres.marriage)
  if (avant.divorced !== apres.divorced) op('divorced', 'booleen', avant.divorced, apres.divorced)
  date('divorce', avant.divorce, apres.divorce)
  return ops
}

/** Une union dont le conjoint est barré part avec la croix : on ne la date pas. */
function unionRetiree(fiche: FichePersonneComplete, etat: EtatEdition, familleId: number): boolean {
  const conjoint = fiche.unions.find((u) => u.familyId === familleId)?.conjoint
  return conjoint !== undefined && conjoint !== null && etat.retraits.includes(cleDeConjoint(conjoint.personId, familleId))
}

export interface UnionModifiee {
  familleId: number
  avant: UnionInitiale
  ops: OpProposee[]
}

/** Les unions existantes dont une date a changé, avec leurs ops. */
export function unionsModifiees(
  fiche: FichePersonneComplete,
  etat: EtatEdition,
  erreurs: ErreurSaisie[] = [],
): UnionModifiee[] {
  const sortie: UnionModifiee[] = []
  for (const avant of etat.unionsInitiales) {
    if (unionRetiree(fiche, etat, avant.familleId)) continue
    const dates = etat.unions[cleDUnion(avant.familleId)]
    if (dates === undefined) continue
    const apres = convertirDates(dates, avant, libelleDUnion(fiche, avant.familleId), erreurs, cleDUnion(avant.familleId))
    if (apres === null) continue
    const ops = opsDUnion(avant, apres)
    if (ops.length > 0) sortie.push({ familleId: avant.familleId, avant, ops })
  }
  return sortie
}

/** Les erreurs des dates saisies pour les conjoints ajoutés. */
function erreursDesAjouts(etat: EtatEdition): ErreurSaisie[] {
  const erreurs: ErreurSaisie[] = []
  for (const conjoint of etat.ajouts.conjoints) {
    const cle = cleDUnionAjoutee(conjoint)
    convertirDates(etat.unions[cle] ?? datesDUnionVides(), null, `avec ${conjoint.libelle}`, erreurs, cle)
  }
  return erreurs
}

/* ------------------------------------------------------------------ */
/* Le récapitulatif                                                    */
/* ------------------------------------------------------------------ */

export interface Changement {
  nature: 'champ' | 'retrait' | 'ajout'
  /** « Naissance : 1894 → 18 mai 1893 », « Lien retiré : enfant de X ». */
  texte: string
}

const MOIS: Record<string, string> = {
  JAN: 'janvier',
  FEB: 'février',
  MAR: 'mars',
  APR: 'avril',
  MAY: 'mai',
  JUN: 'juin',
  JUL: 'juillet',
  AUG: 'août',
  SEP: 'septembre',
  OCT: 'octobre',
  NOV: 'novembre',
  DEC: 'décembre',
}

const MOTS: Record<string, string> = {
  ABT: 'vers',
  EST: 'vers',
  CAL: 'vers',
  BEF: 'avant',
  AFT: 'après',
  BET: 'entre',
  AND: 'et',
  FROM: 'depuis',
  TO: 'jusqu’à',
}

/**
 * Une chaîne GEDCOM en français : `18 MAY 1893` → « 18 mai 1893 »,
 * `BET 1901 AND 1909` → « entre 1901 et 1909 ».
 *
 * **Une traduction mot à mot, jamais une recomposition depuis `min` et `max`** :
 * une fourchette reste une fourchette (§ 14), et ce qu'on lit est ce que la
 * demande enverra dans `raw`. Elle ne sert qu'aux valeurs **saisies**, qui n'ont
 * pas encore de `texte` ; une valeur reçue s'affiche par le `texte` du serveur.
 */
export function gedcomEnFrancais(raw: string): string {
  return raw
    .trim()
    .split(/\s+/)
    .map((mot, index, mots) => {
      const haut = mot.toUpperCase()
      if (haut in MOIS) return MOIS[haut]!
      if (haut in MOTS) return MOTS[haut]!
      // « 1 MAY » → « 1er mai » : la règle typographique française.
      if (mot === '1' && (mots[index + 1]?.toUpperCase() ?? '') in MOIS) return '1er'
      return mot
    })
    .join(' ')
}

/** Une date lisible : le `texte` du serveur quand il existe, sinon la chaîne saisie. */
export function dateLisible(bloc: BlocDate | ValeurDate | null): string {
  if (bloc === null) return '(vide)'
  const texte = 'texte' in bloc && bloc.texte.length > 0 ? bloc.texte : null
  const principal = texte ?? (bloc.raw !== null && bloc.raw.length > 0 ? gedcomEnFrancais(bloc.raw) : null)
  const date = principal ?? 'date inconnue'
  return bloc.place === null || bloc.place.length === 0 ? date : `${date}, ${bloc.place}`
}

function valeurLisible(champ: ChampPersonne, valeur: unknown, fiche: FichePersonneComplete, avant: boolean): string {
  if (estChampDate(champ)) {
    // L'ancienne date s'écrit avec le `texte` du serveur, tel que la fiche l'a
    // affiché : c'est la phrase que l'auteur a lue et qu'il corrige.
    if (avant) return dateLisible(fiche[champ])
    return dateLisible((valeur ?? null) as ValeurDate | null)
  }
  if (valeur === null || valeur === undefined || valeur === '') return '(vide)'
  if (typeof valeur === 'boolean') return valeur ? 'oui' : 'non'
  if (champ === 'sex' && typeof valeur === 'string' && valeur in LIBELLES_SEXE) {
    return LIBELLES_SEXE[valeur as keyof typeof LIBELLES_SEXE]
  }
  return String(valeur)
}

/**
 * Tous les changements de la session, dans l'ordre où on les relit : les champs,
 * puis les liens retirés, puis les liens ajoutés.
 */
export function recapitulatif(fiche: FichePersonneComplete, etat: EtatEdition): Changement[] {
  const changements: Changement[] = []

  const conversion = valeursDepuisSaisie(etat.saisie)
  if (conversion.valeurs !== null) {
    for (const op of construireOps(etat.instantane, conversion.valeurs)) {
      const champ = op.field as ChampPersonne
      changements.push({
        nature: 'champ',
        texte: `${descriptionDeChamp(champ).etiquette} : ${valeurLisible(champ, op.oldValue, fiche, true)} → ${valeurLisible(champ, op.newValue, fiche, false)}`,
      })
    }
  }

  const nom = (id: number): string =>
    liensAffiches(fiche).find((lien) => lien.personId === id)?.libelle ?? `fiche ${id}`
  const couple = (familleId: number): string =>
    fiche.parents
      .filter((p) => p.familyId === familleId)
      .map((p) => p.displayName)
      .join(' et ')

  for (const retrait of retraitsDemandes(fiche, etat.retraits)) {
    let texte: string
    if (retrait.kind === 'conjoint_de') {
      texte = `conjoint de ${nom(retrait.personId)}`
    } else if (retrait.personId !== fiche.id) {
      texte = `parent de ${nom(retrait.personId)}`
    } else if (retrait.parentId !== undefined) {
      texte = `enfant de ${nom(retrait.parentId)}`
    } else {
      texte = `enfant de ${couple(retrait.familyId) || 'cette famille'}`
    }
    changements.push({ nature: 'retrait', texte: `Lien retiré : ${texte}` })
  }

  for (const parent of etat.ajouts.parents) {
    changements.push({ nature: 'ajout', texte: `Lien ajouté : enfant de ${parent.libelle}` })
  }
  for (const union of unionsModifiees(fiche, etat)) {
    for (const op of union.ops) {
      const quoi = op.field === 'marriage' ? 'mariage' : op.field === 'divorce' ? 'date du divorce' : 'divorcés'
      const lire = (v: unknown) => {
        if (typeof v === 'boolean') return v ? 'oui' : 'non'
        const bloc = (v ?? null) as ValeurDate | null
        return designeUneDate(bloc) ? dateLisible(bloc) : '(vide)'
      }
      changements.push({
        nature: 'champ',
        texte: `Union ${libelleDUnion(fiche, union.familleId)}, ${quoi} : ${lire(op.oldValue)} → ${lire(op.newValue)}`,
      })
    }
  }
  for (const conjoint of etat.ajouts.conjoints) {
    const dates = etat.unions[cleDUnionAjoutee(conjoint)] ?? datesDUnionVides()
    const mariage = valeurDUnion(dates.mariage)
    const divorce = valeurDUnion(dates.divorce)
    const details = [
      mariage.valide && mariage.valeur !== null ? `mariage : ${dateLisible(mariage.valeur)}` : null,
      divorce.valide && divorce.valeur !== null ? `divorce : ${dateLisible(divorce.valeur)}` : null,
    ].filter((d) => d !== null)
    const suite = details.length > 0 ? ` (${details.join(' ; ')})` : ''
    changements.push({ nature: 'ajout', texte: `Lien ajouté : conjoint de ${conjoint.libelle}${suite}` })
  }
  for (const enfant of etat.ajouts.enfants) {
    const union = fiche.unions.find((u) => u.familyId === enfant.familleId)
    const avec = union?.conjoint ? ` (avec ${union.conjoint.displayName})` : ''
    changements.push({ nature: 'ajout', texte: `Lien ajouté : parent de ${enfant.personne.libelle}${avec}` })
  }

  return changements
}

/* ------------------------------------------------------------------ */
/* Les demandes à déposer                                              */
/* ------------------------------------------------------------------ */

export type CorpsDEdition = CorpsModificationPersonne | CorpsRetraitLien | CorpsAjoutLien | CorpsModificationFamille

/** Ce qui distingue deux dépôts d'une même session : la nature, et la cible
 *  pour les unions — deux unions corrigées font deux demandes. */
export function cleDeDepot(corps: CorpsDEdition): string {
  return corps.kind === 'modification_famille' ? `${corps.kind}:${corps.targetId}` : corps.kind
}

export type DemandesDEdition =
  | { valide: true; corps: CorpsDEdition[] }
  | { valide: false; erreurs: ErreurSaisie[] }

/**
 * Les corps à poster, **dans cet ordre** : les champs, les retraits, les ajouts.
 *
 * Les retraits avant les ajouts : un parent retiré puis un autre ajouté se lisent
 * dans cet ordre, et c'est l'ordre dans lequel l'administrateur les trouvera
 * dans sa file. Chaque demande reste indépendante — la valider ou la refuser ne
 * touche pas aux deux autres.
 *
 * Les erreurs de saisie (une date mal écrite, un décès daté sans « décédée »)
 * bloquent **tout** : on ne dépose pas les liens en laissant les champs derrière.
 */
export function demandesDEdition(fiche: FichePersonneComplete, etat: EtatEdition, message: string): DemandesDEdition {
  const options = { message, soumettre: true }
  const corps: CorpsDEdition[] = []

  const conversion = valeursDepuisSaisie(etat.saisie)
  if (conversion.valeurs === null) {
    return {
      valide: false,
      erreurs: Object.entries(conversion.erreurs).map(([champ, texte]) => ({ champ, message: texte })),
    }
  }

  const erreursUnions: ErreurSaisie[] = []
  const unions = unionsModifiees(fiche, etat, erreursUnions)
  erreursUnions.push(...erreursDesAjouts(etat))
  if (erreursUnions.length > 0) return { valide: false, erreurs: erreursUnions }

  if (construireOps(etat.instantane, conversion.valeurs).length > 0) {
    corps.push(corpsModificationPersonne(etat.instantane, conversion.valeurs, options))
  }

  const retraits = retraitsDemandes(fiche, etat.retraits)
  if (retraits.length > 0) {
    const retrait: CorpsRetraitLien = {
      kind: 'retrait_lien',
      soumettre: true,
      targetKind: 'personne',
      targetId: fiche.id,
      retraits,
    }
    const mot = message.trim()
    if (mot.length > 0) retrait.message = mot
    corps.push(retrait)
  }

  if (nombreDAjouts(etat.ajouts) > 0) {
    const ajout = corpsAjoutLien(saisieDesAjouts(fiche, etat.ajouts, etat.unions), options)
    if (!ajout.valide) return { valide: false, erreurs: ajout.erreurs }
    corps.push(ajout.corps)
  }

  for (const union of unions) {
    const modification: CorpsModificationFamille = {
      kind: 'modification_famille',
      soumettre: true,
      targetKind: 'famille',
      targetId: union.familleId,
      ops: union.ops,
    }
    const mot = message.trim()
    if (mot.length > 0) modification.message = mot
    corps.push(modification)
  }

  return { valide: true, corps }
}

/** La session a-t-elle quelque chose à envoyer — ou à perdre si on l'annule ? */
export function aDesModifications(etat: EtatEdition, fiche?: FichePersonneComplete): boolean {
  if (etat.retraits.length > 0 || nombreDAjouts(etat.ajouts) > 0) return true
  if (fiche !== undefined) {
    const erreurs: ErreurSaisie[] = []
    if (unionsModifiees(fiche, etat, erreurs).length > 0 || erreurs.length > 0) return true
  }
  const conversion = valeursDepuisSaisie(etat.saisie)
  // Une saisie qui ne se convertit pas a forcément été touchée : une fiche lue
  // se reconvertit toujours.
  if (conversion.valeurs === null) return true
  return construireOps(etat.instantane, conversion.valeurs).length > 0
}

/** Le libellé d'une nature déposée, pour l'annonce de fin. */
export const LIBELLES_DEPOT: Record<CorpsDEdition['kind'], string> = {
  modification_personne: 'Correction de la fiche',
  retrait_lien: 'Liens retirés',
  ajout_lien: 'Liens ajoutés',
  modification_famille: 'Dates d’union corrigées',
}
