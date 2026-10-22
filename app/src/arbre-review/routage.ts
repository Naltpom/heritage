/**
 * **Les traits de `/arbre-review`, routés dans des couloirs** — et la hauteur de chaque
 * couloir, qui en découle.
 *
 * Les fiches sont posées ; il reste à les relier sans qu'aucun trait passe sous une carte
 * ni ne se confonde avec un autre. Tous les traits sont ORTHOGONAUX :
 *
 *  - **l'union** : un trait court entre deux époux voisins, à mi-hauteur des fiches ; deux
 *    époux qu'une autre fiche sépare (un troisième mariage) se rejoignent par le dessous,
 *    dans le couloir ;
 *  - **la descente** : du milieu de l'union (de l'intervalle entre les époux, jamais sous
 *    une fiche) jusqu'au BUS de la fratrie ;
 *  - **le bus** : un trait horizontal dans le couloir entre les deux rangées, qui court
 *    au-dessus des enfants ; un enfant seul, sous ses parents, reçoit un trait droit ;
 *  - **les chutes** : du bus au haut de chaque enfant ;
 *  - un lien qui saute une génération descend par la place réservée entre deux fiches
 *    (le factice de l'ordre), couloir après couloir.
 *
 * **Les pistes.** Dans un couloir, deux bus qui se recouvrent prennent deux pistes. L'ordre
 * des pistes n'est pas indifférent : si le bus A passe au-dessus du bus B, les chutes de A
 * qui tombent dans l'étendue de B le croisent, et la descente de B qui part dans
 * l'étendue de A croise A. Pour chaque paire on compte les deux ordres, on range les bus
 * pour en croiser le moins (tri par préférence), puis chacun prend la plus haute piste
 * libre sous ceux qu'il doit laisser au-dessus. Le couloir prend la hauteur de ses
 * pistes : rien ne se tasse, un couloir chargé s'élargit.
 *
 * **Les traits verticaux** d'un couloir gardent un écart entre eux : une chute se décale
 * dans la largeur de sa fiche plutôt que de longer la descente d'une autre famille.
 *
 * La sortie a la forme de `/arbre` (`TracesHistoire`, `EtiquetteFoyer`) : même rendu,
 * mêmes zones de clic, mêmes styles (lignée directe, divorces, liens en attente).
 *
 * Pur, sans DOM.
 */
import { nomDeFamilleDe, type EtiquetteFoyer, type TracesHistoire } from '@/histoire/lignees'
import type { Abscisses } from './abscisses'
import type { Couches } from './ordre'
import { partiesVoisines, type Structure } from './preparation'

export interface OptionsDeRoutage {
  /** Le couloir le plus étroit entre deux rangées. */
  couloirMin: number
  /** L'écart entre deux pistes. */
  piste: number
  /** La marge entre une rangée et la première (ou la dernière) piste. */
  margePiste: number
  /** Le haut de la première rangée. */
  haut: number
  /** L'écart minimal entre deux traits verticaux d'un couloir. */
  ecartVertical: number
  /** Le retrait d'une chute depuis le bord de sa fiche. */
  retraitChute: number
}

export interface Routage {
  /** Le haut de chaque rangée (indice dans `couches.rangees`). */
  hautRangee: number[]
  hauteurRangee: number[]
  traits: TracesHistoire
  foyers: EtiquetteFoyer[]
  /** La hauteur du monde occupée par les rangées (bas de la dernière). */
  bas: number
}

type Style = 'attente' | 'divorces' | 'directs' | 'ordinaires'

/** Un intervalle horizontal d'un couloir : un bus, un détour d'union, un décrochement. */
interface Intervalle {
  couloir: number
  lo: number
  hi: number
  /** Les traits qui montent depuis la piste (vers la rangée du haut), et ceux qui descendent. */
  hauts: number[]
  bas: number[]
  famille: number
  piste: number
  /** Doit passer au-dessus de ces intervalles (une union qui contourne, au-dessus de son bus). */
  dessusDe: Intervalle[]
}

interface Chute {
  personne: number
  /** L'abscisse de la chute dans le couloir final, et la plage permise (la fiche). */
  x: number
  min: number
  max: number
  /** Les factices traversés, de haut en bas. */
  factices: number[]
}

interface TraceDeFamille {
  f: number
  style: Style
  ancestrale: boolean
  rangee: number
  /** Le point d'où part la descente : sur l'union (mi-hauteur), au bas d'une fiche, ou sur un détour. */
  depart: { x: number; min: number; max: number; depuis: 'union' | 'fiche' | 'detour' }
  union: { type: 'ligne'; x1: number; x2: number; rangee: number } | { type: 'detour'; xa: number; xb: number; intervalle: Intervalle } | null
  bus: Intervalle | null
  chutes: Chute[]
  /** Un enfant seul, sous l'union : un trait droit. */
  droit: boolean
  direct: number
  /** Les décrochements des liens longs : couloir → intervalle. */
  decrochements: { chute: Chute; intervalle: Intervalle; de: number; vers: number }[]
  /**
   * Les époux d'une AUTRE rangée (un couple que les générations n'alignent pas : un
   * implexe) : un trait descend de leur fiche jusqu'au couloir au-dessus de la rangée de la
   * famille, puis longe l'intervalle jusqu'au flanc de l'époux, qu'il rejoint à mi-hauteur
   * — comme une union, et jamais au travers d'une fiche.
   */
  rejoints: { personne: number; cible: number; x: number; intervalle: Intervalle; depart: Chute; arrivee: Chute }[]
  /** Les enfants posés sur la rangée de leurs parents (même cause) : un trait par le couloir du dessous. */
  remontees: { personne: number; intervalle: Intervalle }[]
}

export function router(s: Structure, c: Couches, a: Abscisses, o: OptionsDeRoutage): Routage {
  const { personnes, familles, rangee } = s
  const indiceDe = new Map(c.rangees.map((r, k) => [r, k]))
  const nR = c.rangees.length
  const hauteurRangee = c.rangees.map(() => s.hauteurFiche)
  const elementDe = (i: number): number => c.elementDuFoyer[s.foyerDe[i]!]!
  const gauche = (i: number): number => a.cx[i]! - (personnes[i]!.w || 132) / 2
  const droite = (i: number): number => a.cx[i]! + (personnes[i]!.w || 132) / 2
  const aretesEnAttente = new Set<string>()
  for (const ar of s.graphe.aretes) if (ar.enAttente) aretesEnAttente.add(`${ar.de}|${ar.vers}`)
  const enAttente = (f: number, i: number): boolean => {
    const fid = familles[f]!.id
    const pid = personnes[i]!.id
    return aretesEnAttente.has(`${fid}|${pid}`) || aretesEnAttente.has(`${pid}|${fid}`)
  }

  /* Les fiches de chaque rangée, triées : pour savoir si deux époux sont voisins. */
  const parRangee: number[][] = c.rangees.map(() => [])
  for (let i = 0; i < personnes.length; i++) parRangee[indiceDe.get(rangee[i]!)!]!.push(i)
  for (const l of parRangee) l.sort((p, q) => a.cx[p]! - a.cx[q]!)
  const rangDansRangee = new Int32Array(personnes.length)
  for (const l of parRangee) l.forEach((i, k) => (rangDansRangee[i] = k))

  const intervalles: Intervalle[] = []
  const nouvelIntervalle = (couloir: number, famille: number): Intervalle => {
    const it: Intervalle = { couloir, lo: Infinity, hi: -Infinity, hauts: [], bas: [], famille, piste: 0, dessusDe: [] }
    intervalles.push(it)
    return it
  }
  const etendre = (it: Intervalle, x: number): void => {
    it.lo = Math.min(it.lo, x)
    it.hi = Math.max(it.hi, x)
  }

  /* Les montants des détours, quand deux unions contournent sous la même fiche. */
  const montantsSous = new Map<number, number>()
  const montant = (i: number): number => {
    const k = montantsSous.get(i) ?? 0
    montantsSous.set(i, k + 1)
    const pas = Math.ceil(k / 2) * 12 * (k % 2 ? 1 : -1)
    return Math.min(droite(i) - o.retraitChute, Math.max(gauche(i) + o.retraitChute, a.cx[i]! + pas))
  }

  /* 1. La topologie de chaque famille : union, départ, bus, chutes. */
  const traces: TraceDeFamille[] = []
  familles.forEach((fam, f) => {
    if (!fam.epoux.length) return
    const ancestrale = s.famillesDirectes.has(f)
    const style: Style = fam.epoux.some((e) => enAttente(f, e))
      ? 'attente'
      : fam.noeud.divorced
        ? 'divorces'
        : ancestrale
          ? 'directs'
          : 'ordinaires'
    const rf = Math.max(...fam.epoux.map((e) => rangee[e]!))
    const kf = indiceDe.get(rf)!
    const ep = [...fam.epoux].filter((e) => rangee[e] === rf).sort((p, q) => a.cx[p]! - a.cx[q]!)
    const enfants = fam.enfants.filter((x) => rangee[x]! > rf)
    let union: TraceDeFamille['union'] = null
    let depart: TraceDeFamille['depart']
    if (ep.length >= 2) {
      const g = ep[0]!
      const d = ep[ep.length - 1]!
      const voisins = rangDansRangee[d]! - rangDansRangee[g]! === ep.length - 1
      if (voisins) {
        const x1 = droite(g)
        const x2 = gauche(d)
        union = { type: 'ligne', x1, x2, rangee: kf }
        const m = Math.min(8, Math.max(0, (x2 - x1) / 2 - 2))
        depart = { x: (x1 + x2) / 2, min: x1 + m, max: x2 - m, depuis: 'union' }
      } else {
        // Une fiche entre les époux : l'union contourne par le dessous, dans le couloir.
        const it = nouvelIntervalle(kf, f)
        const xa = montant(g)
        const xb = montant(d)
        etendre(it, xa)
        etendre(it, xb)
        it.hauts.push(xa, xb)
        union = { type: 'detour', xa, xb, intervalle: it }
        depart = { x: (xa + xb) / 2, min: Math.min(xa, xb) + 6, max: Math.max(xa, xb) - 6, depuis: 'detour' }
        // Un implexe (deux époux de rectangles lointains) : les enfants descendent du parent
        // de leur rectangle, pas du milieu d'une union qui traverse l'arbre.
        if (!partiesVoisines(s, s.partieDe[g]!, s.partieDe[d]!)) {
          const enfantsIci = fam.enfants.filter((x) => rangee[x]! > rf)
          const compte = (p: number): number => enfantsIci.filter((x) => s.partieDe[x] === s.partieDe[p]).length
          const porteur = compte(d) > compte(g) ? d : g
          depart = { x: a.cx[porteur]!, min: gauche(porteur) + o.retraitChute, max: droite(porteur) - o.retraitChute, depuis: 'fiche' }
        }
      }
    } else {
      const p = ep[0]!
      depart = { x: a.cx[p]!, min: gauche(p) + o.retraitChute, max: droite(p) - o.retraitChute, depuis: 'fiche' }
    }
    const direct = ancestrale ? (enfants.find((x) => s.sosa[x] !== null) ?? -1) : -1
    const t: TraceDeFamille = {
      f,
      style,
      ancestrale,
      rangee: kf,
      depart,
      union,
      bus: null,
      chutes: [],
      droit: false,
      direct,
      decrochements: [],
      rejoints: [],
      remontees: [],
    }
    traces.push(t)
    // Les époux d'une autre rangée, jusqu'au flanc de l'époux de cette rangée.
    for (const e of fam.epoux) {
      if (rangee[e] === rf || kf === 0) continue
      const cible = ep[0]!
      const x = a.cx[e]! < a.cx[cible]! ? gauche(cible) - 14 : droite(cible) + 14
      const it = nouvelIntervalle(kf - 1, f)
      etendre(it, a.cx[e]!)
      etendre(it, x)
      it.hauts.push(a.cx[e]!)
      it.bas.push(x)
      // Le trait part du bas de la fiche de l'époux, et arrive à côté du flanc de son
      // conjoint, dans l'écart qui le sépare de sa voisine ; chacun glissera (ci-dessous,
      // comme une chute) si un autre trait vertical du couloir passe déjà là.
      const depart: Chute = { personne: e, x: a.cx[e]!, min: gauche(e) + o.retraitChute, max: droite(e) - o.retraitChute, factices: [] }
      const ligne = parRangee[kf]!
      const voisine = ligne[rangDansRangee[cible]! + (x < a.cx[cible]! ? -1 : 1)]
      const arrivee: Chute =
        x < a.cx[cible]!
          ? { personne: cible, x, min: voisine !== undefined ? droite(voisine) + 6 : -Infinity, max: gauche(cible) - 6, factices: [] }
          : { personne: cible, x, min: droite(cible) + 6, max: voisine !== undefined ? gauche(voisine) - 6 : Infinity, factices: [] }
      t.rejoints.push({ personne: e, cible, x, intervalle: it, depart, arrivee })
    }
    // Les enfants de la même rangée (ou plus haut) : par le couloir du dessous.
    for (const x of fam.enfants) {
      if (rangee[x]! > rf) continue
      const it = nouvelIntervalle(kf, f)
      etendre(it, depart.x)
      etendre(it, a.cx[x]!)
      it.hauts.push(depart.x, a.cx[x]!)
      t.remontees.push({ personne: x, intervalle: it })
      if (union?.type === 'detour') union.intervalle.dessusDe.push(it)
    }
    if (!enfants.length || kf >= nR - 1) return

    // Les chutes : au centre de chaque enfant (ou, pour un lien long, au premier factice).
    for (const x of enfants) {
      const chaine = c.factices.get(`${f}|${x}`) ?? []
      const min = gauche(x) + o.retraitChute
      const max = droite(x) - o.retraitChute
      t.chutes.push({ personne: x, x: a.cx[x]!, min, max, factices: chaine })
    }
    // Le départ, au plus près du milieu des enfants : la descente raccourcit le bus.
    const premiers = t.chutes.map((ch) => (ch.factices.length ? a.x[ch.factices[0]!]! + c.elements[ch.factices[0]!]!.largeur / 2 : ch.x))
    const milieu = (Math.min(...premiers) + Math.max(...premiers)) / 2
    depart.x = Math.min(depart.max, Math.max(depart.min, milieu))
    // Un enfant seul, dans la portée du départ : le trait tombe droit.
    if (t.chutes.length === 1 && !t.chutes[0]!.factices.length) {
      const ch = t.chutes[0]!
      const lo = Math.max(ch.min, depart.min)
      const hi = Math.min(ch.max, depart.max)
      if (lo <= hi) {
        const x = Math.min(hi, Math.max(lo, ch.x))
        ch.x = x
        depart.x = x
        t.droit = true
      }
    }
    if (!t.droit) {
      const bus = nouvelIntervalle(kf, f)
      etendre(bus, depart.x)
      bus.hauts.push(depart.x)
      for (const x of premiers) {
        etendre(bus, x)
        bus.bas.push(x)
      }
      t.bus = bus
      if (union?.type === 'detour') union.intervalle.dessusDe.push(bus)
    } else if (union?.type === 'detour' && depart.depuis === 'detour') {
      // Le détour, puis le trait droit qui en descend.
      union.intervalle.bas.push(depart.x)
    }
    // Les liens longs : un décrochement dans chaque couloir où le factice change d'abscisse,
    // et dans le dernier, vers l'enfant.
    for (const ch of t.chutes) {
      if (!ch.factices.length) continue
      const xs = ch.factices.map((e) => a.x[e]! + c.elements[e]!.largeur / 2)
      for (let i = 0; i < xs.length; i++) {
        const de = xs[i]!
        const vers = i + 1 < xs.length ? xs[i + 1]! : Math.min(ch.max, Math.max(ch.min, de))
        if (i + 1 >= xs.length) ch.x = vers
        if (Math.abs(de - vers) < 0.5) continue
        const k = indiceDe.get(c.elements[ch.factices[i]!]!.rangee)!
        const it = nouvelIntervalle(k, f)
        etendre(it, de)
        etendre(it, vers)
        it.hauts.push(de)
        it.bas.push(vers)
        t.decrochements.push({ chute: ch, intervalle: it, de, vers })
      }
    }
  })

  /* 2. Les traits verticaux d'un couloir s'écartent : une chute glisse dans sa fiche. */
  {
    const parCouloir = new Map<number, { x: number; f: number; libre: { ch: Chute } | null }[]>()
    const ajouter = (k: number, x: number, f: number, libre: { ch: Chute } | null): void => {
      const l = parCouloir.get(k)
      if (l) l.push({ x, f, libre })
      else parCouloir.set(k, [{ x, f, libre }])
    }
    for (const t of traces) {
      ajouter(t.rangee, t.depart.x, t.f, null)
      for (const ch of t.chutes) {
        const k = ch.factices.length ? indiceDe.get(rangee[ch.personne]!)! - 1 : t.rangee
        if (!t.droit && !ch.factices.length) ajouter(k, ch.x, t.f, { ch })
        else ajouter(k, ch.x, t.f, null)
      }
      for (const r of t.rejoints) {
        ajouter(r.intervalle.couloir, r.depart.x, t.f, { ch: r.depart })
        ajouter(r.intervalle.couloir, r.arrivee.x, t.f, { ch: r.arrivee })
      }
    }
    for (const l of parCouloir.values()) {
      const fixes = l.filter((v) => !v.libre).sort((p, q) => p.x - q.x)
      const libres = l.filter((v) => v.libre).sort((p, q) => p.x - q.x)
      // Les traits déjà posés, triés sur x : une recherche dichotomique dit ce qui est près.
      const pris = fixes.map((v) => ({ x: v.x, f: v.f }))
      const inserer = (p: { x: number; f: number }): void => {
        let lo = 0
        let hi = pris.length
        while (lo < hi) {
          const m = (lo + hi) >> 1
          if (pris[m]!.x < p.x) lo = m + 1
          else hi = m
        }
        pris.splice(lo, 0, p)
      }
      const occupe = (x: number, f: number): boolean => {
        let lo = 0
        let hi = pris.length
        while (lo < hi) {
          const m = (lo + hi) >> 1
          if (pris[m]!.x < x - o.ecartVertical) lo = m + 1
          else hi = m
        }
        for (let i = lo; i < pris.length && pris[i]!.x < x + o.ecartVertical; i++) if (pris[i]!.f !== f) return true
        return false
      }
      for (const v of libres) {
        const ch = v.libre!.ch
        let x = ch.x
        if (occupe(x, v.f)) {
          for (let k = 1; k < 200; k++) {
            const essais = [x + k * 3, x - k * 3]
            const ok = essais.find((e) => e >= ch.min && e <= ch.max && !occupe(e, v.f))
            if (ok !== undefined) {
              x = ok
              break
            }
          }
        }
        ch.x = x
        inserer({ x, f: v.f })
      }
    }
    // Les traits des époux d'une autre rangée suivent leur départ.
    for (const t of traces)
      for (const r of t.rejoints) {
        r.x = r.arrivee.x
        const it = r.intervalle
        it.lo = Math.min(r.depart.x, r.x)
        it.hi = Math.max(r.depart.x, r.x)
        it.hauts = [r.depart.x]
        it.bas = [r.x]
      }
    // Les bus suivent leurs chutes.
    for (const t of traces) {
      if (!t.bus) continue
      t.bus.lo = Infinity
      t.bus.hi = -Infinity
      t.bus.bas = []
      etendre(t.bus, t.depart.x)
      for (const ch of t.chutes) {
        const x = ch.factices.length ? a.x[ch.factices[0]!]! + c.elements[ch.factices[0]!]!.largeur / 2 : ch.x
        etendre(t.bus, x)
        t.bus.bas.push(x)
      }
    }
  }

  /* 3. Les pistes, couloir par couloir. */
  const pistes: number[] = c.rangees.map(() => 0)
  {
    const parCouloir = new Map<number, Intervalle[]>()
    for (const it of intervalles) {
      const l = parCouloir.get(it.couloir)
      if (l) l.push(it)
      else parCouloir.set(it.couloir, [it])
    }
    const MARGE = 6
    const recouvre = (p: Intervalle, q: Intervalle): boolean => p.lo < q.hi + MARGE && q.lo < p.hi + MARGE
    const dedans = (x: number, it: Intervalle): boolean => x > it.lo + 0.5 && x < it.hi - 0.5
    /** Les croisements si `p` passe au-dessus de `q`. */
    const cout = (p: Intervalle, q: Intervalle): number => {
      let n = 0
      for (const x of p.bas) if (dedans(x, q)) n++
      for (const x of q.hauts) if (dedans(x, p)) n++
      // Deux traits d'une même famille ne se croisent pas : sans objet ici (intervalles de familles distinctes).
      return n
    }
    for (const [k, l] of parCouloir) {
      l.sort((p, q) => p.lo - q.lo || p.hi - q.hi)
      // Les voisins de chaque intervalle (ceux qui le recouvrent), par balayage.
      const voisins: number[][] = l.map(() => [])
      for (let i = 0; i < l.length; i++)
        for (let j = i + 1; j < l.length && l[j]!.lo < l[i]!.hi + MARGE; j++)
          if (recouvre(l[i]!, l[j]!)) {
            voisins[i]!.push(j)
            voisins[j]!.push(i)
          }
      // La préférence : pour chaque paire, ce que coûte « i au-dessus de j » de moins que l'inverse.
      const score = new Float64Array(l.length)
      const pref = new Map<number, number>()
      for (let i = 0; i < l.length; i++)
        for (const j of voisins[i]!) {
          if (j < i) continue
          let d = cout(l[i]!, l[j]!) - cout(l[j]!, l[i]!)
          // Une union qui contourne passe au-dessus de son propre bus.
          if (l[i]!.dessusDe.includes(l[j]!)) d = -1e6
          if (l[j]!.dessusDe.includes(l[i]!)) d = 1e6
          pref.set(i * l.length + j, d)
          score[i]! += d
          score[j]! -= d
        }
      // L'ordre vertical : d'abord ceux qui gagnent à passer dessus (tri par score), puis
      // chacun, à tour de rôle, se replace au meilleur rang parmi les autres (« tamisage ») :
      // descendre sous un voisin qui le recouvre change le compte de d(i, j).
      let ordre = l.map((_, i) => i).sort((p, q) => score[p]! - score[q]! || l[p]!.lo - l[q]!.lo)
      const d = (i: number, j: number): number => (i < j ? (pref.get(i * l.length + j) ?? 0) : -(pref.get(j * l.length + i) ?? 0))
      if (l.length > 2) {
        // Seuls les voisins (ceux qui le recouvrent) changent le compte : on ne parcourt
        // qu'eux, dans l'ordre courant, et l'on garde le rang entre deux voisins qui coûte
        // le moins (linéaire en voisins, et non en intervalles du couloir).
        const rangDe = new Int32Array(l.length)
        for (let tour = 0; tour < 3; tour++) {
          let bouge = false
          ordre.forEach((x, r) => (rangDe[x] = r))
          for (const i of [...ordre]) {
            const vs = voisins[i]!
            if (!vs.length) continue
            const tri = [...vs].sort((p, q) => rangDe[p]! - rangDe[q]!)
            const ancien = rangDe[i]!
            // i au-dessus de tous ses voisins, puis sous chacun, à tour de rôle.
            let cumul = 0
            let mieux = 0
            let apres = -1
            let coutAncien = 0
            for (let r = 0; r < tri.length; r++) {
              const j = tri[r]!
              if (rangDe[j]! < ancien) coutAncien -= d(i, j)
              cumul -= d(i, j)
              if (cumul < mieux - 1e-9) {
                mieux = cumul
                apres = r
              }
            }
            if (mieux >= coutAncien - 1e-9) continue
            // On le place juste sous le voisin choisi (ou juste au-dessus du premier).
            ordre.splice(ordre.indexOf(i), 1)
            const ancre = apres < 0 ? tri[0]! : tri[apres]!
            const k = ordre.indexOf(ancre)
            ordre.splice(apres < 0 ? k : k + 1, 0, i)
            ordre.forEach((x, r) => (rangDe[x] = r))
            bouge = true
          }
          if (!bouge) break
        }
      }
      const rang = new Int32Array(l.length)
      ordre.forEach((i, r) => (rang[i] = r))
      // Chacun, dans cet ordre, prend la piste sous tous ceux qui le recouvrent et sont déjà posés.
      let maxPiste = 0
      for (const i of ordre) {
        let p = 0
        for (const j of voisins[i]!) if (rang[j]! < rang[i]!) p = Math.max(p, l[j]!.piste + 1)
        l[i]!.piste = p
        maxPiste = Math.max(maxPiste, p + 1)
      }
      pistes[k] = maxPiste
    }
  }

  /* 4. La hauteur des couloirs, puis le haut de chaque rangée. */
  const hauteurCouloir = (k: number): number => {
    const besoin = 2 * o.margePiste + Math.max(0, pistes[k]! - 1) * o.piste
    return Math.max(o.couloirMin, besoin)
  }
  const hautRangee: number[] = []
  let y = o.haut
  for (let k = 0; k < nR; k++) {
    hautRangee.push(y)
    y += hauteurRangee[k]! + (k + 1 < nR ? hauteurCouloir(k) : 0)
  }
  // Sous la dernière rangée, un couloir seulement s'il porte quelque chose (un détour d'union).
  const dernier = nR ? hautRangee[nR - 1]! + hauteurRangee[nR - 1]! : o.haut
  const bas = nR && pistes[nR - 1]! > 0 ? dernier + hauteurCouloir(nR - 1) : dernier
  /** L'ordonnée d'une piste : les pistes d'un couloir, centrées entre ses deux rangées. */
  const yPiste = (k: number, piste: number): number => {
    const debut = hautRangee[k]! + hauteurRangee[k]!
    const fin = k + 1 < nR ? hautRangee[k + 1]! : debut + hauteurCouloir(k)
    const n = Math.max(1, pistes[k]!)
    const milieu = (debut + fin) / 2
    return milieu - ((n - 1) * o.piste) / 2 + piste * o.piste
  }

  /* 5. Le dessin. */
  const traits: TracesHistoire = { ordinaires: '', directs: '', divorces: '', attente: '', familles: [] }
  const parFamille = new Map<number, string>()
  const tracer = (cle: Style, d: string, f: number): void => {
    traits[cle] += d
    parFamille.set(f, (parFamille.get(f) ?? '') + d)
  }
  const seg = (x1: number, y1: number, x2: number, y2: number): string =>
    `M${x1.toFixed(1)} ${y1.toFixed(1)}L${x2.toFixed(1)} ${y2.toFixed(1)}`
  for (const t of traces) {
    const k = t.rangee
    const milieuFiche = hautRangee[k]! + s.hauteurFiche / 2
    const basFiche = hautRangee[k]! + hauteurRangee[k]!
    let departY = basFiche
    if (t.union?.type === 'ligne') {
      tracer(t.style, seg(t.union.x1, milieuFiche, t.union.x2, milieuFiche), t.f)
      departY = milieuFiche
    } else if (t.union?.type === 'detour') {
      const yd = yPiste(k, t.union.intervalle.piste)
      tracer(t.style, seg(t.union.xa, basFiche, t.union.xa, yd) + seg(t.union.xa, yd, t.union.xb, yd) + seg(t.union.xb, yd, t.union.xb, basFiche), t.f)
      // (Un implexe : les enfants partent du bas de la fiche de leur parent, pas du détour.)
      departY = t.depart.depuis === 'detour' ? yd : basFiche
    }
    // Les époux d'une autre rangée : de leur fiche au flanc de l'époux, par le couloir du dessus.
    for (const r of t.rejoints) {
      const ke = indiceDe.get(rangee[r.personne]!)!
      const yHaut = hautRangee[ke]! + hauteurRangee[ke]!
      const yp = yPiste(r.intervalle.couloir, r.intervalle.piste)
      const xc = r.depart.x
      const flanc = r.x < a.cx[r.cible]! ? gauche(r.cible) : droite(r.cible)
      tracer(t.style, seg(xc, yHaut, xc, yp) + seg(xc, yp, r.x, yp) + seg(r.x, yp, r.x, milieuFiche) + seg(r.x, milieuFiche, flanc, milieuFiche), t.f)
    }
    // Les enfants de la même rangée : du départ, par le couloir du dessous, jusqu'au bas de leur fiche.
    for (const r of t.remontees) {
      const yp = yPiste(r.intervalle.couloir, r.intervalle.piste)
      const kx = indiceDe.get(rangee[r.personne]!)!
      const yBasEnfant = hautRangee[kx]! + hauteurRangee[kx]!
      const xe = a.cx[r.personne]!
      tracer(t.style, seg(t.depart.x, departY, t.depart.x, yp) + seg(t.depart.x, yp, xe, yp) + seg(xe, yp, xe, yBasEnfant), t.f)
    }
    if (!t.chutes.length) continue
    const enfantDirect = (x: number): boolean => x === t.direct
    const styleChute = (x: number): Style => (enAttente(t.f, x) ? 'attente' : enfantDirect(x) ? 'directs' : 'ordinaires')
    const styleDescente: Style = t.ancestrale ? 'directs' : 'ordinaires'
    if (t.droit) {
      const ch = t.chutes[0]!
      const yEnfant = hautRangee[indiceDe.get(rangee[ch.personne]!)!]!
      tracer(enAttente(t.f, ch.personne) ? 'attente' : styleDescente, seg(t.depart.x, departY, t.depart.x, yEnfant), t.f)
      continue
    }
    const bus = t.bus!
    const yb = yPiste(k, bus.piste)
    tracer(styleDescente, seg(t.depart.x, departY, t.depart.x, yb), t.f)
    // Le tronçon de la lignée directe (de la descente à la chute de l'enfant direct) se
    // trace dans son style, et la barre ordinaire couvre le reste seulement : jamais deux
    // fois le même tronçon (le dessin est le même, mais le chemin de la famille, que
    // relisent la surbrillance et le banc, n'a plus de doublon).
    let directLo = Infinity
    let directHi = -Infinity
    if (t.direct >= 0) {
      const ch = t.chutes.find((x) => x.personne === t.direct)
      const xd = ch ? (ch.factices.length ? a.x[ch.factices[0]!]! + c.elements[ch.factices[0]!]!.largeur / 2 : ch.x) : t.depart.x
      if (Math.abs(xd - t.depart.x) >= 0.5) {
        directLo = Math.min(t.depart.x, xd)
        directHi = Math.max(t.depart.x, xd)
        tracer('directs', seg(directLo, yb, directHi, yb), t.f)
      }
    }
    if (bus.hi > bus.lo) {
      if (directHi < directLo) tracer('ordinaires', seg(bus.lo, yb, bus.hi, yb), t.f)
      else {
        if (bus.lo < directLo - 0.5) tracer('ordinaires', seg(bus.lo, yb, Math.min(bus.hi, directLo), yb), t.f)
        if (bus.hi > directHi + 0.5) tracer('ordinaires', seg(Math.max(bus.lo, directHi), yb, bus.hi, yb), t.f)
      }
    }
    for (const ch of t.chutes) {
      const st = styleChute(ch.personne)
      const ke = indiceDe.get(rangee[ch.personne]!)!
      const yEnfant = hautRangee[ke]!
      if (!ch.factices.length) {
        tracer(st, seg(ch.x, yb, ch.x, yEnfant), t.f)
        continue
      }
      // Le lien long : de piste en piste, par les factices.
      let x = a.x[ch.factices[0]!]! + c.elements[ch.factices[0]!]!.largeur / 2
      let yCourant = yb
      for (const dc of t.decrochements.filter((d) => d.chute === ch)) {
        const kd = dc.intervalle.couloir
        const yd = yPiste(kd, dc.intervalle.piste)
        tracer(st, seg(x, yCourant, x, yd) + seg(dc.de, yd, dc.vers, yd), t.f)
        x = dc.vers
        yCourant = yd
      }
      tracer(st, seg(x, yCourant, x, yEnfant), t.f)
    }
  }
  for (const t of traces) {
    const d = parFamille.get(t.f)
    if (d) traits.familles.push({ id: familles[t.f]!.id, d, personnes: [...familles[t.f]!.epoux, ...familles[t.f]!.enfants].map((i) => personnes[i]!.id) })
  }

  /* 6. Les noms de foyer (niveau Familles) : un par personne et par rangée. */
  const etiquettes = new Map<string, EtiquetteFoyer>()
  for (const t of traces) {
    const fam = familles[t.f]!
    const ep = [...fam.epoux].filter((e) => rangee[e] === c.rangees[t.rangee]).sort((p, q) => a.cx[p]! - a.cx[q]!)
    if (!ep.length) continue
    const tete = [
      ...ep.filter((e) => personnes[e]!.sex === 'masculin'),
      ...ep.filter((e) => personnes[e]!.sex === 'feminin'),
      ...ep.filter((e) => personnes[e]!.sex !== 'masculin' && personnes[e]!.sex !== 'feminin'),
    ].find((e) => nomDeFamilleDe(personnes[e]!) !== '')
    if (tete === undefined) continue
    const g = ep[0]!
    const d = ep[ep.length - 1]!
    const detour = t.union?.type === 'detour'
    const e: EtiquetteFoyer = {
      x: detour ? a.cx[tete]! : ep.length >= 2 ? (droite(g) + gauche(d)) / 2 : a.cx[g]!,
      y: hautRangee[t.rangee]! - 8,
      nom: nomDeFamilleDe(personnes[tete]!),
      personne: personnes[tete]!.id,
      ancetres: t.ancestrale,
      envergure: detour ? personnes[tete]!.w || 132 : droite(d) - gauche(g),
    }
    const cle = `${e.personne}|${e.y}`
    const deja = etiquettes.get(cle)
    if (!deja || Number(e.ancetres) - Number(deja.ancetres) > 0 || (e.ancetres === deja.ancetres && e.envergure > deja.envergure)) etiquettes.set(cle, e)
  }

  void elementDe
  return { hautRangee, hauteurRangee, traits, foyers: [...etiquettes.values()], bas }
}
