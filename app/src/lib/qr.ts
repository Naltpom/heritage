/**
 * Un **code QR**, calculé ici — aucun service distant, aucune dépendance.
 *
 * ────────────────────────────────────────────────────────────────────
 * Pourquoi l'écrire plutôt que d'ajouter un paquet : le tableau de suivi n'en a
 * besoin que pour une chose, montrer l'adresse de l'écran à un téléphone, et la
 * CSP interdit de toute façon un générateur en ligne (`img-src` ne nomme aucun
 * tiers pour cela, et l'URL partirait chez lui). Un encodeur d'une seule forme
 * tient en deux cents lignes ; c'est moins que la notice d'un paquet.
 *
 * **Une seule forme, et c'est ce qui le garde court** : mode octet (UTF-8),
 * correction d'erreurs de niveau M (≈ 15 % de modules récupérables), versions 1
 * à 20 — jusqu'à 666 octets, bien au-delà d'une URL de l'application. Au-delà,
 * `encoderQr` lève : un code qui ne tient pas ne s'improvise pas.
 *
 * L'algorithme suit la norme ISO/IEC 18004 dans l'ordre où elle se lit, et sa
 * mise en œuvre de référence la plus lue (Project Nayuki, MIT) : motifs fixes,
 * données entrelacées avec leur Reed-Solomon, puis le masque de plus faible
 * pénalité. La suite (`qr.test.ts`) tient les invariants qu'un lecteur vérifie.
 * ────────────────────────────────────────────────────────────────────
 */

/** Niveau M : codes de correction par bloc, indexés par version (l'indice 0 est inutilisé). */
const ECC_PAR_BLOC = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26]
/** Niveau M : nombre de blocs, indexé par version. */
const BLOCS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16]

export const VERSION_MAX = 20

/** Une matrice carrée : `modules[y][x]`, `true` = module sombre. */
export interface CodeQr {
  version: number
  taille: number
  modules: boolean[][]
}

/** Les modules utilisables par les données, motifs fixes déduits. */
function modulesDeDonnees(version: number): number {
  let n = (16 * version + 128) * version + 64
  if (version >= 2) {
    const alignements = Math.floor(version / 7) + 2
    n -= (25 * alignements - 10) * alignements - 55
    if (version >= 7) n -= 36
  }
  return n
}

function octetsDeDonnees(version: number): number {
  return Math.floor(modulesDeDonnees(version) / 8) - ECC_PAR_BLOC[version]! * BLOCS[version]!
}

function positionsDAlignement(version: number): number[] {
  if (version === 1) return []
  const n = Math.floor(version / 7) + 2
  const pas = Math.floor((version * 8 + n * 3 + 5) / (n * 4 - 4)) * 2
  const positions = [6]
  for (let p = version * 4 + 17 - 7; positions.length < n; p -= pas) positions.splice(1, 0, p)
  return positions
}

/* ───────────────── Reed-Solomon sur GF(2⁸), polynôme 0x11D ───────────────── */

function multiplier(x: number, y: number): number {
  let z = 0
  for (let i = 7; i >= 0; i--) {
    z = (z << 1) ^ ((z >>> 7) * 0x11d)
    z ^= ((y >>> i) & 1) * x
  }
  return z
}

function diviseur(degre: number): number[] {
  const r = new Array<number>(degre).fill(0)
  r[degre - 1] = 1
  let racine = 1
  for (let i = 0; i < degre; i++) {
    for (let j = 0; j < r.length; j++) {
      r[j] = multiplier(r[j]!, racine)
      if (j + 1 < r.length) r[j]! ^= r[j + 1]!
    }
    racine = multiplier(racine, 0x02)
  }
  return r
}

function reste(donnees: readonly number[], div: readonly number[]): number[] {
  const r = new Array<number>(div.length).fill(0)
  for (const octet of donnees) {
    const facteur = octet ^ r.shift()!
    r.push(0)
    div.forEach((c, i) => {
      r[i]! ^= multiplier(c, facteur)
    })
  }
  return r
}

/* ───────────────── Les données : flux de bits, puis blocs entrelacés ───────────────── */

function versionPour(octets: number): number {
  for (let v = 1; v <= VERSION_MAX; v++) {
    const bitsDeCompte = v <= 9 ? 8 : 16
    if (4 + bitsDeCompte + octets * 8 <= octetsDeDonnees(v) * 8) return v
  }
  throw new RangeError(`Texte trop long pour un code QR de version ${VERSION_MAX} (${octets} octets).`)
}

function fluxDeDonnees(octets: Uint8Array, version: number): number[] {
  const bits: number[] = []
  const ajouter = (valeur: number, longueur: number): void => {
    for (let i = longueur - 1; i >= 0; i--) bits.push((valeur >>> i) & 1)
  }
  ajouter(0b0100, 4) // mode octet
  ajouter(octets.length, version <= 9 ? 8 : 16)
  for (const o of octets) ajouter(o, 8)

  const capacite = octetsDeDonnees(version) * 8
  ajouter(0, Math.min(4, capacite - bits.length)) // terminateur
  ajouter(0, (8 - (bits.length % 8)) % 8)
  for (let bourrage = 0xec; bits.length < capacite; bourrage ^= 0xec ^ 0x11) ajouter(bourrage, 8)

  const resultat: number[] = []
  for (let i = 0; i < bits.length; i += 8) resultat.push(parseInt(bits.slice(i, i + 8).join(''), 2))
  return resultat
}

function entrelacer(donnees: readonly number[], version: number): number[] {
  const nBlocs = BLOCS[version]!
  const nEcc = ECC_PAR_BLOC[version]!
  const total = Math.floor(modulesDeDonnees(version) / 8)
  const courts = nBlocs - (total % nBlocs)
  const longueurCourte = Math.floor(total / nBlocs)
  const div = diviseur(nEcc)

  const blocs: number[][] = []
  for (let i = 0, k = 0; i < nBlocs; i++) {
    const longueur = longueurCourte - nEcc + (i < courts ? 0 : 1)
    const bloc = donnees.slice(k, k + longueur)
    k += longueur
    const ecc = reste(bloc, div)
    if (i < courts) bloc.push(0)
    blocs.push([...bloc, ...ecc])
  }

  const resultat: number[] = []
  for (let i = 0; i < blocs[0]!.length; i++) {
    blocs.forEach((bloc, j) => {
      if (i !== longueurCourte - nEcc || j >= courts) resultat.push(bloc[i]!)
    })
  }
  return resultat
}

/* ───────────────── La matrice ───────────────── */

class Matrice {
  readonly taille: number
  readonly modules: boolean[][]
  readonly fixe: boolean[][]

  constructor(readonly version: number) {
    this.taille = version * 4 + 17
    this.modules = Array.from({ length: this.taille }, () => new Array<boolean>(this.taille).fill(false))
    this.fixe = Array.from({ length: this.taille }, () => new Array<boolean>(this.taille).fill(false))
  }

  poser(x: number, y: number, sombre: boolean): void {
    this.modules[y]![x] = sombre
    this.fixe[y]![x] = true
  }

  motifsFixes(): void {
    const t = this.taille
    for (let i = 0; i < t; i++) {
      this.poser(6, i, i % 2 === 0)
      this.poser(i, 6, i % 2 === 0)
    }
    for (const [cx, cy] of [
      [3, 3],
      [t - 4, 3],
      [3, t - 4],
    ] as const) {
      for (let dy = -4; dy <= 4; dy++) {
        for (let dx = -4; dx <= 4; dx++) {
          const d = Math.max(Math.abs(dx), Math.abs(dy))
          const x = cx + dx
          const y = cy + dy
          if (x >= 0 && x < t && y >= 0 && y < t) this.poser(x, y, d !== 2 && d !== 4)
        }
      }
    }
    const pos = positionsDAlignement(this.version)
    const n = pos.length
    for (let i = 0; i < n; i++) {
      for (let j = 0; j < n; j++) {
        // Les trois coins des motifs de repérage n'ont pas d'alignement.
        if ((i === 0 && j === 0) || (i === 0 && j === n - 1) || (i === n - 1 && j === 0)) continue
        for (let dy = -2; dy <= 2; dy++) {
          for (let dx = -2; dx <= 2; dx++) {
            this.poser(pos[i]! + dx, pos[j]! + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1)
          }
        }
      }
    }
    this.format(0) // réserve les emplacements ; réécrits avec le vrai masque
    if (this.version >= 7) {
      let r = this.version
      for (let i = 0; i < 12; i++) r = (r << 1) ^ ((r >>> 11) * 0x1f25)
      const bits = (this.version << 12) | r
      for (let i = 0; i < 18; i++) {
        const sombre = ((bits >>> i) & 1) !== 0
        const a = t - 11 + (i % 3)
        const b = Math.floor(i / 3)
        this.poser(a, b, sombre)
        this.poser(b, a, sombre)
      }
    }
  }

  /** Niveau M (bits `00`) et le masque, protégés par leur BCH. */
  format(masque: number): void {
    const donnees = masque // (0b00 << 3) | masque
    let r = donnees
    for (let i = 0; i < 10; i++) r = (r << 1) ^ ((r >>> 9) * 0x537)
    const bits = ((donnees << 10) | r) ^ 0x5412
    const bit = (i: number): boolean => ((bits >>> i) & 1) !== 0
    const t = this.taille
    for (let i = 0; i <= 5; i++) this.poser(8, i, bit(i))
    this.poser(8, 7, bit(6))
    this.poser(8, 8, bit(7))
    this.poser(7, 8, bit(8))
    for (let i = 9; i < 15; i++) this.poser(14 - i, 8, bit(i))
    for (let i = 0; i < 8; i++) this.poser(t - 1 - i, 8, bit(i))
    for (let i = 8; i < 15; i++) this.poser(8, t - 15 + i, bit(i))
    this.poser(8, t - 8, true) // le module toujours sombre
  }

  placer(octets: readonly number[]): void {
    const t = this.taille
    let i = 0
    for (let droite = t - 1; droite >= 1; droite -= 2) {
      if (droite === 6) droite = 5
      for (let v = 0; v < t; v++) {
        for (let j = 0; j < 2; j++) {
          const x = droite - j
          const montant = ((droite + 1) & 2) === 0
          const y = montant ? t - 1 - v : v
          if (!this.fixe[y]![x] && i < octets.length * 8) {
            this.modules[y]![x] = ((octets[i >>> 3]! >>> (7 - (i & 7))) & 1) !== 0
            i++
          }
        }
      }
    }
  }

  masquer(masque: number): void {
    for (let y = 0; y < this.taille; y++) {
      for (let x = 0; x < this.taille; x++) {
        if (this.fixe[y]![x]) continue
        const inverse = [
          (x + y) % 2 === 0,
          y % 2 === 0,
          x % 3 === 0,
          (x + y) % 3 === 0,
          (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
          ((x * y) % 2) + ((x * y) % 3) === 0,
          (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
          (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
        ][masque]!
        if (inverse) this.modules[y]![x] = !this.modules[y]![x]
      }
    }
  }

  /**
   * La pénalité de la norme, sans la règle des faux motifs de repérage : les
   * suites de même couleur, les carrés 2 × 2, l'équilibre clair/sombre. Elle ne
   * sert qu'à choisir un masque lisible — tout masque donne un code valide.
   */
  penalite(): number {
    const t = this.taille
    const m = this.modules
    let p = 0
    for (let a = 0; a < t; a++) {
      let suiteL = 1
      let suiteC = 1
      for (let b = 1; b < t; b++) {
        suiteL = m[a]![b] === m[a]![b - 1] ? suiteL + 1 : 1
        suiteC = m[b]![a] === m[b - 1]![a] ? suiteC + 1 : 1
        if (suiteL === 5) p += 3
        else if (suiteL > 5) p += 1
        if (suiteC === 5) p += 3
        else if (suiteC > 5) p += 1
      }
    }
    let sombres = 0
    for (let y = 0; y < t; y++) {
      for (let x = 0; x < t; x++) {
        if (m[y]![x]) sombres++
        if (x < t - 1 && y < t - 1) {
          const c = m[y]![x]
          if (c === m[y]![x + 1] && c === m[y + 1]![x] && c === m[y + 1]![x + 1]) p += 3
        }
      }
    }
    const total = t * t
    p += Math.ceil(Math.abs(sombres * 20 - total * 10) / total - 1) * 10
    return p
  }
}

/** Encode un texte (UTF-8) en code QR, niveau M, au plus petit format qui le tient. */
export function encoderQr(texte: string): CodeQr {
  const octets = new TextEncoder().encode(texte)
  const version = versionPour(octets.length)
  const flux = entrelacer(fluxDeDonnees(octets, version), version)

  let meilleur: Matrice | null = null
  let meilleurePenalite = Infinity
  for (let masque = 0; masque < 8; masque++) {
    const m = new Matrice(version)
    m.motifsFixes()
    m.placer(flux)
    m.masquer(masque)
    m.format(masque)
    const p = m.penalite()
    if (p < meilleurePenalite) {
      meilleur = m
      meilleurePenalite = p
    }
  }
  return { version, taille: meilleur!.taille, modules: meilleur!.modules }
}

/**
 * Le tracé SVG des modules sombres, un sous-chemin `h` par suite horizontale —
 * une seule `<path>`, à poser dans un `viewBox` de `taille + 2 × marge`.
 */
export function cheminSvg(code: CodeQr, marge = 4): string {
  const morceaux: string[] = []
  code.modules.forEach((ligne, y) => {
    let x = 0
    while (x < code.taille) {
      if (!ligne[x]) {
        x++
        continue
      }
      const debut = x
      while (x < code.taille && ligne[x]) x++
      morceaux.push(`M${debut + marge} ${y + marge}h${x - debut}v1h-${x - debut}z`)
    }
  })
  return morceaux.join('')
}
