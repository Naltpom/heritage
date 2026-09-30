/**
 * Les règles de la décision — valider, refuser — en fonctions pures.
 *
 * Elles vivent hors des composants pour la même raison qu'`acces.ts` : ce sont
 * les trois endroits où l'écran d'administration peut faire du mal, et on veut
 * pouvoir les vérifier sans monter un navigateur.
 *
 * 1. **Le motif de refus est obligatoire.** Exigence explicite du commanditaire
 *    (§ 6.4 du dossier), et ce n'est pas une validation de formulaire : la
 *    contrainte `ck_prp_request_motif_refus` refuse la ligne en base. Le front
 *    n'en est pas le seul gardien, et l'API ne compte pas sur lui — mais un
 *    refus parti sans motif reviendrait en `422`, après que l'administrateur a
 *    cru avoir tranché.
 *
 * 2. **`resolutionPrevue` décide de ce que propose le bouton.** Sans lui,
 *    l'administrateur découvre la caducité **en cliquant**, ce qui la rend
 *    indistinguable d'une panne (§ 6.7). Le `switch` est **exhaustif sur
 *    l'union**, et c'est le typeur qui le vérifie : une valeur que le serveur
 *    rend et que l'union ignore ne tombe dans aucun cas, la fonction rend
 *    `undefined`, et l'écran de décision plante — c'est exactement ce qui est
 *    arrivé à `cible_disparue`, absente de l'union alors que le serveur la
 *    rendait depuis toujours.
 *
 * 3. **Un rejeu automatique doit se voir.** `resultat: "rejouee"` est un succès,
 *    mais c'est « une écriture qu'aucun humain n'a réexaminée » (§ 2.7) :
 *    afficher « validée » tout court effacerait la distinction que le journal,
 *    lui, conserve.
 */

import type { ResolutionPrevue, ResultatValidation } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* 1. Le motif de refus                                                */
/* ------------------------------------------------------------------ */

/**
 * Le message d'erreur à afficher sous le champ de motif, ou `null` si le motif
 * convient.
 *
 * Le serveur exige « non vide après `btrim` » : on applique exactement la même
 * règle, `trim()` compris, pour qu'un motif fait de trois espaces soit refusé
 * ici comme il le serait là-bas. Une règle front plus laxiste enverrait une
 * requête vouée au `422` ; une règle plus stricte refuserait un motif que le
 * serveur accepte, sans que personne puisse le diagnostiquer depuis l'écran.
 */
export function erreurMotifRefus(message: string): string | null {
  if (message.trim().length === 0) {
    return 'Le motif du refus est obligatoire : il sera envoyé à l’auteur de la demande.'
  }
  return null
}

/**
 * Le refus peut-il partir ?
 *
 * C'est **la** garde qui doit passer avant `refuserDemande()`, et elle est
 * nommée pour qu'un test puisse vérifier qu'elle est bien là — un refus sans
 * motif ne doit jamais atteindre le réseau.
 */
export function peutRefuser(message: string): boolean {
  return erreurMotifRefus(message) === null
}

/* ------------------------------------------------------------------ */
/* 2. Ce que propose le bouton « Valider »                             */
/* ------------------------------------------------------------------ */

export interface ActionValidation {
  libelle: string
  /** Le bouton est-il désactivé ? Vrai sur une demande déjà caduque. */
  desactive: boolean
  /** Ce qu'il faut dire à côté du bouton, ou `null` s'il n'y a rien à ajouter. */
  explication: string | null
}

/**
 * Traduit `resolutionPrevue` (§ 6.7) en ce que l'écran propose.
 *
 * `resolutionPrevue` **peut être absent** : la charge de l'auteur (§ 6.5) ne le
 * porte pas, seule celle de l'administration le calcule. Dans ce cas on ne
 * décide rien — on ne suppose ni que tout va bien, ni le contraire. Le défaut
 * est fermé : sans information sur l'état de la cible, le bouton reste
 * désactivé plutôt que de promettre une validation dont personne ne sait ce
 * qu'elle ferait.
 */
export function actionValidation(resolution: ResolutionPrevue | undefined): ActionValidation {
  switch (resolution) {
    case 'application':
      return { libelle: 'Valider', desactive: false, explication: null }

    case 'rejeu':
      // La version a bougé, mais **toutes** les anciennes valeurs correspondent
      // encore : la demande sera rejouée sur la version courante. C'est le cas
      // fréquent — celui des champs disjoints — et le traiter comme un conflit
      // condamnerait la seconde de deux demandes de la même semaine.
      return {
        libelle: 'Valider (sera rejouée sur la version courante)',
        desactive: false,
        explication:
          'La fiche a changé depuis le dépôt, mais aucune des valeurs que cette demande remplace n’a bougé. La modification sera appliquée sur la version actuelle.',
      }

    case 'caducite':
      // **Une** ancienne valeur a changé : valider écraserait le travail de
      // quelqu'un. Le bouton est éteint, et on dit pourquoi.
      return {
        libelle: 'Validation impossible',
        desactive: true,
        explication:
          'Une des valeurs que cette demande remplace a été modifiée depuis son dépôt. La valider écraserait ce changement : elle doit être refaite sur les valeurs actuelles.',
      }

    case 'cible_disparue':
      // **Ce cas manquait**, et son absence n'était pas une omission
      // d'affichage : la valeur n'était pas dans l'union `ResolutionPrevue`,
      // donc elle ne tombait dans aucun `case`, donc cette fonction rendait
      // `undefined` — sur la seule demande qu'il fallait surtout ne pas
      // valider. L'écran plantait à l'ouverture.
      return {
        libelle: 'Validation impossible',
        desactive: true,
        explication:
          'La fiche visée par cette demande n’existe plus : elle a été supprimée ou fusionnée depuis le dépôt. Il n’y a plus rien à modifier.',
      }

    case 'dependance_en_attente':
      // Ni un succès ni une fin : **un ordre**. On n'éteint donc pas le bouton
      // comme sur une caducité, on dit ce qu'il faut faire d'abord — et la
      // demande se validera telle quelle ensuite, sans être refaite.
      return {
        libelle: 'À valider après l’autre demande',
        desactive: true,
        explication:
          'Cette demande rattache une personne à une fiche qui est elle-même encore proposée. Validez d’abord la demande qui la propose : celle-ci s’appliquera ensuite telle quelle, sans que son auteur ait à la refaire.',
      }

    case 'dependance_morte':
      return {
        libelle: 'Validation impossible',
        desactive: true,
        explication:
          'La demande sur laquelle celle-ci s’appuyait n’aboutira pas : la personne qu’elle désignait n’existera jamais. Son auteur peut la refaire en désignant quelqu’un d’autre.',
      }

    case undefined:
      return {
        libelle: 'Valider',
        desactive: true,
        explication:
          'L’état de la fiche visée n’a pas pu être vérifié. Rechargez la demande avant de décider.',
      }
  }
}

/* ------------------------------------------------------------------ */
/* 3. Annoncer ce que la validation a réellement fait                  */
/* ------------------------------------------------------------------ */

export interface AnnonceValidation {
  titre: string
  detail: string | null
  /** Vrai pour un rejeu : l'écran le distingue visuellement d'une validation
   *  ordinaire, parce que c'est une écriture non réexaminée. */
  rejoue: boolean
}

/**
 * Ce qu'on affiche après un `200` de validation.
 *
 * Les deux issues sont des succès, et elles ne se disent pas pareil. « Validée »
 * sur un rejeu ferait croire que ce qui a été écrit est exactement ce qui avait
 * été relu — or la version a changé entre-temps, et c'est le serveur, pas un
 * humain, qui a constaté que les anciennes valeurs tenaient encore.
 */
export function annonceValidation(resultat: ResultatValidation): AnnonceValidation {
  if (resultat.resultat === 'rejouee') {
    const champs = resultat.rejeu?.champsVerifies ?? []
    const liste = champs.length > 0 ? ` (${champs.join(', ')})` : ''
    return {
      titre: 'Demande validée, après rejeu automatique',
      detail:
        `La fiche avait changé depuis le dépôt. Les valeurs que cette demande remplace${liste} ont été revérifiées une à une, ` +
        'elles n’avaient pas bougé, et la modification a été appliquée sur la version actuelle. Personne n’a réexaminé ce changement : il est tracé au journal.',
      rejoue: true,
    }
  }

  return {
    titre: 'Demande validée',
    detail: null,
    rejoue: false,
  }
}

/* ------------------------------------------------------------------ */
/* 4. « & suivant » — ce qu'on dit de la décision qu'on vient de quitter */
/* ------------------------------------------------------------------ */

/**
 * L'issue d'une décision suivie d'un enchaînement. `rejouee` reste distincte de
 * `validee` pour la raison du § 3 : l'écran qui s'ouvre ensuite ne montre plus
 * l'annonce du rejeu, il doit donc la redire.
 */
export type IssueDecision = 'validee' | 'rejouee' | 'refusee'

export function issueDeValidation(resultat: ResultatValidation): IssueDecision {
  return resultat.resultat === 'rejouee' ? 'rejouee' : 'validee'
}

/** Relit `?issue=` : une valeur inconnue ne dit rien plutôt que de mentir. */
export function issueDepuisRequete(valeur: unknown): IssueDecision | null {
  return valeur === 'validee' || valeur === 'rejouee' || valeur === 'refusee' ? valeur : null
}

/**
 * La phrase posée en tête de la demande suivante, ou de la file quand il n'y en
 * a plus : l'administrateur a changé d'écran d'un clic, et doit lire que la
 * décision précédente est bien partie.
 */
export function annonceEnchainement(precedente: number, issue: IssueDecision, finDeFile: boolean): string {
  const faite =
    issue === 'refusee'
      ? `Demande n° ${precedente} refusée.`
      : issue === 'rejouee'
        ? `Demande n° ${precedente} validée, après rejeu automatique.`
        : `Demande n° ${precedente} validée.`

  return finDeFile
    ? `${faite} Il n’y a plus de demande en attente dans cette file.`
    : `${faite} Voici la suivante dans la file.`
}
