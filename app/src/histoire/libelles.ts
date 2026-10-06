/**
 * Ce qui s'écrit PAR-DESSUS les boîtes, en coordonnées écran : le texte et la
 * photo de chaque fiche visible, les noms de foyers du niveau Familles, les
 * anneaux de sélection.
 *
 * Calculé pour un cadrage donné — l'instantané que `useCadrage` publie à Vue à la
 * fin d'un geste, à un changement de niveau, ou au plus quelques fois par
 * seconde. Entre deux, la couche est tenue à sa place par une transformation
 * (`transformationRelative`). D'où la `MARGE` : on écrit un peu au-delà du bord,
 * pour qu'un glisser ne découvre pas des fiches muettes.
 *
 * Pur, sans DOM.
 */
import { nomDeFiche } from '@/components/arbre/graphe'
import { entreePortrait, geometriePortrait, texteDePortrait } from './cartes'
import { poserEtiquettes } from './etiquettes'
import { nomEcrit, prenomDe, type EtiquetteFoyer, type FicheHistoire } from './lignees'
import { dansLaFenetre, reductionDuTexte, syDe, texteDeFiche, type Cadrage, type Canevas, type Niveau } from './vue'

/** Les textes sont posés jusqu'à 40 % de la fenêtre au-delà de chaque bord. */
export const MARGE = 0.4

/** L'emplacement photo mesuré par le moteur (`LARGEUR_PHOTO`, `ESPACE_PHOTO`) : 40 px, 8 px de marge. */
const LARGEUR_PHOTO = 40
const ESPACE_PHOTO = 8

interface LibelleCommun {
  id: string
  /** `src` : le portrait s'il y en a un (clé absente sur une fiche restreinte), la silhouette sinon. */
  photo: { x: number; y: number; d: number; src: string | null }
  x: number
  y: number
  w: number
  h: number
  directe: boolean
  estompe: boolean
  /** Le texte est posé à la taille qu'il aurait à 80 % (`w`, `h` en
   *  coordonnées de ce zoom-là) puis réduit de ce facteur (`reductionDuTexte`). */
  reduction: number
}
/** Compact : la photo à gauche, une ou deux lignes à côté (`texteDeFiche`). */
export interface LibelleCompact extends LibelleCommun {
  forme: 'compact'
  t1: string
  t2: string
  taille: number
}
/** Portrait : la photo en haut, NOM, prénom et dates dessous (`texteDePortrait`). */
export interface LibellePortrait extends LibelleCommun {
  forme: 'portrait'
  nom: string
  prenom: string
  dates: string[]
  /** « Personne vivante » : ni capitales, ni rien d'autre. */
  restreinte: boolean
}
export type Libelle = LibelleCompact | LibellePortrait

export interface ParametresDesLibelles {
  /** Les fiches qui passent les filtres (les autres ne s'écrivent pas). */
  fiches: readonly FicheHistoire[]
  cadrage: Cadrage
  canevas: Canevas
  niveau: Niveau
  portrait: boolean
  directe: (f: FicheHistoire) => boolean
  estompe: (f: FicheHistoire) => boolean
}

/** Le texte des fiches visibles, en coordonnées écran (culling). */
export function libellesDesFiches(p: ParametresDesLibelles): Libelle[] {
  // Familles : les photos seules (si elles existent), sans texte ; Fiches : tout.
  if (p.niveau < 1) return []
  const { s, tx, ty } = p.cadrage
  const sy = syDe(s, p.canevas)
  const X = (x: number): number => tx + x * s
  const Y = (y: number): number => ty + y * sy
  const avecTexte = p.niveau >= 2
  const r = reductionDuTexte(s)
  const visibles = p.fiches.filter((f) => dansLaFenetre(p.cadrage, p.canevas, f.x, f.y, f.w, f.h, MARGE))
  const commun = (f: FicheHistoire) => ({ id: f.id, directe: p.directe(f), estompe: p.estompe(f), reduction: r })
  const src = (f: FicheHistoire): string | null => (typeof f.noeud.portrait === 'string' ? f.noeud.portrait : null)

  if (p.portrait) {
    return visibles.map((f): LibellePortrait => {
      const g = geometriePortrait(X(f.x), Y(f.y), s, sy)
      const entree = entreePortrait(f.noeud)
      const w = g.texte.w / r
      const h = g.texte.h / r
      const texte = avecTexte ? texteDePortrait(entree, w, h) : { nom: '', prenom: '', dates: [] }
      return {
        ...commun(f),
        forme: 'portrait',
        ...texte,
        restreinte: entree.restreinte,
        photo: { ...g.photo, src: src(f) },
        ...g.texte,
        w,
        h,
      }
    })
  }

  const d0 = LARGEUR_PHOTO * Math.min(s, sy)
  const texte = (ESPACE_PHOTO * 2 + LARGEUR_PHOTO) * s
  return visibles.map((f): LibelleCompact => {
    const w = Math.max(0, f.w * s - texte - 4) / r
    const h = (f.h * sy) / r
    return {
      ...commun(f),
      forme: 'compact',
      ...(avecTexte
        ? texteDeFiche(
            {
              // Un nœud restreint s'écrit « Personne vivante », quoi que porte `etiquetteNom`.
              nom: f.noeud.visibilite === 'restreinte' ? nomEcrit(f.noeud) : nomDeFiche(f.noeud).texte,
              prenom: prenomDe(f.noeud),
              // Absente sur un nœud restreint : ni tiret, ni case vide.
              dates: typeof f.noeud.etiquetteDates === 'string' ? f.noeud.etiquetteDates : '',
            },
            w,
            h,
          )
        : { t1: '', t2: '', taille: 12 }),
      photo: { x: X(f.x) + ESPACE_PHOTO * s, y: Y(f.y) + (f.h * sy - d0) / 2, d: d0, src: src(f) },
      x: X(f.x) + texte,
      y: Y(f.y),
      w,
      h,
    }
  })
}

export interface LibelleFoyer {
  cle: string
  x: number
  y: number
  nom: string
  ancetres: boolean
}

/**
 * Les noms de foyer du niveau Familles. Ils ne se recouvrent jamais : posés par
 * priorité — les couples d'ancêtres, puis les plus larges —, chacun dans la largeur
 * de son couple et seulement là où rien n'est déjà écrit ; un nom qui ne tient pas
 * se tronque (« Ducat… ») ou se tait (`etiquettes.ts`).
 */
export function libellesDesFoyers(
  foyers: readonly EtiquetteFoyer[],
  cadrage: Cadrage,
  canevas: Canevas,
  niveau: Niveau,
  fort: boolean,
): LibelleFoyer[] {
  if (niveau !== 1) return []
  const { s, tx, ty } = cadrage
  const sy = syDe(s, canevas)
  const demandes = foyers
    .filter((c) => (c.ancetres || s > 0.25) && c.envergure * s > 44 && dansLaFenetre(cadrage, canevas, c.x - 40, c.y - 20, 80, 20, MARGE))
    .map((c, i) => {
      const x = tx + c.x * s
      // Un peu plus large que le couple : un nom court déborde volontiers de deux fiches serrées.
      const demi = (c.envergure * s) / 2 + 12
      return {
        cle: String(i),
        x,
        y: ty + c.y * sy - 15,
        nom: c.nom,
        taille: c.ancetres && fort ? 11.5 : 10.5,
        min: x - demi,
        max: x + demi,
        priorite: (c.ancetres ? 1e9 : 0) + c.envergure,
        ancetres: c.ancetres,
      }
    })
  const ancetres = new Map(demandes.map((x) => [x.cle, x.ancetres]))
  return poserEtiquettes(demandes, { marge: 6, hauteur: 14 }).map((e) => ({
    cle: e.cle,
    x: e.x,
    y: e.y,
    nom: e.nom,
    ancetres: ancetres.get(e.cle) ?? false,
  }))
}

export interface Anneau {
  id: string
  x: number
  y: number
  w: number
  h: number
}

/** Un anneau autour d'une fiche, à `ecart` px de ses bords ; `null` hors de la fenêtre. */
export function anneauDe(f: FicheHistoire, cadrage: Cadrage, canevas: Canevas, ecart: number): Anneau | null {
  if (!dansLaFenetre(cadrage, canevas, f.x, f.y, f.w, f.h, MARGE)) return null
  const sy = syDe(cadrage.s, canevas)
  return {
    id: f.id,
    x: cadrage.tx + f.x * cadrage.s - ecart,
    y: cadrage.ty + f.y * sy - ecart,
    w: f.w * cadrage.s + 2 * ecart,
    h: f.h * sy + 2 * ecart,
  }
}
