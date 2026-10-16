/**
 * L'atelier du **carnet d'archiviste** — ce que les deux maquettes 3D partagent :
 * le Monde du suivi (`monde/scene.ts`) et le Monde de l'arbre
 * (`arbre-monde/scene.ts`).
 *
 * La palette (sépia, ocre, sauge, terre cuite — charte d'Heritage), les matières
 * en trois tons (lavis d'aquarelle), le trait d'encre, les fabriques de formes
 * à géométries partagées, et l'île flottante. Un seul endroit : deux copies
 * d'une palette finissent toujours par diverger.
 *
 * Les caches (matières, géométries, lueurs) sont ceux de l'écran ouvert :
 * `viderAtelier()` les libère à sa fermeture. Deux écrans 3D ne sont jamais
 * montés ensemble — chacun a sa route.
 */

import * as THREE from 'three'
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js'
import { OutlineEffect } from 'three/addons/effects/OutlineEffect.js'

/* ───────────────── Palette ───────────────── */

export const C = {
  // Les noms sont ceux des rôles (mur, socle, toit…), les valeurs celles d'une
  // aquarelle sur papier crème — palette reprise de la charte d'Heritage.
  lavande: 0xe6d3b0, // parchemin : murs
  sauge: 0xa9b98f, // sauge délavée : herbe, feuillages
  bleu: 0xa9bcc4, // bleu-gris d'encre délavée : vitres, inscriptions
  rose: 0xd4a28a, // terre cuite : toits
  abricot: 0xe8c890, // ocre clair : socles, chemins
  lavandeF: 0xa07850, // sépia : bases, accents
  saugeF: 0x7e9468,
  bleuF: 0x6f8a99,
  roseF: 0xb5653a, // rouille
  abricotF: 0xc9955a, // ocre
  prune: 0x3b2a1e, // encre brune : poteaux, pieds
  creme: 0xf4ecdc, // papier
  nuage: 0xfbf7ee,
}

/* ───────────────── Fabriques partagées ───────────────── */

/**
 * Trois tons et pas un de plus : lumière, demi-teinte, ombre — le lavis d'une
 * aquarelle, pas le dégradé d'un rendu réaliste. Partagée par toutes les matières.
 */
export const TONS = (() => {
  const t = new THREE.DataTexture(new Uint8Array([110, 190, 255]), 3, 1, THREE.RedFormat)
  t.minFilter = t.magFilter = THREE.NearestFilter
  t.needsUpdate = true
  return t
})()

const matieres = new Map<string, THREE.MeshToonMaterial>()
export function mat(color: number, o: THREE.MeshToonMaterialParameters = {}): THREE.MeshToonMaterial {
  const cle = `${color}:${JSON.stringify(o)}`
  let m = matieres.get(cle)
  if (!m) {
    m = new THREE.MeshToonMaterial({ color, gradientMap: TONS, ...o })
    matieres.set(cle, m)
  }
  return m
}

/** Une matière qui s'allume : un peu le jour, beaucoup la nuit, et `force` en plus quand la donnée l'allume. */
export interface Lueur {
  m: THREE.MeshToonMaterial
  jour: number
  nuit: number
  force: number
}
export const lueurs: Lueur[] = []
export function lueur(color: number, jour = 0.2, nuit = 1.2): Lueur {
  const l = { m: new THREE.MeshToonMaterial({ color, emissive: color, emissiveIntensity: jour, gradientMap: TONS }), jour, nuit, force: 0 }
  lueurs.push(l)
  return l
}

const geometries = new Map<string, THREE.BufferGeometry>()
export function geo<T extends THREE.BufferGeometry>(cle: string, f: () => T): T {
  let g = geometries.get(cle)
  if (!g) {
    g = f()
    geometries.set(cle, g)
  }
  return g as T
}

export const boite = (w: number, h: number, d: number, r: number, m: THREE.Material): THREE.Mesh =>
  new THREE.Mesh(
    geo(`b${w}:${h}:${d}:${r}`, () => new RoundedBoxGeometry(w, h, d, 3, Math.min(r, w / 2 - 0.001, h / 2 - 0.001, d / 2 - 0.001))),
    m,
  )
export const cylindre = (rh: number, rb: number, h: number, m: THREE.Material, s = 24): THREE.Mesh =>
  new THREE.Mesh(geo(`c${rh}:${rb}:${h}:${s}`, () => new THREE.CylinderGeometry(rh, rb, h, s)), m)
export const sphere = (r: number, m: THREE.Material, s = 18): THREE.Mesh =>
  new THREE.Mesh(geo(`s${r}:${s}`, () => new THREE.SphereGeometry(r, s, Math.round(s * 0.7))), m)
export const cone = (r: number, h: number, m: THREE.Material, s = 8): THREE.Mesh =>
  new THREE.Mesh(geo(`k${r}:${h}:${s}`, () => new THREE.ConeGeometry(r, h, s)), m)
export function en<T extends THREE.Object3D>(o: T, x: number, y: number, z: number): T {
  o.position.set(x, y, z)
  return o
}

/** Un pseudo-hasard stable : le même décor à chaque ouverture. */
export function hasard(x: number, y: number, z = 0): number {
  const s = Math.sin(x * 12.9898 + y * 78.233 + z * 37.719) * 43758.5453
  return s - Math.floor(s)
}

/** Un disque flou, pour les halos, les étoiles, le pollen, la pluie. */
export function texturePoint(): THREE.CanvasTexture {
  const c = document.createElement('canvas')
  c.width = c.height = 64
  const x = c.getContext('2d')!
  const g = x.createRadialGradient(32, 32, 0, 32, 32, 32)
  g.addColorStop(0, 'rgba(255,255,255,1)')
  g.addColorStop(0.45, 'rgba(255,255,255,.8)')
  g.addColorStop(1, 'rgba(255,255,255,0)')
  x.fillStyle = g
  x.fillRect(0, 0, 64, 64)
  return new THREE.CanvasTexture(c)
}

/**
 * Le trait d'encre : chaque objet opaque redessiné en coque inversée, un peu
 * plus grande, à l'encre brune le jour et dorée la nuit. À recréer au
 * changement de thème — la couleur par défaut est fixée à la construction.
 */
export function creerEncre(renderer: THREE.WebGLRenderer, nuit: boolean, epaisseur = 0.0042): OutlineEffect {
  const c = new THREE.Color(nuit ? 0xd9b56a : 0x3b2a1e)
  return new OutlineEffect(renderer, { defaultThickness: epaisseur, defaultColor: [c.r, c.g, c.b], defaultAlpha: 1 })
}

/** Libère les caches de l'atelier : à appeler quand l'écran 3D se ferme. */
export function viderAtelier(): void {
  for (const g of geometries.values()) g.dispose()
  geometries.clear()
  for (const m of matieres.values()) m.dispose()
  matieres.clear()
  lueurs.length = 0
}

export interface FormeDeLIle {
  /** Le rayon du plateau d'herbe ; 16 pour le Monde du suivi. */
  rayon?: number
  /** La profondeur de la roche sous le plateau ; 11 pour le Monde du suivi. */
  profondeur?: number
}

/**
 * L'île flottante : herbe, terre, roche à facettes en dégradé ocre → sépia, un
 * lavis d'aquarelle sur l'herbe, et quelques rochers qui flottent dessous. Rend
 * les rochers flottants, que la scène fait respirer.
 */
export function construireIle(parent: THREE.Object3D, forme: FormeDeLIle = {}): THREE.Mesh[] {
  const k = (forme.rayon ?? 16) / 16
  const kp = (forme.profondeur ?? 11) / 11
  const flottants: THREE.Mesh[] = []
  const tour = (pts: [number, number][], seg: number, m: THREE.Material): THREE.Mesh =>
    new THREE.Mesh(new THREE.LatheGeometry(pts.map(([x, y]) => new THREE.Vector2(x * k, y)), seg), m)
  const herbe = tour([[0, 0], [14.6, 0], [15.3, -0.08], [15.8, -0.3], [15.95, -0.6]], 96, mat(C.sauge, { side: THREE.DoubleSide }))
  herbe.receiveShadow = true
  const terre = tour([[15.95, -0.6], [16, -0.95], [15.8, -1.45], [15.3, -1.75]], 96, mat(C.abricot, { side: THREE.DoubleSide }))
  const pts: [number, number][] = [[15.3, -1.75], [14.5, -2.4], [13.1, -3.5], [10.9, -4.9], [8.2, -6.5], [5.3, -8.2], [2.5, -9.8], [0.6, -10.9], [0, -11.1]]
  const g = new THREE.LatheGeometry(pts.map(([x, y]) => new THREE.Vector2(x * k, y < -1.75 ? -1.75 + (y + 1.75) * kp : y)), 26).toNonIndexed()
  const pos = g.attributes.position!
  const couleurs: number[] = []
  const a = new THREE.Color(C.abricot)
  const b = new THREE.Color(C.lavandeF)
  const c = new THREE.Color()
  for (let i = 0; i < pos.count; i++) {
    const x = pos.getX(i)
    const y = pos.getY(i)
    const z = pos.getZ(i)
    if (y < -1.9 && y > -1.75 - 9.3 * kp) {
      const k = 1 + (hasard(Math.round(x * 10), Math.round(y * 10), Math.round(z * 10)) - 0.5) * 0.16
      pos.setX(i, x * k)
      pos.setZ(i, z * k)
    }
    c.copy(a).lerp(b, THREE.MathUtils.clamp((-y - 1.8) / (8 * kp), 0, 1))
    couleurs.push(c.r, c.g, c.b)
  }
  g.setAttribute('color', new THREE.Float32BufferAttribute(couleurs, 3))
  g.computeVertexNormals()
  const roche = new THREE.Mesh(g, new THREE.MeshToonMaterial({ vertexColors: true, side: THREE.DoubleSide }))
  parent.add(herbe, terre, roche)
  // Le lavis : des taches d'aquarelle superposées, dessinées une fois dans un
  // <canvas> (aucune image distante), posées à plat sur l'herbe.
  const cv = document.createElement('canvas')
  cv.width = cv.height = 512
  const x = cv.getContext('2d')!
  x.fillStyle = '#b4c29a'
  x.fillRect(0, 0, 512, 512)
  for (let i = 0; i < 140; i++) {
    const px = hasard(i, 60) * 512
    const py = hasard(i, 61) * 512
    const r = 20 + hasard(i, 62) * 90
    const g = x.createRadialGradient(px, py, 0, px, py, r)
    const teinte = ['126,148,104', '169,185,143', '201,190,140', '150,170,120'][i % 4]
    g.addColorStop(0, `rgba(${teinte},${0.18 + hasard(i, 63) * 0.22})`)
    g.addColorStop(0.8, `rgba(${teinte},0.08)`)
    g.addColorStop(1, `rgba(${teinte},0)`)
    x.fillStyle = g
    x.beginPath()
    x.arc(px, py, r, 0, Math.PI * 2)
    x.fill()
  }
  const lavisTex = new THREE.CanvasTexture(cv)
  lavisTex.colorSpace = THREE.SRGBColorSpace
  const lavis = new THREE.Mesh(new THREE.CircleGeometry(15.25 * k, 96), new THREE.MeshToonMaterial({ map: lavisTex, gradientMap: TONS }))
  lavis.rotation.x = -Math.PI / 2
  lavis.position.y = 0.012
  lavis.receiveShadow = true
  lavis.material.userData.outlineParameters = { visible: false }
  parent.add(lavis)
  for (const [x, y, z, r] of [[-17, -6, 6, 1.1], [18, -4.5, -4, 0.8], [13, -9, 9, 0.6], [-12, -10, -8, 0.9], [4, -13, 12, 0.5]] as const) {
    const f = new THREE.Mesh(new THREE.IcosahedronGeometry(r, 0), mat(hasard(x, y, z) > 0.5 ? C.abricotF : C.lavandeF))
    f.position.set(x * k, y * kp, z * k)
    f.userData = { y: y * kp, ph: x }
    const chapeau = new THREE.Mesh(new THREE.CylinderGeometry(r * 0.8, r * 0.7, r * 0.3, 7), mat(C.sauge))
    chapeau.position.y = r * 0.55
    f.add(chapeau)
    parent.add(f)
    flottants.push(f)
  }
  return flottants
}
