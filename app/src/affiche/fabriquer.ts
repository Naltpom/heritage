/**
 * L'assemblage d'une affiche — portage de `fabriquer.py` (et de `couple.py` pour le masque
 * des vivants).
 *
 * L'éventail de l'ascendance en haut, le disque central (le couple, son mariage, sa
 * descendance en chiffres), les repères d'années sous la base, les descendants en dessous,
 * la légende des quatre lignées et le pied. Tout est en millimètres ; la taille de l'affiche
 * **découle** de l'arbre (taille naturelle), la mise au format est l'affaire du rendu
 * (`svg.ts`).
 *
 * Un seul écart de fond avec l'original : le pied ne nomme personne. « Arbre établi par… »
 * portait le nom de l'auteur de l'affiche d'origine ; ici, la mention est un paramètre, et
 * vaut par défaut « Arbre établi avec Heritage » — le dépôt ne porte aucun nom réel
 * (ADR 0006).
 */
import { dessinerDesc, mesurerDesc, placerDesc, ReglesDesc } from './boites'
import { titre } from './dates'
import type { ArbreAffiche, Personne } from './donnees'
import { etendueHaut, Eventail, pointLibre, Reglages } from './eventail'
import { ENCRE, ENCRE_DOUCE, melange, TEINTES, TRAIT, type Quartier } from './palette'
import { Style } from './polices'
import { Scene } from './scene'
import { ascendance, compter, descendance, type Desc } from './structure'

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

/** Le disque central : le titre, le mariage et la descendance en chiffres, au plus grand corps qui tient. */
export function centreCouple(scene: Scene, E: Eventail, cx: number, cy: number, A: ArbreAffiche, fam: number | null, enfants: Desc[], H: Personne, F: Personne): void {
  const r = E.rC
  const [date, lieu] = fam !== null ? A.mariage(fam) : [null, null]
  const c = compter(enfants)
  const chiffres = [...c.keys()]
    .sort((a, b) => a - b)
    .filter((n) => n in NOMS_DE_GENERATION)
    .map((n) => pluriel(c.get(n)!, ...NOMS_DE_GENERATION[n]!))
    .join(' · ')
  let mariage = ''
  if (date?.connue) {
    mariage = date.qual === 'exacte' ? `mariés le ${date.texte('long')}` : `mariés ${date.texte('long')}`
    if (lieu) mariage += ` à ${lieu.split(',')[0]}`
  }
  // Du bas vers le haut : (texte, style, interligne après).
  const lignes = (u: number): [string, Style, number][] => [
    [chiffres, new Style('SS', 11 * u, ENCRE_DOUCE, 0, 'Reg'), 1.0],
    [mariage, new Style('SS', 13 * u, ENCRE, 0, 'Ital'), 1.6],
    ['∞', new Style('SS', 24 * u, TRAIT, 0, 'Reg'), 0.9],
    ['Ascendance et descendance', new Style('SS', 12 * u, ENCRE_DOUCE, 0.02, 'Ital'), 1.15],
    [`${nomDeTitre(H)} · ${nomDeTitre(F)}`, new Style('CG', 46 * u, ENCRE, 0.01, 'Bold'), 1.0],
  ]

  const poser = (u: number, dessiner = false): boolean => {
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

/** Sous la base de l'éventail, l'année de naissance médiane de chaque génération. */
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
      scene.texte(cx + sx * rm, cy + 6, t, st.echelle(Math.min(1, (r2 - r1 - 2) / st.largeur(t))))
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
}

export interface AfficheProduite {
  scene: Scene
  eventail: Eventail
  /** Le facteur des descendants (1 = corps de base), et celui de la légende. */
  echelleDescendants: number
  echelleLegende: number
  chiffres: ChiffresDuPied
}

/** L'affiche « éventail » du couple (`hId`, `fId`) — `affiche_eventail()`. */
export function afficheEventail(A: ArbreAffiche, hId: number, fId: number, options: OptionsDAffiche = {}): AfficheProduite {
  const H = A.P.get(hId)
  const F = A.P.get(fId)
  if (!H || !F) throw new Error('Les deux époux doivent être dans l’arbre de l’affiche.')
  const mention = options.mention ?? MENTION_PAR_DEFAUT
  const date = options.date ?? new Date()

  const { racineH, racineF, noeuds } = ascendance(A, H, F, options.profondeur ?? Infinity)
  const E = new Eventail(racineH, racineF, noeuds, options.reglages).calculer()
  const { fam, enfants } = descendance(A, H, F, options.niveauxDescendance ?? 6)
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
  const W = Math.max(2 * R, largDesc) + 2 * marge
  const cy = marge + etendueHaut(E) + 4
  const yDesc = cy + 26
  const basDesc = placerDesc(enfants, W / 2 - largDesc / 2, yDesc, hauteurs, regles)
  const chiffres: ChiffresDuPied = {
    ancetres: new Set(noeuds.map((n) => n.p.id)).size - 2,
    generations: E.nbNiveaux + 1,
    implexes: new Set(noeuds.map((n) => n.implexe).filter((i) => i)).size,
  }

  // La légende va dans le coin haut gauche et le pied dans le coin haut droit, hors du
  // demi-cercle de l'éventail : on prend le plus grand corps qui y tient.
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
      if ((x0 < W / 2 && W / 2 < x0 + w) || bas >= cy) return false
      const pas = 4.0
      const pts: [number, number][] = []
      for (let i = 0; i < Math.floor(w / pas) + 1; i++) pts.push([x0 + i * pas, bas])
      for (const j of [0, w]) for (let i = 0; i < Math.floor((bas - marge) / pas) + 1; i++) pts.push([x0 + j, marge + i * pas])
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
  centreCouple(scene, E, W / 2, cy, A, fam, enfants, H, F)
  reperesAnnees(scene, E, W / 2, cy)
  dessinerDesc(scene, enfants, [W / 2, cy], regles)
  if (kl !== null) {
    const y0 = marge + 9 * kl * 0.3528
    legende(scene, marge, y0, H, F, A, kl)
    pied(scene, W - marge, y0, chiffres, mention, date, 'end', kl)
  } else {
    const yBas = basDesc + 22
    kl = Math.max(1.0, W / 1100)
    legende(scene, marge, yBas, H, F, A, kl)
    pied(scene, W - marge, yBas + 30 * kl, chiffres, mention, date, 'end', kl)
  }
  return { scene, eventail: E, echelleDescendants: echelle, echelleLegende: kl, chiffres }
}
