/**
 * L'index du graphe et les calculs qui s'y font : parcours, voisinage, chaîne
 * de parenté jusqu'à l'ego, classes CSS d'un nœud, filtres.
 *
 * **Pur, sans DOM.** C'est ce qui permet de vérifier sans navigateur la
 * recherche de chemin entre deux personnes et la construction des classes —
 * les deux endroits où une erreur ne se voit qu'à l'échelle réelle.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le graphe est biparti, et c'est ce qui rend le parcours simple.** L'API ne
 * rend pas de listes `parents` / `enfants` / `conjoints` : elle rend des arêtes
 * `personne → famille` (`union`) et `famille → personne` (`filiation`). Deux
 * sauts depuis une personne donnent donc exactement, et d'un seul geste, ses
 * parents, ses conjoints, ses enfants **et** sa fratrie — ce que le viewer
 * d'origine obtenait en concaténant quatre listes.
 *
 * **Un identifiant de nœud ne se découpe jamais.** `"#12/p1"` est opaque : il
 * relie une arête à un nœud et retrouve un élément du DOM, rien d'autre. Pour
 * agir sur ce qu'il désigne, on lit `personId`, `familyId`, `demandeId`,
 * `brouillonRef`, que l'API envoie **déjà séparés** — jamais un `split('/')`
 * ni un `slice(1)`. Le `#` est le séparateur de fragment d'URL, et `local_ref`
 * n'est unique que dans sa demande : un découpage maison qui se tromperait de
 * moitié rapprocherait deux nœuds sans rapport, sans rien casser de visible.
 * ────────────────────────────────────────────────────────────────────
 */

import type {
  AreteGraphe,
  BoiteGraphe,
  Graphe,
  NoeudFamille,
  NoeudGraphe,
  NoeudPersonne,
} from '@/lib/api'

/* ------------------------------------------------------------------ */
/* Les champs de qualité documentaire — voir le rapport de livraison   */
/* ------------------------------------------------------------------ */

/**
 * Ce que les filtres « Vérifiés », « Non vérifiés », « Sans source » et « Avec
 * image » demandent à un nœud, et que **`GET /api/arbre/graphe` n'émet pas
 * encore** : le § 5.2 du contrat n'inscrit sur un nœud que la géométrie, le nom,
 * le sexe, la génération, la branche, `vivant` et `visibilite`.
 *
 * Les champs sont donc déclarés **optionnels** et reprennent les noms que la
 * fiche emploie déjà (§ 5.3), pour qu'il n'y ait rien à traduire le jour où
 * l'API les portera. Tant qu'ils sont absents, le filtre correspondant est
 * désactivé à l'écran plutôt que faux — un filtre qui ne trouve rien parce que
 * la donnée manque ressemble en tout point à un filtre qui trouve zéro fiche.
 *
 * Ils suivent le cercle de visibilité comme le reste : `bestQuay` et
 * `citationCount` figurent nommément dans ce que le § 16.2 interdit d'envoyer
 * pour une personne vivante hors cercle. Un nœud restreint n'en portera donc
 * jamais, et le prédicat doit le supporter — d'où les `??` ci-dessous.
 */
export interface QualiteDocumentaire {
  /** Meilleur `QUAY` des citations de la fiche. `>= 2` ⇒ appuyé sur un acte. */
  bestQuay?: number | null
  citationCount?: number
  /** Une image d'archive est jointe à au moins une citation. */
  avecImage?: boolean

  /**
   * L'ancien nom de la même chose — **cale de transition, à retirer sous
   * condition écrite ci-dessous.**
   *
   * ⚠ Le code du sérialiseur et le conteneur qui tourne ne disent pas la même
   * chose, et c'est tout l'intérêt de cette cale :
   *
   * - **la source** (`Tree/Serializer/NormaliseurDeGraphe.php:274` et `:306`)
   *   pose `etiquetteNom` sur tout nœud de personne et `etiquetteDates` sur les
   *   nœuds complets ; aucune ligne n'y pose `ligneDates` ;
   * - **l'image servie**, relevée le 15/09/2026 à 23 h 20 sur
   *   `GET /api/arbre/graphe` avec un compte administrateur : `ligneDates` sur
   *   **274 nœuds sur 274**, `etiquetteDates` sur **zéro**, et de même pour
   *   `etiquetteNom`, `bestQuay`, `citationCount`, `avecImage`.
   *
   * L'image front comme l'image API attendent une reconstruction. Retirer la
   * cale aujourd'hui viderait la ligne de dates des 274 fiches.
   *
   * **CONDITION DE RETRAIT, sans ambiguïté** : refaire le relevé ci-dessus ; si
   * `etiquetteDates` est présente sur les nœuds complets et `ligneDates` sur
   * zéro nœud, supprimer ce champ et le `??` de `datesDeFiche()`. Une cale sans
   * date devient une convention, et personne n'ose plus retirer une convention.
   *
   * La confusion qui a produit ce renommage mérite d'être connue :
   * `etiquetteDates` est remplie par un appel à `MesureDeFiche::ligneDates()`.
   * Le nom de la méthode diffère du nom de la clé, et lire le premier pour le
   * second est l'erreur d'une seconde.
   */
  ligneDates?: string
}

export type NoeudPersonneEtendu = NoeudPersonne & QualiteDocumentaire

/** Les constantes de mesure du moteur (`graphe.py`) : 6,6 unités par signe pour
 *  le nom, 14 de marge de chaque côté. On ne s'en sert **pas** pour calculer une
 *  largeur — `w` vient du serveur — mais pour savoir si un texte va déborder. */
export const LARGEUR_PAR_SIGNE = 6.6
export const MARGE_FICHE = 14

/**
 * Tolérance avant de considérer qu'un nom déborde.
 *
 * Sans elle, on condenserait des étiquettes qui tiennent : le moteur **tronque**
 * `w` à l'entier, si bien que « Natron Romarin » mesure 92,4 pour 92 unités
 * disponibles. Et 6,6 est de toute façon un majorant — la fonte réelle est plus
 * étroite. Dix pour cent absorbent les deux, et laissent passer les cas où le
 * débordement est franc.
 */
const TOLERANCE = 1.1

/**
 * **Le nom à peindre. À ne pas confondre avec `etiquetteDates`**, qui est la
 * ligne de **dates** du moteur et vit sur la ligne du dessous. Les deux sont
 * « l'étiquette mesurée », et la largeur du nœud dépend des deux ; ce type
 * porte la moitié « nom », et son nom le dit.
 */
export interface NomDeFiche {
  texte: string
  /**
   * Largeur à imposer au texte (`textLength`), ou `null` s'il tient tel quel.
   *
   * On **condense** un nom trop long, on ne le coupe pas et on ne le récrit
   * pas : « Jade de La Tour » raccourci en « Jade Tour » serait un nom faux, et
   * une généalogie ne se permet pas ça. Condensé, il reste juste et il reste
   * dans sa boîte.
   */
  largeurImposee: number | null
}

/**
 * Le nom à peindre dans la fiche, et faut-il le contraindre.
 *
 * ────────────────────────────────────────────────────────────────────
 * **On dessine `etiquetteNom`, la chaîne que le moteur a mesurée**, et on ne
 * recompose jamais un nom. La raison pour laquelle on ne peut pas la dériver
 * de `displayName` est écrite au contrat : `displayName` vaut
 * `nickname ?? givenNames` puis `surname`, et « de la Tour » n'est découpable
 * par aucune règle. Trois clés, trois usages — `displayName` pour **lire**,
 * `etiquetteNom` pour **dessiner**, `surname`/`givenNames` pour **éditer**.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Le repli sur `displayName` n'est qu'un filet**, pour le cas où le nœud
 * arriverait sans sa chaîne mesurée — image en retard, projection incomplète.
 * `displayName` n'a pas été mesuré, et cela se voit :
 * mesuré sur les 274 fiches réelles, `displayName` dépasse sa boîte pour 220
 * d'entre elles, jusqu'à 169 unités de trop sur une boîte de 149 — de quoi
 * faire chevaucher trois fiches voisines. On condense alors, sans perdre une
 * lettre ni inventer un nom : « Jade de La Tour » abrégé en « Jade Tour »
 * serait un nom faux, et une généalogie ne se le permet pas.
 *
 * **Le filet se tait tout seul** le jour où le nœud portera le nom mesuré :
 * vérifié sur les 274 fiches, zéro condensation quand le nom est le bon, 134
 * sinon — dont 27 sous 55 %, difficiles à lire. C'est la mesure du manque.
 */
export function nomDeFiche(noeud: NoeudPersonneEtendu): NomDeFiche {
  // Le cas nominal, dès que l'API l'émettra : la chaîne exacte sur laquelle le
  // moteur a mesuré `w`. Rien à contraindre, le rendu est au pixel celui du
  // viewer d'origine — et tout ce qui suit devient du code mort.
  // Le sérialiseur la pose sur **tout** nœud de personne, restreint compris
  // (`NormaliseurDeGraphe.php:274`), et un test d'invariants du contrat échoue
  // si elle manque. Le garde-fou reste néanmoins : le type la dit obligatoire,
  // le conteneur déployé peut être en retard, et une chaîne absente à
  // l'exécution ne se voit pas à la compilation.
  const mesure = noeud.etiquetteNom
  if (typeof mesure === 'string' && mesure !== '') {
    return { texte: mesure, largeurImposee: null }
  }

  const disponible = noeud.w - 2 * MARGE_FICHE
  const estimee = noeud.displayName.length * LARGEUR_PAR_SIGNE
  if (disponible <= 0 || estimee <= disponible * TOLERANCE) {
    return { texte: noeud.displayName, largeurImposee: null }
  }
  return { texte: noeud.displayName, largeurImposee: disponible }
}

/* ------------------------------------------------------------------ */
/* L'index                                                             */
/* ------------------------------------------------------------------ */

export interface IndexGraphe {
  ego: string
  boite: BoiteGraphe
  parId: Map<string, NoeudGraphe>
  personnes: NoeudPersonneEtendu[]
  familles: NoeudFamille[]
  /** Pour retrouver un nœud depuis un `personId` — le seul sens utile : un
   *  résultat de recherche et une fiche portent un entier, pas un `"p17"`. */
  parPersonId: Map<number, NoeudPersonneEtendu>
  /** Adjacence **bipartite**, dans les deux sens. */
  adjacence: Map<string, string[]>
  /** Époux **et** enfants d'une famille — ce qui la rend « concernée ». */
  membresParFamille: Map<string, string[]>
  enfantsParFamille: Map<string, string[]>
  /**
   * Nombre d'unions par personne, compté sur les arêtes.
   * Le nœud ne porte pas de champ `unions` ; l'information est déjà dans le
   * graphe, il n'y a donc rien à demander à l'API pour allumer `.fiche.remarie`.
   */
  unionsParPersonne: Map<string, number>
}

export function indexer(graphe: Graphe): IndexGraphe {
  const parId = new Map<string, NoeudGraphe>()
  const personnes: NoeudPersonneEtendu[] = []
  const familles: NoeudFamille[] = []
  const parPersonId = new Map<number, NoeudPersonneEtendu>()

  for (const noeud of graphe.noeuds) {
    parId.set(noeud.id, noeud)
    if (noeud.type === 'personne') {
      const p = noeud as NoeudPersonneEtendu
      personnes.push(p)
      if (p.personId !== null) parPersonId.set(p.personId, p)
    } else {
      familles.push(noeud)
    }
  }

  const adjacence = new Map<string, string[]>()
  const membresParFamille = new Map<string, string[]>()
  const enfantsParFamille = new Map<string, string[]>()
  const unionsParPersonne = new Map<string, number>()

  const relier = (carte: Map<string, string[]>, cle: string, valeur: string): void => {
    const liste = carte.get(cle)
    if (liste === undefined) carte.set(cle, [valeur])
    else liste.push(valeur)
  }

  for (const arete of graphe.aretes) {
    // Une arête dont une extrémité manque n'est pas une erreur à signaler : la
    // projection peut légitimement ne pas envoyer un nœud, et faire tomber le
    // rendu entier pour cela serait pire que de ne pas la dessiner.
    const de = parId.get(arete.de)
    const vers = parId.get(arete.vers)
    if (de === undefined || vers === undefined) continue

    relier(adjacence, arete.de, arete.vers)
    relier(adjacence, arete.vers, arete.de)

    // Le contrat écrit l'époux en `de` sur une union et la famille en `de` sur
    // une filiation (§ 5.2). On lit pourtant le **type** des nœuds plutôt que
    // leur place : une arête toujours composée d'une personne et d'une famille
    // se lit sans convention, et une inversion côté serveur produirait sinon un
    // arbre plausible mais faux — une filiation branchée à l'envers ne saute
    // pas aux yeux sur 377 arêtes.
    const personne = de.type === 'personne' ? de : vers.type === 'personne' ? vers : null
    const famille = de.type === 'famille' ? de : vers.type === 'famille' ? vers : null
    if (personne === null || famille === null) continue

    relier(membresParFamille, famille.id, personne.id)
    if (arete.genre === 'union') {
      unionsParPersonne.set(personne.id, (unionsParPersonne.get(personne.id) ?? 0) + 1)
    } else {
      relier(enfantsParFamille, famille.id, personne.id)
    }
  }

  return {
    ego: graphe.ego,
    boite: graphe.boite,
    parId,
    personnes,
    familles,
    parPersonId,
    adjacence,
    membresParFamille,
    enfantsParFamille,
    unionsParPersonne,
  }
}

/* ------------------------------------------------------------------ */
/* Parcours                                                            */
/* ------------------------------------------------------------------ */

/**
 * La **chaîne complète** qui relie deux nœuds, en largeur d'abord.
 *
 * Le parcours en largeur donne le chemin le plus court en nombre de sauts,
 * c'est-à-dire la parenté la plus directe : c'est ce qui fait qu'un cousin
 * s'éclaire par ses grands-parents communs et non par un détour de six unions.
 *
 * Le chemin rendu **contient les nœuds de famille** traversés, parce que c'est
 * eux qu'il faut éclairer pour que la chaîne se lise sans trou : sans le
 * losange, deux fiches voisines semblent reliées par rien.
 *
 * Rend `[]` quand les deux nœuds ne communiquent pas — ce qui arrive, l'arbre
 * n'étant pas connexe pour tout lecteur.
 */
export function chemin(index: IndexGraphe, de: string, vers: string): string[] {
  if (!index.parId.has(de) || !index.parId.has(vers)) return []
  if (de === vers) return [de]

  const precedent = new Map<string, string | null>([[de, null]])
  const file: string[] = [de]
  let tete = 0

  while (tete < file.length) {
    // On consomme par index plutôt que par `shift()` : à 754 nœuds, le décalage
    // du tableau à chaque visite coûte plus cher que le parcours lui-même.
    const courant = file[tete++]
    for (const voisin of index.adjacence.get(courant) ?? []) {
      if (precedent.has(voisin)) continue
      precedent.set(voisin, courant)
      if (voisin === vers) {
        const chaine: string[] = []
        let c: string | null = voisin
        while (c !== null) {
          chaine.push(c)
          c = precedent.get(c) ?? null
        }
        return chaine.reverse()
      }
      file.push(voisin)
    }
  }
  return []
}

/**
 * Les personnes à **deux sauts** : parents, conjoints, enfants et fratrie.
 *
 * C'est l'équivalent exact du `[...parents, ...enfants, ...conjoints]` du
 * viewer d'origine augmenté de sa `fratrie`, obtenu ici en traversant les
 * nœuds de famille au lieu de concaténer quatre listes que l'API n'envoie pas.
 */
export function voisinage(index: IndexGraphe, id: string): string[] {
  const vus = new Set<string>()
  for (const famille of index.adjacence.get(id) ?? []) {
    for (const personne of index.adjacence.get(famille) ?? []) {
      if (personne !== id) vus.add(personne)
    }
  }
  return [...vus]
}

export interface EnsembleEclaire {
  /** Tout ce qui reste peint : fiches, losanges. */
  garder: Set<string>
  /** La chaîne jusqu'à l'ego, dans l'ordre, nœuds de famille compris. */
  chaine: string[]
}

/**
 * Ce qu'une sélection éclaire : la personne, son entourage immédiat, et toute
 * la chaîne qui la relie à l'ego.
 *
 * Les familles dont **un seul** membre est retenu sont ajoutées à leur tour :
 * sans elles, une arête gardée s'arrêterait dans le vide.
 */
export function ensembleAEclairer(index: IndexGraphe, id: string): EnsembleEclaire {
  const chaine = chemin(index, id, index.ego)
  const garder = new Set<string>([id, ...voisinage(index, id), ...chaine])

  for (const [famille, membres] of index.membresParFamille) {
    if (membres.some((m) => garder.has(m))) garder.add(famille)
  }
  return { garder, chaine }
}

/* ------------------------------------------------------------------ */
/* Classes CSS                                                         */
/* ------------------------------------------------------------------ */

/** Teintes de fiche : `h` masculin, `f` féminin, `x` inconnu — les initiales de
 *  `arbre.html`, conservées parce que `canevas.css` les porte déjà. */
function classeDeSexe(sexe: NoeudPersonne['sex']): string {
  if (sexe === 'masculin') return 'h'
  if (sexe === 'feminin') return 'f'
  return 'x'
}

/**
 * Les classes d'une fiche.
 *
 * `prive` est allumée par **`vivant`**, jamais par `deceased` ni par l'absence
 * de dates : `vivant` est le résultat de la règle de confidentialité (seuil,
 * repli par génération, `force_privacy`), `deceased` n'est que le `DEAT` du
 * GEDCOM. Les confondre masquerait les mauvaises fiches dans les deux sens.
 */
export function classesDeFiche(
  noeud: NoeudPersonne,
  egoId: string,
  unions: number = 0,
): string[] {
  const classes = ['fiche', classeDeSexe(noeud.sex)]
  if (noeud.vivant) classes.push('prive')
  if (noeud.id === egoId) classes.push('ego')
  if (unions > 1) classes.push('remarie')
  if (noeud.enAttente === true) classes.push('attente')
  return classes
}

export function classesDeFamille(noeud: NoeudFamille): string[] {
  const classes = ['fam']
  if (noeud.divorced) classes.push('div')
  if (noeud.enAttente === true) classes.push('attente')
  return classes
}

/**
 * Les classes d'une arête.
 *
 * **Il n'y a pas de classe `long`, et il ne doit pas y en avoir.** Le viewer
 * d'origine en posait une dès que les extrémités s'écartaient de plus de
 * 700 unités, ce qui donnait un pointillé translucide. Le commanditaire l'a
 * fait retirer : « ça sert à rien si c'est pour dire c'est loin » (§ 7.3).
 * Cette fonction ne reçoit donc aucune position — elle n'aurait pas de quoi
 * calculer une distance même si on le lui demandait.
 *
 * `divorce` est **conservée** : ce n'est pas une distance, c'est une
 * information.
 */
export function classesDArete(arete: AreteGraphe): string[] {
  const classes = ['ar', arete.genre]
  if (arete.divorce === true) classes.push('divorce')
  if (arete.enAttente === true) classes.push('attente')
  return classes
}

/* ------------------------------------------------------------------ */
/* Filtres                                                             */
/* ------------------------------------------------------------------ */

export type IdFiltre = 'verifies' | 'non-verifies' | 'sans-source' | 'avec-image' | 'vivants'

export interface DefinitionFiltre {
  id: IdFiltre
  libelle: string
  /** Texte de survol — c'est lui qui dit ce que le filtre retient. */
  description: string
  /** Champs du nœud sans lesquels le prédicat serait faux, pas vide. */
  requiert: readonly (keyof QualiteDocumentaire)[]
  test: (noeud: NoeudPersonneEtendu) => boolean
}

/**
 * Les cinq filtres du viewer d'origine.
 *
 * « Vérifié » veut dire *appuyé sur un acte ou un registre* — `QUAY >= 2` — et
 * non « a une source » : la moitié de l'arbre a des sources qui sont des
 * recopies d'autres arbres, et les confondre rendrait le filtre inutile. C'est
 * la nuance qui a coûté le plus cher dans le travail généalogique d'origine.
 */
export const FILTRES: readonly DefinitionFiltre[] = [
  {
    id: 'verifies',
    libelle: 'Vérifiés',
    description: 'Au moins une information appuyée sur un acte ou un registre',
    requiert: ['bestQuay'],
    test: (n) => (n.bestQuay ?? -1) >= 2,
  },
  {
    id: 'non-verifies',
    libelle: 'Non vérifiés',
    description: 'Des sources, mais aucune qui soit un acte',
    requiert: ['bestQuay', 'citationCount'],
    test: (n) => (n.bestQuay ?? -1) < 2 && (n.citationCount ?? 0) > 0 && !n.vivant,
  },
  {
    id: 'sans-source',
    libelle: 'Sans source',
    description: 'Aucune citation de source',
    requiert: ['citationCount'],
    test: (n) => (n.citationCount ?? 0) === 0,
  },
  {
    id: 'avec-image',
    libelle: 'Avec image',
    description: 'Une image d’archive est jointe à la fiche',
    requiert: ['avecImage'],
    test: (n) => n.avecImage === true,
  },
  {
    id: 'vivants',
    libelle: 'Vivants',
    description: 'Fiches à diffusion restreinte',
    requiert: [],
    test: (n) => n.vivant,
  },
]

/**
 * Un filtre est utilisable si **au moins un** nœud porte les champs qu'il lit.
 *
 * On teste la présence de la **clé**, pas sa valeur : `citationCount: 0` est une
 * donnée, `citationCount` absent est un silence. Les confondre ferait passer
 * « aucune fiche ne correspond » pour « la donnée n'est pas arrivée ».
 */
export function filtreDisponible(
  filtre: DefinitionFiltre,
  personnes: readonly NoeudPersonneEtendu[],
): boolean {
  if (filtre.requiert.length === 0) return true
  return filtre.requiert.every((champ) => personnes.some((n) => champ in n))
}

export function filtresDisponibles(
  personnes: readonly NoeudPersonneEtendu[],
): Set<IdFiltre> {
  const disponibles = new Set<IdFiltre>()
  for (const filtre of FILTRES) {
    if (filtreDisponible(filtre, personnes)) disponibles.add(filtre.id)
  }
  return disponibles
}

/** Les identifiants de nœud retenus par un filtre. */
export function noeudsFiltres(
  personnes: readonly NoeudPersonneEtendu[],
  id: IdFiltre,
): string[] {
  const filtre = FILTRES.find((f) => f.id === id)
  if (filtre === undefined) return []
  return personnes.filter((n) => filtre.test(n)).map((n) => n.id)
}

/**
 * La ligne de dates à peindre sous le nom, ou `null` s'il n'y en a pas.
 *
 * **Ce n'est pas la `ligneDates` d'une fiche.** Le contrat sépare trois formes
 * pour la même personne (§ 14.4) : la prose — « entre 1901 et 1909 » — vit dans
 * `texte`, au fond d'un bloc date ; la forme compacte — « 1901-1909 – 1987 » —
 * s'appelle `ligneDates` sur une fiche, une recherche, une barre de
 * chronologie ; et l'étiquette mesurée vit **sur le nœud du graphe, et nulle
 * part ailleurs**. C'est celle-ci, et c'est la seule à rendre la constante
 * « vivant·e » pour une personne vivante.
 *
 * Ce court-circuit est la raison d'être de la clé séparée : la largeur est
 * calculée à l'étage partagé du cache de placement, **sans filtre de
 * confidentialité**. Si l'étiquette portait les vraies dates d'une personne
 * vivante, la largeur de sa fiche en encoderait la longueur pour **tous** les
 * lecteurs, cercle ou pas.
 *
 * Absente — pas vide — sur un nœud restreint. On ne dessine alors aucune ligne
 * et **on ne la reconstitue jamais** : la boîte reste dimensionnée par le
 * moteur, et la reconstituer ferait diverger le texte de sa boîte.
 */
export function datesDeFiche(noeud: NoeudPersonneEtendu): string | null {
  // `etiquetteDates` d'abord : c'est la clé du contrat **et** celle que pose
  // `NormaliseurDeGraphe.php:306`. `ligneDates` reste en repli défensif — voir
  // sa déclaration — mais elle n'est plus attendue sur un nœud.
  const texte = noeud.etiquetteDates ?? noeud.ligneDates
  return texte === undefined || texte === '' ? null : texte
}
