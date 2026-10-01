/**
 * Les **trois** états d'accès — et non deux.
 *
 * C'est le piège de ce produit, et il est ici pour qu'aucun écran n'ait à le
 * redécouvrir :
 *
 * | Régime | Ce que c'est |
 * |---|---|
 * | `anonyme` | aucune session |
 * | `en_attente` | session ouverte, inscription **non validée** — **rien de l'arbre**, ni vivants ni défunts, **aucun cercle** |
 * | `membre` | rattaché, avec un cercle de visibilité |
 * | `administrateur` | `ROLE_ADMIN` ; implique `membre` |
 *
 * **`en_attente` n'est pas « un membre avec moins de droits », et surtout pas
 * « un cercle de profondeur nulle ».** Côté serveur, cette dernière notion n'est
 * même pas exprimable : le service de cercle rend « pas de cercle », un état à
 * part de « cercle calculé, résultat vide ». Un `N = 0` resterait un cercle — il
 * contiendrait encore la personne elle-même et ses conjoints, et laisserait donc
 * sortir la fiche d'une personne vivante (§ 12 du contrat, ADR 0003).
 *
 * Dans le JSON, la distinction tient à **une clé** : `cercle.regime` vaut
 * `"aucun"` contre `"cercle"`. Le front branche sur `regime`, **jamais** sur
 * `taille === 0` — les clés `generations` et `taille` sont absentes du régime
 * `aucun`, et les lire produirait `undefined`, pas `0`. Le type
 * `CercleDeSession` est une union discriminée précisément pour que ce test-là ne
 * compile pas.
 *
 * **Révision du 01/10/2026 (ADR 0003).** Le compte en attente lisait « l'arbre
 * ancien » : les défunts, et chaque vivant en nœud restreint — nom complet
 * compris. Le lien d'invitation circule dans un groupe de messagerie : n'importe
 * qui pouvait s'inscrire sous un faux nom et lire les noms des vivants. Il ne
 * voit donc plus rien de l'arbre : toute route qui le lit exige `membre`, et sa
 * page par défaut est l'écran d'attente (`inscription.etat`). L'API lui répond
 * `403 regime_en_attente` sur les mêmes lectures — la garde n'est que le premier
 * rideau.
 *
 * Ce module ne contient que des fonctions pures : c'est ce qui le rend testable
 * sans monter ni routeur ni application (`acces.test.ts`).
 */

import type { RouteLocationRaw } from 'vue-router'
import type { ChargeDeSession } from '@/lib/api'

export type RegimeAcces = 'anonyme' | 'en_attente' | 'membre' | 'administrateur'

/**
 * Ce qu'une route exige. Le nom est celui du *régime minimal*, sauf `anonyme`
 * qui est une exigence inverse (l'écran de connexion n'a pas de sens pour un
 * compte déjà connecté).
 */
export type NiveauRequis =
  /** Accessible dans tous les cas (page introuvable, mentions). */
  | 'public'
  /** Réservé aux visiteurs sans session : connexion, inscription. */
  | 'anonyme'
  /** Toute session, **y compris `en_attente`** : ce qui concerne le compte
   *  lui-même — son inscription, Mon compte, Moi, l'ouverture d'une
   *  notification. **Jamais une lecture de l'arbre** (ADR 0003, 01/10/2026). */
  | 'attente'
  /** Inscription validée exigée : **toute lecture de l'arbre** (arbre, fiche,
   *  Explorer, accueil) et toute contribution. */
  | 'membre'
  /** `ROLE_ADMIN`. */
  | 'administration'

export type MotifRefus =
  | 'session_absente'
  | 'deja_connecte'
  | 'rattachement_non_valide'
  | 'administration_requise'

export type DecisionAcces =
  | { autorise: true }
  | { autorise: false; motif: MotifRefus; redirection: RouteLocationRaw }

/**
 * Le régime d'un lecteur, déduit de la charge de session (§ 2.2).
 *
 * Deux signaux sont lus au lieu d'un, et c'est un **défaut fermé** assumé :
 * `cercle.regime` est celui sur lequel le contrat demande de brancher, et
 * `utilisateur.status` le double. S'ils divergeaient — ce qui serait un défaut
 * côté API — on retient le plus restrictif, plutôt que d'ouvrir l'arbre des
 * vivants à un compte que le serveur n'a pas activé.
 */
export function regimeDeSession(session: ChargeDeSession | null): RegimeAcces {
  if (session === null) return 'anonyme'
  if (session.cercle.regime === 'aucun') return 'en_attente'
  if (session.utilisateur.status !== 'actif') return 'en_attente'
  if (session.utilisateur.roles.includes('ROLE_ADMIN')) return 'administrateur'
  return 'membre'
}

/** Un lecteur a-t-il une session, quel que soit son régime ? */
export function estConnecte(regime: RegimeAcces): boolean {
  return regime !== 'anonyme'
}

/** Le rattachement est-il validé ? Faux pour `en_attente`, vrai au-dessus. */
export function estRattache(regime: RegimeAcces): boolean {
  return regime === 'membre' || regime === 'administrateur'
}

/** Où atterrit un lecteur qui n'a rien demandé de précis. */
export function routeParDefaut(regime: RegimeAcces): RouteLocationRaw {
  if (regime === 'anonyme') return { name: 'connexion' }
  // Un compte en attente atterrit sur SA page : l'écran d'attente. L'accueil
  // lit l'arbre (anniversaires, tirages), et il ne lit plus rien de l'arbre
  // avant sa validation (ADR 0003, révision du 01/10/2026).
  if (regime === 'en_attente') return { name: 'inscription.etat' }
  // Tout membre atterrit sur l'accueil (`/home`) : les anniversaires du jour,
  // ses tirages, et les deux portes — l'arbre, proposer.
  return { name: 'accueil' }
}

/**
 * La garde, sous forme pure.
 *
 * `cheminDemande` sert à mémoriser la destination dans `?suite=` pour y revenir
 * après connexion : un lien profond collé dans un navigateur vierge est le cas
 * nominal ici, pas un cas limite (lien d'invitation, lien de notification).
 */
export function resoudreAcces(
  niveau: NiveauRequis,
  regime: RegimeAcces,
  cheminDemande: string,
): DecisionAcces {
  switch (niveau) {
    case 'public':
      return { autorise: true }

    case 'anonyme':
      if (!estConnecte(regime)) return { autorise: true }
      return {
        autorise: false,
        motif: 'deja_connecte',
        redirection: routeParDefaut(regime),
      }

    case 'attente':
      // Les trois régimes connectés passent. Ce niveau ne couvre plus que ce
      // qui concerne le compte lui-même : depuis le 01/10/2026, aucune route qui
      // lit l'arbre ne le porte (ADR 0003).
      if (estConnecte(regime)) return { autorise: true }
      return refuserFauteDeSession(cheminDemande)

    case 'membre':
      if (estRattache(regime)) return { autorise: true }
      if (!estConnecte(regime)) return refuserFauteDeSession(cheminDemande)
      // Connecté mais pas validé : on ne le renvoie pas à la connexion — il
      // est bien connecté —, on l'emmène à l'écran qui explique l'attente
      // (§ 4.4). C'est le cas de l'arbre, de la fiche, d'Explorer et de
      // l'accueil comme d'une écriture : le serveur répond de même
      // `403 regime_en_attente`, « Votre inscription est en cours de
      // vérification ».
      return {
        autorise: false,
        motif: 'rattachement_non_valide',
        redirection: { name: 'inscription.etat' },
      }

    case 'administration':
      if (regime === 'administrateur') return { autorise: true }
      if (!estConnecte(regime)) return refuserFauteDeSession(cheminDemande)
      if (regime === 'en_attente') {
        return {
          autorise: false,
          motif: 'rattachement_non_valide',
          redirection: { name: 'inscription.etat' },
        }
      }
      return {
        autorise: false,
        motif: 'administration_requise',
        redirection: { name: 'acces-refuse' },
      }
  }
}

function refuserFauteDeSession(cheminDemande: string): DecisionAcces {
  return {
    autorise: false,
    motif: 'session_absente',
    redirection: { name: 'connexion', query: { suite: cheminDemande } },
  }
}
