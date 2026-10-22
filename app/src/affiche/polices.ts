/**
 * Les polices de l'affiche, et **la mesure des textes** sur leurs métriques.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Pourquoi on mesure sur le fichier de police, et jamais dans le DOM
 *
 * Tout le placement de l'affiche dépend de largeurs de texte : l'angle que réclame un nom
 * dans l'éventail, la largeur d'une boîte de descendant, le corps qui tient dans le disque
 * central. L'affiche d'origine (Python) les mesurait avec PyMuPDF, `Font.text_length()` :
 * la **somme des avances** des glyphes (table `hmtx`), sans crénage, rapportée au corps.
 *
 * On refait exactement ce calcul, ici, sur les mêmes fichiers : un `canvas.measureText()`
 * dépendrait du navigateur, de l'état de chargement des polices et du lissage, et le
 * placement ne serait ni déterministe ni testable sans DOM. Avec les métriques du fichier,
 * la même affiche sort au centième de millimètre près sous Node (vitest) et dans le
 * navigateur — et au centième près de l'affiche Python, qui mesurait les mêmes avances.
 *
 * Le lecteur de TrueType ci-dessous ne lit que ce dont la mesure a besoin — `head`
 * (unités par em), `hhea` et `hmtx` (avances), `cmap` (caractère → glyphe, formats 4 et
 * 12) — soit une centaine de lignes, plutôt qu'une bibliothèque de 170 Ko pour la même
 * addition.
 *
 * ## Les fichiers
 *
 * Neuf instances statiques, tirées de **Source Serif 4** (Adobe, SIL OFL 1.1) et de
 * **Cormorant Garamond** (Cormorant Project, SIL OFL 1.1), réduites aux écritures latines
 * (`app/scripts/polices-affiche.py`, qui dit d'où elles viennent et comment les refaire).
 * Les noms internes ont été changés : « Source » est un nom de police réservé par sa
 * licence, qu'une version modifiée — un sous-ensemble en est une — ne peut pas porter.
 * La licence est à côté des fichiers (`polices/OFL.txt`).
 *
 * La clé d'une police (`SS-Txt-Reg`…) est celle de l'affiche d'origine : famille
 * (`SS` Source Serif, `CG` Cormorant Garamond), taille optique pour Source Serif (`Cap`
 * sous 10,5 pt, `Txt` sous 22 pt, `Dis` au-delà), graisse. Elle sert aussi de nom de
 * famille CSS dans le SVG produit.
 * ────────────────────────────────────────────────────────────────────
 */
import { ENCRE } from './palette'

/** Millimètres par point typographique. */
export const PT = 25.4 / 72

/** Les neuf polices dont l'affiche se sert — et pas une de plus (voir `Style.cle`). */
export const CLES_DE_POLICE = [
  'CG-Bold',
  'SS-Cap-Reg',
  'SS-Cap-Semi',
  'SS-Cap-Ital',
  'SS-Txt-Reg',
  'SS-Txt-Semi',
  'SS-Txt-Ital',
  'SS-Dis-Semi',
  'SS-Dis-Ital',
] as const

export type ClePolice = (typeof CLES_DE_POLICE)[number]

/* ------------------------------------------------------------------ */
/* Le lecteur de TrueType                                              */
/* ------------------------------------------------------------------ */

/** Les avances d'une police TrueType, et la correspondance caractère → glyphe. */
export class MetriquesDePolice {
  private constructor(
    readonly unitesParEm: number,
    private readonly avances: Uint16Array,
    private readonly glyphes: ReadonlyMap<number, number>,
  ) {}

  static depuis(octets: ArrayBuffer | Uint8Array): MetriquesDePolice {
    const tampon = octets instanceof Uint8Array ? octets : new Uint8Array(octets)
    const v = new DataView(tampon.buffer, tampon.byteOffset, tampon.byteLength)

    const tables = new Map<string, number>()
    const nbTables = v.getUint16(4)
    for (let i = 0; i < nbTables; i++) {
      const o = 12 + 16 * i
      const etiquette = String.fromCharCode(v.getUint8(o), v.getUint8(o + 1), v.getUint8(o + 2), v.getUint8(o + 3))
      tables.set(etiquette, v.getUint32(o + 8))
    }
    const table = (nom: string): number => {
      const debut = tables.get(nom)
      if (debut === undefined) throw new Error(`Police sans table « ${nom} » : ce n'est pas un fichier TrueType utilisable.`)
      return debut
    }

    const unitesParEm = v.getUint16(table('head') + 18)
    const nbMetriques = v.getUint16(table('hhea') + 34)
    const nbGlyphes = v.getUint16(table('maxp') + 4)

    // `hmtx` : une avance par glyphe jusqu'à `nbMetriques`, la dernière vaut pour la suite.
    const hmtx = table('hmtx')
    const avances = new Uint16Array(nbGlyphes)
    for (let g = 0; g < nbGlyphes; g++) {
      avances[g] = g < nbMetriques ? v.getUint16(hmtx + 4 * g) : avances[nbMetriques - 1]!
    }

    return new MetriquesDePolice(unitesParEm, avances, lireCmap(v, table('cmap')))
  }

  /**
   * L'avance d'un caractère, en em. Un caractère absent prend celle du glyphe 0 (`.notdef`).
   *
   * **En flottant simple précision** (`Math.fround`), comme MuPDF (`fz_advance_glyph` rend un
   * `float`) : l'éventail choisit entre deux variantes d'un bloc par une comparaison qui tombe
   * souvent **pile** sur l'égalité — une place reçoit exactement l'angle que son texte
   * réclame —, et un écart au huitième chiffre suffit à faire couper des prénoms que
   * l'original laissait sur une ligne.
   */
  avance(pointDeCode: number): number {
    const g = this.glyphes.get(pointDeCode) ?? 0
    return Math.fround((this.avances[g] ?? 0) / this.unitesParEm)
  }

  /** Le caractère a-t-il un glyphe dans cette police ? */
  couvre(pointDeCode: number): boolean {
    return this.glyphes.has(pointDeCode)
  }
}

/**
 * La table `cmap` : la sous-table Unicode la plus complète — format 12 (plan entier) de
 * préférence, sinon format 4 (plan multilingue de base). C'est le choix de FreeType, donc
 * de PyMuPDF.
 */
function lireCmap(v: DataView, cmap: number): Map<number, number> {
  const nb = v.getUint16(cmap + 2)
  let meilleure = -1
  let rang = Infinity
  for (let i = 0; i < nb; i++) {
    const plateforme = v.getUint16(cmap + 4 + 8 * i)
    const codage = v.getUint16(cmap + 6 + 8 * i)
    const debut = cmap + v.getUint32(cmap + 8 + 8 * i)
    const format = v.getUint16(debut)
    const r =
      format === 12 && (plateforme === 3 || plateforme === 0)
        ? 0
        : format === 4 && plateforme === 3 && codage === 1
          ? 1
          : format === 4 && plateforme === 0
            ? 2
            : Infinity
    if (r < rang) {
      rang = r
      meilleure = debut
    }
  }
  if (meilleure < 0) throw new Error('Police sans table cmap Unicode.')

  const carte = new Map<number, number>()
  if (v.getUint16(meilleure) === 12) {
    const groupes = v.getUint32(meilleure + 12)
    for (let i = 0; i < groupes; i++) {
      const o = meilleure + 16 + 12 * i
      const premier = v.getUint32(o)
      const dernier = v.getUint32(o + 4)
      const glyphe = v.getUint32(o + 8)
      for (let c = premier; c <= dernier; c++) carte.set(c, glyphe + c - premier)
    }
    return carte
  }

  const segments = v.getUint16(meilleure + 6) / 2
  const fins = meilleure + 14
  const debuts = fins + 2 * segments + 2
  const deltas = debuts + 2 * segments
  const decalages = deltas + 2 * segments
  for (let s = 0; s < segments; s++) {
    const fin = v.getUint16(fins + 2 * s)
    const debut = v.getUint16(debuts + 2 * s)
    const delta = v.getInt16(deltas + 2 * s)
    const decalage = v.getUint16(decalages + 2 * s)
    for (let c = debut; c <= fin && c !== 0xffff; c++) {
      let g: number
      if (decalage === 0) {
        g = (c + delta) & 0xffff
      } else {
        g = v.getUint16(decalages + 2 * s + decalage + 2 * (c - debut))
        if (g !== 0) g = (g + delta) & 0xffff
      }
      if (g !== 0) carte.set(c, g)
    }
  }
  return carte
}

/* ------------------------------------------------------------------ */
/* Le jeu de polices installé                                          */
/* ------------------------------------------------------------------ */

/*
 * Un jeu **unique**, installé une fois : c'est le `Police._cache` de l'affiche d'origine.
 * Le passer en paramètre à chaque `Style` alourdirait tout le portage pour rien — il n'y a
 * qu'un jeu de polices dans l'application. Les tests l'installent depuis le disque
 * (`polices.essai.ts`), le navigateur depuis le réseau (`chargement.ts`).
 */
let installees: ReadonlyMap<string, MetriquesDePolice> | null = null
const memoire = new Map<string, number>()

export function installerPolices(polices: ReadonlyMap<string, MetriquesDePolice>): void {
  for (const cle of CLES_DE_POLICE) {
    if (!polices.has(cle)) throw new Error(`Police manquante : ${cle}.`)
  }
  installees = polices
  memoire.clear()
}

export function policesInstallees(): boolean {
  return installees !== null
}

function metriques(cle: string): MetriquesDePolice {
  if (installees === null) throw new Error('Les polices de l’affiche ne sont pas installées (installerPolices).')
  const m = installees.get(cle)
  if (m === undefined) throw new Error(`Police inconnue : ${cle}.`)
  return m
}

/** Le nombre de caractères au sens de Python (`len()`) : des points de code, pas des unités UTF-16. */
export function longueur(texte: string): number {
  return Array.from(texte).length
}

/**
 * La somme des avances d'un texte, en em — mémorisée : le placement mesure les mêmes noms
 * des dizaines de fois (recherche dichotomique du rayon, du corps de la légende…).
 */
function emDe(cle: string, texte: string): number {
  const k = `${cle}\u0000${texte}`
  let em = memoire.get(k)
  if (em === undefined) {
    const m = metriques(cle)
    em = 0
    for (const c of texte) em += m.avance(c.codePointAt(0)!)
    memoire.set(k, em)
  }
  return em
}

/** `Police.largeur()` : la largeur en mm, avances plus interlettrage (en em du corps). */
export function largeurEnMm(cle: string, texte: string, taillePt: number, espacementEm = 0): number {
  const w = emDe(cle, texte) * taillePt
  return (w + espacementEm * taillePt * Math.max(longueur(texte) - 1, 0)) * PT
}

/* ------------------------------------------------------------------ */
/* Le style d'une ligne                                                */
/* ------------------------------------------------------------------ */

export type Famille = 'SS' | 'CG'
export type Graisse = 'Reg' | 'Semi' | 'Bold' | 'Ital'

/** Un style de ligne : famille, corps (pt), couleur, interlettrage (em), graisse. */
export class Style {
  constructor(
    readonly famille: Famille,
    readonly taille: number,
    readonly couleur: string = ENCRE,
    readonly espacement = 0,
    readonly graisse: Graisse = 'Reg',
  ) {}

  /**
   * La police réellement employée. Source Serif change de **taille optique** avec le corps
   * (`Cap`, `Txt`, `Dis`) ; les graisses que l'affiche d'origine n'avait pas instanciées se
   * rabattent sur la plus proche — exactement comme `Style.cle` en Python.
   */
  get cle(): string {
    if (this.famille === 'CG') return `CG-${this.graisse === 'Bold' ? 'Bold' : 'Semi'}`
    const opsz = this.taille < 10.5 ? 'Cap' : this.taille < 22 ? 'Txt' : 'Dis'
    let g: Graisse = this.graisse
    if (opsz === 'Dis' && g !== 'Semi' && g !== 'Ital') g = 'Semi'
    if (opsz === 'Txt' && g === 'Bold') g = 'Semi'
    return `SS-${opsz}-${g}`
  }

  /** Le corps en millimètres — l'unité du dessin. */
  get mm(): number {
    return this.taille * PT
  }

  get interligne(): number {
    return this.mm * 1.2
  }

  /** La largeur du texte en mm, majorée de 2 % (la marge de l'affiche d'origine). */
  largeur(texte: string): number {
    return largeurEnMm(this.cle, texte, this.taille, this.espacement) * 1.02
  }

  echelle(k: number): Style {
    return new Style(this.famille, this.taille * k, this.couleur, this.espacement, this.graisse)
  }
}
