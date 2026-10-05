/**
 * Ce que l'écran décide d'un média — état, taille, nature — **sans DOM**.
 *
 * Trois décisions y vivent, et chacune se trompe d'une façon coûteuse :
 *
 * ────────────────────────────────────────────────────────────────────
 * ## 1. Un média téléversé n'est pas un média validé
 *
 * Il naît `status: "en_attente"` et suit **le même circuit de validation** que
 * les autres modifications (§ 7.1, § 6.5 du dossier) : il n'est visible que de
 * son déposant et des administrateurs jusqu'à ce qu'une demande `ajout_media`
 * soit validée. Un écran qui ne le dit pas laisse croire à un contributeur que
 * son acte est publié — et il ne revient jamais vérifier.
 *
 * ## 2. On ne contrôle **pas** le type du fichier côté client
 *
 * Le contrat est explicite : « type réel déterminé par les **octets
 * d'en-tête**, jamais par l'extension ni par le `Content-Type` annoncé »
 * (§ 7.1). Or `File.type` **est** le `Content-Type` annoncé, deviné par le
 * système d'exploitation depuis l'extension. Refuser un fichier sur cette base,
 * ce serait appliquer exactement le contrôle que le contrat déclare non fiable :
 * on refuserait un JPEG correct que le navigateur a mal étiqueté, et on
 * laisserait passer une archive renommée en `.jpg`. L'attribut `accept` du
 * champ de fichier reste un **confort de sélection**, jamais un contrôle.
 *
 * La **taille**, elle, se vérifie : c'est un nombre exact, connu avant l'envoi,
 * et le contrôle évite de pousser vingt mégaoctets sur une liaison familiale
 * pour recevoir un `413`. Avec deux réserves écrites ici parce qu'elles ne se
 * devinent pas :
 *
 * - le plafond réel est `media.taille_max_octets`, un **réglage
 *   d'administration** (§ 2.2) que `GET /api/administration/reglages` réserve à
 *   `ROLE_ADMIN` (§ 10.2) : un membre ne peut pas le lire. La constante
 *   ci-dessous est donc la valeur documentée par défaut, **pas** une source de
 *   vérité ;
 * - le serveur reste l'autorité. Un fichier que ce module laisse passer peut
 *   très bien revenir en `413` — et c'est normal, pas un bogue.
 *
 * ## 3. Une erreur par média, jamais une alerte globale
 *
 * Le téléversement échoue souvent en vrai : taille, type, réseau. Chaque envoi
 * porte donc son propre état et son propre message — celui du serveur, rendu
 * par `analyserErreur()`. Et **jamais d'`alert()` ni de `confirm()`** dans ce
 * projet : ils bloquent le fil, ne se stylent pas, et ne se lisent pas au
 * lecteur d'écran comme le reste de la page.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Media, StatutMedia } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* L'état d'un média, tel que la pastille le dit                       */
/* ------------------------------------------------------------------ */

/** Les états que `PastilleEtat` connaît, plus le repli neutre. */
export type EtatPastille = 'en_attente' | 'validee' | 'refusee' | 'neutre'

export interface PastilleDeMedia {
  etat: EtatPastille
  /** Libellé accordé au masculin — on parle d'un *document*, d'une *preuve*,
   *  d'un *portrait*. `PastilleEtat` accorde au féminin par défaut, parce que
   *  ses libellés d'origine parlent d'une *demande*. */
  libelle: string
}

/**
 * `med_media.status` → la pastille.
 *
 * **La valeur du modèle ne se traduit ni ne se recase** (§ 1.2) : `actif`,
 * `en_attente`, `refuse` partent en base tels quels. Ce qui se traduit, c'est le
 * libellé français — et il est ici, à un seul endroit.
 *
 * `refuse` n'est pas une suppression : « un refus n'est pas une destruction, un
 * média `refuse` garde son fichier sur disque jusqu'à purge explicite » (§ 7.6).
 * L'afficher plutôt que de le faire disparaître, c'est dire au déposant ce qu'il
 * est advenu de son envoi.
 */
export function pastilleDeMedia(status: StatutMedia): PastilleDeMedia {
  switch (status) {
    case 'en_attente':
      return { etat: 'en_attente', libelle: 'En attente de validation' }
    case 'actif':
      return { etat: 'validee', libelle: 'Validé' }
    case 'remplace':
      // Pas un refus ni une suppression : un portrait qu'un plus récent a relayé.
      return { etat: 'neutre', libelle: 'Ancien portrait' }
    case 'refuse':
      return { etat: 'refusee', libelle: 'Refusé' }
    default:
      // Un statut que le modèle ajouterait sans qu'on le sache : on le montre
      // tel quel plutôt que de le taire. Le défaut est bavard, pas permissif.
      return { etat: 'neutre', libelle: String(status) }
  }
}

/* ------------------------------------------------------------------ */
/* Le contrôle de courtoisie avant l'envoi                             */
/* ------------------------------------------------------------------ */

/**
 * `media.taille_max_octets`, **valeur documentée par défaut** : 20 Mio (§ 7.1).
 *
 * Ce n'est pas le plafond appliqué — celui-là vit dans `core_setting`, dans
 * nginx et dans PHP, et les trois doivent concorder. C'est la borne au-delà de
 * laquelle l'envoi est *certainement* refusé, donc celle au-delà de laquelle il
 * est inutile de l'entreprendre.
 */
export const TAILLE_MAX_PAR_DEFAUT = 20 * 1024 * 1024

export type VerdictEnvoi = { accepte: true } | { accepte: false; message: string }

/**
 * Le seul contrôle que le client a le droit d'appliquer : la **taille**.
 *
 * Voir l'en-tête du module pour la raison — vérifier le type ici reviendrait à
 * faire confiance au `Content-Type` annoncé, que le § 7.1 désigne nommément
 * comme ce sur quoi il **ne faut pas** se fonder.
 */
export function verifierAvantEnvoi(
  fichier: File,
  tailleMax: number = TAILLE_MAX_PAR_DEFAUT,
): VerdictEnvoi {
  if (fichier.size === 0) {
    return { accepte: false, message: 'Ce fichier est vide.' }
  }
  if (fichier.size > tailleMax) {
    return {
      accepte: false,
      message: `Ce fichier pèse ${tailleLisible(fichier.size)} ; le maximum est ${tailleLisible(tailleMax)}.`,
    }
  }
  return { accepte: true }
}

const NOMBRE = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })

/** Une taille en octets, lisible. Multiples binaires — ce sont ceux du § 7.1
 *  (« 20 Mio »), et arrondir en Mo décimaux ferait mentir le message de refus
 *  de quelques pour cent, juste assez pour qu'il paraisse faux. */
export function tailleLisible(octets: number): string {
  if (octets < 1024) return `${octets} octets`
  if (octets < 1024 * 1024) return `${NOMBRE.format(octets / 1024)} Kio`
  return `${NOMBRE.format(octets / (1024 * 1024))} Mio`
}

/* ------------------------------------------------------------------ */
/* Ce qu'on peut faire d'un média à l'écran                            */
/* ------------------------------------------------------------------ */

/**
 * Le fichier s'affiche-t-il dans une balise `<img>` ?
 *
 * Une preuve peut être un **PDF** (§ 6.5 du dossier : « acte, document, photo
 * d'archive, PDF »), et `pageCount` n'existe dans la charge que pour eux. La
 * vignette est générée pour tout le monde à l'enregistrement, mais l'original
 * d'un PDF ne se met pas dans une `<img>` : il s'ouvre.
 */
export function estImage(mimeType: string): boolean {
  return mimeType.startsWith('image/')
}

/**
 * L'ordre de la galerie : **ce qui attend une décision d'abord**.
 *
 * Un média `en_attente` appelle un geste — de son déposant, qui veut savoir où
 * il en est, comme de l'administrateur, pour qui c'est une file de validation
 * (§ 7.3). Le mettre en tête, c'est faire remonter ce qui n'est pas fini. Le
 * reste suit du plus récent au plus ancien.
 */
export function ordonnerMedias(medias: Media[]): Media[] {
  const rang: Record<string, number> = { en_attente: 0, actif: 1, remplace: 2, refuse: 3 }
  return [...medias].sort((a, b) => {
    const ecart = (rang[a.status] ?? 4) - (rang[b.status] ?? 4)
    if (ecart !== 0) return ecart
    return b.createdAt.localeCompare(a.createdAt)
  })
}

/**
 * L'ordre du carrousel de portraits : **le portrait en place d'abord** — c'est
 * celui que la fiche montre partout —, puis ce qui attend une décision, puis les
 * anciens portraits du plus récent au plus ancien, puis les refusés.
 */
export function ordonnerPortraits(medias: Media[]): Media[] {
  const rang = (m: Media): number =>
    m.isActivePortrait ? 0 : ({ en_attente: 1, actif: 2, remplace: 3, refuse: 4 } as Record<string, number>)[m.status] ?? 5
  return medias
    .filter((m) => m.kind === 'portrait')
    .sort((a, b) => rang(a) - rang(b) || b.createdAt.localeCompare(a.createdAt))
}

/**
 * Ce que la loupe doit afficher, et **comment elle obtient le droit de le faire**.
 *
 * Deux modes, parce que le contrat expose les médias sous deux formes et qu'une
 * seule des deux porte l'URL du fichier :
 *
 * - `direct` — la charge utile a donné `url` (portrait de fiche, média listé par
 *   `GET /api/medias`). On l'emploie **telle quelle** ;
 * - `identifiant` — on ne connaît que `mediaId`, parce que la charge ne portait
 *   qu'une vignette. C'est le cas des médias attachés à une citation (§ 5.3), où
 *   la fiche rend `{ mediaId, kind, vignetteUrl }` et rien de plus.
 *
 * Dans le second cas, la loupe **charge le média** (`GET /api/medias/{id}`) et
 * lit `url` dans la réponse. Elle ne fabrique pas `/api/medias/{id}/fichier` de
 * tête, alors même que ce serait la bonne chaîne : une URL devinée court-circuite
 * le point où le serveur décide, et un média interdit rend `404` — donc une image
 * cassée au lieu de rien. Passer par la charge utile, c'est laisser le refus
 * arriver là où il se traite.
 */
export type CibleLoupe =
  | { mode: 'direct'; url: string; legende: string; estImage: boolean }
  | { mode: 'identifiant'; mediaId: number; legende: string }

/**
 * Fusionne deux listes de médias en dédoublonnant par identifiant.
 *
 * La galerie interroge `GET /api/medias` **deux fois** — une fois pour `actif`,
 * une fois pour `en_attente` —, parce que le paramètre `status` vaut « `actif`
 * seul » par défaut et que le contrat ne prévoit pas d'en demander deux (§ 7.3).
 * Un média peut donc figurer dans les deux réponses si son statut change entre
 * les deux requêtes : c'est improbable, et c'est exactement le genre de chose
 * qui produit une vignette en double sans qu'on sache pourquoi.
 */
export function fusionnerMedias(...listes: Media[][]): Media[] {
  const parIdentifiant = new Map<number, Media>()
  for (const liste of listes) {
    for (const media of liste) parIdentifiant.set(media.id, media)
  }
  return ordonnerMedias([...parIdentifiant.values()])
}
