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
  /**
   * - `creation` : une personne nouvelle, une demande `creation_personne` ;
   * - `fiche` : « C'est elle : compléter sa fiche » — la saisie est partie sur une
   *   fiche **qui existait déjà** (`modification_personne`, `ajout_lien`, une
   *   `ajout_media` par photo). Rien n'a été créé : la page reste, le volet montre
   *   la personne dans l'arbre.
   */
  nature: 'creation' | 'fiche'
  /** Les demandes déposées par ce geste — une seule pour une création, une par
   *  nature (et par photo) pour une fiche complétée. */
  demandeIds: number[]
  /** Le nœud de la fiche proposée dans le graphe, **tel que le serveur l'a
   *  rendu** ; `null` si la réponse n'en porte pas, et toujours pour une fiche
   *  complétée — elle a un `personId`. */
  noeudId: string | null
  /** La personne de l'arbre : celle qu'on a complétée, ou celle que la demande a
   *  déjà créée (une validation immédiate) ; `null` tant qu'une création attend. */
  personId: number | null
  /** « Prénoms Nom », pour dire de qui l'on parle ; « la personne proposée »
   *  quand la fiche n'a ni prénom ni nom. */
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
    libelle: libelle !== '' ? libelle : 'la personne proposée',
    soumise: demande.status !== 'brouillon',
  }
}

/**
 * Ce que « C'est elle : compléter sa fiche » annonce : les demandes déposées sur
 * la fiche, qui est déjà dans l'arbre. Elles partent toujours en validation — une
 * fiche existante ne se complète pas en brouillon.
 */
export function ficheCompletee(personId: number, libelle: string, demandeIds: readonly number[]): PersonneDeposee {
  return { nature: 'fiche', demandeIds: [...demandeIds], noeudId: null, personId, libelle, soumise: true }
}

/** « Demande n° 12 déposée », « Demandes n° 12 et 13 déposées », « Demandes n° 12, 13 et 14 déposées ». */
export function annonceDesDemandes(ids: readonly number[]): string {
  if (ids.length === 0) return ''
  if (ids.length === 1) return `Demande n° ${ids[0]} déposée`
  return `Demandes n° ${ids.slice(0, -1).join(', ')} et ${ids.at(-1)} déposées`
}
