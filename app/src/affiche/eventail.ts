/**
 * L'éventail de l'ascendance d'un couple — portage de `eventail.py`, redessiné le 24/10/2026
 * (ADR 0019, révision).
 *
 * Le couple occupe le premier anneau (lui à gauche, elle à droite) ; chaque anneau suivant
 * est une génération. Les angles ne sont pas fixés par le numéro sosa (1/2ⁿ) : chaque place
 * reçoit au moins l'angle qu'il faut à son texte et à ceux de ses ancêtres, et le reste est
 * partagé entre le père et la mère. Sans cela, au-delà de huit générations, un texte lisible
 * demanderait des mètres de rayon pour des cases restées vides.
 *
 * Les quatre premiers anneaux écrivent **le long de l'arc** (tangentiels) ; au-delà, le
 * texte **rayonne** du centre. Les corps sont fixes : quand les textes ne tiennent pas dans
 * le demi-cercle, c'est le disque central qui grandit (`calculer()`, par dichotomie sur le
 * facteur `k`), ce qui écarte tous les anneaux et élargit d'autant les cases lointaines.
 *
 * ## Ce qui fixe la taille de l'affiche
 *
 * L'anneau le plus peuplé. Dans un anneau rayonnant, la hauteur du texte se prend sur l'arc,
 * et toutes ses cases — plus l'ombre des branches arrêtées plus bas — doivent tenir sur le
 * demi-cercle. Sur onze générations d'ascendants (246 places à la neuvième), l'original écrivait
 * chaque nom sur deux lignes : le disque central montait à 58 % du rayon, l'éventail à 2,25 m
 * de large, et sur un A0 le texte tombait à 3,8 pt. Écrire nom et dates **sur une ligne** dans
 * les anneaux serrés divise par deux l'angle de chaque case : le disque redescend au tiers du
 * rayon, et sur la même feuille tout le texte grandit d'un tiers. `uneLigneDes: 'auto'` choisit
 * le premier anneau écrit ainsi — celui qui donne le plus petit éventail.
 *
 * ## L'égalisation
 *
 * `egaliser` rend égales les cases d'une même génération, autant que la place le permet. Elle a
 * un prix : une branche qui monte à la onzième génération réclame plus d'angle que ses voisines
 * arrêtées à la septième, et leur donner à toutes la même part demanderait, sur l'arbre mesuré,
 * un disque de plus de deux mètres. On accorde donc au disque une marge, une part du rayon, et
 * cette marge égalise génération par génération, du centre vers l'extérieur.
 */
import { ajuster, Bloc, type Ligne } from './boites'
import { ligneVie, type StyleDeDate } from './dates'
import { ENCRE, ENCRE_DOUCE, melange, PAPIER, TEINTES } from './palette'
import { Style } from './polices'
import { f3, secteur, type Scene } from './scene'
import type { Anc } from './structure'

export class Reglages {
  /** Les réglages de l'affiche d'origine (`eventail.py`) : les tests de fidélité s'y comparent. */
  static readonly ORIGINE: Partial<Reglages> = { gamma: 0.6, tangentiels: 4, uneLigneDes: null, egaliser: 0, remplirJusqua: 1 }

  /** Ouverture de l'éventail (degrés). */
  angle = 180.0
  /** Rayon du disque central (mm). */
  rCentre = 36.0
  /** Corps (pt) des anneaux tangentiels 0 à 3. */
  corpsTan = [21.0, 12.5, 10.0, 8.8]
  /**
   * Le nombre d'anneaux écrits le long de l'arc, au plus `corpsTan.length` ; `'auto'` : 4, 3
   * ou 2, celui qui donne le plus petit éventail. Un nom tangentiel réclame toute sa longueur
   * sur l'arc : seize arrière-grands-parents écrits ainsi fixaient seuls le rayon du disque
   * sur une affiche réelle (36 % du rayon ; 23 % quand leur anneau rayonne).
   */
  tangentiels: number | 'auto' = 'auto'
  datesTan: StyleDeDate[] = ['long', 'long', 'abr', 'abr']
  /** Corps des anneaux rayonnants… */
  corpsRad = 7.2
  /** … sauf exceptions (les anneaux 2 et 3, quand ils rayonnent). */
  corpsRadNiveau: Record<number, number> = { 2: 10.5, 3: 9.4, 4: 8.2, 5: 7.6 }
  datesRad: StyleDeDate = 'abr'
  /** Marge le long du rayon (mm). */
  margeRad = 1.6
  /** Marge de part et d'autre des lignes rayonnantes (mm). */
  margePerp = 0.55
  /** Marge aux extrémités des lignes tangentielles (mm). */
  margeTan = 2.0
  /** Le partage de la place libre entre père et mère — 0 : à parts égales, 1 : selon la place requise. */
  gamma = 0
  /** Facteur appliqué aux corps des anneaux tangentiels. */
  croissanceMax = 1.3
  /** Épaisseur d'anneau rayonnant : ce quantile des longueurs de texte. */
  percentile = 0.96
  /** 2 : « Prénoms NOM » / dates ; 3 : prénoms / NOM / dates. */
  lignesRad: 2 | 3 = 2
  /**
   * Le premier anneau rayonnant écrit **sur une ligne** (« Prénoms NOM  ° … † … »), et tous
   * ceux d'au-delà ; `null` : aucun ; `'auto'` : celui qui donne le plus petit éventail.
   */
  uneLigneDes: number | 'auto' | null = 'auto'
  /**
   * La marge, en part du rayon de l'éventail, donnée au disque central pour **égaliser les
   * cases** d'une même génération ; 0 : chaque case prend ce que réclament son texte et ceux de
   * ses ancêtres. La place gagnée sert génération par génération, du centre vers l'extérieur :
   * chaque case y reçoit au moins un angle commun, le plus grand qui tienne, sans dépasser sa
   * place sosa. Seules les branches plus denses que ce partage restent plus larges.
   */
  egaliser = 0
  /**
   * Le plus grand facteur appliqué aux corps des anneaux qui ont de la place, ceux d'avant le
   * premier anneau serré : ils grossissent tant que l'éventail ne grandit pas, et le disque
   * central rapetisse d'autant. 1 : jamais (l'original).
   */
  remplirJusqua = 1.6
  /** Le disque central ne descend pas sous cette part du rayon de l'éventail : il porte le titre. */
  disqueMin = 0.2
  /**
   * Le sens de l'éventail : `'haut'`, les ancêtres au-dessus du couple (l'original) ; `'bas'`,
   * en dessous, les descendants passant au-dessus. Le calcul est le même : seul le dessin se
   * retourne, et les textes restent à l'endroit (lui à gauche, elle à droite, toujours).
   */
  sens: 'haut' | 'bas' = 'haut'
  /**
   * Le dessin tourné de ce nombre de degrés, dans le sens des aiguilles d'une montre — pour le
   * soleil (`angle` 360) : 0, lui à gauche et elle à droite ; 90, lui en haut ; 180, lui à
   * droite ; 270, lui en bas.
   */
  rotation = 0
  /** Le disque central fait au moins ce rayon (mm) : le soleil y pose les descendants. */
  rayonCentralMin = 0
  /**
   * Le premier anneau (le couple) n'est ni dessiné ni écrit, et ne prend aucune place : le
   * soleil des quatre parents met les parents au premier anneau et le couple au centre.
   */
  premierAnneauMasque = false

  constructor(reglages: Partial<Reglages> = {}) {
    Object.assign(this, reglages)
  }
}

function styles(corps: number): [Style, Style, Style] {
  return [new Style('SS', corps, ENCRE, 0, 'Reg'), new Style('SS', corps, ENCRE, 0.045, 'Semi'), new Style('SS', corps * 0.86, ENCRE_DOUCE, 0, 'Ital')]
}

/** Les lignes d'une place. Sur une ligne (`nb = 1`), ce sont celles de deux, mises bout à bout. */
function lignes(n: Anc, st: [Style, Style, Style], dates: StyleDeDate, nb: 1 | 2 | 3 = 3): Ligne[] {
  const p = n.p
  const nom = p.nom + (n.implexe ? ` ◆${n.implexe}` : '') + (p.probable ? ' ?' : '')
  if (nb !== 3) {
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

/** Ce qu'un anneau doit à ses seuls textes, quel que soit le disque : son épaisseur. */
interface Forme {
  orientation: 'tan' | 'rad'
  coupe: boolean
  /** Tangentiel : l'épaisseur ; rayonnant : la longueur de texte retenue, sans les marges. */
  epaisseur: number
}

/**
 * Les lignes d'un bloc **bout à bout**, sur une seule ligne : « Prénoms NOM  ° 1750  † 1810 ».
 * Dans un anneau rayonnant, la hauteur du texte se prend sur l'arc : une ligne au lieu de deux
 * divise par deux l'angle qu'une case réclame.
 */
export class BlocEnLigne extends Bloc {
  /** L'écart entre deux morceaux (mm). */
  static readonly ECART = 1.8

  override get largeurTexte(): number {
    return this.lignes.reduce((w, [t, s]) => w + s.largeur(t), 0) + BlocEnLigne.ECART * Math.max(0, this.lignes.length - 1)
  }

  override get hauteurTexte(): number {
    return this.lignes.reduce((h, [, s]) => Math.max(h, s.interligne), 0)
  }
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
  /** Le nombre d'anneaux tangentiels retenu (`Reglages.tangentiels`). */
  tangentiels = 4
  /** Le premier anneau écrit sur une ligne ; `Infinity` : aucun. */
  uneLigneDes = Infinity
  /** Le facteur des corps des `anneauxLibres` premiers anneaux (`Reglages.remplirJusqua`). */
  croissanceLibre = 1
  anneauxLibres = 0

  private formes: Forme[] = []
  /** Les variantes de chaque place tangentielle — prénoms entiers, puis coupés. */
  private variantesDe = new Map<Anc, Bloc[]>()
  /** Par génération, l'angle commun que chaque case reçoit au moins (`Reglages.egaliser`). */
  private planchers = new Map<number, number>()
  /** Le besoin de chaque place, pour les rayons du dernier `preparer()`. */
  private besoins = new Map<Anc, number>()

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

  /**
   * Les blocs de texte et l'épaisseur de chaque anneau, pour ce premier anneau sur une ligne,
   * les `libres` premiers anneaux grossis de `croissance`.
   */
  private textes(uneLigneDes: number, croissance = 1, libres = 0): void {
    const R = this.R
    this.uneLigneDes = uneLigneDes
    this.croissanceLibre = croissance
    this.anneauxLibres = libres
    this.formes = []
    this.variantesDe.clear()
    for (let g = 0; g < this.nbNiveaux; g++) {
      const ns = this.parNiveau.get(g) ?? []
      const facteur = g < libres ? croissance : 1
      if (g === 0 && R.premierAnneauMasque) {
        for (const n of ns) {
          n.bloc = new Bloc([], 0, 0)
          this.variantesDe.set(n, [n.bloc])
        }
        this.formes.push({ orientation: 'tan', coupe: false, epaisseur: 0 })
      } else if (g < this.tangentiels) {
        // `facteur` vaut 1 sans remplissage : le produit reste celui de l'original, au bit près.
        const st = styles(facteur === 1 ? R.corpsTan[g]! * R.croissanceMax : R.corpsTan[g]! * R.croissanceMax * facteur)
        const coupe = g >= 2
        for (const n of ns) {
          n.bloc = new Bloc(lignes(n, st, R.datesTan[g]!), 0, 0)
          this.variantesDe.set(n, variantes(n.bloc, coupe))
        }
        const nb = coupe ? 4 : 3
        const epaisseur = st[0].interligne * (nb - 2) + st[1].interligne + st[2].interligne + 2.4 * (1 + g * 0.1)
        this.formes.push({ orientation: 'tan', coupe, epaisseur })
      } else {
        const st = styles((R.corpsRadNiveau[g] ?? R.corpsRad) * facteur)
        const nb = g >= uneLigneDes ? 1 : R.lignesRad
        const longueurs: number[] = []
        for (const n of ns) {
          const ls = lignes(n, st, R.datesRad, nb)
          n.bloc = nb === 1 ? new BlocEnLigne(ls, 0, 0) : new Bloc(ls, 0, 0)
          longueurs.push(n.bloc.largeurTexte)
        }
        longueurs.sort((a, b) => a - b)
        const q = longueurs[Math.min(longueurs.length - 1, Math.floor(R.percentile * longueurs.length))] ?? 0
        this.formes.push({ orientation: 'rad', coupe: false, epaisseur: q })
      }
    }
  }

  /** Les rayons pour un facteur de croissance `k` du disque central. */
  private preparer(k: number): void {
    const R = this.R
    this.k = k
    this.rC = R.rCentre + (k - 1.0) * 120
    this.anneaux = []
    let r = this.rC
    for (const { orientation, coupe, epaisseur } of this.formes) {
      const r2 = orientation === 'tan' ? r + epaisseur : r + epaisseur + 2 * R.margeRad
      this.anneaux.push({ r1: r, r2, orientation, coupe })
      r = r2
    }
    this.rExt = r
  }

  /** L'angle minimal (degrés) que réclame le texte de la place `n`. */
  private besoin(n: Anc): number {
    const { r1, r2, orientation } = this.anneaux[n.niveau]!
    const R = this.R
    if (orientation === 'rad') return degres((n.bloc!.hauteurTexte + 2 * R.margePerp) / r1)
    // L'ordre des lignes dépend du côté du cercle où la place tombera, que le calcul ne sait
    // pas encore : sur un cercle entier ou tourné, on réserve pour le pire des deux.
    const ordres = R.angle <= 180 && R.rotation % 360 === 0 ? [R.sens === 'bas'] : [false, true]
    let pire = 0
    for (const versLeCentre of ordres) {
      let meilleur: number | null = null
      for (const b of this.variantesDe.get(n) ?? [n.bloc!]) {
        let besoin = 0
        const bases = this.bases(b, r1, r2, versLeCentre)
        b.lignes.forEach(([t, s], i) => {
          besoin = Math.max(besoin, (s.largeur(t) + 2 * R.margeTan) / bases[i]!)
        })
        meilleur = meilleur === null ? besoin : Math.min(meilleur, besoin)
      }
      pire = Math.max(pire, meilleur ?? 0)
    }
    return degres(pire)
  }

  /**
   * Les rayons des lignes de base, bloc centré dans l'anneau : de l'extérieur vers l'intérieur
   * au-dessus du centre (le haut des lettres regarde au loin), de l'intérieur vers l'extérieur
   * en dessous (`versLeCentre` : le haut des lettres regarde le centre).
   */
  private bases(b: Bloc, r1: number, r2: number, versLeCentre: boolean): number[] {
    const sortie: number[] = []
    if (versLeCentre) {
      let bord = (r1 + r2) / 2 - b.hauteurTexte / 2
      for (const [, s] of b.lignes) {
        sortie.push(bord + s.interligne * 0.8)
        bord += s.interligne
      }
      return sortie
    }
    let haut = (r1 + r2) / 2 + b.hauteurTexte / 2
    for (const [, s] of b.lignes) {
      sortie.push(haut - s.interligne * 0.8)
      haut -= s.interligne
    }
    return sortie
  }

  /** Les angles dessinés d'une place : ceux du calcul, reflétés sous le centre (`sens`), puis tournés (`rotation`). */
  dessines(a1: number, a2: number): [number, number] {
    const [d1, d2] = this.R.sens === 'bas' ? [-a2, -a1] : [a1, a2]
    return this.R.rotation ? [d1 - this.R.rotation, d2 - this.R.rotation] : [d1, d2]
  }

  private minimums(n: Anc): number {
    const ps = n.parents.map((p) => this.minimums(p))
    let besoin = this.besoins.get(n)
    if (besoin === undefined) this.besoins.set(n, (besoin = this.besoin(n)))
    n.minimum = Math.max(besoin, this.planchers.get(n.niveau) ?? 0, ps.reduce((a, b) => a + b, 0))
    return n.minimum
  }

  private total(k: number): number {
    this.preparer(k)
    this.besoins.clear()
    return this.minimums(this.h) + this.minimums(this.f)
  }

  /** Le plus petit facteur de croissance pour lequel tous les textes tiennent. */
  private facteur(ouverture: number): number {
    let lo = 1.0
    if (this.total(lo) <= ouverture) return 1.0
    let hi = 2.0
    // Garde-fou : le disque grandit sans borne dans le script d'origine. Soixante
    // multiplications par 1,6 dépassent de très loin tout arbre réel.
    for (let i = 0; i < 60 && this.total(hi) > ouverture; i++) hi *= 1.6
    for (let i = 0; i < 30; i++) {
      const mid = (lo + hi) / 2
      if (this.total(mid) > ouverture) lo = mid
      else hi = mid
    }
    return hi
  }

  /**
   * Les textes de chaque anneau, et leur facteur `k`. Avec `uneLigneDes: 'auto'`, chaque anneau
   * rayonnant est essayé comme premier anneau sur une ligne, et aucun : on garde le plus petit
   * éventail — et, à 2 % près, celui qui laisse le plus d'anneaux sur deux lignes, plus aérés.
   * Un petit arbre, que rien ne serre, reste ainsi sur deux lignes partout.
   */
  private choisirLesLignes(ouverture: number): number {
    const choix = this.R.uneLigneDes
    const candidats: number[] = [Infinity]
    if (choix === 'auto') for (let g = this.nbNiveaux - 1; g >= this.tangentiels; g--) candidats.push(g)
    else if (choix !== null) candidats[0] = choix
    const essais = candidats.map((premier) => {
      this.textes(premier)
      const k = this.facteur(ouverture)
      this.preparer(k)
      return { premier, k, rExt: this.rExt }
    })
    const plusPetit = Math.min(...essais.map((e) => e.rExt))
    const retenu = essais.find((e) => e.rExt <= plusPetit * 1.02)!
    this.textes(retenu.premier)
    return retenu.k
  }

  /**
   * Le nombre d'anneaux tangentiels, puis les textes et leur facteur `k` (`choisirLesLignes`).
   * Avec `tangentiels: 'auto'`, on essaie quatre, trois puis deux anneaux le long de l'arc, et
   * l'on garde le plus petit éventail — à 2 % près, celui qui en garde le plus.
   */
  private choisirLaForme(ouverture: number): number {
    const choix = this.R.tangentiels
    const max = this.R.corpsTan.length
    const candidats = choix === 'auto' ? [max, max - 1, max - 2].filter((t) => t >= 1) : [Math.min(choix, max)]
    const essais = candidats.map((tangentiels) => {
      this.tangentiels = tangentiels
      const k = this.choisirLesLignes(ouverture)
      this.preparer(k)
      return { tangentiels, premier: this.uneLigneDes, k, rExt: this.rExt }
    })
    const plusPetit = Math.min(...essais.map((e) => e.rExt))
    const retenu = essais.find((e) => e.rExt <= plusPetit * 1.02)!
    this.tangentiels = retenu.tangentiels
    this.textes(retenu.premier)
    return retenu.k
  }

  /**
   * Les anneaux qui ont de la place grossissent tant que l'éventail ne grandit pas : le disque
   * central, qui n'est que du remplissage quand un anneau lointain fixe le rayon, rapetisse
   * d'autant. Sont « libres » les anneaux d'avant le premier anneau rayonnant serré — celui
   * où la case médiane n'a pas le double de la place que réclame son texte. Rend le facteur
   * `k` des textes retenus.
   */
  private remplir(ouverture: number, k: number): number {
    const R = this.R
    if (R.remplirJusqua <= 1 || k <= 1) return k
    const premier = this.uneLigneDes
    this.total(k)
    const rExt = this.rExt
    const debut = 90 - R.angle / 2
    const fin = 90 + R.angle / 2
    const ah = this.part(R.angle, this.h.minimum, this.f.minimum)
    this.repartir(this.h, fin - ah, fin)
    this.repartir(this.f, debut, fin - ah)
    let libres = this.nbNiveaux
    for (let g = this.tangentiels; g < this.nbNiveaux; g++) {
      const { r1 } = this.anneaux[g]!
      const aises = (this.parNiveau.get(g) ?? [])
        .map((n) => (radians(n.a2 - n.a1) * r1) / (n.bloc!.hauteurTexte + 2 * R.margePerp))
        .sort((a, b) => a - b)
      if ((aises[aises.length >> 1] ?? Infinity) < 2) {
        libres = g
        break
      }
    }
    const tient = (c: number): number | null => {
      this.textes(premier, c, libres)
      const kc = this.facteur(ouverture)
      this.preparer(kc)
      return this.rExt <= rExt * 1.005 && this.rC >= R.disqueMin * rExt ? kc : null
    }
    let lo = 1
    let kLo = k
    let hi = R.remplirJusqua
    const kHi = tient(hi)
    if (kHi !== null) {
      lo = hi
      kLo = kHi
    } else {
      for (let i = 0; i < 12; i++) {
        const mid = (lo + hi) / 2
        const km = tient(mid)
        if (km !== null) [lo, kLo] = [mid, km]
        else hi = mid
      }
    }
    this.textes(premier, lo, lo > 1 ? libres : 0)
    return lo > 1 ? kLo : k
  }

  /**
   * Les angles communs, génération par génération, pour le disque de `k` : du centre vers
   * l'extérieur, le plus grand angle que toutes les cases de la génération peuvent recevoir
   * sans que l'ensemble dépasse l'ouverture — jamais plus que leur place sosa.
   */
  private egaliserLesCases(k: number, ouverture: number): void {
    this.total(k)
    const somme = (): number => this.minimums(this.h) + this.minimums(this.f)
    for (let g = 0; g < this.nbNiveaux; g++) {
      let lo = 0
      let hi = ouverture / 2 ** (g + 1)
      this.planchers.set(g, hi)
      if (somme() <= ouverture) continue
      for (let i = 0; i < 24; i++) {
        const mid = (lo + hi) / 2
        this.planchers.set(g, mid)
        if (somme() <= ouverture) lo = mid
        else hi = mid
      }
      this.planchers.set(g, lo)
    }
  }

  /** Le plus petit facteur de croissance pour lequel tous les textes tiennent, puis les angles. */
  calculer(): this {
    const ouverture = this.R.angle * 0.985
    this.planchers.clear()
    let k = this.choisirLaForme(ouverture)
    this.preparer(k)
    if (this.rC < this.R.rayonCentralMin) {
      // Un disque imposé, plus grand que le nécessaire : rien à remplir, la place est là.
      k = 1 + (this.R.rayonCentralMin - this.R.rCentre) / 120
    } else {
      k = this.remplir(ouverture, k)
    }
    if (this.R.egaliser > 0) {
      // Le disque grandit de `egaliser` fois le rayon de l'éventail, et cette marge égalise.
      this.preparer(k)
      k += (this.R.egaliser * this.rExt) / 120
      this.egaliserLesCases(k, ouverture)
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
    scene.chemin(secteur(cx, cy, 0, this.rC, ...this.dessines(90 - R.angle / 2, 90 + R.angle / 2)), melange([222, 205, 168], 1), PAPIER, 0.6)
    const dessines = this.noeuds.filter((n) => n.niveau > 0 || !R.premierAnneauMasque)
    for (const n of [...dessines].sort((a, b) => a.niveau - b.niveau)) {
      const { r1, r2 } = this.anneaux[n.niveau]!
      scene.chemin(secteur(cx, cy, r1, r2, ...this.dessines(n.a1, n.a2)), this.fond(n), PAPIER, n.niveau < 4 ? 0.7 : 0.45)
    }
    for (const n of dessines) {
      const { r1, r2, orientation, coupe } = this.anneaux[n.niveau]!
      if (orientation === 'tan') this.texteTan(scene, cx, cy, n, r1, r2, coupe)
      else this.texteRad(scene, cx, cy, n, r1, r2)
    }
  }

  private texteTan(scene: Scene, cx: number, cy: number, n: Anc, r1: number, r2: number, coupe: boolean): void {
    const R = this.R
    const A = radians(n.a2 - n.a1)
    const vs = variantes(n.bloc!, coupe)
    const [d1, d2] = this.dessines(n.a1, n.a2)
    const versLeCentre = Math.sin(radians((d1 + d2) / 2)) < 0
    // Une place reçoit souvent **exactement** l'angle que réclame sa variante la plus sobre :
    // la comparaison tombe alors sur l'égalité, et l'original la tranchait au dernier bit
    // près — parfois contre lui-même, en coupant des prénoms qui tenaient, puis en serrant la
    // ligne des dates. Un millionième de millimètre de tolérance rend la décision voulue, et
    // la même dans tous les navigateurs.
    const tient = (bb: Bloc): boolean => {
      const bases = this.bases(bb, r1, r2, versLeCentre)
      return bb.lignes.every(([t, s], i) => s.largeur(t) + 2 * R.margeTan <= A * bases[i]! + 1e-6)
    }
    const choisi = vs.find(tient) ?? vs[vs.length - 1]!
    const bases = this.bases(choisi, r1, r2, versLeCentre)
    choisi.lignes.forEach(([t, s], i) => {
      const rb = bases[i]!
      const dispo = A * rb - 2 * R.margeTan
      let [st, comp] = ajuster(t, s, dispo)
      if (comp) st = st.echelle((comp / st.largeur(t)) * 1.02)
      scene.texteSurArc(cx, cy, rb, d1, d2, t, st)
    })
  }

  private texteRad(scene: Scene, cx: number, cy: number, n: Anc, r1: number, r2: number): void {
    const R = this.R
    const [d1, d2] = this.dessines(n.a1, n.a2)
    const am = (d1 + d2) / 2
    const rm = (r1 + r2) / 2
    const dispo = r2 - r1 - 2 * R.margeRad
    // À droite du centre, le texte se lit vers l'extérieur ; à gauche, vers le centre.
    const adroite = Math.cos(radians(am)) >= 0
    const [rot, x] = adroite ? [-am, rm] : [180 - am, -rm]
    const tr = `translate(${f3(cx)},${f3(cy)}) rotate(${rot.toFixed(4)})`
    const b = n.bloc!
    if (b instanceof BlocEnLigne) return this.ligneRad(scene, b, x, dispo, tr)
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

  /** Les morceaux bout à bout, centrés sur le milieu de l'anneau, resserrés ensemble s'il le faut. */
  private ligneRad(scene: Scene, b: BlocEnLigne, x: number, dispo: number, tr: string): void {
    const w = b.largeurTexte
    // Comme `ajuster()` : on réduit le corps jusqu'à 84 %, puis on comprime la chasse.
    const k = w > dispo && dispo > 0 ? dispo / w : 1
    const corps = Math.max(k, 0.84)
    const chasse = k < 0.84 ? k / 0.84 : 1
    let pos = x - (w * corps * chasse) / 2
    const haut = b.lignes[0]![1].echelle(corps)
    for (const [t, s] of b.lignes) {
      const st = s.echelle(corps)
      const largeur = st.largeur(t) * chasse
      scene.texte(pos, haut.mm * 0.34, t, st, 'start', tr, chasse < 1 ? largeur : null)
      pos += largeur + BlocEnLigne.ECART * corps * chasse
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

/**
 * La hauteur réellement dessinée au-dessus du centre — en dessous, quand l'éventail descend
 * (les anneaux lointains sont creux au sommet).
 */
export function etendueHaut(E: Eventail): number {
  let h = E.rC
  for (const n of E.noeuds) h = Math.max(h, E.anneaux[n.niveau]!.r2 * sinMax(n.a1, n.a2))
  return h
}

/** Vrai si (x, y) ne tombe dans aucune case de l'éventail, élargie de `marge` mm. */
export function pointLibre(E: Eventail, cx: number, cy: number, x: number, y: number, marge = 6.0): boolean {
  if (E.R.angle > 180 || E.R.rotation % 360 !== 0) return pointLibreAutour(E, cx, cy, x, y, marge)
  const dx = x - cx
  // Le calcul est celui d'un éventail qui monte : un point sous un éventail qui descend se
  // lit comme son reflet au-dessus.
  const dy = E.R.sens === 'bas' ? y - cy : cy - y
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

/** `pointLibre()` pour un dessin qui n'est pas un demi-cercle montant : le soleil, tourné ou non. */
function pointLibreAutour(E: Eventail, cx: number, cy: number, x: number, y: number, marge: number): boolean {
  const dx = x - cx
  const dy = cy - y
  const r = Math.hypot(dx, dy)
  const a = degres(Math.atan2(dy, dx))
  const dans = ([d1, d2]: [number, number], r1: number, r2: number): boolean => {
    if (r < r1 - marge || r > r2 + marge) return false
    const da = degres(marge / Math.max(r, 1))
    return (((a - d1 + da) % 360) + 360) % 360 <= d2 - d1 + 2 * da
  }
  if (dans(E.dessines(90 - E.R.angle / 2, 90 + E.R.angle / 2), 0, E.rC)) return false
  return !E.noeuds.some((n) => dans(E.dessines(n.a1, n.a2), E.anneaux[n.niveau]!.r1, E.anneaux[n.niveau]!.r2))
}

/** Jusqu'où va le dessin depuis le centre, dans les quatre directions (mm). */
export function etendues(E: Eventail): { haut: number; bas: number; gauche: number; droite: number } {
  const plein = E.R.angle >= 360
  const sortie = { haut: E.rC, bas: plein ? E.rC : 0, gauche: E.rC, droite: E.rC }
  const pousser = (r: number, a: number): void => {
    const [c, s] = [Math.cos(radians(a)), Math.sin(radians(a))]
    sortie.droite = Math.max(sortie.droite, r * c)
    sortie.gauche = Math.max(sortie.gauche, -r * c)
    sortie.haut = Math.max(sortie.haut, r * s)
    sortie.bas = Math.max(sortie.bas, -r * s)
  }
  for (const n of E.noeuds) {
    const r = E.anneaux[n.niveau]!.r2
    const [d1, d2] = E.dessines(n.a1, n.a2)
    pousser(r, d1)
    pousser(r, d2)
    // Les axes que la place traverse.
    for (let axe = Math.ceil(d1 / 90) * 90; axe < d2; axe += 90) pousser(r, axe)
  }
  return sortie
}
