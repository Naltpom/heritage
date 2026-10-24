/**
 * L'affiche « soleil » : l'ascendance sur le cercle entier, la descendance dans le disque central.
 *
 * Le cercle est l'éventail à 360° (`Reglages.angle`), tourné au choix (`Reglages.rotation`).
 * Deux centres possibles (`OptionsDuSoleil.centre`) :
 *
 * - **le couple** de la fiche : il occupe le premier anneau, et ses descendants le disque ;
 * - **ses quatre parents** : ils occupent le premier anneau (celui du couple est masqué), et le
 *   disque reçoit leurs descendants. Le couple au milieu, ses enfants en dessous ; les frères et
 *   sœurs du mari à gauche, ceux de la femme à droite, chaque famille pendue à son tronc, qui
 *   prend aussi le mari ou la femme. Chacun n'y figure qu'une fois.
 *
 * Le centre porte le titre, puis les sous-arbres des enfants **rangés en bandes qui suivent le
 * cercle** : chaque bande est aussi large que le disque le permet à sa hauteur, le long d'un tronc
 * qui descend du titre — au milieu, enfants de part et d'autre, pour le couple ; un tronc par
 * famille pour les quatre parents. Une rangée unique, trop large, laissait le disque vide au-dessus
 * et en dessous (relevé du 24/10/2026 : trente et un descendants à mi-taille dans un disque grossi
 * de moitié).
 *
 * Le disque garde sa taille tant que les descendants y tiennent à 55 % de leur corps ; ils
 * grossissent s'il y a de la place (jusqu'à 140 %). Au-delà, c'est le disque qui grandit.
 */
import { dessinerDesc, mesurerDesc, peigne, placerDesc, ReglesDesc } from './boites'
import { CORPS_MAX, CORPS_MIN, Rosace, SoleilInterieur, type Coeur, type FamilleDuCentre } from './centres'
import type { ArbreAffiche } from './donnees'
import { etendues, Eventail, pointLibre, Reglages } from './eventail'
import { chiffresDeDescendance, legende, lignesDuCouple, MENTION_PAR_DEFAUT, pied, type AfficheProduite, type ChiffresDuPied, type OptionsDAffiche } from './fabriquer'
import type { Style } from './polices'
import { Scene } from './scene'
import { ascendance, compter, descendance, descendanceDeFamille, type Desc } from './structure'

export type CentreDuSoleil = 'couple' | 'parents'

export interface OptionsDuSoleil extends OptionsDAffiche {
  /** Ce que porte le premier anneau, et dont le disque montre la descendance. */
  centre?: CentreDuSoleil
}

/** Les bornes du corps des descendants, en part de leur corps de base (13 pt pour les enfants). */
const ECHELLE_MIN = 0.55
const ECHELLE_MAX = 1.4
/** La part du rayon où le texte peut aller : un peu de marge avant les anneaux. */
const DANS_LE_DISQUE = 0.93
/** Le soleil intérieur et la rosace occupent le disque jusqu'à cette part de son rayon. */
const CENTRE_DANS_LE_DISQUE = 0.97


const reglesPour = (e: number): ReglesDesc => new ReglesDesc([13, 11, 9.5, 9].map((c) => c * e), 'long', 3 * e, 11 * e, 5.5 * e, 34 * e)

/** Les lignes du titre posées de haut en bas depuis 0 (le haut des noms) : lignes de base et hauteur du bloc. */
function titreEmpile(ls: [string, Style, number][]): { lignes: [string, Style][]; bases: number[]; hauteur: number } {
  const vivantes = ls.filter(([t]) => Boolean(t))
  // Les écarts de `centreCouple()`, du bas vers le haut.
  const ys = [0]
  for (let i = 0; i + 1 < vivantes.length; i++) {
    const [, st, apres] = vivantes[i]!
    ys.push(ys[i]! - (st.mm * 0.74 + st.mm * 0.32 * apres + vivantes[i + 1]![1].mm * 0.26))
  }
  const dernier = vivantes.length - 1
  const haut = ys[dernier]! - vivantes[dernier]![1].mm * 0.8
  return { lignes: vivantes.map(([t, st]) => [t, st]), bases: ys.map((y) => y - haut), hauteur: vivantes[0]![1].mm * 0.25 - haut }
}

/** Le nombre de rangées d'un sous-arbre (1 : l'enfant seul). */
function profondeur(d: Desc): number {
  let p = 1
  for (const [, , enf] of d.unions) for (const e of enf) p = Math.max(p, 1 + profondeur(e))
  return p
}

/**
 * Une bande du centre. `cote` dit où sont ses enfants par rapport à son tronc : des deux côtés
 * (`0`, avec un couloir où le tronc passe, ou centrés sous lui s'il s'arrête là), à gauche (`-1`)
 * ou à droite (`1`).
 */
interface Bande {
  enfants: Desc[]
  /** Pour `cote` 0 : combien des enfants vont à gauche du couloir ; `-1` : centrés, sans couloir. */
  coupure: number
  cote: -1 | 0 | 1
  y: number
  hauteurs: Map<number, number>
}

interface Agencement {
  e: number
  regles: ReglesDesc
  titre: ReturnType<typeof titreEmpile>
  /** Le haut du bloc, par rapport au centre du disque. */
  haut: number
  /** Le couple au milieu (quatre parents), avec ses descendants. */
  couple: { desc: Desc; y: number; largeur: number; hauteurs: Map<number, number> } | null
  /**
   * Les bandes de chaque tronc, avec l'abscisse du tronc par rapport au centre — et, pour les
   * quatre parents, l'époux que ce tronc prend aussi, dans le couple du milieu.
   */
  troncs: { x: number; bandes: Bande[]; avec: Desc | null }[]
}

/**
 * Les familles du centre : une seule (les enfants du couple), ou, pour les quatre parents, le
 * couple (le mari, sa femme parmi ses conjoints) et les fratries de part et d'autre.
 */
type Familles = { seule: Desc[] } | { couple: Desc; epouse: Desc | null; gauche: Desc[]; droite: Desc[] }

/**
 * Le centre à l'échelle `e` dans un disque de rayon `r` (coordonnées relatives à son centre), ou
 * `null` s'il n'y tient pas. On essaie plusieurs hauteurs pour le haut du bloc et l'on garde
 * celle qui le centre le mieux.
 */
function agencer(familles: Familles, lignes: (u: number) => [string, Style, number][], e: number, r: number): Agencement | null {
  const regles = reglesPour(e)
  const listes = 'seule' in familles ? [familles.seule] : [familles.gauche, familles.droite, [familles.couple]]
  const hauteursGlobales = new Map<number, number>()
  for (const l of listes) for (const [n, h] of mesurerDesc(l, regles)[1]) hauteursGlobales.set(n, Math.max(hauteursGlobales.get(n) ?? 0, h))
  const titre = titreEmpile(lignes(Math.max(e, 0.75)))
  const rr = r * DANS_LE_DISQUE
  const demiCorde = (y1: number, y2: number): number => {
    const loin = Math.max(Math.abs(y1), Math.abs(y2))
    return loin >= rr ? -1 : Math.sqrt(rr * rr - loin * loin)
  }
  const couloir = 8 * e
  const ecartBandes = regles.ecartY * 1.6
  const aucun = listes.every((l) => l.length === 0)
  const hauteurDe = (p: number): number => {
    let h = 0
    for (let n = 1; n <= p; n++) h += hauteursGlobales.get(n) ?? 0
    return h + regles.ecartY * (p - 1)
  }
  const largeurDe = (ds: Desc[]): number => ds.reduce((s, d) => s + d.largeurSousArbre, 0) + regles.ecartX * 2 * Math.max(ds.length - 1, 0)
  /** La coupure gauche/droite la plus équilibrée, dans l'ordre. */
  const couper = (ds: Desc[]): number => {
    let meilleur: [number, number] = [Infinity, 0]
    for (let k = 0; k <= ds.length; k++) {
      const pire = Math.max(largeurDe(ds.slice(0, k)), largeurDe(ds.slice(k)))
      if (pire < meilleur[0]) meilleur = [pire, k]
    }
    return meilleur[1]
  }
  const hauteursDe = (ds: Desc[]): Map<number, number> => {
    const p = Math.max(...ds.map(profondeur))
    return new Map([...hauteursGlobales].filter(([n]) => n <= p))
  }

  /**
   * Les bandes d'un tronc, du haut `y0` vers le bas. Sur le tronc du milieu (`cote` 0), la
   * dernière bande se centre sous lui ; un enfant seul aussi, mais il arrête le tronc : il ne peut
   * donc être que dans la dernière.
   */
  const bandesDuTronc = (liste: Desc[], cote: -1 | 0 | 1, y0: number, decalage = couloir / 2): { bandes: Bande[]; bas: number } | null => {
    const bandes: Bande[] = []
    let y = y0
    let i = 0
    while (i < liste.length) {
      let retenue: Bande | null = null
      for (let j = i; j < liste.length; j++) {
        const ds = liste.slice(i, j + 1)
        const h = hauteurDe(Math.max(...ds.map(profondeur)))
        const derniere = j === liste.length - 1
        let coupure = -1
        let besoin: number
        if (cote !== 0) {
          besoin = decalage + regles.ecartX + largeurDe(ds)
        } else if (derniere || ds.length === 1) {
          besoin = largeurDe(ds) / 2
        } else {
          coupure = couper(ds)
          besoin = Math.max(largeurDe(ds.slice(0, coupure)), largeurDe(ds.slice(coupure))) + couloir / 2
        }
        if (besoin > demiCorde(y, y + h)) break
        retenue = { enfants: ds, coupure, cote, y, hauteurs: hauteursDe(ds) }
      }
      if (!retenue) return null
      if (cote === 0 && retenue.coupure === -1 && i + retenue.enfants.length < liste.length) return null
      bandes.push(retenue)
      i += retenue.enfants.length
      y += hauteurDe(Math.max(...retenue.enfants.map(profondeur))) + ecartBandes
    }
    return { bandes, bas: bandes.length > 0 ? y - ecartBandes : y0 }
  }

  const essayer = (haut: number): Omit<Agencement, 'e' | 'regles' | 'titre' | 'haut'> & { bas: number } | null => {
    for (const [i, [t, st]] of titre.lignes.entries()) {
      const base = haut + titre.bases[i]!
      if (st.largeur(t) / 2 > demiCorde(base - st.mm * 0.8, base + st.mm * 0.25)) return null
    }
    const y0 = haut + titre.hauteur + (aucun ? 0 : 18 * e)
    if ('seule' in familles) {
      const b = bandesDuTronc(familles.seule, 0, y0)
      return b ? { couple: null, troncs: [{ x: 0, bandes: b.bandes, avec: null }], bas: Math.max(haut + titre.hauteur, b.bas) } : null
    }
    // Le couple au milieu, ses descendants en dessous ; les fratries de part et d'autre, à côté
    // de lui ou, si elles n'y tiennent pas, sous sa rangée — toujours en dehors de ses colonnes.
    const { couple, epouse } = familles
    const largeur = couple.largeurSousArbre
    const pCouple = profondeur(couple)
    const hCouple = hauteurDe(pCouple)
    if (largeur / 2 > demiCorde(y0, y0 + hCouple)) return null
    const decalage = largeur / 2 + regles.ecartX
    const sousLeCouple = y0 + hauteurDe(1) + ecartBandes
    const g = bandesDuTronc(familles.gauche, -1, y0, decalage) ?? bandesDuTronc(familles.gauche, -1, sousLeCouple, decalage)
    const d = bandesDuTronc(familles.droite, 1, y0, decalage) ?? bandesDuTronc(familles.droite, 1, sousLeCouple, decalage)
    if (!g || !d) return null
    return {
      couple: { desc: couple, y: y0, largeur, hauteurs: new Map([...hauteursGlobales].filter(([n]) => n <= pCouple)) },
      troncs: [
        { x: -decalage, bandes: g.bandes, avec: couple },
        { x: decalage, bandes: d.bandes, avec: epouse },
      ],
      bas: Math.max(y0 + hCouple, g.bas, d.bas),
    }
  }

  let meilleur: Agencement | null = null
  let ecart = Infinity
  for (let k = 0; k <= 16; k++) {
    const haut = -rr + (k / 16) * rr
    const essai = essayer(haut)
    if (!essai) continue
    const decentrage = Math.abs(haut + essai.bas)
    if (decentrage < ecart) [meilleur, ecart] = [{ e, regles, titre, haut, couple: essai.couple, troncs: essai.troncs }, decentrage]
  }
  return meilleur
}

/** Pose le centre agencé sur (cx, cy). */
function dessinerLeCentre(scene: Scene, a: Agencement, cx: number, cy: number): void {
  const haut = cy + a.haut
  a.titre.lignes.forEach(([t, st], i) => scene.texte(cx, haut + a.titre.bases[i]!, t, st))
  const couloir = 8 * a.e
  const largeurDe = (ds: Desc[]): number => ds.reduce((s, d) => s + d.largeurSousArbre, 0) + a.regles.ecartX * 2 * Math.max(ds.length - 1, 0)
  // D'abord tout poser : les traits des troncs vont chercher les époux dans le couple du milieu.
  if (a.couple) placerDesc([a.couple.desc], cx - a.couple.largeur / 2, cy + a.couple.y, a.couple.hauteurs, a.regles)
  for (const tronc of a.troncs) {
    const xt = cx + tronc.x
    for (const b of tronc.bandes) {
      const y = cy + b.y
      if (b.cote === -1) {
        placerDesc(b.enfants, xt - a.regles.ecartX - largeurDe(b.enfants), y, b.hauteurs, a.regles)
      } else if (b.cote === 1) {
        placerDesc(b.enfants, xt + a.regles.ecartX, y, b.hauteurs, a.regles)
      } else if (b.coupure === -1) {
        placerDesc(b.enfants, xt - largeurDe(b.enfants) / 2, y, b.hauteurs, a.regles)
      } else {
        const gauche = b.enfants.slice(0, b.coupure)
        placerDesc(gauche, xt - couloir / 2 - largeurDe(gauche), y, b.hauteurs, a.regles)
        placerDesc(b.enfants.slice(b.coupure), xt + couloir / 2, y, b.hauteurs, a.regles)
      }
    }
  }
  if (a.couple) dessinerDesc(scene, [a.couple.desc], null, a.regles)
  for (const tronc of a.troncs) {
    const xt = cx + tronc.x
    let depart: [number, number] = [xt, haut + a.titre.hauteur + 3 * a.e]
    // L'époux du couple d'abord : sa barre est celle de la première bande, quand elle est à côté.
    const barre = tronc.avec ? peigne(scene, depart, [tronc.avec], a.regles) : null
    if (barre !== null) depart = [xt, barre]
    for (const b of tronc.bandes) {
      const y = peigne(scene, depart, b.enfants, a.regles)
      dessinerDesc(scene, b.enfants, null, a.regles)
      // Le tronc repart de la barre de cette bande.
      if (y !== null) depart = [xt, y]
    }
  }
}

/** Le plus grand `x` de [lo, hi] pour lequel `ok(x)`, `ok` étant supposé vrai en `lo`. */
function plusGrand(lo: number, hi: number, ok: (x: number) => boolean): number {
  if (ok(hi)) return hi
  for (let i = 0; i < 18; i++) {
    const mid = (lo + hi) / 2
    if (ok(mid)) lo = mid
    else hi = mid
  }
  return lo
}

/** L'affiche « soleil » du couple (`hId`, `fId`). */
export function afficheSoleil(A: ArbreAffiche, hId: number, fId: number, options: OptionsDuSoleil = {}): AfficheProduite {
  const H = A.P.get(hId)
  const F = A.P.get(fId)
  if (!H || !F) throw new Error('Les deux époux doivent être dans l’arbre de l’affiche.')
  const mention = options.mention ?? MENTION_PAR_DEFAUT
  const date = options.date ?? new Date()
  const niveaux = options.niveauxDescendance ?? 6

  // Les quatre parents au centre : il faut connaître la famille d'origine de chacun des époux.
  const familleH = A.familleDOrigine(H.id)
  const familleF = A.familleDOrigine(F.id)
  const parents = options.centre === 'parents' && familleH !== null && familleF !== null

  const { racineH, racineF, noeuds } = ascendance(A, H, F, options.profondeur ?? Infinity)
  const reglages = new Reglages({ ...(options.reglages ?? new Reglages()), angle: 360, sens: 'haut', premierAnneauMasque: parents })
  let E = new Eventail(racineH, racineF, noeuds, reglages).calculer()

  let familles: Familles
  let lignes: (u: number) => [string, Style, number][]
  const fratrieH = parents ? descendanceDeFamille(A, familleH, niveaux) : []
  const couple = fratrieH.find((d) => d.p.id === H.id)
  if (parents && couple) {
    const fratrieF = descendanceDeFamille(A, familleF, niveaux)
    // Le couple au milieu : le mari, avec sa femme parmi ses conjoints et leurs enfants. Elle
    // quitte donc sa fratrie, où elle figurerait une seconde fois.
    const epouse = couple.unions.find(([c]) => c?.p.id === F.id)?.[0] ?? null
    familles = { couple, epouse, gauche: fratrieH.filter((d) => d !== couple), droite: fratrieF.filter((d) => d.p.id !== F.id) }
    const titre = lignesDuCouple(A, null, [...fratrieH, ...fratrieF.filter((d) => d.p.id !== F.id)], H, F)
    lignes = (u) => titre(u).filter(([t]) => t !== '∞')
  } else {
    const { fam, enfants } = descendance(A, H, F, niveaux)
    familles = { seule: enfants }
    lignes = lignesDuCouple(A, fam, enfants, H, F)
  }

  // Le soleil intérieur ou la rosace, à la place de l'arbre de boîtes.
  const forme = options.descendants ?? 'arbre'
  let autre: { dessiner: (scene: Scene, cx: number, cy: number) => void; echelle: number } | null = null
  if (forme !== 'arbre') {
    const v = (d: Desc): FamilleDuCentre => ({ cote: 'v', couple: null, tete: d })
    const p = (d: Desc): FamilleDuCentre => ({ cote: 'p', couple: null, tete: d })
    // Dans le sens des aiguilles d'une montre depuis le haut : la fratrie de la femme à droite,
    // puis celle du mari, qui revient par la gauche — l'aîné toujours au plus près du couple.
    const autour: FamilleDuCentre[] = 'seule' in familles ? familles.seule.map((d) => ({ cote: 'n', couple: null, tete: d })) : [...familles.droite.map(v), ...[...familles.gauche].reverse().map(p)]
    const parNiveau = compter('seule' in familles ? familles.seule : [familles.couple, ...familles.gauche, ...familles.droite])
    // La femme compte parmi les enfants des quatre parents, même posée en conjointe du mari.
    if (!('seule' in familles)) parNiveau.set(1, (parNiveau.get(1) ?? 0) + 1)
    const chiffresTexte = chiffresDeDescendance(parNiveau)
    const chiffres2 = chiffresTexte.length > 2 ? [chiffresTexte.slice(0, 2).join(' · '), chiffresTexte.slice(2).join(' · ')] : [chiffresTexte.join(' · ')]
    const coeur: Coeur = { noms: [H.nom, F.nom], couple: null, enfants: [], chiffres: chiffres2.filter(Boolean) }
    const rotation = reglages.rotation
    const r0 = E.rC * CENTRE_DANS_LE_DISQUE
    let b: number
    let besoin: number
    if (forme === 'soleil') {
      const familleCouple: FamilleDuCentre[] = 'seule' in familles ? [] : [{ cote: 'n', couple: [H, F], tete: familles.couple }]
      const soleil = new SoleilInterieur([...familleCouple, ...autour], coeur, true)
      const pose = soleil.poseParDefaut(rotation)
      // Le rayon d'abord, au corps le plus petit ; puis chaque anneau au plus grand qui y tient.
      besoin = Math.max(r0, soleil.rayonRequis(Array.from({ length: soleil.anneaux }, () => CORPS_MIN), pose))
      const corps = soleil.corpsPour(besoin, pose, CORPS_MIN, CORPS_MAX)
      b = corps[0]!
      autre = { dessiner: (scene, cx, cy) => soleil.dessiner(scene, cx, cy, E.rC * CENTRE_DANS_LE_DISQUE, corps, soleil.poseParDefaut(rotation)), echelle: b / 13 }
    } else {
      const enfantsDuCouple = 'seule' in familles ? [] : (familles.couple.unions.find(([c]) => c?.p.id === F.id)?.[2] ?? []).map((d) => d.p)
      const rosace = new Rosace(autour, 'seule' in familles ? coeur : { ...coeur, couple: [H, F], enfants: enfantsDuCouple }, true)
      b = rosace.corpsPour(r0, CORPS_MIN, CORPS_MAX)
      besoin = rosace.rayonRequis(b)
      autre = { dessiner: (scene, cx, cy) => rosace.dessiner(scene, cx, cy, E.rC * CENTRE_DANS_LE_DISQUE, b, rotation), echelle: b / 13 }
    }
    if (besoin > r0 * 1.0001) E = new Eventail(racineH, racineF, noeuds, new Reglages({ ...reglages, rayonCentralMin: besoin / CENTRE_DANS_LE_DISQUE })).calculer()
  }

  // L'arbre de boîtes : d'abord rapetisser les descendants pour qu'ils tiennent dans le disque ;
  // ensuite seulement, agrandir le disque.
  let centre: Agencement | null = null
  if (!autre) {
    const r0 = E.rC
    centre = agencer(familles, lignes, ECHELLE_MIN, r0)
    if (centre) {
      const e = plusGrand(ECHELLE_MIN, ECHELLE_MAX, (x) => agencer(familles, lignes, x, r0) !== null)
      centre = agencer(familles, lignes, e, r0)!
    } else {
      let rayon = r0
      while (!agencer(familles, lignes, ECHELLE_MIN, rayon)) rayon *= 1.03
      // Le plus petit rayon qui suffit, entre le dernier essai raté et le premier réussi.
      let lo = rayon / 1.03
      for (let i = 0; i < 12; i++) {
        const mid = (lo + rayon) / 2
        if (agencer(familles, lignes, ECHELLE_MIN, mid)) rayon = mid
        else lo = mid
      }
      centre = agencer(familles, lignes, ECHELLE_MIN, rayon)!
      E = new Eventail(racineH, racineF, noeuds, new Reglages({ ...reglages, rayonCentralMin: rayon })).calculer()
    }
  }

  const marge = 35.0
  const ext = etendues(E)
  const W = ext.gauche + ext.droite + 2 * marge
  const cx = marge + ext.gauche
  const cy = marge + ext.haut
  const chiffres: ChiffresDuPied = {
    ancetres: new Set(noeuds.map((n) => n.p.id)).size - 2,
    generations: E.nbNiveaux + 1,
    implexes: new Set(noeuds.map((n) => n.implexe).filter((i) => i)).size,
  }

  // La légende dans le coin haut gauche, le pied dans le coin haut droit, hors du cercle ;
  // sinon, sous le soleil.
  const encombrement = (k: number): [[number, number], [number, number]] => [
    legende(new Scene(1, 1), 0, 0, H, F, A, k),
    pied(new Scene(1, 1), 0, 0, chiffres, mention, date, 'end', k),
  ]
  const tient = (k: number): boolean => {
    const [[hl, wl], [hp, wp]] = encombrement(k)
    for (const [x0, w, h] of [
      [marge, wl, hl],
      [W - marge - wp, wp, hp],
    ] as const) {
      const bas = marge + h + 9 * k * 0.3528
      if ((x0 < cx && cx < x0 + w) || bas >= cy) return false
      const pas = 4.0
      const pts: [number, number][] = []
      for (let i = 0; i < Math.floor(w / pas) + 1; i++) pts.push([x0 + i * pas, bas])
      for (const j of [0, w]) for (let i = 0; i < Math.floor((bas - marge) / pas) + 1; i++) pts.push([x0 + j, marge + i * pas])
      if (!pts.every(([x, y]) => pointLibre(E, cx, cy, x, y, 8.0))) return false
    }
    return true
  }
  let kl: number | null = tient(1.0) ? Math.min(plusGrand(1.0, 3.0, tient), 1.15) : null
  const basSoleil = cy + ext.bas
  const hauteur = kl !== null ? basSoleil + marge : basSoleil + 22 + 52 * Math.max(1.0, W / 1100) + marge

  const scene = new Scene(W, hauteur)
  E.dessiner(scene, cx, cy)
  if (autre) autre.dessiner(scene, cx, cy)
  else if (centre) dessinerLeCentre(scene, centre, cx, cy)
  if (kl !== null) {
    const y0 = marge + 9 * kl * 0.3528
    legende(scene, marge, y0, H, F, A, kl)
    pied(scene, W - marge, y0, chiffres, mention, date, 'end', kl)
  } else {
    kl = Math.max(1.0, W / 1100)
    legende(scene, marge, basSoleil + 22, H, F, A, kl)
    pied(scene, W - marge, basSoleil + 22 + 30 * kl, chiffres, mention, date, 'end', kl)
  }
  return { scene, eventail: E, echelleDescendants: autre ? autre.echelle : centre!.e, echelleLegende: kl, chiffres, centre: [cx, cy] }
}
