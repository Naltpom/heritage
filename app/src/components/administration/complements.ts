/**
 * **Les compléments d'une proposition, et leur fusion** — § 6.7, § 6.7 bis,
 * ADR 0017.
 *
 * Décision du commanditaire du 09/10/2026 : un membre qui retrouve une personne
 * déjà proposée par un autre ne dépose plus un doublon, il dépose un
 * **complément** rattaché ; l'administrateur le **fusionne** dans l'origine en un
 * clic. Ce module est la partie sans écran de ce clic : mettre en mots le rapport
 * que le serveur calcule à blanc (champs remplis, conflits à trancher, liens
 * repris ou écartés, pièces déplacées), composer le corps de la fusion, et dire
 * ce qui s'est passé.
 *
 * ────────────────────────────────────────────────────────────────────
 * Trois règles qu'il tient, et qui viennent toutes du serveur :
 *
 * 1. **L'origine l'emporte**, sauf choix contraire : un conflit se tranche pour
 *    elle tant que l'administrateur n'a pas coché le complément. `retenir` ne
 *    nomme donc que les conflits tranchés **pour le complément** — un champ sans
 *    conflit y rendrait `422`, et l'origine n'a pas besoin d'être redemandée.
 * 2. **Les deux empreintes** partent avec la fusion : ce qu'on a lu de l'origine,
 *    et ce qu'on a lu du complément. L'un des deux auteurs a pu compléter sa
 *    demande entre l'aperçu et le clic ; le serveur rend alors `409` sans rien
 *    écrire, et l'écran relit.
 * 3. **Le rapport ne nomme les liens que par leur rang** : c'est la demande du
 *    complément qui les met en mots. Sans elle, on dit « lien n° 2 du
 *    complément » — un rang de la demande, jamais un identifiant de personne.
 * ────────────────────────────────────────────────────────────────────
 */

import type {
  BlocDate,
  ChampDeFiche,
  ComplementDe,
  ConflitDeFusion,
  CorpsFusion,
  CoteRetenu,
  LienDemande,
  RaisonLienIgnore,
  RapportDeFusion,
  Sexe,
  StatutDemande,
  ValeurDeFusion,
} from '@/lib/api'
import type { DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import { etiquetteDeChamp, LIBELLES_SEXE } from '@/components/proposition/champs'
import { phraseDuLienPropose, texteDuBloc } from '@/components/proposition/fusion'

/* ------------------------------------------------------------------ */
/* Les mots                                                            */
/* ------------------------------------------------------------------ */

/** Le nom d'un champ de fiche proposée — la note comprise, que la fiche d'une personne n'a pas. */
export function etiquetteDuChampDeFiche(champ: ChampDeFiche): string {
  return champ === 'note' ? 'Note' : etiquetteDeChamp(champ)
}

function estBlocDate(valeur: ValeurDeFusion): valeur is BlocDate {
  return typeof valeur === 'object' && valeur !== null && 'qualifier' in valeur
}

/**
 * Une valeur du rapport, en mots. Un bloc date se lit par son `texte` — le
 * serveur l'a écrit, on ne le recompose pas (§ 14.4) — puis son lieu ; le sexe
 * par son libellé ; « décès attesté » par oui ou non.
 */
export function valeurLisible(champ: ChampDeFiche, valeur: ValeurDeFusion): string {
  if (valeur === null) return '—'
  if (typeof valeur === 'boolean') return valeur ? 'oui' : 'non'
  if (estBlocDate(valeur)) return texteDuBloc(valeur)
  if (champ === 'sex') return LIBELLES_SEXE[valeur as Sexe] ?? valeur
  return valeur.trim() !== '' ? valeur : '—'
}

/** Pourquoi un lien du complément ne passe pas, en une proposition subordonnée. */
export const RAISONS_D_ECARTER: Record<RaisonLienIgnore, string> = {
  deja_present: 'déjà dans la proposition',
  fiche_proposee_ailleurs:
    'il désigne une fiche d’une autre demande, que l’auteur de la proposition n’a pas forcément le droit de citer',
  meme_personne: 'il relierait la personne à elle-même',
}

/**
 * Où en est un complément listé sur son origine. Une ligne close l'est
 * `refusee` **qu'elle ait été fusionnée ou refusée** — la fusion clôt ainsi, sans
 * refus sec à son auteur — : la liste ne prétend pas savoir laquelle, le détail
 * de la demande le dit.
 */
export function etatDuComplement(statut: StatutDemande): string {
  switch (statut) {
    case 'en_attente':
      return 'en attente de fusion'
    case 'brouillon':
      return 'brouillon non envoyé'
    case 'validee':
      return 'validée seule'
    case 'refusee':
      return 'close : fusionnée ou refusée'
    case 'annulee':
      return 'retirée par son auteur'
    case 'caduque':
      return 'devenue caduque'
  }
}

/* ------------------------------------------------------------------ */
/* Le rapport, lisible                                                 */
/* ------------------------------------------------------------------ */

export interface RapportLisible {
  remplis: { champ: ChampDeFiche; etiquette: string; valeur: string }[]
  conflits: { champ: ChampDeFiche; etiquette: string; origine: string; complement: string }[]
  liensAjoutes: { seqComplement: number; libelle: string }[]
  liensEcartes: { seqComplement: number; libelle: string; raison: string }[]
  /** « 1 pièce jointe rejoindra la proposition », ou `null`. */
  medias: string | null
  /** « La demande n° 23 désignera désormais la proposition », ou `null`. */
  repointees: string | null
  /** Rien du tout : ni champ, ni lien, ni pièce. La fusion ne fera que clore. */
  vide: boolean
}

/**
 * Le lien n° `seq` du complément, en mots — ou son rang seul, faute de mieux.
 *
 * Un complément ne porte qu'**une** fiche : toute référence de brouillon de sa
 * demande la désigne, et c'est d'elle que le lien parle.
 */
function libelleDuLien(seq: number, liens: readonly LienDemande[] | null): string {
  const lien = liens?.find((candidat) => candidat.seq === seq)
  if (lien === undefined) return `Lien n° ${seq} du complément`
  return phraseDuLienPropose(lien, lien.subjectDraftRef ?? '')
}

export function rapportLisible(rapport: RapportDeFusion, liens: readonly LienDemande[] | null): RapportLisible {
  const medias =
    rapport.mediasDeplaces === 0
      ? null
      : rapport.mediasDeplaces === 1
        ? '1 pièce jointe rejoindra la proposition.'
        : `${rapport.mediasDeplaces} pièces jointes rejoindront la proposition.`

  const repointees =
    rapport.demandesRepointees.length === 0
      ? null
      : rapport.demandesRepointees.length === 1
        ? `La demande n° ${rapport.demandesRepointees[0]} désignera désormais la proposition.`
        : `Les demandes n° ${rapport.demandesRepointees.join(', ')} désigneront désormais la proposition.`

  return {
    remplis: rapport.remplis.map((r) => ({
      champ: r.champ,
      etiquette: etiquetteDuChampDeFiche(r.champ),
      valeur: valeurLisible(r.champ, r.valeur),
    })),
    conflits: rapport.conflits.map((c) => ({
      champ: c.champ,
      etiquette: etiquetteDuChampDeFiche(c.champ),
      origine: valeurLisible(c.champ, c.origine),
      complement: valeurLisible(c.champ, c.complement),
    })),
    liensAjoutes: rapport.liensAjoutes.map((l) => ({ seqComplement: l.seqComplement, libelle: libelleDuLien(l.seqComplement, liens) })),
    liensEcartes: rapport.liensIgnores.map((l) => ({
      seqComplement: l.seqComplement,
      libelle: libelleDuLien(l.seqComplement, liens),
      raison: RAISONS_D_ECARTER[l.raison] ?? l.raison,
    })),
    medias,
    repointees,
    vide:
      rapport.remplis.length === 0 &&
      rapport.conflits.length === 0 &&
      rapport.liensAjoutes.length === 0 &&
      rapport.mediasDeplaces === 0,
  }
}

/* ------------------------------------------------------------------ */
/* Le choix, et le corps                                               */
/* ------------------------------------------------------------------ */

export type ChoixDeFusion = Partial<Record<ChampDeFiche, CoteRetenu>>

/** Le choix de départ : ce que le serveur a retenu à blanc — l'origine. */
export function choixParDefaut(conflits: readonly ConflitDeFusion[]): ChoixDeFusion {
  const choix: ChoixDeFusion = {}
  for (const conflit of conflits) choix[conflit.champ] = conflit.retenu
  return choix
}

/**
 * Le corps de `POST …/fusionner/{complementId}`, clé par clé.
 *
 * `retenir` ne porte que les conflits **tranchés pour le complément**, et
 * seulement s'ils sont des conflits de l'aperçu : l'origine est le défaut, et un
 * champ hors conflit rendrait `422`. Les empreintes ne partent que si on les
 * tient — sans elles, la fusion porte sur l'état courant, comme avant.
 */
export function corpsDeFusion(
  choix: ChoixDeFusion,
  conflits: readonly ConflitDeFusion[],
  empreintes: { origine: string | null; complement: string | null },
): CorpsFusion {
  const corps: CorpsFusion = {}
  const retenir: Partial<Record<ChampDeFiche, CoteRetenu>> = {}
  for (const conflit of conflits) {
    if (choix[conflit.champ] === 'complement') retenir[conflit.champ] = 'complement'
  }
  if (Object.keys(retenir).length > 0) corps.retenir = retenir
  if (empreintes.origine !== null) corps.empreinte = empreintes.origine
  if (empreintes.complement !== null) corps.empreinteComplement = empreintes.complement
  return corps
}

/* ------------------------------------------------------------------ */
/* Avant et après le clic                                              */
/* ------------------------------------------------------------------ */

function pluriel(nombre: number, singulier: string, plurielle: string): string {
  return `${nombre} ${nombre > 1 ? plurielle : singulier}`
}

/** Ce que la fusion fait, en une phrase — pour la question, puis pour l'annonce. */
export function resumeDuRapport(rapport: RapportDeFusion, choix: ChoixDeFusion = {}): string {
  const morceaux: string[] = []
  if (rapport.remplis.length > 0) morceaux.push(pluriel(rapport.remplis.length, 'champ rempli', 'champs remplis'))
  const pourLeComplement = rapport.conflits.filter((c) => (choix[c.champ] ?? c.retenu) === 'complement').length
  const pourLOrigine = rapport.conflits.length - pourLeComplement
  if (pourLeComplement > 0) morceaux.push(pluriel(pourLeComplement, 'valeur reprise du complément', 'valeurs reprises du complément'))
  if (pourLOrigine > 0) morceaux.push(pluriel(pourLOrigine, 'désaccord tranché pour la proposition', 'désaccords tranchés pour la proposition'))
  if (rapport.liensAjoutes.length > 0) morceaux.push(pluriel(rapport.liensAjoutes.length, 'lien ajouté', 'liens ajoutés'))
  if (rapport.liensIgnores.length > 0) morceaux.push(pluriel(rapport.liensIgnores.length, 'lien écarté', 'liens écartés'))
  if (rapport.mediasDeplaces > 0) morceaux.push(pluriel(rapport.mediasDeplaces, 'pièce déplacée', 'pièces déplacées'))
  return morceaux.length === 0 ? 'Le complément n’apporte rien de plus.' : `${morceaux.join(', ')}.`
}

/** La question posée avant la fusion — elle ne se défait pas. */
export function confirmationDeFusion(
  origineId: number,
  complementId: number,
  rapport: RapportDeFusion,
  choix: ChoixDeFusion,
): DemandeDeConfirmation {
  return {
    titre: `Fusionner la demande n° ${complementId} dans la n° ${origineId} ?`,
    message: `${resumeDuRapport(rapport, choix)} La demande n° ${complementId} sera close, et son auteur prévenu que son travail est repris. Cela ne se défait pas.`,
    confirmer: 'Fusionner',
  }
}

/** Ce que l'écran dit une fois la fusion faite. */
export function annonceDeFusion(origineId: number, complementId: number, rapport: RapportDeFusion): string {
  return `Demande n° ${complementId} fusionnée dans la n° ${origineId} : ${resumeDuRapport(rapport).replace(/^./, (c) => c.toLowerCase())}`
}

/* ------------------------------------------------------------------ */
/* Ce qu'une demande complète                                          */
/* ------------------------------------------------------------------ */

/**
 * L'origine d'un complément **telle que la charge la nomme** — ou `null`.
 *
 * Seule la vue d'administration porte son numéro ; celle de l'auteur ne porte
 * que la fiche, et on ne la reconstruit pas.
 */
export function origineNommee(complementDe: ComplementDe | null | undefined): {
  demandeId: number
  libelle: string | null
  statut: StatutDemande | null
} | null {
  if (complementDe === null || complementDe === undefined || complementDe.demandeId === undefined) return null
  return {
    demandeId: complementDe.demandeId,
    libelle: complementDe.displayName ?? null,
    statut: complementDe.status ?? null,
  }
}

/**
 * Où en est la proposition d'origine, dit à côté de son numéro — ou `null` quand
 * elle attend, le cas ordinaire, qui n'a rien à ajouter.
 */
export function etatDeLOrigine(statut: StatutDemande | null): string | null {
  switch (statut) {
    case null:
    case 'en_attente':
    case 'brouillon':
      return null
    case 'validee':
      return 'déjà validée : la personne existe, ce complément ne peut plus que se refuser'
    case 'refusee':
    case 'annulee':
    case 'caduque':
      return 'close sans être validée : ce complément se valide désormais seul, comme une proposition ordinaire'
  }
}
