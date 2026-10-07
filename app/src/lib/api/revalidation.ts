/**
 * Revalidation par `ETag` — une seule route la porte : `GET /api/arbre/graphe`
 * (§ 1.8, § 5.2). Le mécanisme est isolé ici parce qu'il a trois pièges dont
 * aucun ne se voit à l'exécution : on reçoit simplement un graphe périmé.
 *
 * **1. L'`ETag` se conserve tel quel, guillemets compris** (§ 5.2, étape 2). Il
 * vaut `"<64 hex>"`, fort — jamais `W/`. Le déshabiller de ses guillemets pour
 * « faire propre » produit un `If-None-Match` qui ne correspond à rien, donc un
 * 200 à chaque appel et une reconstruction complète du canevas à chaque
 * navigation.
 *
 * **2. L'empreinte de l'arbre n'est pas l'`ETag`.** L'`ETag` vaut
 * `sha256(empreinte || ':' || empreinte des demandes en attente du lecteur)`.
 * Déposer un brouillon ne touche aucune table `tree_*`, donc ne change pas
 * l'empreinte : un front qui revaliderait sur l'empreinte ne verrait jamais son
 * propre nœud en attente — « c'est-à-dire précisément la seule chose que ce
 * calque existe pour produire » (ADR 0005).
 *
 * **3. L'`ETag` dépend du lecteur.** Il incorpore les demandes en attente du
 * lecteur et, sous la bascule « voir comme un membre », le membre emprunté
 * (§ 10.4). Il est donc **oublié à tout changement de session** : connexion,
 * déconnexion, activation, entrée et sortie de bascule. C'est le rôle de
 * `oublierEtag()`, que le module de session appelle.
 */

import { appelBrut, type OptionsRequete } from './client'
import { erreurDepuisCorps } from './erreurs'

/**
 * Résultat d'un appel revalidé.
 *
 * L'union discriminée est le point : elle oblige l'appelant à traiter le cas
 * `inchange`. Le contrat en dépend — « `304` → le client **garde son SVG en
 * place**. Il ne le reconstruit pas, il ne le vide pas » (§ 5.2, étape 3). Un
 * type qui rendrait `T | null` laisserait écrire un `if (!donnees) vider()`,
 * c'est-à-dire exactement le contraire.
 */
export type ResultatRevalide<T> =
  | { statut: 'inchange' }
  | { statut: 'nouveau'; donnees: T; etag: string | null }

/** Mémoire des `ETag` par clé logique, en mémoire seulement : la charge dépend
 *  du lecteur (§ 1.8). Ce qui en survit à un F5 — le graphe de l'arbre et son
 *  `ETag` — est gardé à part, par lecteur, et effacé à chaque changement de
 *  session (`histoire/memoire.ts`) ; c'est lui qui le rend ici par
 *  {@link retenirEtag}, avant de revalider. */
const etags = new Map<string, string>()

export function etagConnu(cle: string): string | undefined {
  return etags.get(cle)
}

/**
 * Pose l'`ETag` à présenter au prochain appel : celui du corps qu'on a GARDÉ, et
 * rien d'autre — un `304` dit « ce que vous avez est à jour », il faut donc avoir
 * ce dont l'`ETag` parle. Même règle qu'à la réception : tel quel, jamais faible.
 */
export function retenirEtag(cle: string, etag: string): void {
  if (etag.startsWith('W/')) etags.delete(cle)
  else etags.set(cle, etag)
}

/** Oublie un `ETag` (ou tous). À appeler à tout changement de session et après
 *  toute écriture qui modifie l'arbre. Le prochain appel rendra un `200`. */
export function oublierEtag(cle?: string): void {
  if (cle === undefined) etags.clear()
  else etags.delete(cle)
}

/**
 * `GET` revalidé : pose `If-None-Match` si l'on connaît un `ETag`, traite le
 * `304` comme un succès, et mémorise l'`ETag` rendu.
 *
 * Note d'exploitation : `appelBrut` force `cache: 'no-store'`, sans quoi le
 * cache du navigateur — la réponse porte `Cache-Control: private, no-cache` —
 * revaliderait pour son compte et nous rendrait un `200` issu de sa mémoire.
 * On ne saurait alors plus distinguer « le graphe a changé » de « le navigateur
 * nous ressert le sien », et la reconstruction du canevas se ferait pour rien.
 */
export async function requeteRevalidee<T>(
  cle: string,
  chemin: string,
  options: OptionsRequete = {},
): Promise<ResultatRevalide<T>> {
  const connu = etags.get(cle)
  const entetes = { ...(options.entetes ?? {}) }
  if (connu !== undefined) entetes['If-None-Match'] = connu

  const brut = await appelBrut(chemin, { ...options, methode: 'GET', entetes })

  if (brut.statut === 304) return { statut: 'inchange' }
  if (brut.statut >= 400) {
    // Un ETag conservé après une erreur ferait revalider contre un état qu'on
    // n'a peut-être plus le droit de voir : on le jette.
    etags.delete(cle)
    throw erreurDepuisCorps(brut.statut, brut.corps)
  }

  const etag = brut.entetes.get('ETag')
  // Tel quel, guillemets compris (§ 5.2). Aucun décapage, aucun `W/` toléré :
  // un ETag faible autoriserait un intermédiaire à confondre deux
  // représentations qui diffèrent d'une donnée personnelle.
  if (etag !== null && !etag.startsWith('W/')) etags.set(cle, etag)
  else etags.delete(cle)

  return { statut: 'nouveau', donnees: brut.corps as T, etag }
}
