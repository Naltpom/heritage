/**
 * La saisie d'une **date incertaine** — le cœur du formulaire, et son piège.
 *
 * > « Ne jamais aplatir une fourchette en date ferme : ni en base, ni à
 * > l'écran. C'est une règle de fond — la donnée ne porte pas cette
 * > certitude. » (§ 4 du dossier de mission)
 *
 * Ce corpus est fait de `BET 1901 AND 1909`, de `ABT 1889`, de `BEF 1931`. Un
 * `<input type="date">` nu ne sait exprimer qu'un **point** : le brancher ici
 * obligerait le contributeur à inventer un jour et un mois qu'aucune source ne
 * donne, et cette invention deviendrait une donnée. C'est pourquoi la saisie
 * passe par **une forme et une ou deux bornes**, jamais par un champ de date du
 * navigateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce que ce module produit : cinq clés, toujours les cinq.**
 *
 * Une valeur d'op est fixée « au caractère près » par le contrat : `raw`,
 * `qualifier`, `min`, `max`, `place` — « même quand trois valent `null`, pour
 * qu'un champ absent soit toujours une erreur et jamais un non-renseigné
 * implicite » (§ 14.1). La sixième clé, `texte`, **n'entre jamais dans un corps
 * de requête** : elle est produite par le serveur, et un back qui l'accepterait
 * ferait diverger la comparaison `JSONB` de la détection de conflit (§ 9.6).
 * D'où `sansTexte()`, à appeler sur tout bloc reçu d'une fiche avant de le
 * reposter. C'est la seule manipulation à faire, et le contrat l'écrit
 * explicitement « pour qu'elle ne s'invente pas ».
 * ────────────────────────────────────────────────────────────────────
 *
 * **Ce n'est pas `min`/`max` qui porte l'incertitude, c'est `qualifier`.**
 * `1930` donne déjà `1930-01-01` → `1930-12-31` : un client qui déduirait
 * « `min ≠ max` donc fourchette » classerait les trois quarts de l'arbre en
 * incertain. Le test « est-ce une fourchette » est `qualifier === 'entre'`,
 * rien d'autre (§ 14.3, piège n° 1).
 *
 * **`ABT` n'est jamais élargi.** `ABT 1934` reste `1934-01-01` → `1934-12-31` :
 * « la source dit *vers 1934*, elle ne dit pas *entre 1929 et 1939* ». Une
 * tolérance serait un choix du lecteur, pas une donnée.
 */

import type { BlocDate, ValeurDate } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* La valeur transportée                                               */
/* ------------------------------------------------------------------ */

/**
 * Retire `texte` d'un bloc reçu du serveur.
 *
 * À appeler **systématiquement** avant de reposter un bloc lu sur une fiche. Le
 * champ est reconstruit clé par clé plutôt que par déstructuration avec reste :
 * si le contrat ajoute un jour une septième clé de lecture seule, elle ne
 * passera pas non plus.
 */
export function sansTexte(bloc: BlocDate | ValeurDate): ValeurDate {
  return {
    raw: bloc.raw,
    qualifier: bloc.qualifier,
    min: bloc.min,
    max: bloc.max,
    place: bloc.place,
  }
}

/**
 * **Ce bloc désigne-t-il quelque chose ?**
 *
 * `composerBlocDate()` écrit déjà la règle dans l'autre sens : il rend
 * `valeur: null` — « et non un bloc `inconnue` » — quand rien n'est saisi, ni
 * date ni lieu. Ceci en est le miroir, pour les blocs qui **arrivent** : un bloc
 * `qualifier: 'inconnue'` dont les quatre autres clés sont nulles n'affirme
 * rien. Ni borne, ni lieu, ni provenance. C'est l'écriture longue de « pas de
 * date », et la confondre avec une date coûte cher — voir `blocDeSaisie()` dans
 * `reprise.ts`, qui existe pour cette confusion-là et pour ce qu'elle a produit.
 *
 * **Un lieu sans date désigne bien quelque chose** (« né à Saule, date
 * inconnue », le cas courant de ce corpus) et n'entre donc pas dans ce cas ;
 * un `raw` seul non plus, qui est une provenance qu'on ne réécrit jamais.
 *
 * Ce prédicat ne décide de rien tout seul : il **nomme** la question, pour
 * qu'elle ne se repose pas à quatre endroits sous quatre formes différentes.
 */
export function designeUneDate(bloc: BlocDate | ValeurDate | null): boolean {
  if (bloc === null) return false
  if (bloc.qualifier !== 'inconnue') return true
  return bloc.raw !== null || bloc.min !== null || bloc.max !== null || bloc.place !== null
}

/** Deux blocs décrivent-ils la même date ? `texte` est ignoré : il est dérivé,
 *  et le serveur ne le compare pas non plus. */
export function memeValeurDate(a: ValeurDate | null, b: ValeurDate | null): boolean {
  if (a === null || b === null) return a === b
  return (
    a.raw === b.raw &&
    a.qualifier === b.qualifier &&
    a.min === b.min &&
    a.max === b.max &&
    a.place === b.place
  )
}

/* ------------------------------------------------------------------ */
/* La saisie                                                           */
/* ------------------------------------------------------------------ */

/**
 * Les six formes offertes au contributeur.
 *
 * Elles ne recouvrent pas une par une les qualificatifs du § 14.1 : `connue`
 * produit `exacte` ou `annee` **selon la précision réellement saisie**. C'est le
 * point qui empêche l'aplatissement — celui qui ne connaît que l'année écrit
 * l'année, et rien dans l'interface ne lui réclame un jour.
 *
 * `connue` recouvre à elle seule trois qualificatifs — `annee`, `mois`,
 * `exacte` — parce que ce n'est pas au contributeur de déclarer sa précision :
 * elle se lit dans ce qu'il écrit.
 *
 * `depuis` et `jusqu_a` (les `FROM` / `TO` du GEDCOM) ne sont pas proposés à la
 * saisie : ils décrivent une période en cours, que l'import produit mais qu'un
 * contributeur n'a jamais à écrire. Un champ qui en porte une se **verrouille**
 * plutôt que de se vider — voir `relectureFidele()`, et le motif qui va avec.
 */
export type FormeDate = 'connue' | 'environ' | 'entre' | 'avant' | 'apres' | 'inconnue'

export const LIBELLES_FORME: Record<FormeDate, string> = {
  connue: 'Date connue',
  environ: 'Vers',
  entre: 'Entre … et …',
  avant: 'Avant',
  apres: 'Après',
  inconnue: 'Inconnue',
}

/** L'aide affichée sous le sélecteur : elle dit ce que la forme fera des
 *  bornes, parce que « avant 1920 » n'inclut pas 1920 et que ça se devine mal. */
export const AIDES_FORME: Record<FormeDate, string> = {
  connue: 'L’année seule (1930), le mois (03/1930) ou la date complète (11/03/1930). Écrivez ce que la source donne, rien de plus.',
  environ: 'Une date approchée, telle que la source la donne. Elle n’est pas élargie.',
  entre: 'Une fourchette : la date se situe quelque part entre les deux bornes, incluses.',
  avant: 'Strictement avant la borne indiquée.',
  apres: 'Strictement après la borne indiquée.',
  inconnue: 'La date n’est pas connue. Un lieu peut malgré tout être indiqué.',
}

/**
 * L'état d'un champ de date dans le formulaire.
 *
 * `origine` porte le bloc tel qu'il a été **reçu** : il sert à décider si `raw`
 * — la provenance GEDCOM, « jamais reconstruite, jamais réécrite » — doit être
 * conservée ou recomposée. Tant que la valeur ne bouge pas, la chaîne d'origine
 * reste ; dès qu'elle bouge, la garder ferait mentir la provenance.
 */
export interface SaisieDate {
  forme: FormeDate
  /** Première borne, telle que tapée : « 1901 », « 11/03/1930 ». */
  debut: string
  /** Seconde borne, employée par la seule forme « entre ». */
  fin: string
  lieu: string
  origine: ValeurDate | null
}

export function saisieVide(): SaisieDate {
  return { forme: 'inconnue', debut: '', fin: '', lieu: '', origine: null }
}

/* ------------------------------------------------------------------ */
/* Analyse d'une borne                                                 */
/* ------------------------------------------------------------------ */

/**
 * Trois précisions, parce que la source en connaît trois.
 *
 * `mois` a manqué à cette liste, et le manque n'était pas théorique : une date
 * `JAN 1920` ne se relisait alors dans aucun champ, le champ ressortait vide, et
 * la composition en faisait « aucune date » — c'est-à-dire une **proposition de
 * suppression** que personne n'avait demandée. Une précision absente du modèle
 * de saisie ne produit pas une saisie approximative : elle produit une perte
 * silencieuse.
 */
type Precision = 'annee' | 'mois' | 'jour'

interface Borne {
  precision: Precision
  annee: number
  /** 1 à 12. Vaut 1 quand la précision est l'année : il ne sert alors qu'au
   *  calcul des bornes, jamais à l'affichage. */
  mois: number
  jour: number
}

export type AnalyseBorne = { valide: true; borne: Borne } | { valide: false; message: string }

const ANNEE_SEULE = /^(\d{3,4})$/
const DATE_COMPLETE = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{3,4})$/
const DATE_ISO = /^(\d{4})-(\d{2})-(\d{2})$/
const MOIS_SEUL = /^(\d{1,2})[/.-](\d{3,4})$/

/** Nombre de jours du mois, années bissextiles comprises. */
function joursDuMois(annee: number, mois: number): number {
  return new Date(Date.UTC(annee, mois, 0)).getUTCDate()
}

/**
 * Lit une borne saisie à la main.
 *
 * Quatre écritures acceptées — `1930`, `03/1930`, `11/03/1930` (avec `/`, `.`
 * ou `-`) et `1930-03-11` — parce que les quatre se tapent naturellement et
 * qu'aucune n'est ambiguë : deux nombres séparés, c'est un mois et une année ;
 * trois, c'est un jour, un mois et une année.
 *
 * **Le mois seul est une précision à part entière**, et non un jour manquant.
 * `JAN 1920` vaut `1920-01-01` → `1920-01-31` (§ 14.3) : ni un point inventé au
 * 1ᵉʳ, ni l'année entière. Le refuser obligeait le contributeur à choisir entre
 * les deux — et faisait disparaître du formulaire les dates que l'import avait
 * pourtant lues correctement.
 */
export function analyserBorne(saisie: string): AnalyseBorne {
  const texte = saisie.trim()
  if (texte.length === 0) return { valide: false, message: 'Indiquez une date.' }

  const annee = ANNEE_SEULE.exec(texte)
  if (annee !== null) {
    return { valide: true, borne: { precision: 'annee', annee: Number(annee[1]), mois: 1, jour: 1 } }
  }

  const iso = DATE_ISO.exec(texte)
  const complete = iso === null ? DATE_COMPLETE.exec(texte) : null

  if (iso !== null || complete !== null) {
    const [a, m, j] =
      iso !== null
        ? [Number(iso[1]), Number(iso[2]), Number(iso[3])]
        : [Number(complete![3]), Number(complete![2]), Number(complete![1])]

    if (m < 1 || m > 12) return { valide: false, message: 'Le mois doit être compris entre 1 et 12.' }
    if (j < 1 || j > joursDuMois(a, m)) {
      return { valide: false, message: `Ce mois ne compte pas ${j} jours.` }
    }
    return { valide: true, borne: { precision: 'jour', annee: a, mois: m, jour: j } }
  }

  const mois = MOIS_SEUL.exec(texte)
  if (mois !== null) {
    const m = Number(mois[1])
    if (m < 1 || m > 12) return { valide: false, message: 'Le mois doit être compris entre 1 et 12.' }
    return { valide: true, borne: { precision: 'mois', annee: Number(mois[2]), mois: m, jour: 1 } }
  }

  return { valide: false, message: 'Écrivez 1930, 03/1930, ou 11/03/1930.' }
}

/* ------------------------------------------------------------------ */
/* Bornes et arithmétique de jours                                     */
/* ------------------------------------------------------------------ */

function iso(annee: number, mois: number, jour: number): string {
  const a = String(annee).padStart(4, '0')
  const m = String(mois).padStart(2, '0')
  const j = String(jour).padStart(2, '0')
  return `${a}-${m}-${j}`
}

/** Premier jour de la période que la borne désigne. */
function debutDePeriode(borne: Borne): string {
  if (borne.precision === 'annee') return iso(borne.annee, 1, 1)
  if (borne.precision === 'mois') return iso(borne.annee, borne.mois, 1)
  return iso(borne.annee, borne.mois, borne.jour)
}

/** Dernier jour de la période que la borne désigne. */
function finDePeriode(borne: Borne): string {
  if (borne.precision === 'annee') return iso(borne.annee, 12, 31)
  if (borne.precision === 'mois') return iso(borne.annee, borne.mois, joursDuMois(borne.annee, borne.mois))
  return iso(borne.annee, borne.mois, borne.jour)
}

/** Décale une date `YYYY-MM-DD` d'un nombre de jours. En UTC : une arithmétique
 *  de dates en heure locale saute une heure deux fois par an, et le décalage
 *  d'un jour retombe alors sur le même jour. */
function decalerJour(date: string, delta: number): string {
  const point = new Date(`${date}T00:00:00Z`)
  point.setUTCDate(point.getUTCDate() + delta)
  return iso(point.getUTCFullYear(), point.getUTCMonth() + 1, point.getUTCDate())
}

/* ------------------------------------------------------------------ */
/* Composition GEDCOM                                                  */
/* ------------------------------------------------------------------ */

const MOIS_GEDCOM = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']

/** La précision saisie **est** le qualificatif, sur la forme « date connue ».
 *  Une table plutôt qu'une chaîne de ternaires : le compilateur exige alors une
 *  entrée par précision le jour où il s'en ajoute une. */
const QUALIFICATIF_DE_PRECISION: Record<Precision, 'annee' | 'mois' | 'exacte'> = {
  annee: 'annee',
  mois: 'mois',
  jour: 'exacte',
}

/** `1930`, `JAN 1920` ou `11 MAR 1930`, selon la précision. Syntaxe du § 14.3. */
function borneEnGedcom(borne: Borne): string {
  if (borne.precision === 'annee') return String(borne.annee)
  if (borne.precision === 'mois') return `${MOIS_GEDCOM[borne.mois - 1]} ${borne.annee}`
  return `${borne.jour} ${MOIS_GEDCOM[borne.mois - 1]} ${borne.annee}`
}

/* ------------------------------------------------------------------ */
/* Composition du bloc                                                 */
/* ------------------------------------------------------------------ */

export type ResultatSaisieDate =
  | { valide: true; valeur: ValeurDate | null }
  | { valide: false; message: string }

function lieuOuNull(saisie: SaisieDate): string | null {
  const lieu = saisie.lieu.trim()
  return lieu.length > 0 ? lieu : null
}

/**
 * Compose la valeur à envoyer, ou dit ce qui manque.
 *
 * Rend `valeur: null` — et non un bloc `inconnue` — quand **rien** n'est saisi,
 * ni date ni lieu : c'est la façon d'écrire « cette fiche ne porte pas de date
 * de décès ». Un lieu sans date, en revanche, produit un bloc `inconnue` avec
 * son `place` : c'est le cas courant de ce corpus (« né à Saule, date inconnue »),
 * et le perdre serait perdre une donnée que quelqu'un a prise le temps d'écrire.
 */
export function composerBlocDate(saisie: SaisieDate): ResultatSaisieDate {
  const place = lieuOuNull(saisie)
  // La forme est extraite dans une constante : le `switch` final doit rester
  // exhaustif pour le compilateur, et le rétrécissement d'un accès de propriété
  // ne survit pas toujours aux appels intermédiaires.
  const forme: FormeDate = saisie.forme

  if (forme === 'inconnue') {
    if (place === null) return { valide: true, valeur: null }
    return { valide: true, valeur: avecRaw(saisie, { raw: null, qualifier: 'inconnue', min: null, max: null, place }) }
  }

  const premiere = analyserBorne(saisie.debut)
  if (!premiere.valide) return { valide: false, message: premiere.message }

  if (forme === 'entre') {
    const seconde = analyserBorne(saisie.fin)
    if (!seconde.valide) return { valide: false, message: seconde.message }

    const min = debutDePeriode(premiere.borne)
    const max = finDePeriode(seconde.borne)
    // `ck_…_intervalle` exige `min < max` **strictement**. Le test porte sur le
    // **début** des deux périodes et non sur `min < max`, et c'est plus sévère
    // à dessein : `BET 1901 AND 1901` satisfait `min < max` (1ᵉʳ janvier contre
    // 31 décembre) tout en ne désignant qu'une seule année. Ce n'est pas une
    // fourchette, c'est une date écrite deux fois — et le contributeur qui l'a
    // saisie voulait « Date connue ». Le refus arrive ici, avec la phrase qui
    // dit quoi faire, plutôt qu'en `422` après l'envoi.
    if (debutDePeriode(seconde.borne) <= min) {
      return {
        valide: false,
        message: 'La seconde borne doit être postérieure à la première. Pour une date unique, choisissez « Date connue ».',
      }
    }
    return {
      valide: true,
      valeur: avecRaw(saisie, {
        raw: `BET ${borneEnGedcom(premiere.borne)} AND ${borneEnGedcom(seconde.borne)}`,
        qualifier: 'entre',
        min,
        max,
        place,
      }),
    }
  }

  const borne = premiere.borne
  const debut = debutDePeriode(borne)
  const fin = finDePeriode(borne)
  const gedcom = borneEnGedcom(borne)

  switch (forme) {
    case 'connue':
      return {
        valide: true,
        valeur: avecRaw(saisie, {
          raw: gedcom,
          // La précision saisie décide, pas le contributeur : celui qui n'a que
          // l'année obtient `annee`, celui qui a le mois obtient `mois`, et
          // personne ne s'est vu réclamer un jour qu'aucune source ne donne.
          qualifier: QUALIFICATIF_DE_PRECISION[borne.precision],
          min: debut,
          max: fin,
          place,
        }),
      }

    case 'environ':
      return {
        valide: true,
        valeur: avecRaw(saisie, {
          raw: `ABT ${gedcom}`,
          qualifier: 'environ',
          min: debut,
          max: fin,
          place,
        }),
      }

    case 'avant':
      // `BEF 12 DEC 1920` → `max = 1920-12-11` (§ 14.3) : la borne est
      // **exclue**. La veille du premier jour de la période nommée.
      return {
        valide: true,
        valeur: avecRaw(saisie, {
          raw: `BEF ${gedcom}`,
          qualifier: 'avant',
          min: null,
          max: decalerJour(debut, -1),
          place,
        }),
      }

    case 'apres':
      // Symétrique : `AFT 1900` commence au 1ᵉʳ janvier 1901.
      return {
        valide: true,
        valeur: avecRaw(saisie, {
          raw: `AFT ${gedcom}`,
          qualifier: 'apres',
          min: decalerJour(fin, 1),
          max: null,
          place,
        }),
      }
  }
}

/**
 * Rend la valeur composée, mais **conserve le `raw` d'origine** si la date
 * décrite n'a pas changé.
 *
 * `raw` est « la chaîne GEDCOM verbatim, jamais reconstruite, jamais
 * réécrite : c'est la provenance » (§ 14.1). Deux conséquences opposées qu'il
 * faut tenir ensemble :
 *
 * - une valeur **non modifiée** doit repartir avec sa chaîne d'origine, sinon
 *   `BET 1901 AND 1909` importé du GEDCOM ressortirait recomposé et la
 *   comparaison `JSONB` du serveur y verrait une modification ;
 * - une valeur **modifiée** ne peut pas garder l'ancienne chaîne, qui décrirait
 *   alors autre chose que `min`/`max`.
 *
 * Le test porte donc sur `qualifier`, `min`, `max` et `place`, jamais sur un
 * drapeau « le champ a été touché » : recopier une valeur à l'identique après
 * l'avoir effacée ne doit pas changer la provenance.
 */
function avecRaw(saisie: SaisieDate, compose: ValeurDate): ValeurDate {
  const origine = saisie.origine
  if (
    origine !== null &&
    origine.qualifier === compose.qualifier &&
    origine.min === compose.min &&
    origine.max === compose.max &&
    origine.place === compose.place
  ) {
    return { ...compose, raw: origine.raw }
  }
  return compose
}

/* ------------------------------------------------------------------ */
/* Relecture d'un bloc reçu                                            */
/* ------------------------------------------------------------------ */

const PREFIXES: ReadonlyArray<[RegExp, FormeDate]> = [
  [/^ABT\s+(.+)$/i, 'environ'],
  [/^BEF\s+(.+)$/i, 'avant'],
  [/^AFT\s+(.+)$/i, 'apres'],
]

const MOIS_INDEX = new Map(MOIS_GEDCOM.map((mois, index) => [mois, index + 1]))

/** `11 MAR 1930` / `1930` → la chaîne à remettre dans le champ de saisie. */
function borneDepuisGedcom(fragment: string): string | null {
  const texte = fragment.trim()
  if (/^\d{3,4}$/.test(texte)) return texte

  const jour = /^(\d{1,2})\s+([A-Z]{3})\s+(\d{3,4})$/i.exec(texte)
  if (jour !== null) {
    const mois = MOIS_INDEX.get(jour[2]!.toUpperCase())
    if (mois === undefined) return null
    return `${jour[1]!.padStart(2, '0')}/${String(mois).padStart(2, '0')}/${jour[3]}`
  }

  // `JAN 1920`, sans jour. Cette branche manquait, et c'est elle qui faisait
  // tomber une date de mois dans le repli par qualificatif — d'où le champ vide.
  const sansJour = /^([A-Z]{3})\s+(\d{3,4})$/i.exec(texte)
  if (sansJour === null) return null
  const mois = MOIS_INDEX.get(sansJour[1]!.toUpperCase())
  if (mois === undefined) return null
  return `${String(mois).padStart(2, '0')}/${sansJour[2]}`
}

/** `1920-01-01` → `01/1920`. Employé quand le bloc n'a pas de provenance. */
function mmaaaa(date: string | null): string {
  if (date === null || date.length < 7) return ''
  return `${date.slice(5, 7)}/${date.slice(0, 4)}`
}

/** Une année depuis une borne `YYYY-MM-DD`, ou `''`. */
function anneeDe(date: string | null): string {
  return date === null ? '' : date.slice(0, 4)
}

/**
 * Reconstitue une saisie depuis un bloc reçu du serveur.
 *
 * **`raw` est lu en premier** quand il est là : c'est la seule source qui rend
 * la saisie d'origine sans perte. `BEF 1920` porte `max = 1919-12-31` ; repartir
 * de `max` afficherait « avant 1919 » au contributeur, qui corrigerait une
 * erreur que personne n'a commise. Le repli sur `qualifier`/`min`/`max` existe
 * pour les blocs sans provenance — ceux qu'un contributeur a créés ici même.
 */
/*
 * Accepte les **deux** formes : un bloc reçu d'une fiche (six clés) et une
 * valeur d'op (cinq). La reprise d'une demande caduque (§ 6.10) rend un corps
 * « postable en l'état », donc sans `texte` ; une fiche en porte un. Les deux
 * finissent dans le même formulaire, et `sansTexte()` continue de normaliser à
 * cinq clés — ce qui compte est que `origine` ne reparte jamais avec `texte`.
 */
export function saisieDepuisBloc(bloc: BlocDate | ValeurDate | null): SaisieDate {
  if (bloc === null) return saisieVide()

  const origine = sansTexte(bloc)
  const base: SaisieDate = { ...saisieVide(), lieu: bloc.place ?? '', origine }

  const brut = bloc.raw?.trim() ?? ''
  if (brut.length > 0) {
    const entre = /^BET\s+(.+?)\s+AND\s+(.+)$/i.exec(brut)
    if (entre !== null) {
      const debut = borneDepuisGedcom(entre[1]!)
      const fin = borneDepuisGedcom(entre[2]!)
      if (debut !== null && fin !== null) return { ...base, forme: 'entre', debut, fin }
    }

    for (const [motif, forme] of PREFIXES) {
      const trouve = motif.exec(brut)
      if (trouve === null) continue
      const debut = borneDepuisGedcom(trouve[1]!)
      if (debut !== null) return { ...base, forme, debut }
    }

    const simple = borneDepuisGedcom(brut)
    if (simple !== null) return { ...base, forme: 'connue', debut: simple }
  }

  // Repli sans provenance : on relit les bornes. La reconstruction n'est exacte
  // que pour les qualificatifs dont les bornes ne sont pas décalées ; `avant` et
  // `apres` rendent donc l'année de la borne **affichée**, pas celle stockée.
  switch (bloc.qualifier) {
    case 'exacte':
      return { ...base, forme: 'connue', debut: jjmmaaaa(bloc.min) }
    case 'annee':
      return { ...base, forme: 'connue', debut: anneeDe(bloc.min) }
    case 'environ':
      return { ...base, forme: 'environ', debut: anneeDe(bloc.min) }
    case 'entre':
      return { ...base, forme: 'entre', debut: anneeDe(bloc.min), fin: anneeDe(bloc.max) }
    case 'avant':
      return { ...base, forme: 'avant', debut: anneeDe(bloc.max === null ? null : decalerJour(bloc.max, 1)) }
    case 'apres':
      return { ...base, forme: 'apres', debut: anneeDe(bloc.min === null ? null : decalerJour(bloc.min, -1)) }

    // `FROM` et `TO` sont **inclusifs**, au contraire d'`AFT` et `BEF` : leurs
    // bornes ne sont pas décalées, donc on ne les décale pas non plus en relisant.
    // C'est la seule différence avec les deux cas ci-dessus, et l'inverser
    // afficherait une date fausse d'un jour au contributeur.
    case 'depuis':
      return { ...base, forme: 'apres', debut: anneeDe(bloc.min) }
    case 'jusqu_a':
      return { ...base, forme: 'avant', debut: anneeDe(bloc.max) }

    // `JAN 1920`. La précision « mois » est une forme à part entière de la
    // saisie : elle se relit en `01/1920` et se recompose à l'identique. Elle ne
    // l'a pas toujours été — tant qu'elle manquait, ce champ ressortait **vide**
    // et la composition en faisait une proposition de suppression.
    case 'mois':
      return { ...base, forme: 'connue', debut: mmaaaa(bloc.min) }

    case 'inconnue':
      return base
  }
}

function jjmmaaaa(date: string | null): string {
  if (date === null || date.length < 10) return ''
  return `${date.slice(8, 10)}/${date.slice(5, 7)}/${date.slice(0, 4)}`
}

/* ------------------------------------------------------------------ */
/* Le garde-fou : une date qu'on ne sait pas rééditer                  */
/* ------------------------------------------------------------------ */

/**
 * **Ce champ peut-il être rouvert sans risque de perdre sa valeur ?**
 *
 * C'est le garde-fou le plus important de ce module, et il protège d'une faute
 * qui ne laisse aucune trace. Le mécanisme, en trois temps :
 *
 * 1. l'éditeur relit un bloc dans une `SaisieDate` — une nature, des bornes ;
 * 2. s'il ne sait pas l'exprimer, le champ ressort **vide** ;
 * 3. à l'envoi, un champ vide se compose en « aucune date » — c'est-à-dire en
 *    **proposition de suppression** de la date existante.
 *
 * Personne ne l'a demandée, personne ne la voit passer, et l'administrateur la
 * valide comme le reste. Un contributeur venu corriger un prénom effacerait une
 * date de naissance sourcée.
 *
 * Plutôt que d'énumérer les qualificatifs à risque — liste qui vieillirait — on
 * pose la question sous sa forme utile : *relire puis recomposer rend-il
 * exactement ce qu'on avait ?* Si oui, le champ est éditable. Sinon il est
 * verrouillé, affiché tel quel, et sa valeur d'origine repart intacte.
 *
 * Aujourd'hui cela ne concerne que `depuis` et `jusqu_a` — les `FROM` / `TO` du
 * GEDCOM, qu'un contributeur n'a jamais à écrire mais que l'import produit.
 * `mois` en faisait partie jusqu'à ce que la saisie sache l'exprimer, ce qui est
 * la bonne façon de réduire cette liste : apprendre une forme, pas la masquer.
 */
export function relectureFidele(bloc: BlocDate | ValeurDate | null): boolean {
  // Un bloc qui n'affirme rien n'a rien à perdre : le champ s'ouvre vide et
  // modifiable. C'est ce que la fiche rend pour une personne sans date connue,
  // et le verrouiller interdisait d'y écrire la date qu'on a trouvée.
  if (bloc === null || !designeUneDate(bloc)) return true
  const compose = composerBlocDate(saisieDepuisBloc(bloc))
  return compose.valide && memeValeurDate(compose.valeur, sansTexte(bloc))
}

/**
 * La valeur à envoyer pour un champ date, **garde-fou compris**.
 *
 * Quand la relecture n'est pas fidèle, on renvoie l'original : la valeur est
 * alors identique à l'ancienne, `construireOps` ne produit aucun op, et rien ne
 * bouge. C'est volontairement le **même chemin** que pour un champ non modifié —
 * un cas de plus à ne pas traiter, plutôt qu'une exception à ne pas oublier.
 */
export function valeurAEnvoyer(saisie: SaisieDate): ResultatSaisieDate {
  const origine = saisie.origine
  if (origine !== null && !relectureFidele(origine)) {
    return { valide: true, valeur: origine }
  }
  const compose = composerBlocDate(saisie)
  // Rien de saisi sur un bloc qui ne disait rien : c'est le même « rien ». On
  // rend l'original pour que `construireOps` n'y voie aucune différence — sans
  // quoi ouvrir puis soumettre la fiche produirait un op `birth: {…} → null`.
  if (compose.valide && compose.valeur === null && origine !== null && !designeUneDate(origine)) {
    return { valide: true, valeur: origine }
  }
  return compose
}

/* ------------------------------------------------------------------ */
/* Rendu d'appoint                                                     */
/* ------------------------------------------------------------------ */

/**
 * Une phrase de contrôle affichée sous le champ, pour que le contributeur voie
 * ce qu'il est en train d'envoyer.
 *
 * Elle ne remplace pas `texte` — qui vient du serveur et fait foi à l'affichage
 * (§ 14.4) — elle sert pendant la saisie, quand aucun serveur n'a encore parlé.
 * Et elle **montre la fourchette**, parce que c'est précisément ce qu'un
 * contributeur doit pouvoir relire avant de valider.
 */
export function apercuDeValeur(valeur: ValeurDate | null): string {
  if (valeur === null) return 'Aucune date ne sera proposée.'

  const lieu = valeur.place === null ? '' : `, à ${valeur.place}`
  switch (valeur.qualifier) {
    case 'inconnue':
      return `Date inconnue${lieu}.`
    case 'entre':
      return `Entre le ${valeur.min} et le ${valeur.max}, bornes comprises${lieu}.`
    case 'avant':
      return `Avant le ${valeur.max}, borne comprise${lieu}.`
    case 'apres':
      return `À partir du ${valeur.min}${lieu}.`
    case 'environ':
      return `Vers ${anneeDe(valeur.min)} — la fourchette ${valeur.min} → ${valeur.max} n’est pas élargie${lieu}.`
    case 'annee':
      return `Année ${anneeDe(valeur.min)}, jour et mois inconnus${lieu}.`
    case 'exacte':
      return `Le ${valeur.min}${lieu}.`
    case 'mois':
      return `Mois entier, du ${valeur.min} au ${valeur.max}, bornes comprises${lieu}.`
    // `depuis` et `jusqu_a` portent une borne **ouverte** : le dire, plutôt que
    // de laisser croire à un intervalle fermé dont une extrémité serait vide.
    case 'depuis':
      return `À partir du ${valeur.min}, sans fin connue${lieu}.`
    case 'jusqu_a':
      return `Jusqu’au ${valeur.max}, sans début connu${lieu}.`
  }
}
