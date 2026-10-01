/**
 * **Mes demandes** — ce que devient ce qu'on a proposé.
 *
 * Le commanditaire l'a demandé en une phrase : « notif de refus si l'admin
 * refuse avec le msg ». Le serveur tient sa part depuis longtemps — la décision
 * est notifiée, le motif est obligatoire, il est stocké, il est servi. Ce qui
 * manquait était plus simple et plus grave : **aucun écran ne l'affichait**. Un
 * membre proposait, on lui refusait avec un motif, et il n'atteignait ce motif
 * qu'en tapant `/demandes/6` à la main — c'est-à-dire jamais.
 *
 * Un refus qu'on ne peut pas lire n'est pas un refus, c'est une disparition. Et
 * « un membre dont la première contribution se perd n'en dépose pas de
 * seconde » (§ 9.6) : c'est exactement le même coût que celui de la caducité
 * sans bouton « refaire », pour la même raison.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le piège de cet écran, et il est mesuré :** `GET /api/demandes` rend douze
 * clés par élément, et `decisionMessage` n'en fait pas partie. La liste sait
 * donc dire « refusée » sans savoir dire pourquoi. Le motif vit sur
 * `GET /api/demandes/{id}`, à côté de `decidedByLabel` et `decidedAt`.
 *
 * D'où `motifsARecuperer()` : une seconde lecture, **sur les seules demandes
 * refusées**, jamais sur les autres. Ce n'est pas un détail d'implémentation à
 * cacher dans une vue — c'est une décision qui doit se voir, se tester, et se
 * retirer d'une ligne le jour où le serveur porte `decisionMessage` dans le
 * résumé. (Ce jour-là, c'est ce fichier qui le dira.)
 * ────────────────────────────────────────────────────────────────────
 *
 * Tout ici est pur : aucune requête, aucun composant, aucun routeur — la table
 * de routes est nommée, jamais construite en URL, comme partout ailleurs.
 */

import type { RouteLocationRaw } from 'vue-router'
import type { Demande, NatureDemande, ResumeDemande, StatutDemande } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* Libellés                                                            */
/* ------------------------------------------------------------------ */

/**
 * `Record<NatureDemande, string>` et non `Record<string, string>` : une nature
 * ajoutée au contrat ne compilera pas tant qu'elle n'a pas de libellé, au lieu
 * de s'afficher en `snake_case` sous les yeux d'un contributeur. La **valeur**
 * reste celle du modèle (§ 1.2) ; seul le libellé est français.
 */
export const LIBELLES_NATURE: Record<NatureDemande, string> = {
  modification_personne: 'Modification d’une fiche',
  modification_famille: 'Modification d’une famille',
  modification_citation: 'Modification d’une source',
  creation_personne: 'Ajout d’une personne',
  ajout_lien: 'Ajout d’un lien',
  ajout_media: 'Ajout d’un document',
  ajout_citation: 'Ajout d’une source',
  ajout_note: 'Ajout d’une note',
  suppression_personne: 'Suppression d’une fiche',
}

/** Les états que `PastilleEtat` sait colorer, plus le repli neutre. */
export type EtatPastille = 'en_attente' | 'validee' | 'refusee' | 'caduque' | 'neutre'

export interface Pastille {
  etat: EtatPastille
  /** Vide quand `PastilleEtat` connaît déjà le libellé. Renseigné sinon : une
   *  pastille neutre **sans** libellé s'affiche vide, ce qui se lit comme un
   *  défaut d'affichage plutôt que comme un état. */
  libelle: string
}

/**
 * La pastille d'un statut — les six, sans exception ni repli implicite.
 *
 * `refusee` et `caduque` ont deux couleurs parce qu'ils ont deux sens :
 * personne n'a refusé une demande caduque, la fiche a bougé sous elle
 * (ADR 0004). Les confondre ici ferait lire « on vous a refusé » à quelqu'un à
 * qui personne n'a rien refusé.
 */
const PASTILLES: Record<StatutDemande, Pastille> = {
  brouillon: { etat: 'neutre', libelle: 'Brouillon' },
  en_attente: { etat: 'en_attente', libelle: '' },
  validee: { etat: 'validee', libelle: '' },
  refusee: { etat: 'refusee', libelle: '' },
  caduque: { etat: 'caduque', libelle: '' },
  annulee: { etat: 'neutre', libelle: 'Annulée par vous' },
}

export function pastilleDeStatut(statut: StatutDemande): Pastille {
  return PASTILLES[statut]
}

/* ------------------------------------------------------------------ */
/* Le motif : la seconde lecture, et pourquoi elle est bornée          */
/* ------------------------------------------------------------------ */

/**
 * Les demandes dont il faut aller chercher le motif.
 *
 * **Les refusées, et elles seules.** `decisionMessage` n'est écrit que par un
 * refus : une demande validée n'en a pas, et une demande **caduque** n'en a pas
 * non plus — personne ne l'a refusée. Interroger le détail de toute la liste
 * serait une lecture par ligne pour une clé qui, dans la quasi-totalité des cas,
 * n'existe pas.
 *
 * `dejaConnus` évite de redemander ce qu'on a déjà : changer de filtre relit la
 * liste, pas les motifs.
 *
 * Rend les identifiants **sans doublon** et dans l'ordre de la liste.
 */
export function motifsARecuperer(
  elements: readonly ResumeDemande[],
  dejaConnus: ReadonlySet<number> = new Set(),
): number[] {
  const aLire: number[] = []
  for (const demande of elements) {
    if (demande.status !== 'refusee') continue
    if (dejaConnus.has(demande.id)) continue
    if (aLire.includes(demande.id)) continue
    aLire.push(demande.id)
  }
  return aLire
}

/**
 * Le motif d'une décision, tel qu'il s'affiche.
 *
 * `message` n'est jamais vide : une décision sans motif n'est pas un motif vide,
 * c'est **pas de motif du tout**, et c'est `null` que rend `motifDepuisDemande`.
 * L'écran n'a donc aucun cas « panneau présent mais vide » à dessiner.
 */
export interface MotifDecision {
  message: string
  /** Qui a décidé. `null` quand la charge ne le porte pas — on n'invente pas. */
  parQui: string | null
  quand: string | null
}

/**
 * Tire le motif d'un détail de demande, ou `null`.
 *
 * Le message part du serveur **tel quel** et s'affiche tel quel : c'est le mot
 * d'un administrateur à un membre, et le reformuler serait le réécrire. Seul le
 * blanc de bordure est retiré — le serveur `trim()` déjà avant de stocker, et la
 * contrainte `ck_prp_request_motif_refus` compare sur `btrim` : un motif qui
 * arriverait blanc ici serait un motif absent, pas un motif vide.
 */
export function motifDepuisDemande(demande: Demande): MotifDecision | null {
  const message = (demande.decisionMessage ?? '').trim()
  if (message.length === 0) return null

  return {
    message,
    parQui: demande.decidedByLabel ?? null,
    quand: demande.decidedAt ?? null,
  }
}

/* ------------------------------------------------------------------ */
/* Refaire — § 6.10                                                    */
/* ------------------------------------------------------------------ */

/**
 * Où mène « Refaire cette demande », ou `null` s'il n'y a nulle part où aller.
 *
 * Les deux formulaires existent et acceptent déjà `?refaire=<id>` : la reprise
 * se branche sur ce qui est là, elle n'invente pas d'écran.
 *
 * **`null` plutôt qu'un lien qui refuserait**, dans trois cas et pour trois
 * raisons distinctes :
 *
 * - un statut autre que `refusee` ou `caduque` : il n'y a rien à refaire d'une
 *   demande validée, et une demande en attente se retire, elle ne se reprend
 *   pas ;
 * - une nature que la reprise n'outille pas (`ajout_lien`, `ajout_media`, les
 *   deux modifications qui ne visent pas une personne) : le geste existe
 *   ailleurs dans le produit, pas derrière ce bouton-là. `ajout_lien` a
 *   **désormais son formulaire** — c'est l'écran des liens de parenté — mais le
 *   corps que rend `GET /api/demandes/{id}/refaire` ne porte que des
 *   identifiants de personnes et de familles, sans les noms ni la distinction
 *   « frère ou sœur » / « enfant d'une union » que cet écran demande. Le
 *   pré-remplir demanderait de relire la fiche et de reclasser chaque lien :
 *   c'est faisable, ce n'est pas fait, et un bouton qui ouvrirait un formulaire
 *   vide serait pire qu'un bouton absent — l'auteur croirait sa demande reprise ;
 * - une modification **sans cible** : `proposition.modification` est montée sur
 *   `/personnes/:id(\d+)/proposer` et n'a pas de sens sans identifiant. Le
 *   serveur peut rendre `targetId: null` — on ne fabrique pas d'URL par
 *   concaténation pour s'en apercevoir en `404`.
 *
 * Un lien absent se remarque ; un lien qui mène à un refus fait croire à une
 * panne.
 */
export function lienDeReprise(demande: ResumeDemande): RouteLocationRaw | null {
  if (demande.status !== 'refusee' && demande.status !== 'caduque') return null

  const refaire = String(demande.id)

  if (demande.kind === 'creation_personne') {
    return { name: 'proposition.creation', query: { refaire } }
  }

  if (demande.kind === 'modification_personne' && demande.targetId !== null) {
    return {
      name: 'proposition.modification',
      params: { id: demande.targetId },
      query: { refaire },
    }
  }

  // Une suppression se refait **sans `?refaire`**, et c'est la seule nature dans
  // ce cas. Les trois autres écrans pré-remplissent un formulaire depuis la
  // demande morte ; celui-ci n'a rien à pré-remplir qu'il ne doive relire.
  //
  // C'est le fond de la chose, pas une commodité : une suppression devient
  // caduque **précisément parce que ce qu'elle emportait a changé** — une note,
  // une citation, une preuve est arrivée depuis le dépôt. Recopier l'inventaire
  // d'alors, ou le motif écrit en le regardant, remettrait sous les yeux de
  // l'auteur l'état périmé qui a tué la première demande. L'écran relit, montre
  // ce qu'il en est maintenant, et redemande un motif.
  if (demande.kind === 'suppression_personne' && demande.targetId !== null) {
    return { name: 'proposition.suppression', params: { id: demande.targetId } }
  }

  return null
}

/* ------------------------------------------------------------------ */
/* Le cas vide — deux vides, deux phrases                              */
/* ------------------------------------------------------------------ */

/** Les états proposés au filtre. `''` veut dire « toutes », et c'est le défaut :
 *  sa propre liste est courte, et la masquer derrière un filtre par défaut
 *  reproduirait le défaut qu'on répare. */
export const FILTRES: readonly { valeur: StatutDemande | ''; libelle: string }[] = [
  { valeur: '', libelle: 'Toutes' },
  { valeur: 'en_attente', libelle: 'En attente' },
  { valeur: 'validee', libelle: 'Validées' },
  { valeur: 'refusee', libelle: 'Refusées' },
  { valeur: 'caduque', libelle: 'Devenues caduques' },
  { valeur: 'brouillon', libelle: 'Brouillons' },
]

/**
 * Ce que dit l'écran quand il n'y a rien à montrer.
 *
 * **Deux vides différents**, et les confondre est une petite cruauté : celui qui
 * n'a jamais rien proposé doit lire une invitation, celui qui vient de filtrer
 * sur « Refusées » doit lire que ce filtre-là ne rend rien — sans quoi il croit
 * que ses demandes ont disparu.
 */
export function messageQuandVide(filtre: StatutDemande | ''): string {
  if (filtre === '') {
    return 'Vous n’avez encore rien proposé. Chaque proposition est relue par un administrateur avant d’entrer dans l’arbre.'
  }

  const libelle = FILTRES.find((choix) => choix.valeur === filtre)?.libelle ?? ''
  return `Aucune demande « ${libelle.toLowerCase()} ». Choisissez « Toutes » pour voir les autres.`
}

/* ------------------------------------------------------------------ */
/* Les compteurs du segmenté — ce qu'on sait, et rien de plus          */
/* ------------------------------------------------------------------ */

/** Le compte connu de chaque filtre. Une clé **absente** veut dire « inconnu » :
 *  le segmenté n'affiche alors aucun nombre, plutôt qu'un zéro inventé. */
export type ComptesParFiltre = Partial<Record<StatutDemande | '', number>>

/**
 * Ce qu'une réponse de `GET /api/demandes` apprend sur les compteurs.
 *
 * **Aucun appel de plus.** Le serveur n'a pas de route de comptes ; on ne lit
 * donc que ce que l'écran reçoit déjà :
 *
 * - le `total` d'une liste filtrée est le compte **exact** de ce filtre-là ;
 * - la liste **non filtrée**, quand elle est **entière** (`total` égal au nombre
 *   d'éléments reçus), se ventile par état : chaque filtre y trouve son compte,
 *   zéro compris. Tronquée — plus de 100 demandes —, elle ne dit que le total :
 *   ventiler cent éléments sur cent quarante donnerait des comptes faux, et un
 *   compteur faux est pire qu'un compteur absent.
 *
 * Le résultat se **fusionne** avec ce qu'on savait déjà : changer de filtre ne
 * fait pas oublier les autres comptes.
 */
export function comptesDepuisListe(
  filtre: StatutDemande | '',
  elements: readonly ResumeDemande[],
  total: number,
): ComptesParFiltre {
  const comptes: ComptesParFiltre = { [filtre]: total }
  if (filtre !== '' || total !== elements.length) return comptes

  for (const choix of FILTRES) {
    if (choix.valeur === '') continue
    comptes[choix.valeur] = elements.filter((demande) => demande.status === choix.valeur).length
  }
  return comptes
}

/**
 * Les choix du segmenté, avec leur compte.
 *
 * Le handoff n'en montre que cinq — Toutes, En attente, Validées, Refusées,
 * Devenues caduques. **« Brouillons » n'est pas retiré pour autant** : il
 * n'apparaît que s'il a quelque chose à montrer (compte connu non nul), ou s'il
 * est le filtre en cours. Un bouton qui mène toujours à une liste vide
 * encombre ; un filtre qui disparaît sous les pieds de celui qui l'a choisi
 * serait pire.
 */
export function choixDuSegmente(
  comptes: ComptesParFiltre,
  filtre: StatutDemande | '',
): { valeur: StatutDemande | ''; libelle: string; compte: number | undefined }[] {
  return FILTRES.filter(
    (choix) => choix.valeur !== 'brouillon' || filtre === 'brouillon' || (comptes.brouillon ?? 0) > 0,
  ).map((choix) => ({ valeur: choix.valeur, libelle: choix.libelle, compte: comptes[choix.valeur] }))
}
