/**
 * **Les traits de `/arbre-2`** : unions, descentes, barres de fratrie et chutes vers les
 * enfants, une fois les fiches posées.
 *
 * C'est le tracé de `/arbre` (`histoire/lignees.ts`, « Les traits, famille par
 * famille »), repris ici tel quel plutôt qu'importé : `/arbre` ne doit pas bouger d'une
 * ligne, et ce tracé-là est enfoui dans `disposerLignees`. Les règles sont les mêmes —
 * une union contourne par le dessous quand des fiches séparent les époux, deux barres de
 * fratrie qui se chevaucheraient prennent chacune leur couloir, aucun trait vertical sur
 * un autre — et la sortie a la même forme (`TracesHistoire`), pour le même rendu.
 *
 * Ce qui change avec les rectangles : un lien entre deux groupes passe dans le couloir de
 * deux cartes qui les sépare (une union) ou dans l'interligne (une barre de fratrie).
 */
import type { NoeudFamille } from '@/lib/api'
import { nomDeFamilleDe, type EtiquetteFoyer, type FicheHistoire, type TracesHistoire } from '@/histoire/lignees'

const BARRE_DIRECTE = 12
const BARRE_ORDINAIRE = 10
const COULOIRS_DETOUR = 4
const COULOIRS_BARRE = 5
const ECART_VERTICAL = 8

export interface EntreeTraces {
  fiches: readonly FicheHistoire[]
  parId: ReadonlyMap<string, FicheHistoire>
  familles: ReadonlyMap<string, NoeudFamille>
  epouxDe: (famille: string) => readonly string[]
  enfantsDe: (famille: string) => readonly string[]
  /** Les couples de la lignée directe de l'ego. */
  famillesDirectes: ReadonlySet<string>
  /** Les arêtes en attente, en `de|vers`. */
  aretesEnAttente: ReadonlySet<string>
  hautRangee: (g: number) => number
  hauteurFiche: number
}

interface Intervalle {
  lo: number
  hi: number
  couloir: number
}

interface Trace {
  fam: string
  ancestrale: boolean
  style: 'attente' | 'divorces' | 'directs' | 'ordinaires'
  ep: FicheHistoire[]
  en: FicheHistoire[]
  detour: (Intervalle & { rangee: number; xa: number; xb: number }) | null
  milieuX: number | null
  milieuY: number
  portee: [number, number] | null
  barre: (Intervalle & { rangee: number }) | null
  departX: number
  plage: [number, number]
  chutes: Map<string, number>
  direct: FicheHistoire | undefined
}

/** Répartit des intervalles d'une même rangée en couloirs sans chevauchement. */
function repartir(liste: Intervalle[], ecart: number): void {
  const fins: number[] = []
  for (const it of [...liste].sort((a, b) => a.lo - b.lo)) {
    let c = fins.findIndex((fin) => fin + ecart <= it.lo)
    if (c < 0) {
      c = fins.length
      fins.push(it.hi)
    } else fins[c] = it.hi
    it.couloir = c
  }
}

const seg = (x1: number, y1: number, x2: number, y2: number): string =>
  `M${x1.toFixed(1)} ${y1.toFixed(1)}L${x2.toFixed(1)} ${y2.toFixed(1)}`

export function tracerLesLiens(entree: EntreeTraces): { traits: TracesHistoire; foyers: EtiquetteFoyer[] } {
  const { fiches, parId, familles, epouxDe, enfantsDe, famillesDirectes, aretesEnAttente, hautRangee, hauteurFiche } = entree
  const traits: TracesHistoire = { ordinaires: '', directs: '', divorces: '', attente: '', familles: [] }
  const parFamille = new Map<string, string>()
  const tracer = (cle: 'ordinaires' | 'directs' | 'divorces' | 'attente', s: string, fam: string): void => {
    traits[cle] += s
    parFamille.set(fam, (parFamille.get(fam) ?? '') + s)
  }
  const enAttente = (f: string, p: string): boolean => aretesEnAttente.has(`${f}|${p}`) || aretesEnAttente.has(`${p}|${f}`)
  const yDetour = (rangee: number, couloir: number): number =>
    hautRangee(rangee) + hauteurFiche + 6 + 5 * Math.min(couloir, COULOIRS_DETOUR - 1)
  const yBarre = (rangee: number, couloir: number, directe: boolean): number =>
    hautRangee(rangee) - (directe ? BARRE_DIRECTE : BARRE_ORDINAIRE) - 6 * Math.min(couloir, COULOIRS_BARRE - 1)

  /* Une fiche entre deux époux sur leur rangée ? (Dichotomie sur la rangée triée.) */
  const rangeesTriees = new Map<number, { debuts: number[]; finMax: number[] }>()
  {
    const parRangee = new Map<number, FicheHistoire[]>()
    for (const f of fiches) {
      const l = parRangee.get(f.rangee)
      if (l) l.push(f)
      else parRangee.set(f.rangee, [f])
    }
    for (const [g, l] of parRangee) {
      const triees = [...l].sort((a, b) => a.x - b.x)
      const finMax: number[] = []
      let m = -Infinity
      for (const f of triees) finMax.push((m = Math.max(m, f.x + f.w)))
      rangeesTriees.set(g, { debuts: triees.map((f) => f.x), finMax })
    }
  }
  const separes = (a: FicheHistoire, b: FicheHistoire): boolean => {
    const r = rangeesTriees.get(a.rangee)
    if (!r) return false
    let lo = 0
    let hi = r.debuts.length
    while (lo < hi) {
      const m = (lo + hi) >> 1
      if (r.debuts[m]! < b.x) lo = m + 1
      else hi = m
    }
    return lo > 0 && r.finMax[lo - 1]! > a.x + a.w
  }

  const foyers: EtiquetteFoyer[] = []
  const traces: Trace[] = []
  for (const [fam, noeudFamille] of familles) {
    const ancestrale = famillesDirectes.has(fam)
    const ep = epouxDe(fam)
      .map((e) => parId.get(e))
      .filter((e): e is FicheHistoire => e !== undefined)
      .sort((a, b) => a.cx - b.cx)
    const en = enfantsDe(fam)
      .map((e) => parId.get(e))
      .filter((e): e is FicheHistoire => e !== undefined)
    const style: Trace['style'] = ep.some((e) => enAttente(fam, e.id))
      ? 'attente'
      : noeudFamille.divorced
        ? 'divorces'
        : ancestrale
          ? 'directs'
          : 'ordinaires'

    let milieuX: number | null = null
    let milieuY = 0
    let portee: [number, number] | null = null
    let detour: Trace['detour'] = null
    if (ep.length >= 2) {
      const [a, b] = [ep[0]!, ep[ep.length - 1]!]
      const ya = a.y + a.h / 2
      if (a.rangee === b.rangee && separes(a, b)) {
        detour = { lo: a.cx, hi: b.cx, couloir: 0, rangee: a.rangee, xa: a.cx, xb: b.cx }
        milieuX = (a.cx + b.cx) / 2
        portee = [a.cx, b.cx]
      } else {
        const s = a.rangee === b.rangee ? seg(a.x + a.w, ya, b.x, ya) : seg(a.cx, a.y + a.h / 2, b.cx, b.y + b.h / 2)
        tracer(style, s, fam)
        milieuX = (a.x + a.w + b.x) / 2
        milieuY = ya
        if (a.rangee === b.rangee && b.x - (a.x + a.w) > 16) portee = [a.x + a.w + 8, b.x - 8]
      }
    } else if (ep.length === 1) {
      const p = ep[0]!
      milieuX = p.cx
      milieuY = p.y + p.h
      portee = [p.x + 12, p.x + p.w - 12]
    }

    if (ep.length && milieuX !== null) {
      const tete = [
        ...ep.filter((e) => e.noeud.sex === 'masculin'),
        ...ep.filter((e) => e.noeud.sex === 'feminin'),
        ...ep.filter((e) => e.noeud.sex !== 'masculin' && e.noeud.sex !== 'feminin'),
      ].find((e) => nomDeFamilleDe(e.noeud) !== '')
      if (tete) {
        const gauche = ep[0]!
        const droite = ep[ep.length - 1]!
        const [x, envergure] = detour ? [tete.cx, tete.w] : [milieuX, droite.x + droite.w - gauche.x]
        foyers.push({
          x,
          y: hautRangee(tete.rangee) - 8,
          nom: nomDeFamilleDe(tete.noeud),
          personne: tete.id,
          ancetres: ancestrale,
          envergure,
        })
      }
    }

    const direct = ancestrale ? en.find((e) => e.directe) : undefined
    let departX = milieuX ?? 0
    if (en.length && milieuX !== null) {
      const xs = en.map((e) => e.cx)
      const vise = direct ? direct.cx : (Math.min(...xs) + Math.max(...xs)) / 2
      departX = portee ? Math.min(portee[1], Math.max(portee[0], vise)) : milieuX
    }
    const [a0, b0] = [ep[0], ep[ep.length - 1]]
    const plage: [number, number] =
      portee ??
      (a0 && b0 && a0 !== b0 && a0.rangee === b0.rangee && b0.x - (a0.x + a0.w) >= 6
        ? [a0.x + a0.w + 3, b0.x - 3]
        : [departX, departX])
    const chutes = new Map(en.map((e) => [e.id, e.cx]))
    traces.push({ fam, ancestrale, style, ep, en, detour, milieuX, milieuY, portee, barre: null, departX, plage, chutes, direct })
  }

  /* Deux unions qui contournent sous la même fiche : leurs montants s'écartent. */
  const departsSous = new Map<string, number>()
  const montant = (f: FicheHistoire): number => {
    const k = departsSous.get(f.id) ?? 0
    departsSous.set(f.id, k + 1)
    const pas = Math.ceil(k / 2) * 10 * (k % 2 ? 1 : -1)
    return Math.min(f.x + f.w - 12, Math.max(f.x + 12, f.cx + pas))
  }
  for (const t of traces) {
    if (!t.detour) continue
    t.detour.xa = montant(t.ep[0]!)
    t.detour.xb = montant(t.ep[t.ep.length - 1]!)
  }

  /* Aucun trait vertical sur un autre. */
  const libre = (x: number, pris: { x: number; fam: string }[], fam: string): boolean =>
    pris.every((o) => o.fam === fam || Math.abs(o.x - x) >= ECART_VERTICAL)
  const auPlusPres = (x: number, [lo, hi]: [number, number], ok: (x: number) => boolean): number => {
    if (ok(x)) return x
    for (let k = 1; k * 2 <= hi - lo + ECART_VERTICAL * 40; k++) {
      for (const c of [x + k * 2, x - k * 2]) if (c >= lo && c <= hi && ok(c)) return c
    }
    return x
  }
  const espaceDe = (t: Trace): number => Math.min(...t.en.map((e) => e.rangee))
  const parEspace = new Map<number, Trace[]>()
  for (const t of traces) {
    if (!t.en.length || t.milieuX === null) continue
    const r = espaceDe(t)
    const l = parEspace.get(r)
    if (l) l.push(t)
    else parEspace.set(r, [t])
  }
  for (const liste of parEspace.values()) {
    const pris: { x: number; fam: string }[] = []
    for (const t of liste) for (const e of t.en) pris.push({ x: e.cx, fam: t.fam })
    for (const t of traces)
      if (t.detour && t.detour.rangee + 1 === espaceDe(liste[0]!))
        pris.push({ x: t.detour.xa, fam: t.fam }, { x: t.detour.xb, fam: t.fam })
    const descentes: { x: number; fam: string }[] = []
    for (const t of liste) {
      t.departX = auPlusPres(t.departX, t.plage, (x) => libre(x, pris, t.fam) && libre(x, descentes, t.fam))
      descentes.push({ x: t.departX, fam: t.fam })
    }
    for (const t of liste) {
      for (const e of t.en) {
        const plage: [number, number] = [e.x + 14, e.x + e.w - 14]
        t.chutes.set(e.id, auPlusPres(e.cx, plage, (x) => libre(x, descentes, t.fam)))
      }
    }
  }
  for (const t of traces) {
    if (!t.en.length || t.milieuX === null) continue
    const xs = [...t.chutes.values()]
    const seul = t.en.length === 1 ? t.en[0]! : null
    if (seul && Math.abs(t.chutes.get(seul.id)! - t.departX) < 0.5) continue
    t.barre = {
      lo: Math.min(t.departX, ...xs),
      hi: Math.max(t.departX, ...xs),
      couloir: 0,
      rangee: Math.min(...t.en.map((e) => e.rangee)),
    }
  }

  /* Une étiquette par personne et par rangée. */
  const unParPersonne = new Map<string, EtiquetteFoyer>()
  for (const e of foyers) {
    const cle = `${e.personne}|${e.y}`
    const deja = unParPersonne.get(cle)
    if (!deja || Number(e.ancetres) - Number(deja.ancetres) > 0 || (e.ancetres === deja.ancetres && e.envergure > deja.envergure))
      unParPersonne.set(cle, e)
  }

  /* Les couloirs, rangée par rangée. */
  const parRangee = <T extends { rangee: number }>(liste: T[]): Map<number, T[]> => {
    const m = new Map<number, T[]>()
    for (const it of liste) {
      const l = m.get(it.rangee)
      if (l) l.push(it)
      else m.set(it.rangee, [it])
    }
    return m
  }
  for (const liste of parRangee(traces.flatMap((t) => (t.detour ? [t.detour] : []))).values()) repartir(liste, 10)
  for (const liste of parRangee(traces.flatMap((t) => (t.barre ? [t.barre] : []))).values()) repartir(liste, 10)

  /* Le dessin. */
  for (const t of traces) {
    const { ep, en, detour, milieuX, direct, ancestrale, fam } = t
    let departY = t.milieuY
    if (detour) {
      const [a, b] = [ep[0]!, ep[ep.length - 1]!]
      const y = yDetour(detour.rangee, detour.couloir)
      tracer(t.style, seg(detour.xa, a.y + a.h, detour.xa, y) + seg(detour.xa, y, detour.xb, y) + seg(detour.xb, y, detour.xb, b.y + b.h), fam)
      departY = y
    }
    if (!en.length || milieuX === null) continue
    const cible = (s: string, directe: boolean, attente: boolean): void =>
      tracer(attente ? 'attente' : directe ? 'directs' : 'ordinaires', s, fam)
    if (!t.barre) {
      const seul = en[0]!
      cible(seg(t.departX, departY, t.departX, seul.y), ancestrale, enAttente(fam, seul.id))
      continue
    }
    const chute = (e: FicheHistoire): number => t.chutes.get(e.id) ?? e.cx
    const barre = yBarre(t.barre.rangee, t.barre.couloir, ancestrale)
    cible(seg(t.departX, departY, t.departX, barre), ancestrale, false)
    if (t.barre.hi > t.barre.lo) tracer('ordinaires', seg(t.barre.lo, barre, t.barre.hi, barre), fam)
    if (direct && Math.abs(chute(direct) - t.departX) >= 0.5)
      tracer('directs', seg(Math.min(t.departX, chute(direct)), barre, Math.max(t.departX, chute(direct)), barre), fam)
    for (const e of en) cible(seg(chute(e), barre, chute(e), e.y), e === direct, enAttente(fam, e.id))
  }
  for (const t of traces) {
    const d = parFamille.get(t.fam)
    if (d) traits.familles.push({ id: t.fam, d, personnes: [...t.ep, ...t.en].map((f) => f.id) })
  }

  return { traits, foyers: [...unParPersonne.values()] }
}
