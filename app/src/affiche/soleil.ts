/**
 * L'affiche « soleil » : l'ascendance sur le cercle entier, la descendance dans le disque central.
 *
 * Le cercle est l'éventail à 360° (`Reglages.angle`), tourné au choix (`Reglages.rotation`).
 * Deux centres possibles (`OptionsDAffiche.centre`) :
 *
 * - **le couple** de la fiche : il occupe le premier anneau, et ses descendants le disque ;
 * - **ses quatre parents** : ils occupent le premier anneau (celui du couple est masqué), et le
 *   disque reçoit leurs descendants (`quatreParents()`). Le couple au milieu, ses enfants en
 *   dessous ; les frères et sœurs du mari à gauche, ceux de la femme à droite, chaque famille
 *   pendue à son tronc, qui prend aussi le mari ou la femme. Chacun n'y figure qu'une fois.
 *
 * Le centre porte le titre, puis les sous-arbres des enfants **rangés en bandes qui suivent le
 * cercle** (`bandes.ts`) — ou le soleil intérieur, ou la rosace (`centres.ts`).
 *
 * Le disque garde sa taille tant que les descendants y tiennent à 55 % de leur corps ; ils
 * grossissent s'il y a de la place (jusqu'à 140 %). Au-delà, c'est le disque qui grandit.
 */
import { agencer, dessinerLesBandes, plusGrand, rayonPour, type Agencement, type Familles } from './bandes'
import { CORPS_MAX, CORPS_MIN, Rosace, SoleilInterieur, type Coeur, type FamilleDuCentre } from './centres'
import type { ArbreAffiche } from './donnees'
import { etendues, Eventail, pointLibre, Reglages } from './eventail'
import { chiffresDeDescendance, legende, lignesDesQuatreParents, lignesDuCouple, MENTION_PAR_DEFAUT, pied, type AfficheProduite, type ChiffresDuPied, type OptionsDAffiche } from './fabriquer'
import type { Style } from './polices'
import { Scene } from './scene'
import { ascendance, compter, compterQuatreParents, descendance, quatreParents, type Desc } from './structure'

/** Les bornes du corps des descendants, en part de leur corps de base (13 pt pour les enfants). */
const ECHELLE_MIN = 0.55
const ECHELLE_MAX = 1.4
/** Le soleil intérieur et la rosace occupent le disque jusqu'à cette part de son rayon. */
const CENTRE_DANS_LE_DISQUE = 0.97

/** L'affiche « soleil » du couple (`hId`, `fId`). */
export function afficheSoleil(A: ArbreAffiche, hId: number, fId: number, options: OptionsDAffiche = {}): AfficheProduite {
  const H = A.P.get(hId)
  const F = A.P.get(fId)
  if (!H || !F) throw new Error('Les deux époux doivent être dans l’arbre de l’affiche.')
  const mention = options.mention ?? MENTION_PAR_DEFAUT
  const date = options.date ?? new Date()
  const niveaux = options.niveauxDescendance ?? 6

  // Les quatre parents au centre : il faut connaître la famille d'origine de chacun des époux.
  const quatre = options.centre === 'parents' ? quatreParents(A, H, F, niveaux) : null

  const { racineH, racineF, noeuds } = ascendance(A, H, F, options.profondeur ?? Infinity)
  const reglages = new Reglages({ ...(options.reglages ?? new Reglages()), angle: 360, sens: 'haut', premierAnneauMasque: quatre !== null })
  let E = new Eventail(racineH, racineF, noeuds, reglages).calculer()

  let familles: Familles
  let lignes: (u: number) => [string, Style, number][]
  if (quatre) {
    familles = quatre
    lignes = lignesDesQuatreParents(A, quatre, H, F)
  } else {
    const { fam, enfants } = descendance(A, H, F, niveaux)
    familles = { seule: enfants }
    lignes = lignesDuCouple(A, fam, compter(enfants), H, F)
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
    const chiffresTexte = chiffresDeDescendance('seule' in familles ? compter(familles.seule) : compterQuatreParents(familles))
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
      const rayon = rayonPour(familles, lignes, ECHELLE_MIN, r0)
      centre = agencer(familles, lignes, ECHELLE_MIN, rayon)!
      E = new Eventail(racineH, racineF, noeuds, new Reglages({ ...reglages, rayonCentralMin: rayon })).calculer()
    }
  }

  const marge = 35.0
  const ext = etendues(E)
  const chiffres: ChiffresDuPied = {
    ancetres: new Set(noeuds.map((n) => n.p.id)).size - 2,
    generations: E.nbNiveaux + 1,
    implexes: new Set(noeuds.map((n) => n.implexe).filter((i) => i)).size,
  }

  // Une affiche carrée : le soleil est rond, et l'impression sur mesure se commande à l'échelle
  // 1:1. La légende dans le coin bas gauche, le pied dans le coin bas droit, hors du cercle ;
  // sinon, sous le soleil, et la page s'élargit pour rester carrée.
  const cote = Math.max(ext.gauche + ext.droite, ext.haut + ext.bas) + 2 * marge
  const cy = marge + ext.haut + (cote - 2 * marge - ext.haut - ext.bas) / 2
  const encombrement = (k: number): [[number, number], [number, number]] => [
    legende(new Scene(1, 1), 0, 0, H, F, A, k),
    pied(new Scene(1, 1), 0, 0, chiffres, mention, date, 'end', k),
  ]
  const tient = (k: number): boolean => {
    const cx = marge + ext.gauche + (cote - 2 * marge - ext.gauche - ext.droite) / 2
    const [[hl, wl], [hp, wp]] = encombrement(k)
    for (const [x0, w, h] of [
      [marge, wl, hl],
      [cote - marge - wp, wp, hp],
    ] as const) {
      const haut = cote - marge - (h + 9 * k * 0.3528)
      if ((x0 < cx && cx < x0 + w) || haut <= cy) return false
      const pas = 4.0
      const pts: [number, number][] = []
      for (let i = 0; i < Math.floor(w / pas) + 1; i++) pts.push([x0 + i * pas, haut])
      for (const j of [0, w]) for (let i = 0; i < Math.floor((cote - marge - haut) / pas) + 1; i++) pts.push([x0 + j, haut + i * pas])
      if (!pts.every(([x, y]) => pointLibre(E, cx, cy, x, y, 8.0))) return false
    }
    return true
  }
  let kl: number | null = tient(1.0) ? Math.min(plusGrand(1.0, 3.0, tient), 1.15) : null
  const basSoleil = cy + ext.bas
  const hauteur = kl !== null ? cote : basSoleil + 22 + 52 * Math.max(1.0, cote / 1100) + marge
  const W = Math.max(cote, hauteur)
  const cx = marge + ext.gauche + (W - 2 * marge - ext.gauche - ext.droite) / 2

  const scene = new Scene(W, hauteur)
  E.dessiner(scene, cx, cy)
  if (autre) autre.dessiner(scene, cx, cy)
  else if (centre) dessinerLesBandes(scene, centre, cx, cy)
  if (kl !== null) {
    // Chaque bloc finit sur le bas de la page.
    const [[hl], [hp]] = encombrement(kl)
    legende(scene, marge, hauteur - marge - hl, H, F, A, kl)
    pied(scene, W - marge, hauteur - marge - hp, chiffres, mention, date, 'end', kl)
  } else {
    kl = Math.max(1.0, cote / 1100)
    legende(scene, marge, basSoleil + 22, H, F, A, kl)
    pied(scene, W - marge, basSoleil + 22 + 30 * kl, chiffres, mention, date, 'end', kl)
  }
  return { scene, eventail: E, echelleDescendants: autre ? autre.echelle : centre!.e, echelleLegende: kl, chiffres, centre: [cx, cy] }
}
