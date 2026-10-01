/**
 * L'état du parcours d'inscription, entre ses trois écrans.
 *
 * Trois routes se partagent une même conversation avec le serveur
 * (`/inscription`, `/inscription/rattachement`, `/inscription/compte`) et
 * doivent se passer trois choses : le jeton d'invitation, le mode de formulaire,
 * et **le rattachement demandé** — soit le candidat retenu (`choix`), soit, quand
 * le candidat ne s'est pas trouvé, la famille qu'il décrit (`declaration`). Les
 * deux sont exclusifs : retenir l'un efface l'autre, comme le serveur refuse de
 * recevoir les deux. Ce module les porte, en mémoire, pour la durée de l'onglet.
 *
 * **Rien n'est stocké — et « rien » est à prendre au pied de la lettre.** Ni
 * `localStorage`, ni `sessionStorage`, ni cookie, ni URL. Le jeton d'invitation
 * vaut, pendant sept jours, le droit d'ouvrir un compte sur cet arbre ; le `ref`
 * d'un candidat désigne une personne potentiellement vivante, et la description
 * de famille parle de vivants par nature. Les écrire
 * quelque part les ferait survivre à l'onglet, sur une machine familiale
 * partagée, et un `sessionStorage` se lit depuis la console du navigateur.
 *
 * Conséquence assumée : **un rechargement de page perd le parcours en cours.**
 * C'est le bon compromis. Le lien d'invitation n'est consommé qu'au succès de
 * l'étape 2 ; tant que le compte n'est pas créé, rouvrir le lien reprend tout
 * depuis le début. Les écrans qui découvrent un flux vide le disent avec des
 * mots — « reprenez depuis le lien que vous avez reçu » — plutôt que d'échouer.
 *
 * **Le jeton ne reparaît jamais dans une URL** après l'écran d'entrée. Il arrive
 * par le lien d'invitation, et il s'arrête là : le propager en `?jeton=` sur les
 * étapes suivantes le sèmerait dans l'historique, dans les `Referer` et dans
 * toute capture d'écran d'un candidat qui demande de l'aide.
 *
 * Et **rien de tout cela ne se journalise** : ni le jeton, ni les `ref`, ni les
 * libellés de candidats. Il n'y a volontairement pas un seul `console.*` dans ce
 * module ni dans les écrans qui l'emploient.
 */

import { computed, shallowRef, type ComputedRef } from 'vue'
import type { CandidatRattachement, DeclarationDeFamille, VerificationJeton } from '@/lib/api'

/** Le jeton d'invitation, en clair, pour la durée de l'onglet uniquement. */
const jeton = shallowRef<string | null>(null)
/** Ce que `POST /inscription/verifier` a répondu (§ 4.1). */
const verification = shallowRef<VerificationJeton | null>(null)
/** Les candidats de la dernière recherche — au plus trois (§ 4.2). */
const candidats = shallowRef<CandidatRattachement[]>([])
/** Le `ref` opaque retenu par le candidat, à renvoyer en `choix` à l'étape 2. */
const choix = shallowRef<CandidatRattachement | null>(null)
/** « Je ne me trouve pas » : l'identité saisie et la famille décrite, à envoyer
 *  en `declaration` à l'étape 2 à la place d'un `choix`. */
const declaration = shallowRef<DeclarationDeFamille | null>(null)

/**
 * Ouvre le parcours après une vérification réussie du jeton.
 *
 * Tout ce qui pouvait rester d'une tentative précédente est effacé : un candidat
 * retenu sous un autre jeton n'a aucun sens, et le serveur le refuserait de
 * toute façon — un `ref` est lié à la tentative qui l'a produit.
 */
export function ouvrirFlux(jetonValide: string, reponse: VerificationJeton): void {
  jeton.value = jetonValide
  verification.value = reponse
  candidats.value = []
  choix.value = null
  declaration.value = null
}

/** Enregistre le résultat d'une recherche. Un nouveau résultat périme le choix
 *  précédent : les `ref` de la tentative d'avant ne sont plus acceptés. */
export function retenirCandidats(resultat: CandidatRattachement[]): void {
  candidats.value = resultat
  choix.value = null
}

export function choisirCandidat(candidat: CandidatRattachement): void {
  choix.value = candidat
  declaration.value = null
}

/**
 * « Je ne me trouve pas » ou « Aucune de ces personnes » : le candidat décrit sa
 * famille. Le candidat éventuellement retenu avant est oublié — on ouvre le
 * compte **sans fiche**, pas avec une fiche douteuse.
 *
 * La déclaration n'est **pas** effacée par une nouvelle recherche : le candidat
 * qui relance une recherche après avoir commencé sa description ne doit pas la
 * perdre. Elle l'est à la fermeture du parcours, comme tout le reste.
 */
export function retenirDeclaration(entree: DeclarationDeFamille): void {
  declaration.value = entree
  choix.value = null
}

/**
 * Efface tout. Appelé au succès de l'étape 2 — le jeton vient d'être consommé
 * côté serveur, le garder en mémoire n'aurait plus d'objet — et sur un lien
 * refusé.
 */
export function fermerFlux(): void {
  jeton.value = null
  verification.value = null
  candidats.value = []
  choix.value = null
  declaration.value = null
}

export interface FluxInscription {
  /** Le jeton, ou `null` si le parcours n'a pas été ouvert dans cet onglet. */
  jeton: ComputedRef<string | null>
  verification: ComputedRef<VerificationJeton | null>
  candidats: ComputedRef<CandidatRattachement[]>
  choix: ComputedRef<CandidatRattachement | null>
  /** La famille décrite, si le candidat ne s'est pas trouvé. Exclusive de `choix`. */
  declaration: ComputedRef<DeclarationDeFamille | null>
  /** Le parcours est-il exploitable ? Faux après un rechargement de page. */
  ouvert: ComputedRef<boolean>
  /** `confirmation` : une personne est pré-liée. `recherche` : rien ne l'est. */
  mode: ComputedRef<VerificationJeton['mode'] | null>
}

export function useFluxInscription(): FluxInscription {
  return {
    jeton: computed(() => jeton.value),
    verification: computed(() => verification.value),
    candidats: computed(() => candidats.value),
    choix: computed(() => choix.value),
    declaration: computed(() => declaration.value),
    ouvert: computed(() => jeton.value !== null && verification.value !== null),
    mode: computed(() => verification.value?.mode ?? null),
  }
}
