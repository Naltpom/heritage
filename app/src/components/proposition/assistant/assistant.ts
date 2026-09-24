/**
 * La logique de l'assistant de proposition — **sans Vue, sans réseau**.
 *
 * Tout ce qui se décide ici se teste sur des valeurs : quels fichiers entrent,
 * ce que dit l'écran d'une valeur absente, à quelle fiche on joint les
 * documents, et dans quel ordre les dépôts s'enchaînent. Les composants ne font
 * qu'afficher ce que ces fonctions rendent.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux règles tiennent l'écran, et elles sont écrites ici pour qu'aucun
 * gabarit ne les contourne.**
 *
 * 1. **Rien n'est déposé sans le clic du membre.** `planDeDepot()` ne part que
 *    des demandes **cochées** ; l'exécution (`executerDepot()`) n'est appelée que
 *    par le bouton « Déposer ».
 * 2. **Un échec n'interrompt pas les suivants.** Les dépôts et les envois de
 *    pièces sont **séquentiels** (le limiteur de téléversement compte par heure :
 *    six requêtes parallèles produiraient six `429` au lieu d'un), et chaque
 *    étape garde son propre état et son propre message — même philosophie que
 *    `TeleversementMedia.vue`.
 * ────────────────────────────────────────────────────────────────────
 */

import {
  analyserErreur,
  estErreurApi,
  type ChangementPropose,
  type ConfianceAssistant,
  type DemandeProposee,
  type PersonneCitee,
} from '@/lib/api'
import { tailleLisible, verifierAvantEnvoi } from '@/components/medias/medias'

/* ------------------------------------------------------------------ */
/* Les bornes de l'entrée                                              */
/* ------------------------------------------------------------------ */

/** Nombre maximal de documents par analyse (contrat de l'assistant). */
export const NOMBRE_MAX_DOCUMENTS = 6
/** 10 Mio par document — plus bas que les 20 Mio d'un média : ces octets
 *  partent entiers vers le modèle, dans une requête qui en porte six. */
export const TAILLE_MAX_DOCUMENT = 10 * 1024 * 1024
/** Longueur maximale du texte libre. */
export const TEXTE_MAX = 8000
/** Tous documents confondus. Le trajet est borné à 25 Mo par requête, et le modèle
 *  refuse au-delà d'environ 20 Mo de documents en ligne (voir `reduireImage.ts`). */
export const TAILLE_MAX_TOTALE = 18 * 1024 * 1024
/** Le quota annoncé avant le premier appel ; ensuite, `restant` fait foi. */
export const QUOTA_QUOTIDIEN = 20

export interface VerdictDocuments {
  /** Les fichiers qui entrent, dans l'ordre de sélection. */
  acceptes: File[]
  /** Un message par fichier refusé, en clair. */
  refus: string[]
}

/**
 * Trie une sélection de fichiers : la taille et le nombre, **rien d'autre**.
 *
 * Le type n'est pas contrôlé ici, pour la même raison que dans
 * `components/medias/medias.ts` : `File.type` est le type *annoncé*, et c'est le
 * serveur qui renifle les octets. L'attribut `accept` reste un confort.
 */
export function trierDocuments(
  dejaJoints: number,
  selection: readonly File[],
  poidsDejaJoint = 0,
): VerdictDocuments {
  const acceptes: File[] = []
  const refus: string[] = []
  let places = Math.max(0, NOMBRE_MAX_DOCUMENTS - dejaJoints)
  let poids = poidsDejaJoint

  for (const fichier of selection) {
    const verdict = verifierAvantEnvoi(fichier, TAILLE_MAX_DOCUMENT)
    if (!verdict.accepte) {
      refus.push(`« ${fichier.name} » : ${verdict.message}`)
      continue
    }
    if (places === 0) {
      refus.push(
        `« ${fichier.name} » n’a pas été ajouté : ${NOMBRE_MAX_DOCUMENTS} documents au plus par analyse.`,
      )
      continue
    }
    if (poids + fichier.size > TAILLE_MAX_TOTALE) {
      refus.push(
        `« ${fichier.name} » n’a pas été ajouté : ${tailleLisible(TAILLE_MAX_TOTALE)} au plus pour tous les documents.`,
      )
      continue
    }
    acceptes.push(fichier)
    poids += fichier.size
    places--
  }
  return { acceptes, refus }
}

/** Le rappel des bornes, tel que l'écran l'affiche sous les boutons. */
export function rappelDesBornes(): string {
  return `Images ou PDF, ${NOMBRE_MAX_DOCUMENTS} documents au plus, ${tailleLisible(TAILLE_MAX_TOTALE)} en tout. Les photos sont allégées avant l’envoi.`
}

/** Un PDF ne se met pas dans une `<img>` : il a sa pastille. Le nom sert de
 *  repli quand le navigateur n'annonce aucun type (courant sur mobile). */
export function estPdf(fichier: Pick<File, 'type' | 'name'>): boolean {
  return fichier.type === 'application/pdf' || fichier.name.toLowerCase().endsWith('.pdf')
}

/** Une image se prévisualise par une URL d'objet. */
export function estApercuImage(fichier: Pick<File, 'type'>): boolean {
  return fichier.type.startsWith('image/')
}

/** Il faut au moins un texte ou un document, et un texte dans sa borne. */
export function peutAnalyser(texte: string, nombreDocuments: number): boolean {
  if (texte.length > TEXTE_MAX) return false
  return texte.trim().length > 0 || nombreDocuments > 0
}

/** La mention du quota : avant le premier appel on ne sait que la règle. */
export function mentionQuota(restant: number | null): string {
  if (restant === null) return `${QUOTA_QUOTIDIEN} analyses par jour`
  if (restant <= 0) return 'Plus aucune analyse possible aujourd’hui'
  return restant === 1
    ? 'Encore 1 analyse possible aujourd’hui'
    : `Encore ${restant} analyses possibles aujourd’hui`
}

/* ------------------------------------------------------------------ */
/* Les erreurs de l'analyse                                            */
/* ------------------------------------------------------------------ */

/**
 * Le titre posé au-dessus du message du serveur.
 *
 * Le message fait foi (§ 15.1), mais « 429 », « 502 » et « 503 » appellent trois
 * attitudes différentes — attendre demain, réessayer, revenir plus tard — et le
 * titre les dit avant qu'on lise la phrase. `null` : pas de titre, le message
 * suffit.
 */
export function titreErreurAnalyse(erreur: unknown): string | null {
  const { statut, code } = analyserErreur(erreur)
  if (statut === 429) return 'Quota du jour atteint'
  if (statut === 503 || code === 'assistant_indisponible') return 'Assistant indisponible'
  if (statut === 502 || code === 'assistant_erreur') return 'L’assistant n’a pas pu conclure'
  return null
}

/** Une annulation par le membre n'est pas une erreur à afficher. */
export function estAnnulation(erreur: unknown): boolean {
  return estErreurApi(erreur) && erreur.code === 'client_requete_annulee'
}

/* ------------------------------------------------------------------ */
/* La relecture                                                        */
/* ------------------------------------------------------------------ */

export const LIBELLES_CONFIANCE: Record<ConfianceAssistant, string> = {
  haute: 'Confiance haute',
  moyenne: 'Confiance moyenne',
  faible: 'Confiance faible',
}

/** Une personne restreinte n'a que son nom : **jamais** de dates, même si la
 *  réponse en portait. C'est la règle du graphe, tenue ici plutôt que dans le
 *  gabarit, pour qu'un test la voie. */
export function datesAffichables(personne: PersonneCitee): string | null {
  if (personne.visibilite !== 'complete') return null
  const dates = personne.ligneDates?.trim() ?? ''
  return dates.length > 0 ? dates : null
}

/** La fiche existante que vise une demande, ou `null` pour une création. */
export function cibleExistante(demande: Pick<DemandeProposee, 'corps'>): number | null {
  const corps = demande.corps
  if (corps.kind === 'modification_personne' || corps.kind === 'ajout_lien') return corps.targetId
  return null
}

export const VALEUR_MASQUEE = '(valeur masquée)'

/**
 * Ce que l'écran écrit avant la flèche — ou `null` : pas de « avant » du tout.
 *
 * `avant === null` n'a pas un seul sens :
 *
 * - sur une **modification**, c'est une valeur que le lecteur n'a pas le droit
 *   de lire (fiche restreinte) — le serveur ne l'a pas envoyée, et l'écran dit
 *   « (valeur masquée) » plutôt qu'un tiret qui ferait croire le champ vide ;
 * - sur une **création** ou un **ajout de lien**, rien n'existait : on n'écrit
 *   que la valeur proposée.
 */
export function libelleAvant(
  changement: ChangementPropose,
  demande: Pick<DemandeProposee, 'corps'>,
): string | null {
  if (changement.avant !== null) return changement.avant
  return demande.corps.kind === 'modification_personne' ? VALEUR_MASQUEE : null
}

/** Les clés cochées au départ : **toutes**. Décocher est le geste du membre. */
export function clesCocheesParDefaut(demandes: readonly DemandeProposee[]): Set<string> {
  return new Set(demandes.map((demande) => demande.cle))
}

/** Le libellé du bouton de dépôt : « Déposer la demande », « Déposer les 3 demandes ». */
export function libelleDepot(nombre: number): string {
  if (nombre <= 0) return 'Aucune demande cochée'
  return nombre === 1 ? 'Déposer la demande' : `Déposer les ${nombre} demandes`
}

/* ------------------------------------------------------------------ */
/* Le dépôt                                                            */
/* ------------------------------------------------------------------ */

export type EtatEtape = 'attente' | 'en_cours' | 'reussi' | 'echec'

export interface EnvoiPreuve {
  /** Index du document dans la liste jointe à l'analyse. */
  index: number
  nom: string
  etat: EtatEtape
  erreur: unknown
}

export interface EtapeDepot {
  cle: string
  titre: string
  etat: EtatEtape
  /** La demande créée, une fois déposée. */
  demandeId: number | null
  erreur: unknown
  /** La fiche existante qui recevra les documents, ou `null`. */
  cibleDesPreuves: number | null
  /** Vide si aucune pièce n'est à joindre ici — création, option décochée, ou
   *  fiche qui les reçoit déjà par une demande précédente. */
  preuves: EnvoiPreuve[]
}

/**
 * Le plan de dépôt : une étape par demande **cochée**, dans l'ordre de l'analyse.
 *
 * Les documents vont aux **personnes existantes** visées (modification, ajout de
 * lien) — une création n'a pas encore de fiche. Deux demandes sur la même fiche
 * ne font pas deux fois les mêmes envois : la première les porte, la seconde
 * n'en porte aucun.
 */
export function planDeDepot(
  demandes: readonly DemandeProposee[],
  cochees: ReadonlySet<string>,
  documents: readonly Pick<File, 'name'>[],
  joindrePreuves: boolean,
): EtapeDepot[] {
  const dejaServies = new Set<number>()
  const plan: EtapeDepot[] = []

  for (const demande of demandes) {
    if (!cochees.has(demande.cle)) continue
    const cible = cibleExistante(demande)
    const recoit = joindrePreuves && cible !== null && !dejaServies.has(cible) && documents.length > 0
    if (recoit) dejaServies.add(cible)

    plan.push({
      cle: demande.cle,
      titre: demande.titre,
      etat: 'attente',
      demandeId: null,
      erreur: null,
      cibleDesPreuves: recoit ? cible : null,
      preuves: recoit
        ? documents.map((document, index) => ({ index, nom: document.name, etat: 'attente', erreur: null }))
        : [],
    })
  }
  return plan
}

/** Légende d'une preuve jointe : d'où elle vient, bornée aux 255 caractères
 *  du § 7.1. */
export function legendeDePreuve(demandeId: number, titre: string): string {
  const legende = `Joint à la demande n° ${demandeId} (assistant) : ${titre}`
  return legende.length > 255 ? `${legende.slice(0, 254)}…` : legende
}

export interface OperationsDeDepot {
  deposer: (cle: string) => Promise<{ id: number }>
  televerser: (envoi: { index: number; personId: number; legende: string }) => Promise<unknown>
}

/**
 * Exécute le plan, **une étape après l'autre**, en écrivant l'état dans les
 * objets reçus — l'écran passe un tableau réactif, et chaque transition se voit.
 *
 * Une demande qui échoue n'emporte ni les suivantes, ni ses pièces : ses pièces
 * ne partent simplement pas (elles n'auraient pas de demande à accompagner). Une
 * pièce qui échoue n'emporte pas les autres.
 */
export async function executerDepot(plan: EtapeDepot[], operations: OperationsDeDepot): Promise<void> {
  for (const etape of plan) {
    // Une demande déjà déposée ne se redépose pas (le serveur rendrait `409
    // deja_deposee`) ; on passe directement à ses pièces restantes.
    if (etape.etat !== 'reussi') {
      etape.etat = 'en_cours'
      etape.erreur = null
      try {
        const demande = await operations.deposer(etape.cle)
        etape.demandeId = demande.id
        etape.etat = 'reussi'
      } catch (cause) {
        etape.etat = 'echec'
        etape.erreur = cause
        continue
      }
    }

    if (etape.cibleDesPreuves === null) continue
    for (const preuve of etape.preuves) {
      if (preuve.etat === 'reussi') continue
      preuve.etat = 'en_cours'
      preuve.erreur = null
      try {
        await operations.televerser({
          index: preuve.index,
          personId: etape.cibleDesPreuves,
          legende: legendeDePreuve(etape.demandeId ?? 0, etape.titre),
        })
        preuve.etat = 'reussi'
      } catch (cause) {
        preuve.etat = 'echec'
        preuve.erreur = cause
      }
    }
  }
}

/**
 * Le message d'un dépôt refusé.
 *
 * Deux cas propres à l'assistant se disent autrement que par le message brut :
 * une analyse **expirée** (`404`, deux heures) — relancer est la seule issue —
 * et une demande **déjà déposée** (`409 deja_deposee`), qui n'est pas un échec
 * pour le membre : elle est dans la file.
 */
export function messageEchecDepot(erreur: unknown): string {
  const analyse = analyserErreur(erreur)
  if (analyse.statut === 404) return 'L’analyse a expiré, relancez-la.'
  if (analyse.code === 'deja_deposee') return 'Cette demande a déjà été déposée : elle est dans la file.'
  return analyse.message
}

/** Récapitulatif de fin. */
export function bilanDepot(plan: readonly EtapeDepot[]): { reussis: number; echecs: number } {
  return {
    reussis: plan.filter((etape) => etape.etat === 'reussi').length,
    echecs: plan.filter((etape) => etape.etat === 'echec').length,
  }
}
