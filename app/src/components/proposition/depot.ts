/**
 * **Ce que le formulaire « Ajouter une personne » annonce une fois sa demande
 * déposée** — photos comprises.
 *
 * Le formulaire sert à deux endroits (`FormulaireCreationPersonne.vue`) : la page
 * `/personnes/proposer`, qui mène ensuite au suivi de la demande, et le volet
 * gauche de `/arbre`, qui confirme sur place et va chercher la personne dans
 * l'arbre redessiné. Les deux lisent le même objet, construit ici, une fois.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le nœud se lit dans la réponse, il ne se fabrique pas.** `noeudId` est
 * l'identifiant de la fiche proposée **dans le graphe** — `#12/p1` —, et le
 * serveur le rend déjà, brouillon par brouillon (`BrouillonDemande.noeudId`).
 * Le recoller à la main (`'#' + id + '/p1'`) est exactement ce que
 * `lib/api/types.ts` interdit : un identifiant de nœud est opaque, et la
 * reconstruction maison serait juste… jusqu'au jour où le serveur change de
 * forme, ou où la fiche n'est plus `p1`. Sans nœud dans la réponse, on dit
 * `null`, et l'arbre dit qu'il ne la trouve pas — il ne devine pas.
 * ────────────────────────────────────────────────────────────────────
 */
import type { Demande } from '@/lib/api'

export interface PersonneDeposee {
  /** `creation` : une personne nouvelle. Seule nature de ce lot ; compléter une
   *  fiche existante en sera une autre. */
  nature: 'creation'
  /** Les demandes déposées par ce geste — une seule pour une création. */
  demandeIds: number[]
  /** Le nœud de la fiche proposée dans le graphe, **tel que le serveur l'a
   *  rendu** ; `null` si la réponse n'en porte pas. */
  noeudId: string | null
  /** La personne de l'arbre, si la demande l'a déjà créée (une validation
   *  immédiate) ; `null` tant qu'elle attend. */
  personId: number | null
  /** « Prénoms Nom », pour dire de qui l'on parle. */
  libelle: string
  /**
   * Partie en validation (`true`), ou gardée en brouillon (`false`) — lu sur le
   * statut rendu par le serveur, pas sur le bouton cliqué. Un brouillon n'est pas
   * dans l'arbre : seul le calque des demandes **en attente** y est dessiné.
   */
  soumise: boolean
}

/** Ce que le formulaire annonce, d'après la réponse de `creerDemande`. */
export function personneDeposee(demande: Demande): PersonneDeposee {
  const fiche = demande.brouillons[0]
  const libelle = [fiche?.givenNames, fiche?.surname]
    .map((morceau) => morceau?.trim() ?? '')
    .filter((morceau) => morceau !== '')
    .join(' ')

  return {
    nature: 'creation',
    demandeIds: [demande.id],
    noeudId: fiche?.noeudId ?? null,
    personId: fiche?.resolvedPersonId ?? null,
    libelle: libelle !== '' ? libelle : 'La personne proposée',
    soumise: demande.status !== 'brouillon',
  }
}
