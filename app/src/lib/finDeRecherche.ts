/**
 * La **fin de recherche** (ADR 0023) — le contrat partagé des trois écrans qui la
 * montrent : l'encart de la fiche, le sommet d'une branche dans `/arbre`, le mur et sa
 * ligne de légende sur l'affiche.
 *
 * Pur : ni DOM, ni appel d'API, ni Vue. Tout ce que deux écrans doivent dire de la même
 * façon est ici, une fois — les motifs et leurs libellés, l'état d'un côté au sommet
 * d'une branche, la place d'un parent (père ou mère), les ops d'une demande, le tracé
 * du mur.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce qu'il faut savoir avant de s'en servir
 *
 * - **Une fin de recherche est par côté.** `pere` et `mere` sont deux champs de la
 *   fiche (`finDeRecherchePere`, `finDeRechercheMere`) ; « les deux » est un raccourci
 *   de saisie qui dépose **deux ops dans une demande** (`cotesDuChoix('les-deux')`).
 * - **Un parent connu l'emporte.** Une fin de recherche reste enregistrée quand un
 *   parent est lié ensuite du même côté ; l'arbre et l'affiche montrent alors le parent,
 *   pas le mur (`etatDuSommet()`). C'est la levée — une demande — qui l'efface.
 * - **Elle passe par le circuit des demandes**, comme un nom ou une date : une
 *   `modification_personne` ordinaire (`corpsDeFinDeRecherche()`), avec ses trois clés
 *   `{reason, beforeYear, body}` et jamais `authorLabel` ni `closedAt`, qui sont en
 *   lecture seule (`valeurDeFinDeRecherche()`).
 * - **Les libellés sont ceux du serveur** (`Core\Enum\MotifDeFinDeRecherche::label()`),
 *   recopiés : le diff d'administration (`libelleDiff`) et l'écran disent la même
 *   phrase. Un motif ajouté au serveur s'ajoute ici, et le test le rappelle.
 * ────────────────────────────────────────────────────────────────────
 */
import type {
  AreteGraphe,
  ChampDeFinDeRecherche,
  CorpsFinDeRecherche,
  CoteDeRecherche,
  FinDeRecherche,
  IdentifiantNoeud,
  MotifDeFinDeRecherche,
  NoeudGraphe,
  OpFinDeRecherche,
  ResumeFinDeRecherche,
  Sexe,
  ValeurFinDeRecherche,
} from '@/lib/api'

/* ------------------------------------------------------------------ */
/* Les côtés                                                           */
/* ------------------------------------------------------------------ */

/** Les deux côtés, dans l'ordre où on les dit et où partent les ops : le père, puis la mère. */
export const COTES_DE_RECHERCHE: readonly CoteDeRecherche[] = ['pere', 'mere']

/** Le champ de la fiche — et la clé du calque `enAttente` — qui porte chaque côté. */
export const CHAMP_DU_COTE: Readonly<Record<CoteDeRecherche, ChampDeFinDeRecherche>> = {
  pere: 'finDeRecherchePere',
  mere: 'finDeRechercheMere',
}

/** La nature de valeur d'un op de fin de recherche (`ValueKind::FinDeRecherche`). */
export const NATURE_DE_VALEUR = 'fin_de_recherche' as const

/** « côté père », « côté mère ». */
export function libelleDuCote(cote: CoteDeRecherche): string {
  return cote === 'pere' ? 'côté père' : 'côté mère'
}

/** Ce que l'écran de saisie propose : un côté, ou les deux d'un geste. */
export type ChoixDeCote = CoteDeRecherche | 'les-deux'

/** Les côtés qu'un choix de saisie vise — « les deux » en dépose deux ops. */
export function cotesDuChoix(choix: ChoixDeCote): CoteDeRecherche[] {
  return choix === 'les-deux' ? [...COTES_DE_RECHERCHE] : [choix]
}

/* ------------------------------------------------------------------ */
/* Les motifs                                                          */
/* ------------------------------------------------------------------ */

/** Un motif, tel que le formulaire le propose et que l'écran le dit. */
export interface DescriptionDeMotif {
  code: MotifDeFinDeRecherche
  /** Le libellé du choix, sans paramètre : « Registres inexistants avant une année ». */
  libelle: string
  /** Le motif porte une année (`beforeYear`) — `registres_inexistants`, et lui seul. */
  avecAnnee: boolean
  /** Le texte libre est exigé — `autre` : « autre » sans un mot ne dit rien. */
  texteObligatoire: boolean
  /** Les côtés où le motif a un sens : « père inconnu » ne se dit que du père. */
  cotes: readonly CoteDeRecherche[]
}

const LES_DEUX: readonly CoteDeRecherche[] = COTES_DE_RECHERCHE

/**
 * Les huit motifs, dans l'ordre du formulaire — celui où le commanditaire les a donnés.
 * Les codes sont ceux de `Core\Enum\MotifDeFinDeRecherche` ; les libellés, ceux de son
 * `label()`, au caractère près.
 */
export const MOTIFS_DE_FIN_DE_RECHERCHE: readonly DescriptionDeMotif[] = [
  { code: 'registres_inexistants', libelle: 'Registres inexistants avant une année', avecAnnee: true, texteObligatoire: false, cotes: LES_DEUX },
  { code: 'registres_detruits', libelle: 'Registres détruits', avecAnnee: false, texteObligatoire: false, cotes: LES_DEUX },
  { code: 'lacune', libelle: 'Lacune dans les registres', avecAnnee: false, texteObligatoire: false, cotes: LES_DEUX },
  { code: 'pere_inconnu', libelle: 'Père inconnu', avecAnnee: false, texteObligatoire: false, cotes: ['pere'] },
  { code: 'enfant_trouve', libelle: 'Enfant trouvé ou abandonné', avecAnnee: false, texteObligatoire: false, cotes: LES_DEUX },
  { code: 'origine_inconnue', libelle: 'Lieu d’origine inconnu', avecAnnee: false, texteObligatoire: false, cotes: LES_DEUX },
  { code: 'homonymie', libelle: 'Homonymie impossible à trancher', avecAnnee: false, texteObligatoire: false, cotes: LES_DEUX },
  { code: 'autre', libelle: 'Autre motif', avecAnnee: false, texteObligatoire: true, cotes: LES_DEUX },
]

const PAR_CODE: ReadonlyMap<string, DescriptionDeMotif> = new Map(MOTIFS_DE_FIN_DE_RECHERCHE.map((m) => [m.code, m]))

/** Le motif d'un code, ou `undefined` pour un code que cet écran ne connaît pas. */
export function descriptionDuMotif(code: string): DescriptionDeMotif | undefined {
  return PAR_CODE.get(code)
}

/** Le code est-il un motif connu ? */
export function estMotif(code: unknown): code is MotifDeFinDeRecherche {
  return typeof code === 'string' && PAR_CODE.has(code)
}

/** Les motifs qu'on peut choisir pour ce côté — ou pour les deux à la fois. */
export function motifsDuChoix(choix: ChoixDeCote): DescriptionDeMotif[] {
  const cotes = cotesDuChoix(choix)
  return MOTIFS_DE_FIN_DE_RECHERCHE.filter((m) => cotes.every((c) => m.cotes.includes(c)))
}

/**
 * Le motif en clair, **avec son année** : « Registres inexistants avant 1668 ». Sans
 * année, « Registres inexistants ». Un code inconnu est rendu tel quel — mieux vaut un
 * nom technique qu'une ligne vide.
 */
export function libelleDuMotif(code: string, beforeYear: number | null = null): string {
  if (code === 'registres_inexistants') {
    return beforeYear === null ? 'Registres inexistants' : `Registres inexistants avant ${beforeYear}`
  }
  return PAR_CODE.get(code)?.libelle ?? code
}

/**
 * La phrase d'une fin de recherche, pour un diff ou une infobulle :
 * « Registres inexistants avant 1668 — les registres de Saint-Martin commencent en 1668 ».
 * La même que `libelleDiff` côté serveur (`NormaliseurDeValeur::enUneLigne()`).
 */
export function phraseDeFinDeRecherche(valeur: ResumeFinDeRecherche & { body?: string | null }): string {
  const motif = libelleDuMotif(valeur.reason, valeur.beforeYear)
  const texte = typeof valeur.body === 'string' ? valeur.body.trim() : ''
  return texte === '' ? motif : `${motif} — ${texte}`
}

/** La ligne de la légende d'une affiche, à côté du mur. */
export const LEGENDE_DU_MUR = 'recherche close faute d’archives'

/* ------------------------------------------------------------------ */
/* La valeur d'un op                                                   */
/* ------------------------------------------------------------------ */

/** La borne basse de `beforeYear` — la même qu'au serveur. La haute est l'année en cours. */
export const PREMIERE_ANNEE = 1000

/** La longueur maximale du texte libre, en caractères — la même qu'au serveur. */
export const LONGUEUR_MAX_DU_TEXTE = 4000

/**
 * La valeur d'op d'une fin de recherche lue sur une fiche : ses **trois** clés, sans
 * `authorLabel` ni `closedAt` (une clé en plus rend `422`, comme le `texte` d'un bloc
 * date). `null` quand il n'y en a pas — ou quand la charge ne porte pas la clé.
 */
export function valeurDeFinDeRecherche(fin: FinDeRecherche | ValeurFinDeRecherche | null | undefined): ValeurFinDeRecherche | null {
  if (fin === null || fin === undefined) return null
  return { reason: fin.reason, beforeYear: fin.beforeYear ?? null, body: fin.body ?? null }
}

/**
 * La forme canonique d'une saisie, celle que le serveur stockerait : le texte rogné, vide
 * ramené à `null` ; l'année gardée pour `registres_inexistants` seulement — un motif
 * changé dans le formulaire ne traîne pas l'année d'avant.
 */
export function normaliserValeur(valeur: ValeurFinDeRecherche): ValeurFinDeRecherche {
  const texte = typeof valeur.body === 'string' ? valeur.body.trim() : ''
  const avecAnnee = descriptionDuMotif(valeur.reason)?.avecAnnee ?? false
  return {
    reason: valeur.reason,
    beforeYear: avecAnnee ? valeur.beforeYear : null,
    body: texte === '' ? null : texte,
  }
}

/** Deux valeurs (ou deux absences) disent-elles la même chose ? */
export function memesValeurs(a: ValeurFinDeRecherche | null, b: ValeurFinDeRecherche | null): boolean {
  if (a === null || b === null) return a === b
  return a.reason === b.reason && a.beforeYear === b.beforeYear && a.body === b.body
}

/**
 * Reconnaît une valeur de fin de recherche dans un `oldValue` / `newValue` qu'on ne sait
 * pas typer — le diff d'administration, qui reçoit toutes les natures.
 */
export function estValeurDeFinDeRecherche(valeur: unknown): valeur is ValeurFinDeRecherche {
  if (typeof valeur !== 'object' || valeur === null || Array.isArray(valeur)) return false
  const v = valeur as Record<string, unknown>
  return estMotif(v['reason']) && 'beforeYear' in v && 'body' in v
}

/** Les erreurs d'une saisie, par clé de la valeur. Vide : la saisie partirait. */
export type ErreursDeFinDeRecherche = Partial<Record<keyof ValeurFinDeRecherche, string>>

/**
 * Les contrôles du dépôt, rejoués devant celui qui saisit — le serveur reste juge
 * (`422` sur `ops[n].newValue.<clé>`), et ces messages ne font qu'éviter l'aller-retour.
 *
 * `anneeCourante` est un paramètre pour que les tests ne dépendent pas de la date.
 */
export function erreursDeFinDeRecherche(
  saisie: ValeurFinDeRecherche,
  cote: CoteDeRecherche,
  anneeCourante: number = new Date().getFullYear(),
): ErreursDeFinDeRecherche {
  const erreurs: ErreursDeFinDeRecherche = {}
  const motif = descriptionDuMotif(saisie.reason)
  if (motif === undefined) {
    erreurs.reason = 'Choisissez un motif.'
    return erreurs
  }
  if (!motif.cotes.includes(cote)) {
    const seul = motif.cotes.length === 1 ? motif.cotes[0] : undefined
    erreurs.reason =
      seul === undefined
        ? `« ${motif.libelle} » ne vaut pas ${libelleDuCote(cote)}.`
        : `« ${motif.libelle} » ne vaut que ${seul === 'pere' ? 'du côté du père' : 'du côté de la mère'}.`
  }
  const valeur = normaliserValeur(saisie)
  if (motif.avecAnnee) {
    const annee = valeur.beforeYear
    if (annee === null) {
      erreurs.beforeYear = 'Indiquez l’année avant laquelle les registres n’existent pas.'
    } else if (!Number.isInteger(annee) || annee < PREMIERE_ANNEE || annee > anneeCourante) {
      erreurs.beforeYear = `L’année s’écrit en chiffres, entre ${PREMIERE_ANNEE} et ${anneeCourante}.`
    }
  }
  if (motif.texteObligatoire && valeur.body === null) {
    erreurs.body = 'Dites en quelques mots pourquoi la recherche s’arrête.'
  }
  if (valeur.body !== null && [...valeur.body].length > LONGUEUR_MAX_DU_TEXTE) {
    // Écrit à la main plutôt que par `toLocaleString` : l'espace des milliers change
    // d'un moteur ICU à l'autre (insécable, ou fine insécable).
    erreurs.body = 'Le texte dépasse 4 000 caractères.'
  }
  return erreurs
}

/* ------------------------------------------------------------------ */
/* Le sommet d'une branche                                             */
/* ------------------------------------------------------------------ */

/**
 * L'état d'un côté d'une personne, au sommet d'une branche :
 *
 * - `connus` — un parent est lié de ce côté : la branche continue ;
 * - `close` — aucun parent, et une fin de recherche : la carte porte le mur ;
 * - `a-chercher` — aucun parent, et rien de dit : pas encore cherché.
 */
export type EtatDuSommet = 'connus' | 'close' | 'a-chercher'

/**
 * L'état d'un côté. **Un parent connu l'emporte** sur une fin de recherche restée
 * enregistrée : la fin de recherche ne s'efface que par sa levée, une demande (ADR 0023).
 */
export function etatDuSommet(parentConnu: boolean, fin: ResumeFinDeRecherche | null | undefined): EtatDuSommet {
  if (parentConnu) return 'connus'
  return fin === null || fin === undefined ? 'a-chercher' : 'close'
}

/** Les côtés où un parent est lié. */
export interface CotesConnus {
  pere: boolean
  mere: boolean
}

/** Aucun parent lié. */
export const AUCUN_PARENT: Readonly<CotesConnus> = { pere: false, mere: false }

/**
 * Ce qu'une charge porte des fins de recherche d'une personne — fiche complète, nœud du
 * graphe ou personne d'affiche : les trois ont les mêmes clés.
 */
export interface FinsDeRecherche {
  finDeRecherchePere?: ResumeFinDeRecherche | null
  finDeRechercheMere?: ResumeFinDeRecherche | null
}

/** La fin de recherche d'un côté, ou `null`. */
export function finDuCote(fins: FinsDeRecherche, cote: CoteDeRecherche): ResumeFinDeRecherche | null {
  return (cote === 'pere' ? fins.finDeRecherchePere : fins.finDeRechercheMere) ?? null
}

/** L'état des deux côtés d'une personne. */
export function etatsDuSommet(parents: CotesConnus, fins: FinsDeRecherche): Record<CoteDeRecherche, EtatDuSommet> {
  return {
    pere: etatDuSommet(parents.pere, finDuCote(fins, 'pere')),
    mere: etatDuSommet(parents.mere, finDuCote(fins, 'mere')),
  }
}

/** La carte porte-t-elle le mur ? — vrai dès qu'un côté est clos. */
export function estEnFinDeRecherche(etats: Record<CoteDeRecherche, EtatDuSommet>): boolean {
  return etats.pere === 'close' || etats.mere === 'close'
}

/**
 * Ce que dit le survol d'une carte : une ligne par côté clos, « Recherche close côté
 * père : Registres inexistants avant 1668 ». `null` quand aucun côté ne l'est.
 */
export function infobulleDuSommet(parents: CotesConnus, fins: FinsDeRecherche): string | null {
  const lignes: string[] = []
  for (const cote of COTES_DE_RECHERCHE) {
    const fin = finDuCote(fins, cote)
    if (etatDuSommet(cote === 'pere' ? parents.pere : parents.mere, fin) === 'close' && fin !== null) {
      lignes.push(`Recherche close ${libelleDuCote(cote)} : ${libelleDuMotif(fin.reason, fin.beforeYear)}`)
    }
  }
  return lignes.length === 0 ? null : lignes.join('\n')
}

/* ------------------------------------------------------------------ */
/* Père ou mère : la place d'un parent                                 */
/* ------------------------------------------------------------------ */

/**
 * Les places d'une famille de parents, depuis le sexe de ses époux — **la règle de
 * `Tree`** (`EcritureDeLArbrePourProposal`, normalisation du couple), recopiée : un homme
 * prend la place du père, une femme celle de la mère ; un second du même sexe, ou un
 * parent de sexe inconnu, prend la place libre, celle du père d'abord.
 *
 * Sert au graphe, qui ne dit pas quelle arête d'union est l'époux et laquelle l'épouse.
 * La fiche, elle, le dit (`ParentDeFiche.cote`) : `cotesDesParentsDeFiche()`.
 */
export function cotesDUneFamille(sexes: readonly Sexe[]): CotesConnus {
  let pere = false
  let mere = false
  let indetermines = 0
  for (const sexe of sexes) {
    if (sexe === 'masculin' && !pere) pere = true
    else if (sexe === 'feminin' && !mere) mere = true
    else indetermines++
  }
  for (; indetermines > 0; indetermines--) {
    if (!pere) pere = true
    else if (!mere) mere = true
  }
  return { pere, mere }
}

/**
 * Les côtés connus d'une fiche complète, depuis `parents[].cote`. Un parent sans `cote`
 * (charge d'un serveur antérieur à l'ADR 0023) n'est compté d'aucun côté : ce serveur-là
 * ne sert pas non plus de fin de recherche.
 */
export function cotesDesParentsDeFiche(parents: readonly { cote?: CoteDeRecherche }[]): CotesConnus {
  return {
    pere: parents.some((p) => p.cote === 'pere'),
    mere: parents.some((p) => p.cote === 'mere'),
  }
}

/**
 * Les côtés connus de chaque personne du graphe, depuis ses arêtes : `filiation` va de la
 * famille vers l'enfant, `union` de l'époux vers la famille (§ 5.2). Une personne qui a
 * plusieurs familles de parents (une naissance, une adoption) cumule leurs côtés.
 *
 * Une personne **absente** de la carte n'a aucun parent dans le graphe : `cotesConnusDe()`
 * rend alors `AUCUN_PARENT`. Les nœuds et arêtes en attente comptent comme les autres :
 * un parent proposé est dessiné au-dessus de la carte, la branche continue.
 */
export function cotesConnusDansLeGraphe(
  noeuds: readonly NoeudGraphe[],
  aretes: readonly AreteGraphe[],
): Map<IdentifiantNoeud, CotesConnus> {
  const sexes = new Map<IdentifiantNoeud, Sexe>()
  for (const n of noeuds) if (n.type === 'personne') sexes.set(n.id, n.sex)

  const epoux = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const famillesDeParents = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  for (const a of aretes) {
    if (a.genre === 'union') {
      const liste = epoux.get(a.vers) ?? []
      liste.push(a.de)
      epoux.set(a.vers, liste)
    } else {
      const liste = famillesDeParents.get(a.vers) ?? []
      liste.push(a.de)
      famillesDeParents.set(a.vers, liste)
    }
  }

  const carte = new Map<IdentifiantNoeud, CotesConnus>()
  for (const [enfant, familles] of famillesDeParents) {
    let pere = false
    let mere = false
    for (const famille of familles) {
      const parents = (epoux.get(famille) ?? []).filter((p) => p !== enfant && sexes.has(p))
      const cotes = cotesDUneFamille(parents.map((p) => sexes.get(p) ?? 'inconnu'))
      pere = pere || cotes.pere
      mere = mere || cotes.mere
    }
    carte.set(enfant, { pere, mere })
  }
  return carte
}

/** Les côtés connus d'un nœud, `AUCUN_PARENT` s'il n'en a aucun. */
export function cotesConnusDe(carte: ReadonlyMap<IdentifiantNoeud, CotesConnus>, id: IdentifiantNoeud): CotesConnus {
  return carte.get(id) ?? AUCUN_PARENT
}

/* ------------------------------------------------------------------ */
/* Les ops d'une demande                                               */
/* ------------------------------------------------------------------ */

/** Ce que les ops lisent d'une fiche : son identifiant, sa version, ses deux côtés. */
export interface FicheCibleDeFinDeRecherche {
  id: number
  version: number
  finDeRecherchePere?: FinDeRecherche | ValeurFinDeRecherche | null
  finDeRechercheMere?: FinDeRecherche | ValeurFinDeRecherche | null
}

/**
 * Les ops qui portent une saisie sur les côtés choisis — un par côté **qui change** :
 *
 * - `nouvelle` renseignée et le côté vide : la **pose** (`null → valeur`) ;
 * - `nouvelle` renseignée et le côté déjà clos : la **modification** ;
 * - `nouvelle` à `null` : la **levée** (`valeur → null`) — rouvrir la recherche.
 *
 * Un côté dont la valeur ne changerait pas est omis : le serveur refuse une modification
 * qui ne modifie rien. `oldValue` est la valeur de la fiche, privée de sa date et de son
 * auteur ; `baseVersion` sa `version`. Les rangs partent de `premierRang`, père d'abord.
 */
export function opsDeFinDeRecherche(
  fiche: FicheCibleDeFinDeRecherche,
  cotes: readonly CoteDeRecherche[],
  nouvelle: ValeurFinDeRecherche | null,
  premierRang = 1,
): OpFinDeRecherche[] {
  const valeur = nouvelle === null ? null : normaliserValeur(nouvelle)
  const ops: OpFinDeRecherche[] = []
  let rang = premierRang
  for (const cote of COTES_DE_RECHERCHE) {
    if (!cotes.includes(cote)) continue
    const champ = CHAMP_DU_COTE[cote]
    const ancienne = valeurDeFinDeRecherche(fiche[champ])
    if (memesValeurs(ancienne, valeur)) continue
    ops.push({
      seq: rang++,
      opKind: 'champ',
      subjectKind: 'personne',
      subjectId: fiche.id,
      field: champ,
      valueKind: NATURE_DE_VALEUR,
      oldValue: ancienne,
      newValue: valeur === null ? null : { ...valeur },
      baseVersion: fiche.version,
    })
  }
  return ops
}

/**
 * Le corps de la demande — une `modification_personne` — ou `null` quand rien ne change.
 * `soumettre` vaut `true` par défaut : la fiche dépose, elle ne garde pas de brouillon.
 */
export function corpsDeFinDeRecherche(
  fiche: FicheCibleDeFinDeRecherche,
  cotes: readonly CoteDeRecherche[],
  nouvelle: ValeurFinDeRecherche | null,
  options: { message?: string; soumettre?: boolean } = {},
): CorpsFinDeRecherche | null {
  const ops = opsDeFinDeRecherche(fiche, cotes, nouvelle)
  if (ops.length === 0) return null
  const corps: CorpsFinDeRecherche = {
    kind: 'modification_personne',
    targetKind: 'personne',
    targetId: fiche.id,
    soumettre: options.soumettre ?? true,
    ops,
  }
  const message = options.message?.trim() ?? ''
  if (message !== '') corps.message = message
  return corps
}

/* ------------------------------------------------------------------ */
/* Le mur                                                              */
/* ------------------------------------------------------------------ */

/**
 * La couleur du mur et du bord d'une carte close : **brique**. C'est la valeur claire du
 * jeton `--h-fin-de-recherche` (`styles/main.css`) et la couleur imprimée de l'affiche,
 * qui ne suit pas le thème sombre. Un rouge, distinct de l'orange brûlé de `--h-alerte`
 * (divorces, fiches sans source), pour qu'un mur ne se lise pas comme une alerte.
 */
export const BRIQUE = '#9b3426'

/** La boîte du tracé du mur, celle des icônes de l'interface (`viewBox="0 0 24 24"`). */
export const BOITE_DU_MUR = 24

/** L'épaisseur du trait du mur dans sa boîte de 24 — celle des icônes de l'interface. */
export const EPAISSEUR_DU_MUR = 1.8

/*
 * Le mur : trois rangs de briques en quinconce dans un cadre, sur la grille de 24. Un
 * rectangle et six traits — lisible à 12 px comme à 3 mm d'impression.
 */
const CADRE: readonly [number, number, number, number] = [3, 4.5, 21, 19.5]
const JOINTS: readonly (readonly [number, number, number, number])[] = [
  [3, 9.5, 21, 9.5],
  [3, 14.5, 21, 14.5],
  [12, 4.5, 12, 9.5],
  [7.5, 9.5, 7.5, 14.5],
  [16.5, 9.5, 16.5, 14.5],
  [12, 14.5, 12, 19.5],
]

/** Au millième près, sans zéros inutiles : `3`, `4.5`, `0.375`. */
function nombre(x: number): string {
  // `String(-0)` vaut déjà `"0"`.
  return String(Math.round(x * 1000) / 1000)
}

/**
 * Le tracé du mur posé dans le carré `[x, x + taille] × [y, y + taille]`, en commandes
 * absolues — pour `Scene.chemin()` de l'affiche (en mm) comme pour un `<path>` en pixels.
 * Un trait sans fond : `fill="none"`, épaisseur `epaisseurDuMur(taille)`.
 */
export function cheminDuMur(x: number, y: number, taille: number): string {
  const k = taille / BOITE_DU_MUR
  const px = (v: number): string => nombre(x + v * k)
  const py = (v: number): string => nombre(y + v * k)
  const [x0, y0, x1, y1] = CADRE
  const morceaux = [`M${px(x0)} ${py(y0)}H${px(x1)}V${py(y1)}H${px(x0)}Z`]
  for (const [xa, ya, xb, yb] of JOINTS) morceaux.push(`M${px(xa)} ${py(ya)}L${px(xb)} ${py(yb)}`)
  return morceaux.join('')
}

/** L'épaisseur du trait du mur posé dans un carré de `taille`. */
export function epaisseurDuMur(taille: number): number {
  return (EPAISSEUR_DU_MUR * taille) / BOITE_DU_MUR
}

/**
 * Le tracé du mur dans sa boîte de 24, pour une icône en ligne de l'interface :
 * `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
 * stroke-linecap="round" stroke-linejoin="round"><path :d="TRACE_DU_MUR" /></svg>`.
 */
export const TRACE_DU_MUR = cheminDuMur(0, 0, BOITE_DU_MUR)
