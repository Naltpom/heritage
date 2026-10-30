/**
 * L'apparence des boîtes et des traits de `/arbre`, en fonctions pures.
 *
 * **Aucune `opacity` ici** (ADR 0007, règle 2, et sa révision du 2026-10-14). Une
 * fiche estompée par un filtre ou par la recherche l'est **dans la peinture même** :
 * son fond et ses bordures prennent une couleur partiellement transparente
 * (`attenuer`, l'équivalent HTML de `fill-opacity`), un trait prend
 * `stroke-opacity`. Rien ne crée de contexte d'empilement par élément sur un canevas
 * de plusieurs milliers de fiches — jusqu'au 2026-10-14, chaque boîte estompée
 * portait `opacity: 0.1` ou `0.25`, chacune sa couche de composition.
 *
 * Pur, sans DOM.
 */
import type { NoeudPersonne } from '@/lib/api'
import type { FicheHistoire } from '@/histoire/lignees'
import type { Niveau } from '@/histoire/vue'

const BRANCHES_CONNUES = new Set(['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm'])
/** La teinte d'une branche ; `hors` pour une branche qu'on ne connaît pas. */
export const couleurBranche = (b: string): string => `var(--h-branche-${BRANCHES_CONNUES.has(b) ? b : 'hors'})`

/**
 * Une couleur rendue **en partie transparente dans la peinture même** — aucune
 * `opacity`, donc aucun contexte d'empilement. `alpha` va de 0 à 1 ; à 1, la
 * couleur est rendue telle quelle.
 */
export function attenuer(couleur: string, alpha: number): string {
  if (alpha >= 1) return couleur
  const pourcent = Math.round(Math.max(0, alpha) * 1000) / 10
  return `color-mix(in srgb, ${couleur} ${pourcent}%, transparent)`
}

/** Ce qu'une fiche hors filtre garde de sa couleur (c'était `opacity: 0.1`). */
export const ALPHA_HORS_FILTRE = 0.1
/** Ce qu'une fiche garde quand une recherche en désigne d'autres (c'était `opacity: 0.25`). */
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
  /**
   * Recherche close au sommet de la fiche (ADR 0023, `arbre2/sommets.ts`) : bord brique.
   * Facultatif : absent, la fiche n'est pas en fin de recherche.
   */
  fin?: boolean
}

const sexeDe = (n: NoeudPersonne): 'homme' | 'femme' | 'inconnu' =>
  n.sex === 'masculin' ? 'homme' : n.sex === 'feminin' ? 'femme' : 'inconnu'

/*
 * UNE PROPOSITION EN ATTENTE se voit (demande du 22/10/2026, la bascule « En attente de
 * validation », allumée par défaut) : en pointillé épais de l'accent, le fond franchement
 * teinté de l'accent (au niveau Familles, le pointillé ne fait plus qu'un demi-pixel :
 * c'est la teinte qu'on voit) ; au niveau Ensemble, un rectangle de l'accent relevé du
 * texte plutôt que de sa branche — en sombre, l'accent EST la teinte de la branche
 * paternelle du père, et seul il s'y confondrait ; ses liens, en pointillé de l'accent, à
 * tous les niveaux (`stylesDesTraits`). Le pointillé code un fait — « pas encore
 * validée » —, jamais une distance (ADR 0007).
 */
const COULEUR_EN_ATTENTE = 'var(--h-accent)'
const ENSEMBLE_EN_ATTENTE = `color-mix(in srgb, ${COULEUR_EN_ATTENTE} 60%, var(--h-texte))`

/*
 * UNE RECHERCHE CLOSE (ADR 0023) : un bord brique plein de 2 px — et, avec l'icône « mur »
 * du calque des libellés, une forme qui se lit sans la couleur. Priorités de la bordure :
 * en attente > ligne directe surlignée > fin de recherche > ordinaire. Une carte de la
 * ligne directe garde son liseré d'accent ; le mur, lui, reste (icône, survol, volet).
 * Au niveau Ensemble, où la fiche n'est qu'un rectangle, un filet intérieur brique.
 */
const COULEUR_FIN_DE_RECHERCHE = 'var(--h-fin-de-recherche)'

/**
 * Le style d'une boîte, en coordonnées monde, prêt pour `style.cssText` : la boîte
 * ne se réécrit que si cette chaîne change (`FichesGroupes.vue`).
 */
export function styleDeFiche(f: FicheHistoire, c: ContexteDeFiche): string {
  const n = f.noeud
  const a = c.alpha
  const position = `left:${f.x}px;top:${f.y}px;width:${f.w}px;height:${f.h}px`
  if (c.ensemble) {
    const fond =
      c.place === 'ascendant'
        ? attenuer('var(--hi-direct-plein)', a)
        : c.place === 'descendant'
          ? attenuer('var(--hi-direct-plein)', 0.55 * a)
          : n.enAttente
            ? attenuer(ENSEMBLE_EN_ATTENTE, a)
            : attenuer(couleurBranche(c.branche), 0.7 * a)
    const filet = c.fin ? `;box-shadow:inset 0 0 0 2px ${attenuer(COULEUR_FIN_DE_RECHERCHE, a)}` : ''
    return `${position};background:${fond}${filet}`
  }
  const sexe = sexeDe(n)
  const accent = attenuer('var(--h-accent)', a)
  const bordure =
    c.place === 'ascendant'
      ? `1px solid ${accent}`
      : c.place === 'descendant'
        ? `1px dashed ${accent}`
        : n.enAttente
          ? `2px dashed ${accent}`
          : c.fin
            ? `2px solid ${attenuer(COULEUR_FIN_DE_RECHERCHE, a)}`
            : `1px ${n.vivant ? 'dashed' : 'solid'} ${attenuer(`var(--h-${sexe}-bord)`, a)}`
  // Le liseré de branche : à gauche d'une fiche couchée, en haut d'une fiche debout.
  const lisere = `${c.portrait ? 'border-top' : 'border-left'}:4px solid ${attenuer(couleurBranche(c.branche), a)}`
  const fond = n.enAttente ? `color-mix(in srgb, ${COULEUR_EN_ATTENTE} 30%, var(--h-${sexe}))` : `var(--h-${sexe})`
  return `${position};background:${attenuer(fond, a)};border:${bordure};${lisere};border-radius:${c.portrait ? 10 : 7}px`
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
    // Les liens à valider se voient à tous les niveaux, Ensemble compris.
    attente: { stroke: COULEUR_EN_ATTENTE, 'stroke-width': '2', 'stroke-opacity': niveau === 0 ? '0.85' : '0.95' },
    directs: surlignerTraits
      ? { stroke: 'var(--h-accent)', 'stroke-width': niveau === 0 ? '2.5' : '2', 'stroke-opacity': '1' }
      : { stroke: 'var(--hi-arete)', 'stroke-width': '1.2', 'stroke-opacity': String(ordinaire) },
  }
}
