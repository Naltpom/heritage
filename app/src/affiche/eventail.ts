/**
 * L'éventail de l'ascendance d'un couple — portage de `eventail.py`.
 *
 * Le couple occupe le premier anneau (lui à gauche, elle à droite) ; chaque anneau suivant
 * est une génération. Les angles ne sont pas fixés par le numéro sosa (1/2ⁿ) : chaque place
 * reçoit au moins l'angle qu'il faut à son texte, et le reste est réparti en gardant
 * l'éventail aussi régulier que possible. Sans cela, au-delà de huit générations, un texte
 * lisible demanderait des mètres de rayon pour des cases restées vides.
 *
 * Les quatre premiers anneaux écrivent **le long de l'arc** (tangentiels) ; au-delà, le
 * texte **rayonne** du centre. Le corps des anneaux tangentiels est fixe : quand les textes
 * ne tiennent pas dans le demi-cercle, c'est le disque central qui grandit
 * (`calculer()`, par dichotomie sur le facteur `k`), ce qui écarte tous les anneaux et
 * élargit d'autant les cases des générations lointaines.
 */
import { ajuster, Bloc, type Ligne } from './boites'
import { ligneVie, type StyleDeDate } from './dates'
import { ENCRE, ENCRE_DOUCE, melange, PAPIER, TEINTES } from './palette'
import { Style } from './polices'
import { f3, secteur, type Scene } from './scene'
import type { Anc } from './structure'

export class Reglages {
  /** Ouverture de l'éventail (degrés). */
  angle = 180.0
  /** Rayon du disque central (mm). */
  rCentre = 36.0
  /** Corps (pt) des anneaux tangentiels 0 à 3. */
  corpsTan = [21.0, 12.5, 10.0, 8.8]
  datesTan: StyleDeDate[] = ['long', 'long', 'abr', 'abr']
  /** Corps des anneaux rayonnants… */
  corpsRad = 7.2
  /** … sauf exceptions. */
  corpsRadNiveau: Record<number, number> = { 4: 8.2, 5: 7.6 }
  datesRad: StyleDeDate = 'abr'
  /** Marge le long du rayon (mm). */
  margeRad = 1.6
  /** Marge de part et d'autre des lignes rayonnantes (mm). */
  margePerp = 0.55
  /** Marge aux extrémités des lignes tangentielles (mm). */
  margeTan = 2.0
  /** 0 : partage égal, 1 : proportionnel à la place requise. */
  gamma = 0.6
  /** Facteur appliqué aux corps des anneaux tangentiels. */
  croissanceMax = 1.3
  /** Épaisseur d'anneau rayonnant : ce quantile des longueurs de texte. */
  percentile = 0.96
  /** 2 : « Prénoms NOM » / dates ; 3 : prénoms / NOM / dates. */
  lignesRad: 2 | 3 = 2

  constructor(reglages: Partial<Reglages> = {}) {
    Object.assign(this, reglages)
  }

  get niveauxTan(): number {
    return this.corpsTan.length
  }
}

function styles(corps: number): [Style, Style, Style] {
  return [new Style('SS', corps, ENCRE, 0, 'Reg'), new Style('SS', corps, ENCRE, 0.045, 'Semi'), new Style('SS', corps * 0.86, ENCRE_DOUCE, 0, 'Ital')]
}

function lignes(n: Anc, st: [Style, Style, Style], dates: StyleDeDate, nb: 2 | 3 = 3): Ligne[] {
  const p = n.p
  const nom = p.nom + (n.implexe ? ` ◆${n.implexe}` : '') + (p.probable ? ' ?' : '')
  if (nb === 2) {
    return [
      [`${p.prenoms} ${nom}`.trim(), new Style('SS', st[0].taille, ENCRE, 0, 'Semi')],
      [ligneVie(p, dates), st[2]],
    ]
  }
  return [
    [p.prenoms, st[0]],
    [nom, st[1]],
    [ligneVie(p, dates), st[2]],
  ]
}

/*
 * `math.degrees()` et `math.radians()` de CPython : **une** multiplication par la constante
 * précalculée. `rad * 180 / π` arrondit deux fois, et l'éventail tranche des égalités au
 * dernier bit près (voir `MetriquesDePolice.avance`).
 */
const VERS_DEGRES = 180 / Math.PI
const VERS_RADIANS = Math.PI / 180
const degres = (rad: number): number => rad * VERS_DEGRES
export const radians = (deg: number): number => deg * VERS_RADIANS

/** Un anneau : rayons intérieur et extérieur, orientation du texte, prénoms coupables. */
export interface Anneau {
  r1: number
  r2: number
  orientation: 'tan' | 'rad'
  coupe: boolean
}

/** La variante coupée d'un bloc — `null` si le bloc n'a pas de première ligne à couper. */
function variantes(b: Bloc, coupe: boolean): Bloc[] {
  const sortie = [b]
  if (coupe && b.lignes.length > 0) {
    const v = b.coupePrenoms()
    if (v) sortie.push(v)
  }
  return sortie
}

export class Eventail {
  readonly R: Reglages
  readonly parNiveau = new Map<number, Anc[]>()
  readonly nbNiveaux: number
  k = 1
  rC = 0
  rExt = 0
  anneaux: Anneau[] = []

  constructor(
    readonly h: Anc,
    readonly f: Anc,
    readonly noeuds: Anc[],
    reglages?: Reglages,
  ) {
    this.R = reglages ?? new Reglages()
    for (const n of noeuds) this.parNiveau.set(n.niveau, [...(this.parNiveau.get(n.niveau) ?? []), n])
    this.nbNiveaux = Math.max(...this.parNiveau.keys()) + 1
  }

  /* ---------------------------------------------------------------- géométrie */

  /** Blocs de texte et rayons pour un facteur de croissance `k` du disque central. */
  private preparer(k: number): void {
    const R = this.R
    this.k = k
    // Le corps des anneaux tangentiels est fixe ; seul le disque central grandit.
    const croissance = R.croissanceMax
    this.rC = R.rCentre + (k - 1.0) * 120
    this.anneaux = []
    let r = this.rC
    for (let g = 0; g < this.nbNiveaux; g++) {
      const ns = this.parNiveau.get(g) ?? []
      if (g < R.niveauxTan) {
        const st = styles(R.corpsTan[g]! * croissance)
        for (const n of ns) n.bloc = new Bloc(lignes(n, st, R.datesTan[g]!), 0, 0)
        const coupe = g >= 2
        const nb = coupe ? 4 : 3
        const epaisseur = st[0].interligne * (nb - 2) + st[1].interligne + st[2].interligne + 2.4 * (1 + g * 0.1)
        this.anneaux.push({ r1: r, r2: r + epaisseur, orientation: 'tan', coupe })
      } else {
        const st = styles(R.corpsRadNiveau[g] ?? R.corpsRad)
        const longueurs: number[] = []
        for (const n of ns) {
          n.bloc = new Bloc(lignes(n, st, R.datesRad, R.lignesRad), 0, 0)
          longueurs.push(n.bloc.largeurTexte)
        }
        longueurs.sort((a, b) => a - b)
        const q = longueurs[Math.min(longueurs.length - 1, Math.floor(R.percentile * longueurs.length))] ?? 0
        this.anneaux.push({ r1: r, r2: r + q + 2 * R.margeRad, orientation: 'rad', coupe: false })
      }
      r = this.anneaux[this.anneaux.length - 1]!.r2
    }
    this.rExt = r
  }

  /** L'angle minimal (degrés) que réclame le texte de la place `n`. */
  private besoin(n: Anc): number {
    const { r1, r2, orientation, coupe } = this.anneaux[n.niveau]!
    const R = this.R
    if (orientation === 'rad') return degres((n.bloc!.hauteurTexte + 2 * R.margePerp) / r1)
    let meilleur: number | null = null
    for (const b of variantes(n.bloc!, coupe)) {
      let besoin = 0
      const bases = this.bases(b, r1, r2)
      b.lignes.forEach(([t, s], i) => {
        besoin = Math.max(besoin, (s.largeur(t) + 2 * R.margeTan) / bases[i]!)
      })
      meilleur = meilleur === null ? besoin : Math.min(meilleur, besoin)
    }
    return degres(meilleur ?? 0)
  }

  /** Les rayons des lignes de base, de l'extérieur vers l'intérieur, bloc centré dans l'anneau. */
  private bases(b: Bloc, r1: number, r2: number): number[] {
    let haut = (r1 + r2) / 2 + b.hauteurTexte / 2
    const sortie: number[] = []
    for (const [, s] of b.lignes) {
      sortie.push(haut - s.interligne * 0.8)
      haut -= s.interligne
    }
    return sortie
  }

  private minimums(n: Anc): number {
    const ps = n.parents.map((p) => this.minimums(p))
    n.minimum = Math.max(this.besoin(n), ps.reduce((a, b) => a + b, 0))
    return n.minimum
  }

  private total(k: number): number {
    this.preparer(k)
    return this.minimums(this.h) + this.minimums(this.f)
  }

  /** Le plus petit facteur de croissance pour lequel tous les textes tiennent, puis les angles. */
  calculer(): this {
    const ouverture = this.R.angle * 0.985
    let lo = 1.0
    let hi = 1.0
    let k: number
    if (this.total(lo) > ouverture) {
      hi = 2.0
      // Garde-fou : le disque grandit sans borne dans le script d'origine. Soixante
      // multiplications par 1,6 dépassent de très loin tout arbre réel.
      for (let i = 0; i < 60 && this.total(hi) > ouverture; i++) hi *= 1.6
      for (let i = 0; i < 30; i++) {
        const mid = (lo + hi) / 2
        if (this.total(mid) > ouverture) lo = mid
        else hi = mid
      }
      k = hi
    } else {
      k = 1.0
    }
    this.total(k)
    const debut = 90 - this.R.angle / 2
    const fin = 90 + this.R.angle / 2
    const ah = this.part(this.R.angle, this.h.minimum, this.f.minimum)
    this.repartir(this.h, fin - ah, fin)
    this.repartir(this.f, debut, fin - ah)
    return this
  }

  private part(A: number, mGauche: number, mDroite: number): number {
    const g = this.R.gamma
    const wg = mGauche ** g
    const wd = mDroite ** g
    // Deux places sans texte (ancêtres sans nom ni date) : partage égal plutôt qu'un 0/0.
    const cible = wg + wd > 0 ? (A * wg) / (wg + wd) : A / 2
    return Math.min(Math.max(cible, mGauche), A - mDroite)
  }

  private repartir(n: Anc, a1: number, a2: number): void {
    n.a1 = a1
    n.a2 = a2
    const A = a2 - a1
    if (n.pere && n.mere) {
      const ap = this.part(A, n.pere.minimum, n.mere.minimum)
      this.repartir(n.pere, a2 - ap, a2)
      this.repartir(n.mere, a1, a2 - ap)
    } else if (n.pere) {
      const ap = Math.min(A, Math.max(A / 2, n.pere.minimum))
      this.repartir(n.pere, a2 - ap, a2)
    } else if (n.mere) {
      const am = Math.min(A, Math.max(A / 2, n.mere.minimum))
      this.repartir(n.mere, a1, a1 + am)
    }
  }

  /* ---------------------------------------------------------------- dessin */

  fond(n: Anc): string {
    const [, rgb] = TEINTES[n.quartier ?? (n.sosa === 2 ? 'H' : 'F')]
    if (n.niveau === 0) return melange(rgb, 1.0)
    let k = 0.95 - (0.55 * (n.niveau - 1)) / Math.max(this.nbNiveaux - 2, 1)
    if (n.p.probable) k *= 0.4
    return melange(rgb, k)
  }

  dessiner(scene: Scene, cx: number, cy: number): void {
    const R = this.R
    // Le disque central.
    scene.chemin(secteur(cx, cy, 0, this.rC, 90 - R.angle / 2, 90 + R.angle / 2), melange([222, 205, 168], 1), PAPIER, 0.6)
    for (const n of [...this.noeuds].sort((a, b) => a.niveau - b.niveau)) {
      const { r1, r2 } = this.anneaux[n.niveau]!
      scene.chemin(secteur(cx, cy, r1, r2, n.a1, n.a2), this.fond(n), PAPIER, n.niveau < 4 ? 0.7 : 0.45)
    }
    for (const n of this.noeuds) {
      const { r1, r2, orientation, coupe } = this.anneaux[n.niveau]!
      if (orientation === 'tan') this.texteTan(scene, cx, cy, n, r1, r2, coupe)
      else this.texteRad(scene, cx, cy, n, r1, r2)
    }
  }

  private texteTan(scene: Scene, cx: number, cy: number, n: Anc, r1: number, r2: number, coupe: boolean): void {
    const R = this.R
    const A = radians(n.a2 - n.a1)
    const vs = variantes(n.bloc!, coupe)
    // Une place reçoit souvent **exactement** l'angle que réclame sa variante la plus sobre :
    // la comparaison tombe alors sur l'égalité, et l'original la tranchait au dernier bit
    // près — parfois contre lui-même, en coupant des prénoms qui tenaient, puis en serrant la
    // ligne des dates. Un millionième de millimètre de tolérance rend la décision voulue, et
    // la même dans tous les navigateurs.
    const tient = (bb: Bloc): boolean => {
      const bases = this.bases(bb, r1, r2)
      return bb.lignes.every(([t, s], i) => s.largeur(t) + 2 * R.margeTan <= A * bases[i]! + 1e-6)
    }
    const choisi = vs.find(tient) ?? vs[vs.length - 1]!
    const bases = this.bases(choisi, r1, r2)
    choisi.lignes.forEach(([t, s], i) => {
      const rb = bases[i]!
      const dispo = A * rb - 2 * R.margeTan
      let [st, comp] = ajuster(t, s, dispo)
      if (comp) st = st.echelle((comp / st.largeur(t)) * 1.02)
      scene.texteSurArc(cx, cy, rb, n.a1, n.a2, t, st)
    })
  }

  private texteRad(scene: Scene, cx: number, cy: number, n: Anc, r1: number, r2: number): void {
    const R = this.R
    const am = (n.a1 + n.a2) / 2
    const rm = (r1 + r2) / 2
    const dispo = r2 - r1 - 2 * R.margeRad
    const [rot, x] = am <= 90 ? [-am, rm] : [180 - am, -rm]
    const tr = `translate(${f3(cx)},${f3(cy)}) rotate(${rot.toFixed(4)})`
    const b = n.bloc!
    // Si la place est plus large que le strict nécessaire, on aère un peu les lignes.
    const largeurPerp = radians(n.a2 - n.a1) * r1 - 2 * R.margePerp
    const h = b.hauteurTexte
    const aeration = h ? Math.min(1.25, Math.max(1.0, largeurPerp / h)) : 1
    let y = (-h * aeration) / 2
    for (const [t, s] of b.lignes) {
      const [st, comp] = ajuster(t, s, dispo)
      const base = y + s.interligne * aeration * 0.5 + st.mm * 0.34
      scene.texte(x, base, t, st, 'middle', tr, comp)
      y += s.interligne * aeration
    }
  }

  /* ---------------------------------------------------------------- repères */

  /** L'année de naissance médiane de chaque génération — sur les dates **affichées**. */
  anneesParAnneau(): Map<number, number | null> {
    const sortie = new Map<number, number | null>()
    for (const [g, ns] of this.parNiveau) {
      const ans = ns
        .map((n) => n.p.annee)
        .filter((a): a is number => Boolean(a))
        .sort((a, b) => a - b)
      sortie.set(g, ans.length > 0 ? ans[Math.floor(ans.length / 2)]! : null)
    }
    return sortie
  }
}

function sinMax(a1: number, a2: number): number {
  if (a1 <= 90 && 90 <= a2) return 1.0
  return Math.max(Math.sin(radians(a1)), Math.sin(radians(a2)))
}

/** La hauteur réellement dessinée au-dessus du centre (les anneaux lointains sont creux au sommet). */
export function etendueHaut(E: Eventail): number {
  let h = E.rC
  for (const n of E.noeuds) h = Math.max(h, E.anneaux[n.niveau]!.r2 * sinMax(n.a1, n.a2))
  return h
}

/** Vrai si (x, y) ne tombe dans aucune case de l'éventail, élargie de `marge` mm. */
export function pointLibre(E: Eventail, cx: number, cy: number, x: number, y: number, marge = 6.0): boolean {
  const dx = x - cx
  const dy = cy - y
  const r = Math.hypot(dx, dy)
  if (dy < -marge) return true
  const a = degres(Math.atan2(dy, dx))
  if (r <= E.rC + marge) return false
  for (const n of E.noeuds) {
    const { r1, r2 } = E.anneaux[n.niveau]!
    if (r1 - marge <= r && r <= r2 + marge) {
      const da = degres(marge / Math.max(r, 1))
      if (n.a1 - da <= a && a <= n.a2 + da) return false
    }
  }
  return true
}

