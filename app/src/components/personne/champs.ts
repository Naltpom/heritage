/**
 * Ce que la fiche affiche, champ par champ — et **pourquoi c'est un module pur**.
 *
 * Trois règles du contrat décident de tout l'écran, et elles se décident toutes
 * les trois sans DOM : *le champ est-il là ?*, *vaut-il `null` ?*, *le calque
 * porte-t-il une valeur proposée ?* Les sortir du gabarit, c'est pouvoir les
 * éprouver sur des charges utiles réelles plutôt que sur un rendu — c'est ce que
 * font déjà `geometrie.ts` et `chemins.ts` du canevas.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## 1. « Absent » et « `null` » ne veulent pas dire la même chose (§ 1.3)
 *
 * > `'birth' in fiche === false` veut dire **interdit** : il n'y a **pas de
 * > ligne du tout**, jamais un « — ». `fiche.birth === null` veut dire
 * > **inconnu** : la ligne existe, la valeur est vide.
 *
 * Le premier protège une personne vivante hors du cercle du lecteur ; le second
 * décrit une lacune de la donnée généalogique. Un `??` mal placé les rend
 * identiques à l'écran, et la règle de confidentialité disparaît sans qu'aucun
 * test ne s'en aperçoive — l'écran affiche un tiret, ce qui *ressemble* à une
 * information manquante.
 *
 * D'où la forme de `construireLignes()` : elle **itère sur les descripteurs et
 * interroge la charge utile avec `in`**. Elle ne construit jamais la liste
 * complète pour en retirer ensuite ce qui manque — c'est le pendant front du
 * « on ne construit pas la charge utile complète pour en retirer les clés
 * interdites » du § 1.3, et il tombe sur le même défaut : la première ligne
 * ajoutée après coup passe au travers.
 *
 * Conséquence directe et vérifiable : donnée la **fiche réduite** à sept clés
 * du § 11.1, cette fonction rend **trois** lignes — sexe, branche, génération —
 * et pas une quatrième à vide.
 *
 * ## 2. Le calque « en attente » est **à côté** de la valeur validée (§ 13.2)
 *
 * > Si `enAttente[champ]` existe : afficher `propose` avec la pastille « en
 * > attente de validation », **et** rendre `actuel` accessible. Sinon, afficher
 * > `fiche[champ]`.
 *
 * La valeur validée reste dans le corps principal — le calque s'ajoute, il ne
 * se substitue pas. Et le piège, celui que le contrat prend la peine d'écrire
 * en toutes lettres : **`actuel` peut différer de `fiche[champ]`.** `actuel`
 * vaut l'`old_value` de l'op, c'est-à-dire la valeur *au moment de la
 * soumission*. Si la fiche a bougé depuis, l'écart est précisément ce qui
 * annonce une **caducité à venir** (§ 6.8). On ne les fusionne pas, on ne
 * suppose pas qu'ils sont égaux : on les compare, et on le dit.
 *
 * ## 3. Une fourchette de dates ne s'aplatit pas (§ 14)
 *
 * « entre 1901 et 1909 » s'affiche tel quel, jamais « 1901 ». Cas de contrôle
 * du projet : Marne Dolomite Sureau, `BET 1901 AND 1909`. Sa fourchette a
 * survécu au GEDCOM, à l'import, à PostgreSQL et au moteur de placement ; elle
 * ne se perd pas au dernier mètre.
 *
 * C'est pourquoi `rendreDate()` **ne lit ni `min`, ni `max`, ni `qualifier`**.
 * Elle lit `texte` — le rendu de `UncertainDate::format()`, que le serveur écrit
 * pour ça — puis `raw`, la chaîne GEDCOM verbatim. Deux chaînes écrites par le
 * serveur, aucune recomposition. Fabriquer « entre 1901 et 1909 » à partir de
 * deux bornes, ce serait réimplémenter le formateur du serveur, et en diverger
 * au premier cas limite : `1930` donne déjà `min = 1930-01-01` et
 * `max = 1930-12-31` sans être une fourchette pour autant — « le test *est-ce
 * une fourchette* est `qualifier === 'entre'`, rien d'autre » (§ 14.3).
 * ────────────────────────────────────────────────────────────────────
 */

import type { BlocDate, BlocDateOp, EntreeCalque, FichePersonne } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* Ce qu'une valeur devient à l'écran                                  */
/* ------------------------------------------------------------------ */

/**
 * Une valeur prête à afficher.
 *
 * `vide` distingue « la ligne existe, la valeur n'est pas renseignée » de « la
 * ligne porte quelque chose ». Il n'est **jamais** vrai pour un champ absent :
 * un champ absent n'a pas de ligne, donc pas de valeur à rendre.
 */
export interface ValeurAffichee {
  /** Le texte principal. Pour une date, celui que le serveur a écrit. */
  principal: string
  /** Complément de second plan : le lieu porté par un bloc date. */
  secondaire: string | null
  /** La charge utile portait `null` : *inconnu*, et non *interdit*. */
  vide: boolean
}

/** Une ligne de la fiche, dans l'un de ses **deux** états d'affichage. */
export type LigneDeFiche =
  | {
      cle: string
      etiquette: string
      /** Aucune demande en cours sur ce champ : la valeur validée, point. */
      etat: 'validee'
      valeur: ValeurAffichee
      chasseFixe: boolean
    }
  | {
      cle: string
      etiquette: string
      /** Le calque porte une valeur proposée pour ce champ (§ 13.2). */
      etat: 'en_attente'
      /** Ce qu'on affiche en premier, avec la pastille. */
      propose: ValeurAffichee
      /** La valeur **validée aujourd'hui**, celle du corps principal. Elle
       *  reste à l'écran : le calque est à côté, jamais à la place. */
      validee: ValeurAffichee
      /** `old_value` de l'op — la valeur **au moment de la soumission**. */
      actuel: ValeurAffichee
      /**
       * `actuel` diffère de la valeur validée : la fiche a bougé depuis le
       * dépôt, et cette demande deviendra probablement **caduque** (§ 6.8).
       * On le signale ; on ne décide de rien à la place de l'administrateur.
       */
      caduciteAnnoncee: boolean
      entree: EntreeCalque
      chasseFixe: boolean
    }

/* ------------------------------------------------------------------ */
/* Rendu des valeurs — aucune date n'est recomposée                    */
/* ------------------------------------------------------------------ */

const SEXES: Record<string, string> = {
  masculin: 'Homme',
  feminin: 'Femme',
  inconnu: 'Inconnu',
}

/** Les quatre quarts d'ascendance et le tronc, avec le patronyme qui les nomme
 *  dans cet arbre — ce sont les libellés de `arbre.html`, pas des inventions. */
const BRANCHES: Record<string, string> = {
  pp: 'Romarin — paternelle du père',
  pm: 'Bourrache — maternelle du père',
  mp: 'Chardon — paternelle de la mère',
  mm: 'Tilleul — maternelle de la mère',
  tr: 'Tronc',
}

function vide(texte: string): ValeurAffichee {
  return { principal: texte, secondaire: null, vide: true }
}

function plein(principal: string, secondaire: string | null = null): ValeurAffichee {
  return { principal, secondaire, vide: false }
}

/**
 * Rend un bloc date **sans jamais le recomposer**.
 *
 * `texte` d'abord (§ 14.1 : la sixième clé, qui n'existe que dans une fiche),
 * `raw` ensuite — la chaîne GEDCOM verbatim. Les deux sont écrites par le
 * serveur. À défaut des deux, on le dit franchement plutôt que d'assembler
 * quelque chose à partir de `min` et `max` : il n'existe pas de `getYear()` sur
 * `UncertainDate`, et « un appelant qui veut un point pour dessiner l'écrit
 * lui-même, à partir de `min` et `max` » — ce n'est pas le cas d'un libellé.
 *
 * Le `place` part en second plan : c'est un complément de la date, pas une
 * ligne à part. Une date absente avec un lieu connu reste affichable.
 */
export function rendreDate(bloc: BlocDate | BlocDateOp | null, motSiVide: string): ValeurAffichee {
  if (bloc === null) return vide(motSiVide)

  // `texte` est la **sixième** clé, et elle n'existe que dans une fiche : une
  // valeur d'op en compte cinq, toujours les cinq (§ 14.1). Le calque porte bien
  // le bloc entier, `texte` compris (§ 13.2) — mais cette fonction reçoit aussi
  // des valeurs passées en `unknown`, et supposer la sixième clé la ferait tomber
  // sur la forme courte. On teste donc son existence plutôt que de l'espérer.
  const texte = 'texte' in bloc && bloc.texte.length > 0 ? bloc.texte : null
  const brut = bloc.raw !== null && bloc.raw.length > 0 ? bloc.raw : null
  // `qualifier: 'inconnue'` rend `texte: ""` (§ 14.3) : la date est inconnue,
  // le lieu peut ne pas l'être.
  const principal = texte ?? brut

  if (principal === null) return { principal: motSiVide, secondaire: bloc.place, vide: true }
  return plein(principal, bloc.place)
}

/**
 * Rend une valeur quelconque de la charge utile ou du calque.
 *
 * Volontairement tolérante : le calque porte les mêmes valeurs que le corps
 * principal, mais son type est `unknown` — la clé du calque *est* la clé du
 * corps (§ 13.2), et le typeur ne peut pas relier l'une à l'autre. Une valeur
 * inattendue doit s'afficher lisiblement, pas faire tomber l'écran.
 */
export function rendreValeur(valeur: unknown, motSiVide: string, cle?: string): ValeurAffichee {
  if (valeur === null || valeur === undefined) return vide(motSiVide)

  if (typeof valeur === 'string') {
    if (valeur.length === 0) return vide(motSiVide)
    if (cle === 'sex') return plein(SEXES[valeur] ?? valeur)
    if (cle === 'branch') return plein(BRANCHES[valeur] ?? valeur)
    return plein(valeur)
  }

  if (typeof valeur === 'number') return plein(String(valeur))
  if (typeof valeur === 'boolean') return plein(valeur ? 'oui' : 'non')

  if (estBlocDate(valeur)) return rendreDate(valeur, motSiVide)

  // Rien de connu : on ne fabrique pas de libellé, on rend la forme brute.
  return plein(JSON.stringify(valeur))
}

/**
 * Un bloc date se reconnaît à `qualifier` — la clé qui **porte** l'incertitude,
 * et la seule qui dise si c'est une fourchette (§ 14.3).
 *
 * La garde rend `BlocDateOp`, la forme à **cinq** clés, et non `BlocDate` : on
 * ne peut affirmer que `texte` est là qu'en venant d'une fiche, et cette
 * fonction reçoit des valeurs de provenance inconnue.
 */
export function estBlocDate(valeur: unknown): valeur is BlocDateOp {
  return (
    typeof valeur === 'object' &&
    valeur !== null &&
    !Array.isArray(valeur) &&
    'qualifier' in valeur &&
    typeof (valeur as { qualifier: unknown }).qualifier === 'string'
  )
}

/**
 * Deux valeurs de champ sont-elles la même ?
 *
 * Sert à une seule chose : décider si `actuel` (la valeur au moment du dépôt)
 * s'est écartée de la valeur validée d'aujourd'hui, ce qui annonce une caducité.
 *
 * Un bloc date se compare sur ses **cinq clés d'op** — `raw`, `qualifier`,
 * `min`, `max`, `place` — et jamais sur `texte`, qui en est dérivé : deux blocs
 * peuvent rendre le même libellé et porter des lieux différents. C'est la même
 * frontière que le § 14.1 trace pour l'entrée : cinq clés, toujours les cinq, et
 * `texte` n'en fait pas partie.
 */
export function memeValeur(a: unknown, b: unknown): boolean {
  if (a === b) return true
  if (a === null || b === null || a === undefined || b === undefined) return false

  if (estBlocDate(a) && estBlocDate(b)) {
    return (
      a.raw === b.raw &&
      a.qualifier === b.qualifier &&
      a.min === b.min &&
      a.max === b.max &&
      a.place === b.place
    )
  }

  if (typeof a === 'object' && typeof b === 'object') {
    // Repli, pour une forme que le contrat ajouterait sans qu'on le sache.
    // Il vaut ce que vaut l'ordre des clés du sérialiseur — d'où la comparaison
    // nommée ci-dessus pour le seul cas qui se présente réellement.
    return JSON.stringify(a) === JSON.stringify(b)
  }

  return false
}

/* ------------------------------------------------------------------ */
/* Les champs de la fiche                                              */
/* ------------------------------------------------------------------ */

interface Descripteur {
  /** **La clé de la charge utile**, telle quelle. C'est aussi la clé du calque :
   *  aucune table de correspondance, aucune traduction (§ 1.2, § 13.2). */
  cle: string
  etiquette: string
  /** Ce qu'on écrit quand la valeur est présente mais `null` — *inconnu*, avec
   *  l'accord français qui convient à l'étiquette. Jamais « — ». */
  motSiVide: string
  /** Références, années, fourchettes : chasse fixe, chiffres alignés. */
  chasseFixe?: true
}

/**
 * L'ordre des lignes de la fiche.
 *
 * `displayName` et `ligneDates` n'y sont pas : ils composent l'**en-tête**, pas
 * le tableau. `deceased`, `vivant`, `forcePrivacy` et `privacyNote` non plus —
 * ce ne sont pas des traits d'identité mais le **régime** de la fiche, et ils
 * s'affichent en bandeau. `version` non plus : c'est le `baseVersion` du verrou
 * optimiste (§ 6.1), une donnée de formulaire et non de lecture.
 *
 * Tout le reste de ce que le § 5.3 met sur une fiche est ici, et **c'est la
 * charge utile qui décide** de ce qui se dessine : une clé absente saute la
 * ligne, une clé à `null` la rend vide.
 */
const DESCRIPTEURS: Descripteur[] = [
  { cle: 'givenNames', etiquette: 'Prénoms', motSiVide: 'non renseignés' },
  { cle: 'surname', etiquette: 'Nom', motSiVide: 'non renseigné' },
  { cle: 'marriedName', etiquette: 'Nom d’usage', motSiVide: 'aucun' },
  { cle: 'nickname', etiquette: 'Surnom', motSiVide: 'aucun' },
  { cle: 'sex', etiquette: 'Sexe', motSiVide: 'inconnu' },
  { cle: 'birth', etiquette: 'Naissance', motSiVide: 'inconnue' },
  { cle: 'death', etiquette: 'Décès', motSiVide: 'inconnu' },
  { cle: 'parente', etiquette: 'Parenté avec vous', motSiVide: 'non calculée' },
  { cle: 'branch', etiquette: 'Branche', motSiVide: 'non classée' },
  { cle: 'generation', etiquette: 'Génération', motSiVide: 'inconnue', chasseFixe: true },
  { cle: 'gedcomXref', etiquette: 'Référence GEDCOM', motSiVide: 'aucune', chasseFixe: true },
]

/** Lit le calque d'un champ. Une fiche réduite n'a **pas de clé `enAttente` du
 *  tout** — elle fait partie de ce qui n'est pas envoyé (§ 11.1, § 13.3) —,
 *  d'où le `in` plutôt qu'un accès direct. */
export function calqueDuChamp(fiche: FichePersonne, cle: string): EntreeCalque | undefined {
  if (!('enAttente' in fiche)) return undefined
  return fiche.enAttente[cle]
}

/**
 * Les lignes à dessiner, dans l'ordre, pour **cette** charge utile.
 *
 * On lit la fiche comme un enregistrement indexé et non par ses propriétés
 * typées : le typeur décrit le contrat, la charge utile décrit ce qui est
 * *arrivé*. Les deux coïncident presque toujours — et c'est exactement pourquoi
 * il faut interroger la seconde : le jour où elles divergent, c'est une règle de
 * confidentialité qui est en jeu, pas un détail d'affichage.
 */
export function construireLignes(fiche: FichePersonne): LigneDeFiche[] {
  const charge = fiche as unknown as Record<string, unknown>
  const lignes: LigneDeFiche[] = []

  for (const descripteur of DESCRIPTEURS) {
    // § 1.3 — la clé n'est pas là : le lecteur n'a pas le droit de la voir.
    // Pas de ligne. Pas de tiret. Rien.
    if (!(descripteur.cle in charge)) continue

    const brute = charge[descripteur.cle]
    const chasseFixe = descripteur.chasseFixe === true
    const calque = calqueDuChamp(fiche, descripteur.cle)
    const validee = rendreValeur(brute, descripteur.motSiVide, descripteur.cle)

    if (calque === undefined) {
      lignes.push({
        cle: descripteur.cle,
        etiquette: descripteur.etiquette,
        etat: 'validee',
        valeur: validee,
        chasseFixe,
      })
      continue
    }

    lignes.push({
      cle: descripteur.cle,
      etiquette: descripteur.etiquette,
      etat: 'en_attente',
      propose: rendreValeur(calque.propose, descripteur.motSiVide, descripteur.cle),
      validee,
      actuel: rendreValeur(calque.actuel, descripteur.motSiVide, descripteur.cle),
      caduciteAnnoncee: !memeValeur(calque.actuel, brute),
      entree: calque,
      chasseFixe,
    })
  }

  return lignes
}

/**
 * Un horodatage d'API (`TIMESTAMPTZ`, § 1.4), rendu en français.
 *
 * **Ce n'est pas une date généalogique**, et la distinction vaut d'être écrite :
 * la règle « ne jamais aplatir une fourchette » ne s'applique pas ici. Un
 * `submittedAt` est un **instant exact**, connu à la seconde, produit par une
 * horloge et non par une source d'archive. Il n'a ni qualificatif, ni bornes, ni
 * incertitude — rien à aplatir. Les dates d'arbre, elles, passent toujours par
 * `rendreDate()`, qui ne les compose jamais.
 */
export function horodatageLisible(valeur: string | null | undefined): string {
  if (typeof valeur !== 'string' || valeur.length === 0) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

/* ------------------------------------------------------------------ */
/* Le régime de la fiche — ce que le bandeau doit dire                 */
/* ------------------------------------------------------------------ */

export interface RegimeDeFiche {
  ton: 'neutre' | 'mauvais' | 'bon'
  texte: string
  /** La forme courte, pour la pastille du bandeau mobile : « Vérifié » n'y
   *  s'écrit que sur un acte, jamais sur « a une source ». */
  court: string
}

/**
 * Le bandeau de confiance documentaire, repris du viewer d'origine.
 *
 * « Vérifié » veut dire **appuyé sur un acte ou un registre** (`bestQuay >= 2`),
 * et non « a une source » : sur 274 personnes, 177 citations sont à `QUAY 2`
 * mais 242 restent à `0` — des recopies d'arbres. Confondre les deux viderait
 * l'indication de son sens, et c'est le genre de nuance que le GEDCOM porte
 * précisément pour qu'on ne la perde pas.
 *
 * Il rend `null` quand la charge utile ne porte pas de quoi le calculer — une
 * fiche réduite, par exemple, où `bestQuay` et `citationCount` sont **absents**.
 * Là encore : rien à afficher plutôt qu'un jugement fabriqué.
 */
export function regimeDocumentaire(fiche: FichePersonne): RegimeDeFiche | null {
  const charge = fiche as unknown as Record<string, unknown>
  if (!('citationCount' in charge) || !('bestQuay' in charge)) return null

  const citations = typeof charge['citationCount'] === 'number' ? charge['citationCount'] : 0
  const meilleur = typeof charge['bestQuay'] === 'number' ? charge['bestQuay'] : -1

  if (citations === 0) {
    return { ton: 'mauvais', texte: 'Aucune source. Rien n’appuie ces informations.', court: 'Sans source' }
  }
  if (meilleur < 2) {
    return { ton: 'mauvais', texte: 'Non vérifié : recopies d’arbres, aucun acte.', court: 'Non vérifié' }
  }
  return { ton: 'bon', texte: 'Appuyé sur un acte ou un registre.', court: 'Vérifié' }
}

/* ------------------------------------------------------------------ */
/* La place dans l'arbre — la colonne « Dans l'arbre » de la fiche     */
/* ------------------------------------------------------------------ */

/** Le nom court de chaque branche — ceux de la légende de l'arbre. Le libellé
 *  long (« Romarin — paternelle du père ») reste celui de la ligne Branche. */
const NOMS_BRANCHE: Record<string, string> = {
  pp: 'Romarin',
  pm: 'Bourrache',
  mp: 'Chardon',
  mm: 'Tilleul',
  tr: 'Tronc',
  cpp: 'Romarin du conjoint',
  cpm: 'Bourrache du conjoint',
  cmp: 'Chardon du conjoint',
  cmm: 'Tilleul du conjoint',
}

/** Le nom court d'une branche ; une valeur inconnue s'affiche telle quelle. */
export function nomBranche(branche: string): string {
  return NOMS_BRANCHE[branche] ?? branche
}

/** La couleur de la pastille de branche : un jeton `--h-branche-*` existant,
 *  jamais une couleur nouvelle. Une branche inconnue prend celle du hors-arbre. */
export function couleurBranche(branche: string): string {
  return branche in NOMS_BRANCHE ? `var(--h-branche-${branche})` : 'var(--h-branche-hors)'
}

/** Une génération, avec un vrai signe moins (« −1 ») : c'est un nombre relatif
 *  à l'ego, et le tiret court se lirait comme une ponctuation. */
export function generationLisible(generation: number): string {
  return generation < 0 ? `−${Math.abs(generation)}` : String(generation)
}

/**
 * Pourquoi cette personne est traitée comme vivante — la note du bandeau.
 *
 * C'est `vivant` qui commande, **jamais `deceased`** ni l'absence de dates :
 * `deceased` est le `DEAT` du GEDCOM, `vivant` le résultat de la règle de
 * confidentialité (seuil, repli par génération, `forcePrivacy`, § 5.3).
 *
 * `forcePrivacy` est une décision d'administration : elle l'emporte sur les
 * dates. Le dire évite la question « pourquoi cette fiche de 1901 est-elle
 * protégée ? », dont la réponse est dans `privacyNote` quand elle existe.
 *
 * `null` sur une fiche réduite : la carte réduite dit déjà tout, autrement.
 */
export function noteDeConfidentialite(fiche: FichePersonne): string | null {
  if (fiche.visibilite !== 'complete' || !fiche.vivant) return null
  if (fiche.forcePrivacy) {
    return fiche.privacyNote !== null && fiche.privacyNote.length > 0
      ? `Fiche protégée par décision d’administration : ${fiche.privacyNote}`
      : 'Fiche protégée par décision d’administration.'
  }
  // Depuis le 2026-10-01, tout membre validé voit les vivants (ADR 0003) : seule
  // une fiche à confidentialité forcée reste réservée à son cercle.
  return 'Personne vivante : sa fiche n’est communiquée qu’aux membres de la famille.'
}
