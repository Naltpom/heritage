/**
 * **Proposer des liens de parenté sur une fiche qui existe** — la nature
 * `ajout_lien` du § 6.4 bis.
 *
 * Le défaut réparé, dans les mots où il a été signalé :
 *
 * > « Et pareil quand je demande une modification je peux pas ajouter les
 * > parents les frères et sœur ni les enfants. »
 *
 * C'était exact, et ce n'était pas une lacune de l'API : `modification_personne`
 * ne porte que des **ops de champ** — un op nomme un champ, et la parenté n'est
 * pas un champ de `tree_person`, c'est une ligne de `tree_child` ou de
 * `tree_family`. La nature `ajout_lien` existait depuis le début, avec son
 * constructeur et son applicateur ; **aucun écran ne la déposait**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi un écran à part et non un bloc de l'écran de modification.**
 *
 * Parce qu'une demande porte **une** nature, et que `kind` « dit quelles tables
 * filles sont peuplées » (§ 5.7 du modèle). Un formulaire qui déposerait à la
 * fois des ops et des liens déposerait **deux demandes** — deux lignes dans la
 * file, deux décisions, deux refus possibles — sous un seul bouton. L'auteur
 * croirait avoir déposé une chose et en verrait deux ; l'administrateur pourrait
 * valider l'une et refuser l'autre, laissant un résultat que personne n'a voulu.
 * Les deux écrans se renvoient l'un à l'autre, et c'est le lien qui les relie,
 * pas le bouton.
 * ────────────────────────────────────────────────────────────────────
 *
 * ## Les quatre gestes, et le lien que chacun écrit
 *
 * Le point à ne pas rater est que **les trois natures de lien se lisent du sujet
 * vers l'autre** : ce n'est pas toujours la fiche d'où l'on vient qui est le
 * sujet.
 *
 * | Geste | `kind` | sujet | autre extrémité |
 * |---|---|---|---|
 * | ajouter un ou deux **parents** | `enfant_de` | **la fiche** | le ou les parents |
 * | ajouter un **frère ou une sœur** | `enfant_de` | **le frère ou la sœur** | `familyId` de la famille d'origine |
 * | ajouter un **conjoint** | `conjoint_de` | **la fiche** | le conjoint |
 * | ajouter un **enfant** | `parent_de` ou `enfant_de` | selon l'union | voir ci-dessous |
 *
 * ## La fratrie n'est pas une nature de lien, et c'est volontaire
 *
 * `LinkKind` compte trois valeurs « ni plus ni moins » (§ 9.3). Une fratrie
 * s'écrit donc « cette personne est enfant du **même couple** » —
 * `enfant_de` + `familyId`. Conséquence directe, et l'écran doit la dire au lieu
 * de la subir : **on ne rattache un frère ou une sœur que si la fiche a déjà une
 * famille d'origine.** Sans parent connu, il n'existe aucun couple à désigner,
 * et une fratrie sans parents ne s'exprime pas dans ce modèle — il faut d'abord
 * proposer les parents.
 *
 * ## Un enfant : la même intention s'écrit de deux façons, et l'une est meilleure
 *
 * - **Avec une union désignée** → `enfant_de`, sujet l'enfant, `familyId` cette
 *   union. L'enfant rejoint le couple existant.
 * - **Sans union** → `parent_de`, sujet la fiche. La validation crée alors une
 *   famille à **un seul parent**, `(la fiche, ∅)`.
 *
 * La seconde forme est correcte et parfois la seule vraie — un enfant dont on ne
 * connaît qu'un parent. Mais la choisir par défaut alors qu'une union existe
 * produirait une seconde famille pour le même couple : l'enfant serait rattaché
 * à « la fiche seule » au lieu du couple, et le graphe montrerait deux losanges.
 * D'où le choix explicite à l'écran, avec l'union préselectionnée quand il n'y
 * en a qu'une.
 *
 * ## `targetId` n'est pas décoratif
 *
 * Le serveur prend pour cible le sujet du **premier** lien quand le corps n'en
 * annonce pas. Or « ajouter un frère à Untel » a pour sujet le frère : la file
 * afficherait la demande sur la fiche du frère, alors que le contributeur
 * travaillait sur celle d'Untel, et l'administrateur la chercherait longtemps.
 * On annonce donc la cible, et le serveur vérifie qu'elle est bien une des
 * personnes que les liens désignent — sans quoi `targetId` serait un champ libre.
 */

import type { CorpsAjoutLien, LienPropose } from '@/lib/api'
import { valeurAEnvoyer } from './dates'
import {
  autreDuChoix,
  cleDuChoix,
  estFicheProposee,
  secondParentDuChoix,
  type ChoixDePersonne,
  type ErreurSaisie,
  type SaisieUnion,
} from './creation'
import { texteOuNull, type OptionsDepot } from './operations'

/* ------------------------------------------------------------------ */
/* L'état du formulaire                                                */
/* ------------------------------------------------------------------ */

/**
 * Un frère ou une sœur à rattacher.
 *
 * `familleId` est **la famille d'origine de la fiche**, pas un choix libre : la
 * fratrie s'exprime par « enfant du même couple ». Quand la fiche a deux
 * familles d'origine — adoption, remariage —, l'écran demande laquelle.
 */
export interface SaisieFratrie {
  personne: ChoixDePersonne
  familleId: number
  rangFratrie: number | null
}

/**
 * Un enfant à rattacher.
 *
 * `familleId` à `null` veut dire « un seul parent connu » : le lien s'écrit
 * alors `parent_de`, et la validation créera une famille à un parent. Ce n'est
 * pas un défaut de saisie, c'est un état du travail généalogique — trois
 * familles réelles de cet arbre n'ont qu'un époux.
 */
export interface SaisieEnfantARattacher {
  personne: ChoixDePersonne
  familleId: number | null
  rangFratrie: number | null
}

export interface SaisieLiens {
  /** La fiche d'où l'on vient. C'est elle qu'on annonce en cible. */
  personneId: number
  /** Un ou deux parents. Deux au plus : c'est un couple, pas une liste. */
  parents: ChoixDePersonne[]
  fratrie: SaisieFratrie[]
  unions: SaisieUnion[]
  enfants: SaisieEnfantARattacher[]
}

export function liensVides(personneId: number): SaisieLiens {
  return { personneId, parents: [], fratrie: [], unions: [], enfants: [] }
}

export type ResultatLiens =
  | { valide: true; corps: CorpsAjoutLien }
  | { valide: false; erreurs: ErreurSaisie[] }

/** Le compte des liens décrits — pour l'aide de pied de bloc, jamais un compteur
 *  de résultats masqués. */
export function nombreDeLiensProposes(saisie: SaisieLiens): number {
  const filiation = saisie.parents.length > 0 ? 1 : 0
  return filiation + saisie.fratrie.length + saisie.unions.length + saisie.enfants.length
}

/** Toutes les personnes déjà désignées, **plus la fiche elle-même** : on ne
 *  rattache pas une fiche à elle-même, et le sélecteur doit l'éteindre plutôt
 *  que de laisser déposer un corps que le serveur refuserait. */
export function dejaDesignes(saisie: SaisieLiens): string[] {
  return [
    cleDuChoix({ personId: saisie.personneId, libelle: '' }),
    ...saisie.parents.map(cleDuChoix),
    ...saisie.fratrie.map((ligne) => cleDuChoix(ligne.personne)),
    ...saisie.unions.map((union) => cleDuChoix(union.personne)),
    ...saisie.enfants.map((enfant) => cleDuChoix(enfant.personne)),
  ]
}

/* ------------------------------------------------------------------ */
/* Construction du corps                                              */
/* ------------------------------------------------------------------ */

/**
 * Le sujet d'un lien quand ce n'est **pas** la fiche : une personne de l'arbre,
 * ou une fiche proposée ailleurs.
 *
 * Le pendant de {@link autreDuChoix} pour l'autre bout du lien. Écrit à part
 * parce que les noms de clés diffèrent, et qu'un `prefixe` en paramètre rendrait
 * l'union des quatre formes — ce que le typeur ne saurait plus contrôler.
 */
function sujetDuChoix(
  choix: ChoixDePersonne,
): { subjectPersonId: number } | { subjectDraftPersonId: number } {
  return estFicheProposee(choix)
    ? { subjectDraftPersonId: choix.draftPersonId }
    : { subjectPersonId: choix.personId }
}

/**
 * Compose le corps du § 6.4 bis, ou rend la liste de ce qui manque.
 *
 * **« Au moins un lien » n'est contrôlé que si l'on soumet**, comme pour une
 * création : on enregistre un brouillon incomplet un soir, on le complète le
 * lendemain. Bloquer l'enregistrement exigerait que la contribution se fasse
 * d'un trait.
 */
export function corpsAjoutLien(saisie: SaisieLiens, options: OptionsDepot): ResultatLiens {
  const liens: LienPropose[] = []
  const erreurs: ErreurSaisie[] = []

  /* --- Les parents de la fiche : un seul lien, un ou deux parents --- */
  if (saisie.parents.length > 2) {
    erreurs.push({ champ: 'parents', message: 'Une personne ne peut avoir plus de deux parents désignés.' })
  } else if (
    saisie.parents.length === 2 &&
    cleDuChoix(saisie.parents[0]!) === cleDuChoix(saisie.parents[1]!)
  ) {
    erreurs.push({ champ: 'parents', message: 'Les deux parents désignés sont la même personne.' })
  } else if (saisie.parents.length > 0) {
    const lien: LienPropose = {
      seq: liens.length + 1,
      kind: 'enfant_de',
      subjectPersonId: saisie.personneId,
      ...autreDuChoix(saisie.parents[0]!),
    }
    if (saisie.parents.length === 2) {
      Object.assign(lien, secondParentDuChoix(saisie.parents[1]!))
    }
    liens.push(lien)
  }

  /* --- La fratrie : enfant du même couple -------------------------- */
  saisie.fratrie.forEach((ligne) => {
    const lien: LienPropose = {
      seq: liens.length + 1,
      kind: 'enfant_de',
      // Le sujet est **le frère ou la sœur**, pas la fiche : c'est lui qu'on
      // rattache au couple. Lire l'inverse ferait de la fiche l'enfant de ses
      // propres parents une seconde fois — un doublon de filiation.
      ...sujetDuChoix(ligne.personne),
      familyId: ligne.familleId,
    }
    if (ligne.rangFratrie !== null) lien.siblingOrder = ligne.rangFratrie
    liens.push(lien)
  })

  /* --- Les unions -------------------------------------------------- */
  saisie.unions.forEach((union, index) => {
    const lien: LienPropose = {
      seq: liens.length + 1,
      kind: 'conjoint_de',
      subjectPersonId: saisie.personneId,
      ...autreDuChoix(union.personne),
      // Toujours posé : c'est un booléen non nul en base, et l'omettre
      // laisserait le serveur deviner.
      divorced: union.rompue,
    }

    const mariage = valeurAEnvoyer(union.mariage)
    if (!mariage.valide) {
      erreurs.push({ champ: `unions.${index}.mariage`, message: mariage.message })
    } else if (mariage.valeur !== null) {
      lien.marriage = mariage.valeur
    }

    const rupture = valeurAEnvoyer(union.rupture)
    if (!rupture.valide) {
      erreurs.push({ champ: `unions.${index}.rupture`, message: rupture.message })
    } else if (rupture.valeur !== null) {
      if (!union.rompue) {
        // On ne devine pas une rupture à partir d'une date : le lecteur a pu
        // saisir la date avant de cocher. C'est un fait d'état civil, pas un
        // détail d'affichage.
        erreurs.push({
          champ: `unions.${index}.rupture`,
          message: 'Une date de rupture est indiquée : cochez « union rompue », ou effacez la date.',
        })
      } else {
        lien.divorce = rupture.valeur
      }
    }

    const note = texteOuNull(union.note)
    if (note !== null) lien.note = note

    liens.push(lien)
  })

  /* --- Les enfants : dans une union, ou d'un parent seul ------------ */
  saisie.enfants.forEach((enfant) => {
    const lien: LienPropose =
      enfant.familleId === null
        ? {
            seq: liens.length + 1,
            kind: 'parent_de',
            subjectPersonId: saisie.personneId,
            ...autreDuChoix(enfant.personne),
          }
        : {
            seq: liens.length + 1,
            kind: 'enfant_de',
            // Sujet l'enfant, et `familyId` l'union : c'est ce qui le rattache au
            // **couple** plutôt qu'à la fiche seule, et qui évite un second
            // losange pour un couple qui existe déjà.
            ...sujetDuChoix(enfant.personne),
            familyId: enfant.familleId,
          }

    if (enfant.rangFratrie !== null) lien.siblingOrder = enfant.rangFratrie
    liens.push(lien)
  })

  if (options.soumettre && liens.length === 0) {
    erreurs.push({
      champ: 'liens',
      message:
        'Décrivez au moins un lien : un parent, un frère ou une sœur, un conjoint ou un enfant.',
    })
  }

  if (erreurs.length > 0) {
    return { valide: false, erreurs }
  }

  const corps: CorpsAjoutLien = {
    kind: 'ajout_lien',
    soumettre: options.soumettre,
    targetKind: 'personne',
    targetId: saisie.personneId,
    liens,
  }

  const message = texteOuNull(options.message)
  if (message !== null) corps.message = message
  if (options.origine !== undefined) corps.origine = options.origine

  return { valide: true, corps }
}
