/**
 * Le filtre « vivants uniquement » de la chronologie.
 *
 * Module à part, sur le modèle de `components/evenements/filtres.ts` : la
 * fonction pure ici, l'état dans la vue. Un prédicat rangé dans un `.vue` n'est
 * importable que par le chemin `*.vue`, que seul `vue-tsc` sait résoudre vers un
 * export nommé ; sorti dans son propre module, il se type et se teste par le
 * chemin ordinaire, et les types voyagent avec lui.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LE PRÉDICAT EST `barre.vivant`, ET RIEN D'AUTRE.**
 *
 * Trois voisins rendent un résultat **plausible, et jamais une erreur** — la
 * panne la plus chère qui soit :
 *
 *  - **`fin === null`** compte aussi les personnes **décédées dont on ignore la
 *    date du décès** : 53 barres sur 235 de la charge réelle (voir
 *    `BarreSansFin` dans `lib/api/chronologie.ts`). Mesuré sur l'arbre d'essai :
 *    4 barres retenues au lieu de 3 ;
 *  - **`!ouverte`** ne retient rien de plus, mais rien de moins : côté serveur
 *    `$ouverte = $personne->vivant` (`NormaliseurDeChronologie`), c'est le même
 *    booléen. Il n'apporte donc aucune garantie propre, et laisse croire qu'il
 *    en apporte une ;
 *  - **l'étiquette de date** vaut littéralement « vivant·e » sur d'autres
 *    écrans. Un filtre bâti sur cette chaîne casserait au premier changement de
 *    libellé, **en silence** : zéro barre, pas une exception.
 *
 * `deceased` — le `DEAT` **stocké** du GEDCOM — n'existe pas dans
 * `BarreChronologie` : le type rend cette erreur-là impossible, et c'est voulu.
 * `vivant` est le résultat **calculé** de la règle de confidentialité (seuil
 * d'âge, médiane par génération, cercle). C'est déjà la règle de
 * `classesDeNoeud()` dans `components/arbre/graphe.ts`, où la classe `prive` est
 * allumée par `vivant`, « jamais par `deceased` ni par l'absence de dates ».
 * ────────────────────────────────────────────────────────────────────
 */

import type { VueChronologie } from '@/lib/api'

/**
 * La vue à dessiner, filtrée ou non.
 *
 * **Filtre éteint ⟹ la vue elle-même, à l'identité.** Pas une copie
 * superficielle : le canevas surveille sa propriété `vue` par identité et se
 * reconstruit à chaque nouvel objet. Rendre `{ ...vue }` ferait repeindre tout
 * le dessin pour une valeur inchangée, et le cadrage du lecteur sauterait. C'est
 * aussi ce qui garantit que « filtre éteint » est **exactement** le comportement
 * d'avant ce filtre, sans rien à comparer.
 *
 * **Les jalons suivent les barres.** `placerJalons()` écarte déjà un jalon dont
 * la personne n'a pas de barre, mais s'appuyer là-dessus rendrait un objet
 * incohérent avec lui-même — des `jalons` qui désignent des personnes absentes
 * de `barres`. Une retouche de `placerJalons()` remettrait alors à l'écran les
 * faits datés de personnes que le filtre venait d'écarter, sans qu'aucun test
 * ne rougisse. On rend une vue cohérente, pas une vue rattrapée en aval.
 *
 * Ne touche ni aux bandes, ni aux bornes, ni aux branches : l'axe vient du
 * serveur, et un filtre qui le redécouperait changerait la graduation du temps
 * à chaque geste du lecteur.
 */
export function filtrerVivants(vue: VueChronologie, vivantsSeuls: boolean): VueChronologie {
  if (!vivantsSeuls) return vue
  const barres = vue.barres.filter((barre) => barre.vivant)
  const retenues = new Set(barres.map((barre) => barre.personId))
  return { ...vue, barres, jalons: vue.jalons.filter((j) => retenues.has(j.personId)) }
}
