/**
 * Un arbre **inventé** pour éprouver l'affiche — tests et vérification visuelle.
 *
 * ADR 0006 : aucune donnée réelle dans le dépôt, et pas de vrais noms déformés. Les prénoms
 * sont des minéraux, les patronymes des herbes et des plantes, comme partout ailleurs dans
 * les fixtures ; les dates sont tirées par un générateur à graine fixe. Rien ici n'est dérivé
 * d'un arbre existant : on a écrit la **forme** qu'il faut éprouver, pas un relevé.
 *
 * Ce que l'arbre porte, parce que c'est ce qui casse une affiche :
 *
 * - une ascendance profonde (dix générations) et lacunaire, donc des anneaux rayonnants ;
 * - un **implexe** : deux ancêtres frère et sœur, dont les parents occupent deux places ;
 * - des dates de toutes les formes (jour, année, « vers », « entre », « avant ») et des noms
 *   longs, à particule, ou absents ;
 * - une descendance sur cinq générations, avec un **remariage après veuvage**, un
 *   **divorce**, une **union sans mariage** et des **vivants**.
 */
import type { ArbreDAffiche, BlocDate, FamilleDAffiche, PersonneDAffiche, Sexe } from '@/lib/api'

/** Un générateur pseudo-aléatoire à graine fixe (mulberry32) : le même arbre à chaque appel. */
function generateur(graine: number): () => number {
  let a = graine >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

const PRENOMS_M = [
  'Natron', 'Pyroxène', 'Cobalt', 'Émeri', 'Lapis', 'Basalte', 'Quartz', 'Gypse', 'Jaspe', 'Onyx', 'Zircon',
  'Grenat', 'Silex', 'Granit', 'Schiste', 'Feldspath', 'Mica', 'Marbre', 'Rubis', 'Péridot', 'Calcite', 'Galène',
]
const PRENOMS_F = [
  'Marne', 'Opale', 'Agate', 'Ambre', 'Jade', 'Azurite', 'Sodalite', 'Tourmaline', 'Malachite', 'Turquoise',
  'Dolomite', 'Bérylite', 'Ardoise', 'Argile', 'Topaze', 'Améthyste', 'Perle', 'Nacre', 'Craie', 'Lave',
]
const NOMS = [
  'Romarin', 'Sureau', 'Muguet', 'Sarriette', 'Tilleul', 'Thym', 'Persil', 'Fenouil', 'Verveine', 'Cresson',
  'Marjolaine', 'Lichen', 'Lavande', 'Cerfeuil', 'Mélisse', 'Origan', 'Chanterelle', 'Basilic', 'Ciboulette',
  'Estragon', 'Aneth', 'Livèche', 'Hysope', 'Oseille', 'Pimprenelle', 'Tanaisie', 'Armoise', 'Camomille',
  'de la Sauge', 'Le Cerfeuil', 'Coriandre-Aneth', 'Pimprenelle de la Ciboulette',
]
const MOIS_GEDCOM = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']
const LIEUX = ['Saule, Ombrage, Sylvanie', 'Charmille, Ombrage, Sylvanie', 'Sorbière, Fougerie, Florie', 'Épinaille, Fougerie, Florie']

const deux = (n: number): string => String(n).padStart(2, '0')
const finDuMois = (a: number, m: number): number => new Date(Date.UTC(a, m, 0)).getUTCDate()

export const DATE_INCONNUE: BlocDate = { raw: null, qualifier: 'inconnue', min: null, max: null, place: null, texte: '' }

/** Une année seule. */
export function annee(a: number, place: string | null = null): BlocDate {
  return { raw: String(a), qualifier: 'annee', min: `${a}-01-01`, max: `${a}-12-31`, place, texte: String(a) }
}

/** Un jour exact. */
export function jour(a: number, m: number, j: number, place: string | null = null): BlocDate {
  const iso = `${a}-${deux(m)}-${deux(j)}`
  return { raw: `${j} ${MOIS_GEDCOM[m - 1]} ${a}`, qualifier: 'exacte', min: iso, max: iso, place, texte: iso }
}

/** Une date tirée au sort autour d'une année : jour, année, « vers », « entre », « avant ». */
function dateTiree(hasard: () => number, a: number, ancienne: boolean, place: string | null): BlocDate {
  const t = hasard()
  if (ancienne && t < 0.12) return { raw: `ABT ${a}`, qualifier: 'environ', min: `${a}-01-01`, max: `${a}-12-31`, place, texte: `vers ${a}` }
  if (ancienne && t < 0.18) {
    return { raw: `BET ${a - 3} AND ${a + 4}`, qualifier: 'entre', min: `${a - 3}-01-01`, max: `${a + 4}-12-31`, place, texte: `entre ${a - 3} et ${a + 4}` }
  }
  if (ancienne && t < 0.22) return { raw: `BEF ${a}`, qualifier: 'avant', min: null, max: `${a - 1}-12-31`, place, texte: `avant ${a}` }
  if (t < 0.45) return annee(a, place)
  const m = 1 + Math.floor(hasard() * 12)
  return jour(a, m, 1 + Math.floor(hasard() * finDuMois(a, m)), place)
}

interface Brouillon {
  id: number
  sexe: Sexe
  prenoms: string
  nom: string
  naissance: number
  deces: number | null
  /** Ni date de décès ni décès enregistré, et né après 1925 : vivant (§ 9.2, simplifié). */
  vivant: boolean
  restreinte?: boolean
}

/**
 * La fabrique : des personnes, des familles, et la charge que la route servirait.
 * `profondeur` borne l'ascendance ; `complet` ajoute l'implexe et la descendance riche.
 */
export class FabriqueDArbre {
  private readonly hasard: () => number
  private suivant = 1
  private suivanteFamille = 1
  readonly personnes = new Map<number, Brouillon>()
  readonly familles = new Map<number, { id: number; h: number | null; w: number | null; mariage: BlocDate; divorce: boolean; enfants: number[]; married?: boolean | null }>()
  private readonly dates = new Map<number, { naissance: BlocDate; deces: BlocDate }>()

  constructor(graine = 1789) {
    this.hasard = generateur(graine)
  }

  private choisir<T>(liste: readonly T[]): T {
    return liste[Math.floor(this.hasard() * liste.length)]!
  }

  personne(sexe: Sexe, nom: string, naissance: number, options: { prenoms?: string; deces?: number | null; vivant?: boolean; dates?: { naissance: BlocDate; deces: BlocDate } } = {}): number {
    const id = this.suivant++
    const ancienne = naissance < 1850
    const mort = options.deces !== undefined ? options.deces : naissance < 1925 ? naissance + 25 + Math.floor(this.hasard() * 60) : null
    const prenoms =
      options.prenoms ??
      (this.hasard() < 0.35
        ? `${this.choisir(sexe === 'feminin' ? PRENOMS_F : PRENOMS_M)} ${this.choisir(sexe === 'feminin' ? PRENOMS_F : PRENOMS_M)}`
        : this.choisir(sexe === 'feminin' ? PRENOMS_F : PRENOMS_M))
    this.personnes.set(id, { id, sexe, prenoms, nom, naissance, deces: mort, vivant: options.vivant ?? (mort === null && naissance >= 1925) })
    this.dates.set(
      id,
      options.dates ?? {
        naissance: dateTiree(this.hasard, naissance, ancienne, this.hasard() < 0.5 ? this.choisir(LIEUX) : null),
        deces: mort === null ? DATE_INCONNUE : dateTiree(this.hasard, mort, ancienne, null),
      },
    )
    return id
  }

  famille(h: number | null, w: number | null, mariage: BlocDate = DATE_INCONNUE, divorce = false): number {
    const id = this.suivanteFamille++
    this.familles.set(id, { id, h, w, mariage, divorce, enfants: [] })
    return id
  }

  enfant(famille: number, enfant: number): void {
    this.familles.get(famille)!.enfants.push(enfant)
  }

  masquer(id: number): void {
    this.personnes.get(id)!.restreinte = true
  }

  naissanceDe(id: number, bloc: BlocDate): void {
    this.dates.get(id)!.naissance = bloc
  }

  /** Une ascendance tirée au sort au-dessus de `id`, sur `profondeur` générations. */
  ascendance(id: number, profondeur: number, niveau = 1): void {
    if (profondeur <= 0) return
    const p = this.personnes.get(id)!
    // Les branches se ferment en remontant : c'est l'état normal d'un arbre (ADR 0006).
    if (niveau > 2 && this.hasard() > 0.93 - 0.035 * niveau) return
    const ecart = 24 + Math.floor(this.hasard() * 12)
    const pere = this.hasard() < 0.96 ? this.personne('masculin', p.sexe === 'masculin' || this.hasard() < 0.5 ? p.nom : this.choisir(NOMS), p.naissance - ecart) : null
    const mere = this.hasard() < 0.94 ? this.personne('feminin', this.choisir(NOMS), p.naissance - ecart + 2 - Math.floor(this.hasard() * 6)) : null
    if (pere === null && mere === null) return
    const f = this.famille(pere, mere, this.hasard() < 0.6 ? dateTiree(this.hasard, p.naissance - 1, p.naissance < 1850, this.choisir(LIEUX)) : DATE_INCONNUE)
    this.enfant(f, id)
    if (pere !== null) this.ascendance(pere, profondeur - 1, niveau + 1)
    if (mere !== null) this.ascendance(mere, profondeur - 1, niveau + 1)
  }

  /** La charge de `GET /api/arbre/familles/{id}/affiche`, enfants dans l'ordre du serveur. */
  charge(couple: number): ArbreDAffiche {
    const tri = (id: number): string => {
      const n = this.dates.get(id)!.naissance
      return n.min ?? n.max ?? '9999'
    }
    const personnes: PersonneDAffiche[] = [...this.personnes.values()]
      .sort((a, b) => a.id - b.id)
      .map((p) =>
        p.restreinte
          ? { id: p.id, visibilite: 'restreinte', displayName: 'Personne vivante', sex: p.sexe, vivant: true }
          : {
              id: p.id,
              visibilite: 'complete',
              givenNames: p.prenoms,
              surname: p.nom,
              sex: p.sexe,
              deceased: p.deces !== null || !p.vivant,
              vivant: p.vivant,
              birth: this.dates.get(p.id)!.naissance,
              death: this.dates.get(p.id)!.deces,
            },
      )
    const familles: FamilleDAffiche[] = [...this.familles.values()]
      .sort((a, b) => a.id - b.id)
      .map((f) => ({
        id: f.id,
        husband: f.h,
        wife: f.w,
        marriage: f.mariage,
        divorced: f.divorce,
        divorce: f.divorce ? DATE_INCONNUE : null,
        ...(f.married !== undefined ? { married: f.married } : {}),
        enfants: [...f.enfants]
          .map((id, rang) => ({ id, rang }))
          .sort((a, b) => (tri(a.id) < tri(b.id) ? -1 : tri(a.id) > tri(b.id) ? 1 : a.rang - b.rang || a.id - b.id))
          .map(({ id }) => ({ personId: id, relation: 'naissance' })),
      }))
    return { famille: couple, generationsDeDescendance: 6, personnes, familles }
  }
}

export interface ArbreDEssai {
  charge: ArbreDAffiche
  mari: number
  femme: number
  /** Quelques identifiants nommés, pour les tests. */
  reperes: Record<string, number>
}

/**
 * L'arbre complet : le couple Natron Romarin ∞ Marne Sureau (mariés en 1910), dix
 * générations d'ascendance, un implexe, et cinq générations de descendants.
 */
export function arbreFictif(graine = 1789): ArbreDEssai {
  const a = new FabriqueDArbre(graine)
  const mari = a.personne('masculin', 'Romarin', 1886, { prenoms: 'Natron Basalte Émeri', deces: 1958 })
  const femme = a.personne('feminin', 'Sureau', 1889, { prenoms: 'Marne Dolomite', deces: 1969 })
  const couple = a.famille(mari, femme, jour(1910, 5, 14, 'Saule, Ombrage, Sylvanie'))

  a.ascendance(mari, 10)
  a.ascendance(femme, 10)

  // L'implexe : le grand-père paternel de Natron et l'arrière-grand-mère maternelle de Marne
  // sont frère et sœur — leurs parents sont à deux places de l'ascendance.
  const reperes: Record<string, number> = {}
  const parentsDe = (id: number): FamilleDAffiche | undefined => a.charge(couple).familles.find((f) => f.enfants.some((e) => e.personId === id))
  const gpPaternel = parentsDe(parentsDe(mari)?.husband ?? -1)?.husband ?? null
  const mereDeMarne = parentsDe(femme)?.wife ?? null
  const agmMaternelle = mereDeMarne !== null ? (parentsDe(mereDeMarne)?.wife ?? null) : null
  if (gpPaternel !== null && agmMaternelle !== null) {
    const familleDuFrere = parentsDe(gpPaternel)
    const familleDeLaSoeur = parentsDe(agmMaternelle)
    if (familleDuFrere) {
      // La sœur quitte sa famille d'origine tirée au sort pour rejoindre celle de son frère.
      if (familleDeLaSoeur) {
        const f = a.familles.get(familleDeLaSoeur.id)!
        f.enfants = f.enfants.filter((e) => e !== agmMaternelle)
      }
      a.enfant(familleDuFrere.id, agmMaternelle)
      reperes['implexeFrere'] = gpPaternel
      reperes['implexeSoeur'] = agmMaternelle
    }
  }

  // ── La descendance ────────────────────────────────────────────────────────────────
  const aine = a.personne('masculin', 'Romarin', 1911, { prenoms: 'Cobalt Pyroxène', deces: 1979 })
  const cadette = a.personne('feminin', 'Romarin', 1914, { prenoms: 'Opale', deces: 1999 })
  const benjamin = a.personne('masculin', 'Romarin', 1918, { prenoms: 'Lapis Grenat Quartz Zircon Onyx', deces: 1987 })
  const sansDate = a.personne('feminin', 'Romarin', 1920, { prenoms: 'Jade', deces: null, vivant: false, dates: { naissance: DATE_INCONNUE, deces: DATE_INCONNUE } })
  for (const e of [aine, cadette, benjamin, sansDate]) a.enfant(couple, e)

  // L'aîné : veuf, puis remarié — « veuf » au-dessus du premier signe, rangs 1 et 2.
  const premiere = a.personne('feminin', 'Thym', 1913, { prenoms: 'Agate', deces: 1938 })
  const seconde = a.personne('feminin', 'Persil', 1916, { prenoms: 'Ambre Malachite', deces: 2002 })
  const u1 = a.famille(aine, premiere, jour(1934, 9, 3))
  const u2 = a.famille(aine, seconde, annee(1941))
  const petit1 = a.personne('masculin', 'Romarin', 1936, { prenoms: 'Silex', deces: null })
  const petit2 = a.personne('feminin', 'Romarin', 1943, { prenoms: 'Topaze Améthyste', deces: null })
  const petit3 = a.personne('masculin', 'Romarin', 1946, { prenoms: 'Granit', deces: 2015 })
  a.enfant(u1, petit1)
  a.enfant(u2, petit2)
  a.enfant(u2, petit3)

  // La cadette : divorcée — signe barré, « div. » — puis une union sans mariage (« & »).
  const ex = a.personne('masculin', 'Fenouil', 1911, { prenoms: 'Galène', deces: 1981 })
  const compagnon = a.personne('masculin', 'Cerfeuil', 1912, { prenoms: 'Feldspath', deces: 1994 })
  const ud = a.famille(ex, cadette, annee(1935), true)
  const ul = a.famille(compagnon, cadette)
  const petit4 = a.personne('feminin', 'Fenouil', 1937, { prenoms: 'Perle', deces: null })
  const petit5 = a.personne('masculin', 'Cerfeuil', 1949, { prenoms: 'Mica', deces: null })
  a.enfant(ud, petit4)
  a.enfant(ul, petit5)

  // Le benjamin : marié à une personne dont la fiche est confidentielle (hors cercle).
  const masquee = a.personne('feminin', 'Valériane', 1926, { prenoms: 'Obsidienne', deces: null })
  a.masquer(masquee)
  const ub = a.famille(benjamin, masquee, jour(1947, 4, 19, 'Charmille, Ombrage, Sylvanie'))
  const petit6 = a.personne('feminin', 'Romarin', 1949, { prenoms: 'Craie', deces: null })
  a.enfant(ub, petit6)

  // Les générations suivantes, vivantes.
  const conjoint1 = a.personne('feminin', 'Mélisse', 1939, { prenoms: 'Turquoise', deces: null })
  const u3 = a.famille(petit1, conjoint1, jour(1961, 10, 7))
  const arriere1 = a.personne('masculin', 'Romarin', 1964, { prenoms: 'Rubis', deces: null })
  const arriere2 = a.personne('feminin', 'Romarin', 1967, { prenoms: 'Sodalite', deces: null })
  a.enfant(u3, arriere1)
  a.enfant(u3, arriere2)
  const conjoint2 = a.personne('feminin', 'Origan', 1966, { prenoms: 'Argile', deces: null })
  const u4 = a.famille(arriere1, conjoint2, annee(1990))
  const aa1 = a.personne('masculin', 'Romarin', 1992, { prenoms: 'Péridot', deces: null })
  const aa2 = a.personne('feminin', 'Romarin', 1995, { prenoms: 'Azurite', deces: null })
  a.enfant(u4, aa1)
  a.enfant(u4, aa2)
  const conjoint3 = a.personne('masculin', 'Lavande', 1941, { prenoms: 'Schiste', deces: null })
  const u5 = a.famille(conjoint3, petit2, annee(1966))
  a.enfant(u5, a.personne('feminin', 'Lavande', 1969, { prenoms: 'Lave', deces: null }))

  // Une naissance réduite par la réciprocité (l'année seule, sans `raw`), et une naissance
  // dont seuls le jour et le mois sont partagés.
  a.naissanceDe(petit5, { raw: null, qualifier: 'annee', min: '1949-01-01', max: '1949-12-31', place: null, texte: '1949' })
  a.naissanceDe(petit6, { raw: null, qualifier: 'masquee', min: null, max: null, place: null, texte: '27 novembre', composantes: { jour: 27, mois: 11 } })

  Object.assign(reperes, { couple, aine, cadette, benjamin, sansDate, premiere, seconde, ex, compagnon, masquee, petit1, petit5, petit6, u1, u2, ud, ul, ub })
  return { charge: a.charge(couple), mari, femme, reperes }
}

/** Un couple sans aucune ascendance connue, et sans enfant. */
export function coupleSeul(): ArbreDEssai {
  const a = new FabriqueDArbre(7)
  const mari = a.personne('masculin', 'Tanaisie', 1880, { prenoms: 'Onyx', deces: 1950 })
  const femme = a.personne('feminin', 'Armoise', 1885, { prenoms: 'Craie', deces: 1960 })
  const couple = a.famille(mari, femme, annee(1905))
  return { charge: a.charge(couple), mari, femme, reperes: { couple } }
}
