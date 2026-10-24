/**
 * L'assemblage d'une affiche — portage de `fabriquer.py` (et de `couple.py` pour le masque
 * des vivants).
 *
 * L'éventail de l'ascendance en haut, le disque central (le couple, son mariage, sa
 * descendance en chiffres), les repères d'années sous la base, les descendants en dessous,
 * la légende des quatre lignées et le pied. Avec les quatre parents au premier anneau
 * (`OptionsDAffiche.centre`), les descendants sont les leurs : le couple au milieu, les frères
 * et sœurs de chacun de son côté, dans le demi-cercle sous la base. Tout est en millimètres ; la taille de l'affiche
 * **découle** de l'arbre (taille naturelle), la mise au format est l'affaire du rendu
 * (`svg.ts`).
 *
 * Un seul écart de fond avec l'original : le pied ne nomme personne. « Arbre établi par… »
 * portait le nom de l'auteur de l'affiche d'origine ; ici, la mention est un paramètre, et
 * vaut par défaut « Arbre établi avec Heritage » — le dépôt ne porte aucun nom réel
 * (ADR 0006).
 */
import { agencer, dessinerLesBandes, plusGrand, rayonPour, type Agencement } from './bandes'
import { dessinerDesc, inverserDesc, mesurerDesc, placerDesc, ReglesDesc } from './boites'
import { CORPS_MAX, CORPS_MIN, Rosace, SoleilInterieur, type FamilleDuCentre } from './centres'
import { titre } from './dates'
import type { ArbreAffiche, Personne } from './donnees'
import { etendueHaut, Eventail, pointLibre, Reglages } from './eventail'
import { ENCRE, ENCRE_DOUCE, melange, TEINTES, TRAIT, type Quartier } from './palette'
import { Style } from './polices'
import { ReglagesSablier, Sablier, type ModeDeSablier } from './sablier'
import { Scene } from './scene'
import { ascendance, compter, compterQuatreParents, descendance, quatreParents, type QuatreParents } from './structure'

export const MOIS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']

/** La mention du pied, par défaut. */
export const MENTION_PAR_DEFAUT = 'Arbre établi avec Heritage'

export function pluriel(n: number, mot: string, mots?: string): string {
  return `${n} ${n === 1 ? mot : (mots ?? `${mot}s`)}`
}

/** `round(a, -1)` de Python : à la dizaine, une demi-dizaine allant au pair (1745 → 1740). */
export function arrondiDizaine(a: number): number {
  const reste = ((a % 10) + 10) % 10
  if (reste < 5) return a - reste
  if (reste > 5) return a + 10 - reste
  return Math.floor(a / 10) % 2 === 0 ? a - 5 : a + 5
}

/** Le nom d'un époux dans un titre : « Le Cerfeuil » ; « ? » quand il n'est pas connu. */
const nomDeTitre = (p: Personne): string => (p.nom ? titre(p.nom) : '?')

/** Titre centré en x ; rend le bas du titre. (Le sablier s'en sert ; l'éventail titre dans son disque.) */
export function entete(scene: Scene, x: number, y: number, H: Personne, F: Personne): number {
  const t1 = new Style('CG', 64, ENCRE, 0.02, 'Bold')
  scene.texte(x, y + t1.mm * 0.8, `${nomDeTitre(H)} · ${nomDeTitre(F)}`, t1)
  y += t1.mm * 1.05
  const t2 = new Style('SS', 15, ENCRE_DOUCE, 0, 'Ital')
  scene.texte(x, y + t2.mm, `Ascendance et descendance de ${H.prenoms} ${H.nom} et de ${F.prenoms} ${F.nom}`, t2)
  return y + t2.mm * 1.6
}

const NOMS_DE_GENERATION: Record<number, [string, string]> = {
  1: ['enfant', 'enfants'],
  2: ['petit-enfant', 'petits-enfants'],
  3: ['arrière-petit-enfant', 'arrière-petits-enfants'],
  4: ['arrière-arrière-petit-enfant', 'arrière-arrière-petits-enfants'],
}

/** Les lignes d'un titre, du bas vers le haut : (texte, style, interligne après), au corps `u`. */
export type LignesDuTitre = (u: number) => [string, Style, number][]

/**
 * Les lignes du titre du centre, du bas vers le haut : (texte, style, interligne après) — la
 * descendance en chiffres (`parNiveau`, voir `compter()`), le mariage, ∞, le sous-titre, les deux
 * noms. Au corps `u` (1 : 46 pt pour les noms).
 */
export function lignesDuCouple(A: ArbreAffiche, fam: number | null, parNiveau: Map<number, number>, H: Personne, F: Personne): LignesDuTitre {
  const [date, lieu] = fam !== null ? A.mariage(fam) : [null, null]
  const chiffres = chiffresDeDescendance(parNiveau).join(' · ')
  let mariage = ''
  if (date?.connue) {
    mariage = date.qual === 'exacte' ? `mariés le ${date.texte('long')}` : `mariés ${date.texte('long')}`
    if (lieu) mariage += ` à ${lieu.split(',')[0]}`
  }
  return (u) => [
    [chiffres, new Style('SS', 11 * u, ENCRE_DOUCE, 0, 'Reg'), 1.0],
    [mariage, new Style('SS', 13 * u, ENCRE, 0, 'Ital'), 1.6],
    ['∞', new Style('SS', 24 * u, TRAIT, 0, 'Reg'), 0.9],
    ['Ascendance et descendance', new Style('SS', 12 * u, ENCRE_DOUCE, 0.02, 'Ital'), 1.15],
    [`${nomDeTitre(H)} · ${nomDeTitre(F)}`, new Style('CG', 46 * u, ENCRE, 0.01, 'Bold'), 1.0],
  ]
}

/** « 3 enfants », « 7 petits-enfants »… — une entrée par génération nommée, des enfants aux arrière-arrière-petits-enfants. */
export function chiffresDeDescendance(parNiveau: Map<number, number>): string[] {
  return [...parNiveau.keys()]
    .sort((a, b) => a - b)
    .filter((n) => n in NOMS_DE_GENERATION)
    .map((n) => pluriel(parNiveau.get(n)!, ...NOMS_DE_GENERATION[n]!))
}

/**
 * Le titre des quatre parents : les deux noms et la descendance des quatre parents en chiffres,
 * sans le mariage du couple — ces chiffres ne sont pas les siens.
 */
export function lignesDesQuatreParents(A: ArbreAffiche, q: QuatreParents, H: Personne, F: Personne): LignesDuTitre {
  const lignes = lignesDuCouple(A, null, compterQuatreParents(q), H, F)
  return (u) => lignes(u).filter(([t]) => t !== '∞')
}

/** Le disque central : les lignes du titre (`lignesDuCouple()`), au plus grand corps qui tient. */
export function centreCouple(scene: Scene, E: Eventail, cx: number, cy: number, lignes: LignesDuTitre): void {
  const r = E.rC

  // L'éventail qui descend : le disque est sous le centre, et le bloc s'y pose par le haut.
  const poserDessous = (u: number, dessiner = false): boolean => {
    const ls = lignes(u).filter(([t]) => Boolean(t))
    // Les lignes de base, du bas vers le haut, avec les mêmes écarts que dessus.
    const ys = [0]
    for (let i = 0; i + 1 < ls.length; i++) {
      const [, st, apres] = ls[i]!
      ys.push(ys[i]! - (st.mm * 0.74 + st.mm * 0.32 * apres + ls[i + 1]![1].mm * 0.26))
    }
    const dernier = ls.length - 1
    const decalage = cy + r * 0.1 - (ys[dernier]! - ls[dernier]![1].mm * 0.8)
    for (let i = 0; i < ls.length; i++) {
      const [t, st] = ls[i]!
      const y = decalage + ys[i]!
      const loin = y + st.mm * 0.25 - cy
      if (loin > r * 0.93) return false
      const corde = 2 * Math.sqrt(r * r - loin * loin) * 0.88
      if (st.largeur(t) > corde) return false
      if (dessiner) scene.texte(cx, y, t, st)
    }
    return true
  }

  const poser = (u: number, dessiner = false): boolean => {
    if (E.R.sens === 'bas') return poserDessous(u, dessiner)
    let y = cy - r * 0.1
    const ls = lignes(u).filter(([t]) => Boolean(t))
    for (let i = 0; i < ls.length; i++) {
      const [t, st, apres] = ls[i]!
      const haut = cy - (y - st.mm * 0.8)
      if (haut > r * 0.93) return false
      const corde = 2 * Math.sqrt(r * r - haut * haut) * 0.88
      if (st.largeur(t) > corde) return false
      if (dessiner) scene.texte(cx, y, t, st)
      if (i + 1 < ls.length) {
        const suivant = ls[i + 1]![1]
        y -= st.mm * 0.74 + st.mm * 0.32 * apres + suivant.mm * 0.26
      }
    }
    return true
  }

  let lo = 0.1
  let hi = 4.0
  for (let i = 0; i < 30; i++) {
    const mid = (lo + hi) / 2
    if (poser(mid)) lo = mid
    else hi = mid
  }
  poser(lo, true)
}

/** Sous la base de l'éventail (au-dessus, quand il descend), l'année de naissance médiane de chaque génération. */
export function reperesAnnees(scene: Scene, E: Eventail, cx: number, cy: number): void {
  const st = new Style('SS', 8.5, ENCRE_DOUCE, 0, 'Ital')
  const annees = E.anneesParAnneau()
  for (let g = 0; g < E.anneaux.length; g++) {
    if (E.R.angle > 181) break
    const { r1, r2 } = E.anneaux[g]!
    const rm = (r1 + r2) / 2
    const a = annees.get(g)
    if (g === 0 || !a) continue
    for (const sx of [-1, 1]) {
      const t = `nés vers ${g > 1 ? arrondiDizaine(a) : a}`
      scene.texte(cx + sx * rm, E.R.sens === 'bas' ? cy - 3 : cy + 6, t, st.echelle(Math.min(1, (r2 - r1 - 2) / st.largeur(t))))
    }
  }
}

/** Les signes, ligne par ligne — la légende de l'affiche d'origine, version du 22/10/2026. */
export const SIGNES = [
  '°  naissance     †  décès     ∞  mariage',
  'v.  vers     av.  avant     ap.  après',
  "◆  ancêtre présent à deux places de l'arbre (implexe)",
  '&  union sans mariage connu     ∞2  deuxième union de la personne',
  'signe barré, div.  divorcés     veuf, veuve  remarié(e) après la mort du conjoint',
]

/** Les quatre lignées en couleur, et les signes. `k` : facteur de taille. Rend (bas, largeur). */
export function legende(scene: Scene, x: number, y: number, H: Personne, F: Personne, A: ArbreAffiche, k = 1.0): [number, number] {
  const [pereH, mereH] = A.parents(H.id)
  const [pereF, mereF] = A.parents(F.id)
  const st = new Style('SS', 10 * k, ENCRE, 0, 'Reg')
  const stT = new Style('SS', 9 * k, TRAIT, 0.08, 'Semi')
  scene.texte(x, y, 'LES QUATRE LIGNÉES', stT, 'start')
  let largeur = stT.largeur('LES QUATRE LIGNÉES')
  y += 6 * k
  const entrees: [Quartier, Personne | null, Personne][] = [
    ['HP', pereH, H],
    ['HM', mereH, H],
    ['FP', pereF, F],
    ['FM', mereF, F],
  ]
  for (const [q, p, enfant] of entrees) {
    const [trait, rgb] = TEINTES[q]
    scene.rectangle(x, y - 3.6 * k, 9 * k, 5 * k, melange(rgb, 0.95), trait, 0.25, 1)
    const lien = q.endsWith('P') ? 'père' : 'mère'
    // « ? » aussi pour un parent connu sans nom, ou masqué : un tiret suivi de rien se lirait mal.
    const nom = p?.nom || '?'
    const prenom = enfant.prenoms.split(' ')[0]
    const t = lien === 'mère' ? `${nom} — lignée de la ${lien} de ${prenom}` : `${nom} — lignée du ${lien} de ${prenom}`
    scene.texte(x + 12 * k, y, t, st, 'start')
    largeur = Math.max(largeur, 12 * k + st.largeur(t))
    y += 6.6 * k
  }
  y += 2 * k
  const stS = new Style('SS', 9.5 * k, ENCRE_DOUCE, 0, 'Reg')
  for (const t of SIGNES) {
    scene.texte(x, y, t, stS, 'start')
    largeur = Math.max(largeur, stS.largeur(t))
    y += 5.4 * k
  }
  return [y, largeur]
}

export interface ChiffresDuPied {
  ancetres: number
  generations: number
  implexes: number
}

/** Le pied : les chiffres de l'ascendance, et la mention datée. Rend (bas, largeur). */
export function pied(
  scene: Scene,
  x: number,
  y: number,
  chiffres: ChiffresDuPied,
  mention: string,
  date: Date,
  ancre: 'start' | 'end' = 'end',
  k = 1.0,
): [number, number] {
  const st = new Style('SS', 9.5 * k, ENCRE_DOUCE, 0, 'Ital')
  const n = chiffres.implexes
  const lignes = [
    `${chiffres.ancetres} ancêtres sur ${chiffres.generations} générations`,
    ...(n ? [n === 1 ? `${n} couple d'ancêtres présent deux fois (implexe ◆)` : `${n} couples d'ancêtres présents deux fois (implexes ◆)`] : []),
    `${mention} — ${MOIS_FR[date.getMonth()]} ${date.getFullYear()}`,
  ]
  for (const t of lignes) {
    scene.texte(x, y, t, st, ancre)
    y += st.interligne * 1.1
  }
  return [y, Math.max(...lignes.map((t) => st.largeur(t)))]
}

export interface OptionsDAffiche {
  reglages?: Reglages
  /** Générations d'ascendants dessinées ; par défaut, tout ce que l'arbre connaît. */
  profondeur?: number
  /** Générations de descendants (6, comme l'original et comme la route). */
  niveauxDescendance?: number
  /** La mention du pied, avant le mois et l'année. */
  mention?: string
  /** La date du pied — fixée par les tests. */
  date?: Date
  /**
   * La forme des descendants : l'arbre de boîtes, le **soleil intérieur** (la descendance en
   * anneaux) ou la **rosace de médaillons** (une famille par médaillon) — `centres.ts`.
   */
  descendants?: FormeDesDescendants
  /**
   * Ce que porte le premier anneau de l'éventail ou du soleil, et dont on montre la descendance :
   * le couple, ou ses quatre parents (`quatreParents()`) — le couple, ses frères et sœurs, et leurs
   * descendants. Sans la famille d'origine de l'un des époux, on retombe sur le couple.
   */
  centre?: CentreDeLAffiche
}

export type FormeDesDescendants = 'arbre' | 'soleil' | 'medaillons'
export type CentreDeLAffiche = 'couple' | 'parents'

/** Les bornes du corps des descendants rangés en bandes, en part de leur corps de base (13 pt pour les enfants). */
const ECHELLE_MIN = 0.55
const ECHELLE_MAX = 1.4

export interface AfficheProduite {
  scene: Scene
  eventail: Eventail
  /** Le facteur des descendants (1 = corps de base), et celui de la légende. */
  echelleDescendants: number
  echelleLegende: number
  chiffres: ChiffresDuPied
  /** Le centre de l'éventail (mm). */
  centre: readonly [number, number]
}

/** L'affiche « éventail » du couple (`hId`, `fId`) — `affiche_eventail()`. */
export function afficheEventail(A: ArbreAffiche, hId: number, fId: number, options: OptionsDAffiche = {}): AfficheProduite {
  const H = A.P.get(hId)
  const F = A.P.get(fId)
  if (!H || !F) throw new Error('Les deux époux doivent être dans l’arbre de l’affiche.')
  const mention = options.mention ?? MENTION_PAR_DEFAUT
  const date = options.date ?? new Date()

  const niveaux = options.niveauxDescendance ?? 6
  // Les quatre parents au premier anneau : celui du couple est masqué (comme dans le soleil).
  const quatre = options.centre === 'parents' ? quatreParents(A, H, F, niveaux) : null

  const { racineH, racineF, noeuds } = ascendance(A, H, F, options.profondeur ?? Infinity)
  const reglages = quatre ? new Reglages({ ...(options.reglages ?? new Reglages()), premierAnneauMasque: true }) : options.reglages
  const E = new Eventail(racineH, racineF, noeuds, reglages).calculer()
  const { fam, enfants } = descendance(A, H, F, niveaux)
  const lignes = quatre ? lignesDesQuatreParents(A, quatre, H, F) : lignesDuCouple(A, fam, compter(enfants), H, F)
  const R = E.rExt

  // Les descendants prennent jusqu'à 70 % de la largeur de l'éventail, sans dépasser 20 pt.
  let echelle = 1.0
  const reglesPour = (e: number): ReglesDesc => new ReglesDesc([13, 11, 9.5, 9].map((c) => c * e), 'long', 3 * e, 11 * e, 5.5 * e, 34 * e)
  let regles = reglesPour(echelle)
  let [largDesc, hauteurs] = mesurerDesc(enfants, regles)
  // Sans descendant, rien à mettre à l'échelle (l'original divisait par zéro).
  if (largDesc > 0) {
    for (let i = 0; i < 12; i++) {
      regles = reglesPour(echelle)
      ;[largDesc, hauteurs] = mesurerDesc(enfants, regles)
      const k = Math.min((0.7 * 2 * R) / largDesc, 20 / 13)
      if (Math.abs(k - echelle) < 0.01) break
      echelle = k
    }
  }

  const marge = 35.0
  // Qui monte : l'éventail en haut, les descendants en dessous. Qui descend : les descendants
  // en haut, retournés (les enfants contre le couple), l'éventail en dessous.
  const dessous = E.R.sens === 'bas'

  // Le soleil intérieur ou la rosace (`centres.ts`) : un demi-cercle sous la base de l'éventail —
  // au-dessus quand il descend —, qui referme le cercle des ancêtres par celui des descendants.
  // Au plus le rayon de l'éventail, tant que le texte y tient à son plancher.
  let demi: { rayon: number; demiLargeur: number; corps: number; dessiner: (scene: Scene, cx: number, cy: number) => void } | null = null
  const forme = options.descendants ?? 'arbre'
  // Les quatre parents : de gauche à droite, la fratrie du mari, le couple, celle de la femme —
  // l'aîné de chaque côté au plus près du couple, comme dans le soleil.
  const familles: FamilleDuCentre[] = quatre
    ? [
        ...[...quatre.gauche].reverse().map((d): FamilleDuCentre => ({ cote: 'p', couple: null, tete: d })),
        { cote: 'n', couple: [H, F], tete: quatre.couple },
        ...quatre.droite.map((d): FamilleDuCentre => ({ cote: 'v', couple: null, tete: d })),
      ]
    : enfants.map((d) => ({ cote: 'n', couple: null, tete: d }))
  if (forme !== 'arbre' && familles.length > 0) {
    if (forme === 'soleil') {
      const s = new SoleilInterieur(familles, null, false)
      const pose = s.poseParDefaut(0, dessous)
      const partout = (b: number): number[] => Array.from({ length: s.anneaux }, () => b)
      // Au moins un peu plus de la moitié de l'éventail, pour que les deux demi-cercles se répondent.
      const rayon = Math.max(s.rayonRequis(partout(CORPS_MIN), pose), Math.min(R, Math.max(s.rayonRequis(partout(CORPS_MIN * 1.25), pose), 0.55 * R)))
      const corps = s.corpsPour(rayon, pose, CORPS_MIN, CORPS_MAX)
      demi = { rayon, demiLargeur: rayon, corps: corps[0]!, dessiner: (scene, x, y) => s.dessiner(scene, x, y, rayon, corps, pose) }
    } else {
      const rosace = new Rosace(familles, null, false)
      const dans = (b: number): boolean => rosace.encombrementDemi(b).demiLargeur <= R
      let b = CORPS_MIN
      if (dans(b)) {
        let hi = CORPS_MAX
        for (let i = 0; i < 16; i++) {
          const mid = (b + hi) / 2
          if (dans(mid)) b = mid
          else hi = mid
        }
      }
      const { demiLargeur, profondeur } = rosace.encombrementDemi(b)
      const corps = b
      demi = { rayon: profondeur, demiLargeur, corps, dessiner: (scene, x, y) => rosace.dessiner(scene, x, y, 0, corps, 0, dessous) }
    }
  }
  /** Entre la base de l'éventail et le demi-cercle des descendants : la place des repères d'années. */
  const ECART_DU_DEMI = 14

  // L'arbre de boîtes des quatre parents : rangé en bandes dans le demi-disque sous la base, du
  // rayon de l'éventail (`bandes.ts`) ; les descendants rapetissent pour y tenir, puis il grandit.
  let bandes: Agencement | null = null
  if (forme === 'arbre' && quatre) {
    const rayon = rayonPour(quatre, null, ECHELLE_MIN, R)
    const e = plusGrand(ECHELLE_MIN, ECHELLE_MAX, (x) => agencer(quatre, null, x, rayon) !== null)
    bandes = agencer(quatre, null, e, rayon)!
    // Le bloc, centré sous l'éventail, en déborde peut-être d'un côté : la page s'élargit des deux.
    largDesc = 2 * rayon
  }

  const W = Math.max(2 * R, demi ? 2 * demi.demiLargeur : largDesc) + 2 * marge
  let cy: number
  let basDesc: number
  let cyDemi = 0
  if (bandes && dessous) {
    cy = marge + bandes.bas
    basDesc = cy + etendueHaut(E) + 4
  } else if (bandes) {
    cy = marge + etendueHaut(E) + 4
    basDesc = cy + bandes.bas
  } else if (demi && dessous) {
    cyDemi = marge + demi.rayon
    cy = cyDemi + ECART_DU_DEMI
    basDesc = cy + etendueHaut(E) + 4
  } else if (demi) {
    cy = marge + etendueHaut(E) + 4
    cyDemi = cy + ECART_DU_DEMI
    basDesc = cyDemi + demi.rayon
  } else if (dessous) {
    const basBloc = placerDesc(enfants, W / 2 - largDesc / 2, marge, hauteurs, regles)
    inverserDesc(enfants, marge + basBloc)
    cy = basBloc + 26
    basDesc = cy + etendueHaut(E) + 4
  } else {
    cy = marge + etendueHaut(E) + 4
    basDesc = placerDesc(enfants, W / 2 - largDesc / 2, cy + 26, hauteurs, regles)
  }
  const chiffres: ChiffresDuPied = {
    ancetres: new Set(noeuds.map((n) => n.p.id)).size - 2,
    generations: E.nbNiveaux + 1,
    implexes: new Set(noeuds.map((n) => n.implexe).filter((i) => i)).size,
  }

  // La légende va dans le coin haut gauche et le pied dans le coin haut droit, hors du
  // demi-cercle de l'éventail — dans les coins du bas quand il descend : on prend le plus grand
  // corps qui y tient. `basDesc` est alors le bas de l'éventail.
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
      const pas = 4.0
      const pts: [number, number][] = []
      if (dessous) {
        const haut = basDesc - (h + 9 * k * 0.3528)
        if ((x0 < W / 2 && W / 2 < x0 + w) || haut <= cy) return false
        for (let i = 0; i < Math.floor(w / pas) + 1; i++) pts.push([x0 + i * pas, haut])
        for (const j of [0, w]) for (let i = 0; i < Math.floor((basDesc - haut) / pas) + 1; i++) pts.push([x0 + j, haut + i * pas])
      } else {
        const bas = marge + h + 9 * k * 0.3528
        if ((x0 < W / 2 && W / 2 < x0 + w) || bas >= cy) return false
        for (let i = 0; i < Math.floor(w / pas) + 1; i++) pts.push([x0 + i * pas, bas])
        for (const j of [0, w]) for (let i = 0; i < Math.floor((bas - marge) / pas) + 1; i++) pts.push([x0 + j, marge + i * pas])
      }
      if (!pts.every(([x, y]) => pointLibre(E, W / 2, cy, x, y, 8.0))) return false
    }
    return true
  }

  let kl: number | null = null
  if (tient(1.0)) {
    let lo = 1.0
    let hi = 3.0
    for (let i = 0; i < 20; i++) {
      const mid = (lo + hi) / 2
      if (tient(mid)) lo = mid
      else hi = mid
    }
    kl = Math.min(lo, 1.15) // pas plus grand qu'il ne faut : le corps imprimé reste confortable
  }
  const hauteur = kl !== null ? basDesc + marge : basDesc + 22 + 52 * Math.max(1.0, W / 1100) + marge

  const scene = new Scene(W, hauteur)
  E.dessiner(scene, W / 2, cy)
  centreCouple(scene, E, W / 2, cy, lignes)
  reperesAnnees(scene, E, W / 2, cy)
  if (bandes) dessinerLesBandes(scene, bandes, W / 2, cy, dessous)
  else if (demi) demi.dessiner(scene, W / 2, cyDemi)
  else dessinerDesc(scene, enfants, [W / 2, cy], regles, dessous)
  if (kl !== null && dessous) {
    // Chaque bloc finit sur le bas de l'éventail.
    const [[hl], [hp]] = encombrement(kl)
    legende(scene, marge, basDesc - hl, H, F, A, kl)
    pied(scene, W - marge, basDesc - hp, chiffres, mention, date, 'end', kl)
  } else if (kl !== null) {
    const y0 = marge + 9 * kl * 0.3528
    legende(scene, marge, y0, H, F, A, kl)
    pied(scene, W - marge, y0, chiffres, mention, date, 'end', kl)
  } else {
    const yBas = basDesc + 22
    kl = Math.max(1.0, W / 1100)
    legende(scene, marge, yBas, H, F, A, kl)
    pied(scene, W - marge, yBas + 30 * kl, chiffres, mention, date, 'end', kl)
  }
  return { scene, eventail: E, echelleDescendants: bandes ? bandes.e : demi ? demi.corps / 13 : echelle, echelleLegende: kl, chiffres, centre: [W / 2, cy] }
}

export interface SablierProduit {
  scene: Scene
  sablier: Sablier
  echelleDescendants: number
  chiffres: ChiffresDuPied
}

/** L'affiche « sablier » du couple — `affiche_sablier()`, modes `normal` et `dense`. */
export function afficheSablier(A: ArbreAffiche, hId: number, fId: number, mode: ModeDeSablier = 'normal', options: OptionsDAffiche = {}): SablierProduit {
  const H = A.P.get(hId)
  const F = A.P.get(fId)
  if (!H || !F) throw new Error('Les deux époux doivent être dans l’arbre de l’affiche.')
  const mention = options.mention ?? MENTION_PAR_DEFAUT
  const date = options.date ?? new Date()

  const { racineH, racineF, noeuds } = ascendance(A, H, F, options.profondeur ?? Infinity)
  const S = new Sablier(racineH, racineF, noeuds, new ReglagesSablier(mode)).calculer()
  const { fam, enfants } = descendance(A, H, F, options.niveauxDescendance ?? 6)
  const largeurArbre = S.droite - S.gauche

  let echelle = 1.0
  const base = mode === 'normal' ? [13, 11, 9.5, 9] : [11, 9.5, 8.5, 8]
  const reglesPour = (e: number): ReglesDesc =>
    new ReglesDesc(
      base.map((c) => c * e),
      mode === 'normal' ? 'long' : 'abr',
      3 * e,
      10 * e,
      5.5 * e,
      30 * e,
    )
  let regles = reglesPour(echelle)
  let [largDesc, hauteurs] = mesurerDesc(enfants, regles)
  if (largDesc > 0) {
    for (let i = 0; i < 12; i++) {
      regles = reglesPour(echelle)
      ;[largDesc, hauteurs] = mesurerDesc(enfants, regles)
      const k = Math.min((0.55 * largeurArbre) / largDesc, 18 / base[0]!)
      if (Math.abs(k - echelle) < 0.01) break
      echelle = k
    }
  }

  const marge = 35.0
  const W = Math.max(largeurArbre, largDesc) + 2 * marge
  const basTitre = entete(new Scene(10, 10), W / 2, marge, H, F)
  const ox = marge - S.gauche + (W - 2 * marge - largeurArbre) / 2
  const oy = basTitre + 14 - S.haut
  const h0 = S.hauteurRang.get(0)!
  const yDesc = oy + 16
  const basDesc = placerDesc(enfants, ox - largDesc / 2, yDesc, hauteurs, regles)
  const kl = Math.max(1.0, Math.min(1.6, W / 1100))
  const hauteur = basDesc + 24 + 52 * kl + marge

  const scene = new Scene(W, hauteur)
  entete(scene, W / 2, marge, H, F)
  S.dessiner(scene, ox, oy)
  // Le lien du couple, et la date du mariage.
  const [dateMariage, lieu] = fam !== null ? A.mariage(fam) : [null, null]
  const stInf = new Style('SS', 16, TRAIT, 0, 'Reg')
  scene.texte(ox, oy - h0 / 2 + stInf.mm * 0.3, '∞', stInf)
  if (dateMariage?.connue) {
    const stM = new Style('SS', 9.5, ENCRE_DOUCE, 0, 'Ital')
    scene.texte(ox, oy + 6.5, dateMariage.texte('long') + (lieu ? ` — ${lieu.split(',')[0]}` : ''), stM)
  }
  dessinerDesc(scene, enfants, [ox, oy + 9], regles)
  // Les repères d'années, au bord gauche de chaque rangée.
  const st = new Style('SS', 8.5, ENCRE_DOUCE, 0, 'Ital')
  for (const [g, a] of S.anneesParRang()) {
    if (g === 0 || !a) continue
    const y = oy + S.basRang.get(g)! - S.hauteurRang.get(g)! / 2
    scene.texte(marge * 0.45, y, `vers ${g > 1 ? arrondiDizaine(a) : a}`, st, 'middle', `rotate(-90 ${(marge * 0.45).toFixed(2)} ${y.toFixed(2)})`)
  }
  const yBas = basDesc + 24
  const chiffres: ChiffresDuPied = {
    ancetres: new Set(noeuds.map((n) => n.p.id)).size - 2,
    generations: S.nbNiveaux + 1,
    implexes: new Set(noeuds.map((n) => n.implexe).filter((i) => i)).size,
  }
  legende(scene, marge, yBas, H, F, A, kl)
  pied(scene, W - marge, yBas + 30 * kl, chiffres, mention, date, 'end', kl)
  return { scene, sablier: S, echelleDescendants: echelle, chiffres }
}
