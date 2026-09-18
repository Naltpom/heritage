/**
 * **Une demande est une liste d'opérations, pas un objet remplacé.**
 *
 * C'est la phrase qui décide de la forme de ce module, et elle vient du modèle :
 * « un membre ne modifie jamais directement. Il crée une `ChangeRequest` portant
 * des `ChangeRequestOp` — **un op par champ**, avec ancienne et nouvelle
 * valeur » (§ 6.3 du dossier). On n'envoie donc pas la fiche corrigée : on
 * envoie **ce qui change**, champ par champ, chacun avec la valeur qu'il avait.
 *
 * Trois conséquences, toutes contre-intuitives tant qu'on n'a pas lu le § 9.6 :
 *
 * 1. **`baseVersion` est figée à l'ouverture du formulaire, jamais relue à
 *    l'envoi.** Le scénario réel est *déposer lundi, valider vendredi* : la
 *    version qui doit voyager est celle que l'auteur avait **sous les yeux**.
 *    La rafraîchir en douce au moment du `POST` détruirait exactement la
 *    protection qu'elle apporte — la demande dirait « je pars de la version que
 *    le serveur avait il y a trois secondes » alors que l'auteur a lu la fiche
 *    une heure plus tôt. D'où `InstantaneFiche` : un cliché, pris une fois.
 * 2. **`oldValue` n'est pas `baseVersion` sous une autre forme.** C'est elle,
 *    et non la version, qui tranche à la validation entre **rejeu automatique**
 *    et **caducité** : si toutes les anciennes valeurs correspondent encore, la
 *    demande est rejouée sur la version courante ; si une seule a bougé, elle
 *    devient caduque (§ 9.6). Envoyer une `oldValue` approximative, c'est
 *    fabriquer des caducités ou des écrasements silencieux.
 * 3. **Un champ inchangé ne produit pas d'op.** `ck_prp_op_changement` refuse
 *    en base un op dont l'ancienne et la nouvelle valeur coïncident, et le
 *    contrat rend `422` avant. Filtrer ici plutôt que découvrir le refus après
 *    l'envoi, c'est la différence entre un formulaire qui marche et un
 *    formulaire qui se plaint.
 *
 * **Un bloc date est un seul op, jamais cinq.** Cinq ops permettraient à
 * l'administrateur d'en valider trois et de fabriquer une date incohérente —
 * « exactement l'aplatissement que le § 9.1 interdit ». Le `field` est donc le
 * nom du bloc, `birth`, jamais `birthMin` : le type `ChampPersonne` de
 * `champs.ts` rend l'autre écriture impossible.
 */

import type {
  CorpsModificationPersonne,
  FichePersonneComplete,
  OpProposee,
  OrigineReprise,
  Sexe,
  ValeurDate,
} from '@/lib/api'
import { CHAMPS_PERSONNE, descriptionDeChamp, estChampDate, type ChampPersonne } from './champs'
import { memeValeurDate, saisieDepuisBloc, sansTexte, valeurAEnvoyer, type SaisieDate } from './dates'

/* ------------------------------------------------------------------ */
/* L'état du formulaire                                                */
/* ------------------------------------------------------------------ */

/** Les dix champs de la surface éditable, dans la forme où ils voyagent. */
export interface ValeursPersonne {
  givenNames: string | null
  surname: string | null
  marriedName: string | null
  nickname: string | null
  sex: Sexe
  deceased: boolean
  birth: ValeurDate | null
  death: ValeurDate | null
  forcePrivacy: boolean
  privacyNote: string | null
}

/**
 * Le cliché pris **à l'ouverture** du formulaire.
 *
 * Il n'est jamais rafraîchi tant que le formulaire est ouvert. C'est ce qui
 * rend la protection du § 9.6 réelle : si la fiche bouge pendant la saisie, le
 * serveur le verra en comparant `oldValue` et rendra `409 conflit` au dépôt —
 * « la fiche a changé pendant que le formulaire était ouvert, il faut la
 * rouvrir » (§ 6.2). Une relecture silencieuse effacerait ce signal.
 */
export interface InstantaneFiche {
  personId: number
  /** `version` de la fiche telle que l'auteur l'a lue. */
  version: number
  valeurs: ValeursPersonne
}

/** Chaîne vide ⇒ `null`. Un champ vidé veut dire « effacer », et `null` est la
 *  valeur légitime pour cela — le modèle le dit explicitement de `new_value`. */
export function texteOuNull(valeur: string): string | null {
  const propre = valeur.trim()
  return propre.length > 0 ? propre : null
}

/**
 * Le cliché d'une fiche reçue.
 *
 * **Les valeurs viennent du corps de la fiche, jamais du calque `enAttente`.**
 * Le calque porte ce qu'une demande *propose* ; `oldValue` doit porter ce qui
 * est *validé*. Les confondre ferait déposer une demande dont l'ancienne valeur
 * n'a jamais existé en base, donc caduque à coup sûr (§ 13.2).
 */
export function instantaneDeFiche(fiche: FichePersonneComplete): InstantaneFiche {
  return {
    personId: fiche.id,
    version: fiche.version,
    valeurs: {
      givenNames: fiche.givenNames,
      surname: fiche.surname,
      marriedName: fiche.marriedName,
      nickname: fiche.nickname,
      sex: fiche.sex,
      deceased: fiche.deceased,
      birth: fiche.birth === null ? null : sansTexte(fiche.birth),
      death: fiche.death === null ? null : sansTexte(fiche.death),
      forcePrivacy: fiche.forcePrivacy,
      privacyNote: fiche.privacyNote,
    },
  }
}

/* ------------------------------------------------------------------ */
/* Construction des ops                                                */
/* ------------------------------------------------------------------ */

/** Un champ modifié, tel que le récapitulatif l'affiche avant l'envoi. */
export interface ChampModifie {
  champ: ChampPersonne
  etiquette: string
}

/**
 * Les ops d'une modification de personne — **un par champ réellement changé**.
 *
 * `seq` est attribué dans l'ordre de `CHAMPS_PERSONNE`, qui est l'ordre du
 * formulaire : « les ops se rejouent dans cet ordre, toujours. Un diff qui
 * s'applique dans un ordre variable n'est pas reproductible, donc pas
 * auditable » (§ 5.3 du modèle). Il commence à 1 et n'a pas de trou, quel que
 * soit le nombre de champs sautés.
 */
export function construireOps(instantane: InstantaneFiche, edite: ValeursPersonne): OpProposee[] {
  const ops: OpProposee[] = []
  const avant = instantane.valeurs

  for (const description of CHAMPS_PERSONNE) {
    const champ = description.champ

    if (estChampDate(champ)) {
      const ancienne = avant[champ]
      const nouvelle = edite[champ]
      // `memeValeurDate` compare les cinq clés et **ignore `texte`** : deux blocs
      // qui décrivent la même date ne doivent pas produire d'op parce que le
      // serveur a rendu la prose d'un côté et pas de l'autre.
      if (memeValeurDate(ancienne, nouvelle)) continue

      ops.push({
        seq: ops.length + 1,
        opKind: 'champ',
        subjectKind: 'personne',
        subjectId: instantane.personId,
        field: champ,
        valueKind: description.valueKind,
        oldValue: ancienne,
        newValue: nouvelle,
        baseVersion: instantane.version,
      })
      continue
    }

    const ancienne = avant[champ]
    const nouvelle = edite[champ]
    if (ancienne === nouvelle) continue

    ops.push({
      seq: ops.length + 1,
      opKind: 'champ',
      subjectKind: 'personne',
      subjectId: instantane.personId,
      field: champ,
      valueKind: description.valueKind,
      oldValue: ancienne,
      newValue: nouvelle,
      baseVersion: instantane.version,
    })
  }

  return ops
}

/** Les champs modifiés, en français, pour le récapitulatif de confirmation. */
export function champsModifies(ops: readonly OpProposee[]): ChampModifie[] {
  return ops.map((op) => {
    const champ = op.field as ChampPersonne
    return { champ, etiquette: descriptionDeChamp(champ).etiquette }
  })
}

/* ------------------------------------------------------------------ */
/* Le corps de la demande                                              */
/* ------------------------------------------------------------------ */

export interface OptionsDepot {
  /** Le mot du demandeur. Facultatif côté contrat, précieux côté humain :
   *  c'est souvent la pièce qui emporte la décision. */
  message: string
  soumettre: boolean
  /** § 6.10 — renseigné quand la demande reprend une demande caduque ou
   *  refusée. C'est ce qui fait suivre les médias déjà téléversés. */
  origine?: OrigineReprise
}

/**
 * Le corps du § 6.2, prêt à poster.
 *
 * `targetKind` / `targetId` sont dénormalisés « depuis les ops et servent à
 * filtrer la file sans jointure ; ils doivent être cohérents avec l'op de plus
 * petit `seq`, sinon `422` ». Ils sont donc pris du cliché, qui est aussi la
 * source des `subjectId` : la cohérence est structurelle, pas surveillée.
 */
export function corpsModificationPersonne(
  instantane: InstantaneFiche,
  edite: ValeursPersonne,
  options: OptionsDepot,
): CorpsModificationPersonne {
  const corps: CorpsModificationPersonne = {
    kind: 'modification_personne',
    soumettre: options.soumettre,
    targetKind: 'personne',
    targetId: instantane.personId,
    ops: construireOps(instantane, edite),
  }

  // `exactOptionalPropertyTypes` interdit de poser une clé facultative à
  // `undefined` : on l'ajoute, ou on ne l'ajoute pas.
  const message = texteOuNull(options.message)
  if (message !== null) corps.message = message
  if (options.origine !== undefined) corps.origine = options.origine

  return corps
}

/**
 * Ce qui empêche le dépôt, ou `null`.
 *
 * Une demande sans op n'a rien à proposer. Le serveur la refuserait, mais lui
 * poser la question pour s'entendre répondre `422` ferait perdre au
 * contributeur le contenu de son formulaire pour rien.
 */
export function motifDeBlocage(ops: readonly OpProposee[]): string | null {
  if (ops.length === 0) {
    return 'Aucun champ n’a été modifié : il n’y a rien à proposer pour l’instant.'
  }
  return null
}

/* ------------------------------------------------------------------ */
/* L'état de saisie, et son passage aux valeurs                        */
/* ------------------------------------------------------------------ */

/**
 * Ce que le formulaire manipule réellement.
 *
 * Les champs texte y sont des **chaînes** et non `string | null` : un `<input>`
 * ne sait pas rendre `null`, et la conversion « vidé ⇒ `null` » appartient au
 * passage vers `ValeursPersonne`, pas au champ. Les dates y sont des `SaisieDate`
 * — une nature et des bornes — plutôt que des blocs composés, pour la raison qui
 * fait tout ce module : une fourchette ne se saisit pas comme un point.
 */
export interface SaisieModification {
  givenNames: string
  surname: string
  marriedName: string
  nickname: string
  sex: Sexe
  deceased: boolean
  naissance: SaisieDate
  deces: SaisieDate
  forcePrivacy: boolean
  privacyNote: string
}

/**
 * L'état initial du formulaire, depuis la fiche lue.
 *
 * **Les valeurs viennent du corps de la fiche, jamais du calque `enAttente`.**
 * Le calque montre ce qu'une autre demande propose ; partir de lui ferait
 * déposer une demande fondée sur une valeur qui n'est pas encore en base.
 * L'écran signale le calque à côté du champ, il ne le recopie pas dedans.
 */
export function saisieDepuisFiche(fiche: FichePersonneComplete): SaisieModification {
  return {
    givenNames: fiche.givenNames ?? '',
    surname: fiche.surname ?? '',
    marriedName: fiche.marriedName ?? '',
    nickname: fiche.nickname ?? '',
    sex: fiche.sex,
    deceased: fiche.deceased,
    naissance: saisieDepuisBloc(fiche.birth),
    deces: saisieDepuisBloc(fiche.death),
    forcePrivacy: fiche.forcePrivacy,
    privacyNote: fiche.privacyNote ?? '',
  }
}

/**
 * Passe de la saisie aux valeurs transportables, ou dit ce qui cloche.
 *
 * Les erreurs sont indexées par nom de champ — celui du modèle, `birth` et non
 * `naissance` — pour se brancher directement sur `champs` du § 15.1 : le
 * formulaire n'a alors qu'une seule façon d'afficher une erreur, qu'elle vienne
 * d'ici ou du serveur.
 *
 * **Une date de décès sans `deceased` est refusée ici aussi**, et ça n'a pas
 * toujours été le cas. La raison de s'en abstenir était celle-ci : « une
 * modification part d'une fiche importée, où 25 personnes portent un `DEAT Y`
 * sans date ; refuser tant que la case n'est pas cochée bloquerait la
 * correction d'un prénom sur une incohérence que le contributeur n'a pas
 * créée ». Le souci était juste, la conclusion non.
 *
 * L'incohérence redoutée est **l'inverse** de celle-ci, et elle ne pose aucun
 * problème : `DEAT Y` sans date, c'est `deceased` coché sans date de décès, ce
 * qui est parfaitement valide. Le cas refusé est l'autre — une date de décès
 * sur quelqu'un laissé vivant — et il ne peut pas préexister sur une fiche :
 * `ck_tree_person_deces_coherent` l'interdit en base. Toute occurrence est donc
 * forcément le fait de la saisie en cours.
 *
 * Sans ce contrôle, le refus arrivait quand même — mais du serveur, et il
 * arrivait autrefois de la base elle-même, sous la forme d'une erreur de pilote
 * que personne ne pouvait lire.
 *
 * On signale plutôt que de cocher la case : `deceased` **ouvre la visibilité**
 * — un vivant hors cercle est absent de l'arbre, un défunt est visible de tout
 * compte connecté. On ne bascule pas cela à la place de quelqu'un.
 */
/**
 * Le critère de `ck_tree_person_deces_coherent`, recopié : `raw`, `min` ou `max`
 * renseigné.
 *
 * Le **lieu** n'en fait pas partie, et c'est voulu des deux côtés : « inhumé à
 * Trochu » sans date est une information qu'on n'a pas à refuser. Élargir le
 * critère « par prudence » ferait diverger le front du serveur, et le front
 * refuserait alors des saisies que le serveur accepte.
 */
function porteUneDateDeDeces(date: ValeurDate | null): boolean {
  if (date === null) return false

  return date.raw !== null || date.min !== null || date.max !== null
}

export function valeursDepuisSaisie(saisie: SaisieModification): {
  valeurs: ValeursPersonne | null
  erreurs: Record<string, string>
} {
  const erreurs: Record<string, string> = {}

  // `valeurAEnvoyer` et non `composerBlocDate` : une date que l'éditeur ne sait
  // pas exprimer repart **telle qu'elle était**, et ne produit donc aucun op.
  // Composer directement ferait de son champ vide une proposition de suppression.
  const naissance = valeurAEnvoyer(saisie.naissance)
  if (!naissance.valide) erreurs['birth'] = naissance.message

  const deces = valeurAEnvoyer(saisie.deces)
  if (!deces.valide) erreurs['death'] = deces.message

  if (!naissance.valide || !deces.valide) return { valeurs: null, erreurs }

  // La clé est `deceased` et non `death` : c'est la case à cocher qui manque,
  // c'est donc sur elle que le formulaire doit poser le message. Le serveur dit
  // la même chose, dans les mêmes termes.
  if (!saisie.deceased && porteUneDateDeDeces(deces.valeur)) {
    erreurs['deceased'] =
      'Une date de décès est indiquée : cochez « personne décédée », ou effacez la date.'

    return { valeurs: null, erreurs }
  }

  return {
    valeurs: {
      givenNames: texteOuNull(saisie.givenNames),
      surname: texteOuNull(saisie.surname),
      marriedName: texteOuNull(saisie.marriedName),
      nickname: texteOuNull(saisie.nickname),
      sex: saisie.sex,
      deceased: saisie.deceased,
      birth: naissance.valeur,
      death: deces.valeur,
      forcePrivacy: saisie.forcePrivacy,
      privacyNote: texteOuNull(saisie.privacyNote),
    },
    erreurs,
  }
}
