/**
 * L'apparence des boîtes et des traits de l'arbre, en fonctions pures.
 *
 * **Aucune `opacity` ici** (ADR 0007, règle 2, et sa note du 2026-10-06). Une
 * fiche estompée par un filtre ou par la recherche l'est **dans la peinture
 * même** : son fond et ses bordures prennent une couleur partiellement
 * transparente (`attenuer`, l'équivalent HTML de `fill-opacity`), un trait prend
 * `stroke-opacity`. Rien ne crée de contexte d'empilement par élément sur un
 * canevas de plusieurs centaines de fiches.
 *
 * Pur, sans DOM.
 */
import type { NoeudPersonne } from '@/lib/api'
import { attenuer, couleurBranche } from './branches'
import type { FicheHistoire } from './lignees'
import type { Niveau } from './vue'

/** Ce qu'une fiche hors filtre garde de sa couleur. */
export const ALPHA_HORS_FILTRE = 0.1
/** Ce qu'une fiche garde quand une recherche en désigne d'autres. */
export const ALPHA_HORS_RECHERCHE = 0.25

/** L'estompage d'une fiche : 1 si elle est pleinement visible. */
export function alphaDeFiche(passe: boolean, ciblees: ReadonlySet<string>, id: string): number {
  if (!passe) return ALPHA_HORS_FILTRE
  return ciblees.size > 0 && !ciblees.has(id) ? ALPHA_HORS_RECHERCHE : 1
}

export interface ContexteDeFiche {
  /** Niveau Ensemble : des rectangles de couleur. */
  ensemble: boolean
  /** Format Portrait : liseré en haut, coins plus ronds. */
  portrait: boolean
  /** 1 = pleine ; moins = estompée (`alphaDeFiche`). */
  alpha: number
  /** Place dans la ligne directe surlignée, s'il y en a une. */
  place: 'ascendant' | 'descendant' | null
  /** La branche qui teinte la fiche (`hors` pour une famille par alliance à part). */
  branche: string
  /** L'animation d'apparition, s'il y en a une. */
  apparition?: Record<string, string>
}

const sexeDe = (n: NoeudPersonne): 'homme' | 'femme' | 'inconnu' =>
  n.sex === 'masculin' ? 'homme' : n.sex === 'feminin' ? 'femme' : 'inconnu'

/** Le style d'une boîte, en coordonnées monde. Les clés sont celles de `CSSStyleDeclaration`. */
export function styleDeFiche(f: FicheHistoire, c: ContexteDeFiche): Record<string, string> {
  const n = f.noeud
  const a = c.alpha
  const base: Record<string, string> = {
    left: `${f.x}px`,
    top: `${f.y}px`,
    width: `${f.w}px`,
    height: `${f.h}px`,
    ...(c.apparition ?? {}),
  }
  if (c.ensemble) {
    base.background =
      c.place === 'ascendant'
        ? attenuer('var(--hi-direct-plein)', a)
        : c.place === 'descendant'
          ? attenuer('var(--hi-direct-plein)', 0.55 * a)
          : attenuer(couleurBranche(c.branche), 0.7 * a)
    return base
  }
  const sexe = sexeDe(n)
  const accent = attenuer('var(--h-accent)', a)
  base.background = attenuer(`var(--h-${sexe})`, a)
  base.border =
    c.place === 'ascendant'
      ? `1px solid ${accent}`
      : c.place === 'descendant'
        ? `1px dashed ${accent}`
        : n.enAttente
          ? `1px dashed ${accent}`
          : `1px ${n.vivant ? 'dashed' : 'solid'} ${attenuer(`var(--h-${sexe}-bord)`, a)}`
  // Le liseré de branche : à gauche d'une fiche couchée, en haut d'une fiche debout.
  base[c.portrait ? 'borderTop' : 'borderLeft'] = `4px solid ${attenuer(couleurBranche(c.branche), a)}`
  base.borderRadius = c.portrait ? '10px' : '7px'
  return base
}

/* ------------------------------------------------------------------ */
/* Les traits                                                          */
/* ------------------------------------------------------------------ */

export type CheminDeTrait = 'ordinaires' | 'divorces' | 'attente' | 'directs'

/** Propriétés CSS d'un `<path>` : `stroke-opacity`, jamais `opacity`. */
export type StyleDeTrait = Record<'stroke' | 'stroke-width' | 'stroke-opacity', string>

/**
 * Les quatre familles de traits, selon le niveau et la ligne directe.
 *
 * `surligner` : la ligne directe est demandée (le niveau Ensemble efface alors les
 * liens ordinaires). `surlignerTraits` : c'est la VÔTRE — ses traits se dorent.
 */
export function stylesDesTraits(niveau: Niveau, surligner: boolean, surlignerTraits: boolean): Record<CheminDeTrait, StyleDeTrait> {
  // Sans lignée surlignée, le niveau Ensemble garde tous les liens, en discret.
  const ordinaire = niveau === 0 ? (surligner ? 0 : 0.35) : niveau === 1 ? 0.55 : 0.9
  const fait = niveau === 0 ? 0 : 0.7
  return {
    ordinaires: { stroke: 'var(--hi-arete)', 'stroke-width': '1.2', 'stroke-opacity': String(ordinaire) },
    divorces: { stroke: 'var(--h-alerte)', 'stroke-width': '1.2', 'stroke-opacity': String(fait) },
    attente: { stroke: 'var(--h-accent)', 'stroke-width': '1.2', 'stroke-opacity': String(fait) },
    directs: surlignerTraits
      ? { stroke: 'var(--h-accent)', 'stroke-width': niveau === 0 ? '2.5' : '2', 'stroke-opacity': '1' }
      : { stroke: 'var(--hi-arete)', 'stroke-width': '1.2', 'stroke-opacity': String(ordinaire) },
  }
}
