/**
 * Les appels d'API des **clés d'accès** (passkeys, WebAuthn).
 *
 * Posé selon la convention de `routes.ts` : une fonction par route, chemin écrit
 * **sans** le préfixe `/api`. La conversion entre le JSON du serveur et les
 * `ArrayBuffer` du navigateur est dans `lib/cleDAcces.ts` ; l'enchaînement
 * (options → navigateur → réponse) dans `composables/useClesDAcces.ts`. Ici, on
 * ne fait que parler au serveur.
 *
 * Ce qu'il faut avoir en tête en lisant les signatures :
 *
 * 1. **Le défi est gardé en session côté serveur**, à usage unique et valable
 *    cinq minutes. Chaque `…/options` en fabrique un nouveau et périme le
 *    précédent : deux cérémonies ne se mènent pas en parallèle.
 * 2. **`POST /connexion/cle-d-acces` rend exactement la charge de
 *    `POST /connexion`** — la même `ChargeDeSession` — et ses `401` sont les
 *    mêmes réponses métier (`identifiants_invalides`, `compte_suspendu`) :
 *    `sansRattrapage`, comme la connexion par mot de passe, sans quoi une clé
 *    refusée déconnecterait… un visiteur déjà déconnecté, vers la page où il est.
 *    Même plafond de tentatives aussi (`429 trop_de_tentatives`).
 * 3. **Seulement les siennes** : une clé d'un autre compte rend `404`, jamais
 *    `403` — dire qu'elle existe serait déjà en dire trop.
 */

import { requeteApi } from './client'
import type { ChargeDeSession } from './types'
import type { ReponseCleDAccesJson } from '@/lib/cleDAcces'

/** Une clé d'accès du compte, telle que la liste de Mon compte la montre. */
export interface CleDAcces {
  id: number
  /** Choisi à la création (« iPhone », « PC Windows »…), renommable. */
  nom: string
  /** ISO 8601. */
  creeeLe: string
  /** ISO 8601, ou `null` : jamais servie à se connecter. */
  derniereUtilisation: string | null
  /** Le gestionnaire qui la garde, quand le serveur le reconnaît à son AAGUID
   *  (« Trousseau iCloud », « Gestionnaire de mots de passe Google »…). */
  appareil?: string | null
}

/** `POST /api/cles-d-acces/options-d-enregistrement` — compte connecté, en
 *  attente compris ; refusé pendant une incarnation.
 *
 *  **Preuve d'identité récente exigée** (§ 2.4) : une connexion de moins de
 *  dix minutes, ou `motDePasseActuel`. Sans elle, `422` sur
 *  `champs.motDePasseActuel` — et non `401` : on ne déconnecte pas. Une clé
 *  d'accès survit à la session qui l'ajoute ; une session volée ne doit pas
 *  suffire à en planter une. */
export function lireOptionsEnregistrementCleDAcces(
  motDePasseActuel: string | null = null,
): Promise<{ options: PublicKeyCredentialCreationOptionsJSON }> {
  return requeteApi<{ options: PublicKeyCredentialCreationOptionsJSON }>('/cles-d-acces/options-d-enregistrement', {
    methode: 'POST',
    ...(motDePasseActuel === null ? {} : { corps: { motDePasseActuel } }),
  })
}

/** `POST /api/cles-d-acces` → `201`. `nom` facultatif : le serveur en choisit un. */
export function enregistrerCleDAcces(reponse: ReponseCleDAccesJson, nom: string | null): Promise<CleDAcces> {
  return requeteApi<CleDAcces>('/cles-d-acces', {
    methode: 'POST',
    corps: nom === null ? { reponse } : { reponse, nom },
  })
}

/** `GET /api/cles-d-acces` — les clés du compte connecté, et seulement elles. */
export function listerClesDAcces(): Promise<CleDAcces[]> {
  return requeteApi<CleDAcces[]>('/cles-d-acces')
}

/** `PATCH /api/cles-d-acces/{id}` → `200` avec la clé renommée. */
export function renommerCleDAcces(id: number, nom: string): Promise<CleDAcces> {
  return requeteApi<CleDAcces>(`/cles-d-acces/${id}`, { methode: 'PATCH', corps: { nom } })
}

/** `DELETE /api/cles-d-acces/{id}` → `204`. */
export async function supprimerCleDAcces(id: number): Promise<void> {
  await requeteApi<null>(`/cles-d-acces/${id}`, { methode: 'DELETE' })
}

/** `POST /api/connexion/cle-d-acces/options` — public. `allowCredentials` est
 *  vide : la clé est **découvrable**, c'est elle qui dit quel compte. */
export function lireOptionsConnexionCleDAcces(): Promise<{ options: PublicKeyCredentialRequestOptionsJSON }> {
  return requeteApi<{ options: PublicKeyCredentialRequestOptionsJSON }>('/connexion/cle-d-acces/options', {
    methode: 'POST',
    sansRattrapage: true,
  })
}

/** `POST /api/connexion/cle-d-acces` — voir le point 2 de l'en-tête. */
export function seConnecterParCleDAcces(reponse: ReponseCleDAccesJson): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/connexion/cle-d-acces', {
    methode: 'POST',
    corps: { reponse },
    sansRattrapage: true,
  })
}
