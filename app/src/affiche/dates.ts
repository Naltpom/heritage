/**
 * Les dates et les noms **tels que l'affiche les écrit** — portage de `commun.py`.
 *
 * L'affiche a ses propres formes, plus resserrées que celles des fiches : « 25 mai 1946 »
 * (long), « 25 mai 1946 » avec mois abrégés (« 3 févr. 1780 »), « 25.05.1946 » (court),
 * « 1946 » (année) ; « v. 1745 », « av. 1735 », « 1901–1909 ». Elles se fabriquent ici, à
 * partir de la chaîne GEDCOM (`raw`) quand elle est là — c'est ce que faisait l'affiche
 * d'origine —, sinon des bornes.
 *
 * ## Les dates sans `raw`
 *
 * Deux cas arrivent sans chaîne GEDCOM, et l'affiche d'origine ne les connaissait pas :
 *
 * - la naissance d'un vivant **réduite** par la réciprocité (ADR 0016) : bornes élargies à
 *   l'année ou au mois, qualificatif gardé (§ 5.4 bis du contrat) ;
 * - la naissance `masquee` dont seuls le jour et le mois sont partagés (« 12 mars »).
 *
 * Elles se lisent sur le qualificatif — « vers », « avant », « entre » restent ce qu'ils
 * sont. Toute date qui porte un `raw` suit, elle, le chemin de l'affiche d'origine à
 * l'identique.
 */

const MOIS: Record<string, number> = {
  JAN: 1, FEB: 2, MAR: 3, APR: 4, MAY: 5, JUN: 6, JUL: 7, AUG: 8, SEP: 9, OCT: 10, NOV: 11, DEC: 12,
}
export const MOIS_ABR = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']
export const MOIS_LONG = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
]

const SIMPLE = /^(?:(ABT|EST|CAL|BEF|AFT|FROM|TO|INT)\s+)?(?:(\d{1,2})\s+)?(?:([A-Z]{3})\s+)?(\d{3,4})$/
const INTERVALLE = /^(BET|FROM)\s+(.+?)\s+(AND|TO)\s+(.+)$/

/** (jour, mois, an) — jour et mois facultatifs. */
type Jma = readonly [number | null, number | null, number]
type Qualite = 'entre' | 'vers' | 'avant' | 'apres' | null

export type StyleDeDate = 'long' | 'court' | 'annee' | 'abr'

const QUALITES: Record<string, Qualite> = {
  ABT: 'vers', EST: 'vers', CAL: 'vers', BEF: 'avant', AFT: 'apres', FROM: 'apres', TO: 'avant', INT: null,
}

function jma(m: RegExpMatchArray): Jma {
  const j = m[2] ? Number.parseInt(m[2], 10) : null
  const mo = m[3] ? (MOIS[m[3]] ?? null) : null
  return [j, mo, Number.parseInt(m[4]!, 10)]
}

/** Une borne `AAAA-MM-JJ` décalée d'un jour, comme `modify('±1 day')` côté serveur. */
function decaler(iso: string, jours: number): string {
  const [a, m, j] = iso.split('-').map((x) => Number.parseInt(x, 10)) as [number, number, number]
  const d = new Date(Date.UTC(a, m - 1, j + jours))
  return d.toISOString().slice(0, 10)
}

function an(iso: string): number {
  return Number.parseInt(iso.slice(0, 4), 10)
}

export interface Partiel {
  jour?: number
  mois?: number
}

export class DateAffiche {
  readonly raw: string
  readonly qual: string

  constructor(
    raw: string | null,
    qual: string | null,
    readonly min: string | null,
    readonly max: string | null,
    /** Jour et mois d'une naissance `masquee` sans année (ADR 0016). */
    readonly partiel: Partiel | null = null,
  ) {
    this.raw = (raw ?? '').trim().toUpperCase()
    this.qual = qual || 'inconnue'
  }

  static inconnue(): DateAffiche {
    return new DateAffiche(null, 'inconnue', null, null)
  }

  get connue(): boolean {
    return Boolean(this.raw || this.min || this.max || this.partiel)
  }

  /** La clé de tri : la borne basse, sinon la haute. */
  get tri(): string | null {
    return this.min || this.max || null
  }

  get annee(): number | null {
    const d = this.min || this.max
    return d ? an(d) : null
  }

  /** (qualificatif, morceaux) — depuis la chaîne GEDCOM, sinon depuis les bornes. */
  private morceaux(): [Qualite, Jma[]] {
    const r = this.raw
    if (r) {
      const m = INTERVALLE.exec(r)
      if (m) {
        const a = SIMPLE.exec(m[2]!)
        const b = SIMPLE.exec(m[4]!)
        if (a && b) return ['entre', [jma(a), jma(b)]]
      }
      const s = SIMPLE.exec(r)
      if (s) return [s[1] ? (QUALITES[s[1]] ?? null) : null, [jma(s)]]
    } else {
      const lue = this.depuisLeQualificatif()
      if (lue !== null) return lue
    }
    if (this.min && this.max && this.min === this.max) {
      const [y, mo, d] = this.min.split('-').map((x) => Number.parseInt(x, 10)) as [number, number, number]
      return [null, [[d, mo, y]]]
    }
    if (this.min || this.max) {
      return [this.qual === 'environ' ? 'vers' : null, [[null, null, an((this.min || this.max)!)]]]
    }
    return [null, []]
  }

  /** Une date sans `raw` (réduite par la réciprocité) : la forme se lit sur le qualificatif. */
  private depuisLeQualificatif(): [Qualite, Jma[]] | null {
    const { min, max } = this
    const point = min || max
    switch (this.qual) {
      case 'exacte':
        if (!point) return null
        return this.morceauxDuJour(point)
      case 'mois': {
        if (!point) return null
        const [y, mo] = point.split('-').map((x) => Number.parseInt(x, 10)) as [number, number]
        return [null, [[null, mo, y]]]
      }
      case 'annee':
        return point ? [null, [[null, null, an(point)]]] : null
      case 'environ':
        return point ? ['vers', [[null, null, an(point)]]] : null
      case 'entre':
        if (!min || !max) return null
        return an(min) === an(max) ? [null, [[null, null, an(min)]]] : ['entre', [[null, null, an(min)], [null, null, an(max)]]]
      // `BEF X` est stocké `max = X − 1 jour`, `AFT X` `min = X + 1 jour` : on rend la borne
      // exclue, comme `UncertainDate::format()`.
      case 'avant':
        return max ? ['avant', [[null, null, an(decaler(max, 1))]]] : null
      case 'apres':
        return min ? ['apres', [[null, null, an(decaler(min, -1))]]] : null
      case 'depuis':
        return min ? ['apres', [[null, null, an(min)]]] : null
      case 'jusqu_a':
        return max ? ['avant', [[null, null, an(max)]]] : null
      default:
        return null
    }
  }

  private morceauxDuJour(iso: string): [Qualite, Jma[]] {
    const [y, mo, d] = iso.split('-').map((x) => Number.parseInt(x, 10)) as [number, number, number]
    return [null, [[d, mo, y]]]
  }

  /** « 25 mai 1946 », « v. 1745 », « av. 1735 », « 25.05.1946 », « 1946 ». */
  texte(style: StyleDeDate = 'long'): string {
    if (!this.connue) return ''
    if (!this.raw && !this.min && !this.max && this.partiel) return texteDuPartiel(this.partiel, style)
    const [q, parts] = this.morceaux()
    if (parts.length === 0) return this.raw.toLowerCase()
    if (q === 'entre') {
      const [a, b] = parts as [Jma, Jma]
      if (style === 'long') return `entre ${fmt(a, 'an')} et ${fmt(b, 'an')}`
      return `${a[2]}–${b[2]}`
    }
    const j = parts[0]!
    if (q === 'avant' || q === 'apres') {
      const mot = q === 'avant' ? 'av.' : 'ap.'
      return `${mot} ${fmt(j, style === 'long' ? 'abr' : 'an')}`
    }
    if (q === 'vers') return `${style === 'long' ? 'vers' : 'v.'} ${j[2]}`
    return fmt(j, ({ long: 'long', court: 'num', annee: 'an', abr: 'abr' } as const)[style])
  }
}

function deux(n: number): string {
  return String(n).padStart(2, '0')
}

function fmt(d: Jma, style: 'an' | 'num' | 'long' | 'abr'): string {
  const [j, mo, a] = d
  if (style === 'an' || mo === null) return String(a)
  if (style === 'num') return j ? `${deux(j)}.${deux(mo)}.${a}` : `${deux(mo)}.${a}`
  const nomMois = style === 'long' ? MOIS_LONG[mo - 1] : MOIS_ABR[mo - 1]
  if (j === null) return `${nomMois} ${a}`
  return `${j === 1 ? '1er' : j} ${nomMois} ${a}`
}

function texteDuPartiel(p: Partiel, style: StyleDeDate): string {
  if (style === 'annee') return ''
  if (style === 'court') {
    if (p.jour && p.mois) return `${deux(p.jour)}.${deux(p.mois)}`
    return p.mois ? deux(p.mois) : ''
  }
  const nom = p.mois ? (style === 'long' ? MOIS_LONG : MOIS_ABR)[p.mois - 1] : null
  if (p.jour && nom) return `${p.jour === 1 ? '1er' : p.jour} ${nom}`
  return nom ?? ''
}

/** Ce que `ligneVie` lit d'une personne. */
export interface Vie {
  naissance: DateAffiche
  deces: DateAffiche
  decede: boolean
}

/** « ° 25 mai 1946  † 12 juin 2009 » — vide si rien n'est connu ; « † » seul pour un défunt sans date. */
export function ligneVie(p: Vie, style: StyleDeDate = 'long', separateur = '  '): string {
  const n = p.naissance.texte(style)
  const d = p.deces.texte(style)
  const morceaux: string[] = []
  if (n) morceaux.push(`° ${n}`)
  if (d) morceaux.push(`† ${d}`)
  else if (p.decede) morceaux.push('†')
  return morceaux.join(separateur)
}

/* ------------------------------------------------------------------ */
/* Les noms                                                            */
/* ------------------------------------------------------------------ */

/** Espaces resserrés — et NFC : un « é » décomposé se mesurerait en deux glyphes, l'un hors police. */
const espaces = (s: string | null | undefined): string => (s ?? '').normalize('NFC').trim().replace(/\s+/g, ' ')

/** Un caractère qui a une casse — la notion de « cased » de Python. */
const casse = (c: string): boolean => c.toLowerCase() !== c.toUpperCase()

/**
 * `str.title()` de Python, à l'identique : une lettre prend la capitale quand celle qui la
 * précède n'a pas de casse (début, espace, trait d'union, apostrophe…). « LE CERFEUIL »
 * → « Le Cerfeuil », « D'ORIGAN » → « D'Origan ».
 */
export function titre(s: string): string {
  let sortie = ''
  let precedenteCassee = false
  for (const c of s) {
    if (casse(c)) {
      sortie += precedenteCassee ? c.toLowerCase() : c.toUpperCase()
      precedenteCassee = true
    } else {
      sortie += c
      precedenteCassee = false
    }
  }
  return sortie
}

/** `str.isupper()` : au moins une lettre à casse, et aucune minuscule. */
function enCapitales(s: string): boolean {
  return Array.from(s).some(casse) && s === s.toUpperCase()
}

/** NOM en capitales, particules en minuscules : « OBJOIS de la BRECHE ». */
export function normNom(s: string | null | undefined): string {
  const t = espaces(s)
  if (!t) return ''
  const mots = t.split(' ')
  const sortie: string[] = []
  mots.forEach((m, i) => {
    const bas = m.toLowerCase()
    const avant = sortie.length > 0 ? sortie[sortie.length - 1]!.toLowerCase() : ''
    const particule =
      mots.length > 1 &&
      i < mots.length - 1 &&
      (['de', 'du', 'des', "d'"].includes(bas) || (['la', 'le'].includes(bas) && avant === 'de'))
    sortie.push(particule ? bas : m.toUpperCase())
  })
  return sortie.join(' ')
}

/** Des prénoms saisis en capitales repassent en casse de titre ; les autres restent tels quels. */
export function normPrenoms(s: string | null | undefined): string {
  const t = espaces(s)
  return enCapitales(t) && Array.from(t).length > 1 ? titre(t) : t
}
