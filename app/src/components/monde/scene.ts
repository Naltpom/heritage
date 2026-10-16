/**
 * La scène 3D du Monde — Three.js, chargée paresseusement par `MondeView.vue`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce module ne lit aucune donnée.** Il reçoit un `EtatDuMonde` (calculé et
 * testé dans `monde.ts`) et le donne à voir. Une règle tient tout le reste :
 * **rien ne bouge seul pour faire joli** — la fumée, le tapis, les fenêtres,
 * la pluie, les éclairs, le feu du phare suivent l'état. Seul le décor (rochers
 * flottants, pollen, étoiles) respire doucement, et rien du tout sous
 * `prefers-reduced-motion`.
 *
 * **Style « carnet d'archiviste ».** Une maquette dessinée : matières en trois
 * tons (lavis d'aquarelle), trait d'encre autour de chaque objet (`OutlineEffect`,
 * brun le jour, doré la nuit), palette sépia, ocre, sauge et terre cuite reprise
 * de la charte d'Heritage. Une seule lumière porte ombre.
 *
 * **Sobriété.** Géométries et matières partagées, ombres en 1024², densité de
 * pixels plafonnée à 2, rendu suspendu quand l'onglet est caché. Pas de
 * texture distante (la CSP n'en laisserait passer aucune) : les quelques
 * textures sont dessinées dans un `<canvas>`.
 *
 * **Les étiquettes** sont des éléments HTML de la vue (texte net, lecteur
 * d'écran) ; la scène ne fait que les placer, à chaque image, au-dessus de
 * leur objet.
 * ────────────────────────────────────────────────────────────────────
 */

import * as THREE from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import {
  C,
  boite,
  cone,
  construireIle,
  creerEncre,
  cylindre,
  en,
  geo,
  hasard,
  lueur,
  lueurs,
  mat,
  sphere,
  texturePoint,
  viderAtelier,
  type Lueur,
} from './atelier'
import type { EtatDuMonde, IdZone, NatureDeCarte } from './monde'

export interface SceneDuMonde {
  appliquer(etat: EtatDuMonde): void
  /** Vol de caméra vers une zone, ou vers la vue d'ensemble (`null`). */
  voler(id: IdZone | null, duree?: number): void
  theme(nuit: boolean): void
  mouvementReduit(oui: boolean): void
  /** Décale l'image (vers la gauche, vers le haut) quand le panneau de détail occupe la droite ou le bas. */
  decaler(x: number, y?: number): void
  detruire(): void
}

export interface OptionsDeScene {
  etiquettes: Partial<Record<IdZone, HTMLElement>>
  surSurvol: (id: IdZone | null) => void
  surClic: (id: IdZone) => void
  /** Un geste sur la scène (glisser, molette) : la visite automatique s'arrête. */
  surGeste: () => void
  nuit: boolean
  mouvementReduit: boolean
}

const COULEUR_CARTE: Record<NatureDeCarte, number> = { demande: C.abricotF, inscription: C.bleuF, rgpd: C.roseF }

/* ───────────────── Emplacements ───────────────── */

interface Emplacement {
  pos: THREE.Vector3
  /** Où la caméra se pose, relativement à la zone, et ce qu'elle regarde. */
  cam: [number, number, number]
  cible: [number, number, number]
  /** Hauteur de l'étiquette au-dessus de la zone. */
  etiquette: number
  rayon: number
  hauteur: number
}

const EMPLACEMENTS: Record<IdZone, Emplacement> = {
  maison: { pos: new THREE.Vector3(-8, 0, -3.5), cam: [5, 5, 12], cible: [0, 1.8, 0], etiquette: 7, rayon: 3.6, hauteur: 6 },
  file: { pos: new THREE.Vector3(1.5, 0, 3.2), cam: [1, 7, 12], cible: [0, 0.8, 0], etiquette: 3.2, rayon: 5, hauteur: 2.5 },
  comptes: { pos: new THREE.Vector3(7.5, 0, -6), cam: [5, 6, 13], cible: [0, 3, 0], etiquette: 8.6, rayon: 2.6, hauteur: 8 },
  portail: { pos: new THREE.Vector3(-8.5, 0, 7), cam: [5, 4.5, 10], cible: [0, 1.6, 0], etiquette: 4.6, rayon: 2.6, hauteur: 4 },
  nuage: { pos: new THREE.Vector3(1, 9, -11), cam: [1, -1, 12], cible: [0, -1.5, 0], etiquette: 2.2, rayon: 3, hauteur: 3 },
  arbre: { pos: new THREE.Vector3(-1.5, 0, -6.5), cam: [3, 5, 12], cible: [0, 3, 0], etiquette: 7.4, rayon: 3.4, hauteur: 7 },
  phare: { pos: new THREE.Vector3(12.2, 0, 3.5), cam: [-6, 5, 11], cible: [0, 3, 0], etiquette: 7.8, rayon: 1.8, hauteur: 7.5 },
}

const VUE = { pos: new THREE.Vector3(0, 22, 36), cible: new THREE.Vector3(0.5, 2.4, -0.5) }

/* ───────────────── La scène ───────────────── */

export function creerScene(conteneur: HTMLElement, options: OptionsDeScene): SceneDuMonde {
  let nuit = options.nuit
  let m = options.mouvementReduit ? 0 : 1
  let etat: EtatDuMonde | null = null

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' })
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
  renderer.setSize(conteneur.clientWidth, conteneur.clientHeight)
  renderer.shadowMap.enabled = true
  renderer.shadowMap.type = THREE.PCFSoftShadowMap
  renderer.outputColorSpace = THREE.SRGBColorSpace
  conteneur.appendChild(renderer.domElement)
  renderer.domElement.setAttribute('aria-hidden', 'true')

  let encre = creerEncre(renderer, nuit)

  const scene = new THREE.Scene()
  const camera = new THREE.PerspectiveCamera(38, conteneur.clientWidth / conteneur.clientHeight, 0.1, 400)
  /** Un écran en hauteur voit moins large : la caméra recule d'autant. */
  const recul = (): number => (camera.aspect < 1 ? Math.min(2.4, 1 / Math.pow(camera.aspect, 0.85)) : 1)
  camera.position.copy(VUE.cible).add(VUE.pos.clone().sub(VUE.cible).multiplyScalar(recul()))
  const controles = new OrbitControls(camera, renderer.domElement)
  controles.target.copy(VUE.cible)
  controles.enableDamping = true
  controles.dampingFactor = 0.07
  controles.minDistance = 6
  controles.maxDistance = 60
  controles.maxPolarAngle = 1.32
  controles.rotateSpeed = 0.6

  const ciel = new THREE.HemisphereLight(0xfff6e6, 0xa9b98f, 1.3)
  scene.add(ciel)
  const soleil = new THREE.DirectionalLight(0xfff1dc, 2.2)
  soleil.position.set(16, 30, 14)
  soleil.castShadow = true
  soleil.shadow.mapSize.set(1024, 1024)
  Object.assign(soleil.shadow.camera, { left: -20, right: 20, top: 20, bottom: -20, near: 1, far: 80 })
  soleil.shadow.bias = -0.0004
  soleil.shadow.normalBias = 0.03
  scene.add(soleil)
  const contre = new THREE.DirectionalLight(0xe8c890, 0.5)
  contre.position.set(-20, 12, -18)
  scene.add(contre)

  const point = texturePoint()
  const halos: { s: THREE.Sprite; jour: number; nuit: number }[] = []
  function halo(couleur: number, taille: number, jour = 0, n = 0.55): THREE.Sprite {
    const s = new THREE.Sprite(
      new THREE.SpriteMaterial({ map: point, color: couleur, transparent: true, opacity: jour, depthWrite: false, blending: THREE.AdditiveBlending }),
    )
    s.scale.setScalar(taille)
    halos.push({ s, jour, nuit: n })
    return s
  }

  const monde = new THREE.Group()
  scene.add(monde)

  const flottants = construireIle(monde)

  /** Un chemin clair, posé sur l'herbe. */
  function chemin(points: [number, number][], largeur = 0.9): void {
    const courbe = new THREE.CatmullRomCurve3(points.map(([x, z]) => new THREE.Vector3(x, 0.02, z)))
    const n = 60
    const l = largeur / 2
    const v: number[] = []
    const idx: number[] = []
    for (let i = 0; i <= n; i++) {
      const p = courbe.getPoint(i / n)
      const d = courbe.getTangent(i / n)
      v.push(p.x - d.z * l, 0.025, p.z + d.x * l, p.x + d.z * l, 0.025, p.z - d.x * l)
      if (i < n) idx.push(i * 2, i * 2 + 2, i * 2 + 1, i * 2 + 1, i * 2 + 2, i * 2 + 3)
    }
    const g = new THREE.BufferGeometry()
    g.setAttribute('position', new THREE.Float32BufferAttribute(v, 3))
    g.setIndex(idx)
    g.computeVertexNormals()
    const c = new THREE.Mesh(g, mat(C.creme, { side: THREE.DoubleSide }))
    c.receiveShadow = true
    monde.add(c)
  }
  chemin([[-8, 0.2], [-4.5, 1.8], [-2.5, 3]])
  chemin([[-8.5, 4.5], [-8, 1.5]])
  chemin([[6.8, 3.4], [7.5, -1.5], [7.5, -3.6]])
  chemin([[-5, -2], [-2.5, -3.5]])
  chemin([[8.6, 3.8], [10.8, 3.6]], 0.7)

  // Arbres, buissons, réverbères : le décor.
  const libres = (x: number, z: number): boolean =>
    Object.values(EMPLACEMENTS).every((e) => e.pos.y > 1 || Math.hypot(x - e.pos.x, z - e.pos.z) > e.rayon + 0.8) && Math.hypot(x - 1.5, z - 3.2) > 6
  for (const [x, z] of [[-13.5, -2], [-12.6, -6], [-10.6, -9.6], [-5, -11.4], [4.6, -11.6], [11.6, -9.4], [13.6, -2.6], [13.4, 8.6], [8.8, 11.6], [0.5, 12.6], [-4, 11.6], [-13.4, 4.2], [-12.4, 10]] as const) {
    if (!libres(x, z)) continue
    const r = hasard(x, 1, z)
    const a = new THREE.Group()
    a.add(en(cylindre(0.12, 0.16, 0.8, mat(C.abricotF), 8), 0, 0.4, 0))
    if (r > 0.5) {
      a.add(en(cone(0.75, 1.6, mat(C.saugeF), 7), 0, 1.4, 0))
      a.add(en(cone(0.55, 1.2, mat(C.sauge), 7), 0, 2.1, 0))
    } else {
      a.add(en(new THREE.Mesh(geo('ico', () => new THREE.IcosahedronGeometry(0.85, 1)), mat(r > 0.25 ? C.sauge : C.rose)), 0, 1.35, 0))
    }
    a.position.set(x, 0, z)
    a.scale.setScalar(0.8 + r * 0.5)
    a.rotation.y = r * 6
    monde.add(a)
  }
  for (let i = 0; i < 20; i++) {
    const ang = i * 2.39996
    const rr = 12.6 + hasard(i, 2, 3) * 2.4
    const x = Math.cos(ang) * rr
    const z = Math.sin(ang) * rr
    if (!libres(x, z)) continue
    const b = new THREE.Mesh(geo(`ico0:${i % 3}`, () => new THREE.IcosahedronGeometry(0.4 + (i % 3) * 0.12, 0)), mat([C.saugeF, C.sauge, C.rose, C.lavande][i % 4]!))
    b.position.set(x, 0.2, z)
    b.scale.y = 0.7
    monde.add(b)
  }
  const globeLampe = lueur(C.abricot, 0.15, 2.4)
  for (const [x, z] of [[-5.5, 1.6], [-1.2, 1], [7.9, 1], [-6.6, -6.8], [4.4, -3.4], [-6.2, 4.6]] as const) {
    const l = new THREE.Group()
    l.add(en(cylindre(0.05, 0.07, 1.5, mat(C.prune), 8), 0, 0.75, 0))
    l.add(en(sphere(0.17, globeLampe.m, 12), 0, 1.58, 0))
    l.add(en(halo(0xf2c67a, 1.6), 0, 1.58, 0))
    l.position.set(x, 0, z)
    monde.add(l)
  }

  /* ───── Les zones ───── */

  interface Zone {
    id: IdZone
    g: THREE.Group
    anneau: THREE.Mesh
    survol: number
  }
  const zones = {} as Record<IdZone, Zone>
  const cliquables: THREE.Object3D[] = []
  function nouvelleZone(id: IdZone, couleur: number): THREE.Group {
    const e = EMPLACEMENTS[id]
    const g = new THREE.Group()
    g.position.copy(e.pos)
    monde.add(g)
    const anneau = new THREE.Mesh(
      new THREE.RingGeometry(e.rayon, e.rayon + 0.32, 64),
      new THREE.MeshBasicMaterial({ color: couleur, transparent: true, opacity: 0, depthWrite: false }),
    )
    anneau.rotation.x = -Math.PI / 2
    anneau.position.y = e.pos.y > 1 ? -2.2 : 0.06
    g.add(anneau)
    const cible = new THREE.Mesh(new THREE.CylinderGeometry(e.rayon, e.rayon, e.hauteur, 12), new THREE.MeshBasicMaterial({ visible: false }))
    cible.position.y = e.pos.y > 1 ? 0 : e.hauteur / 2
    cible.userData.zone = id
    g.add(cible)
    cliquables.push(cible)
    zones[id] = { id, g, anneau, survol: 0 }
    return g
  }

  /* Maison des archives — l'API et sa base. */
  const maison = (() => {
    const g = nouvelleZone('maison', C.lavandeF)
    g.add(en(boite(5.6, 0.35, 4.6, 0.15, mat(C.abricot)), 0, 0.17, 0))
    g.add(en(boite(4.6, 2.9, 3.6, 0.4, mat(C.lavande)), 0, 1.8, 0))
    const toit = new THREE.Mesh(new THREE.CylinderGeometry(2.2, 2.2, 5, 36, 1, false, 0, Math.PI), mat(C.rose))
    toit.rotation.z = Math.PI / 2
    toit.scale.set(0.55, 1, 1)
    toit.position.set(0, 3.2, 0)
    g.add(toit)
    const fenetres = lueur(C.bleu, 0.2, 1.4)
    for (const x of [-1.2, 1.2]) g.add(en(boite(0.9, 0.9, 0.2, 0.2, fenetres.m), x, 2, 1.8))
    const porte = lueur(C.abricot, 0.15, 0.9)
    g.add(en(boite(1, 1.6, 0.25, 0.25, porte.m), 0, 1.1, 1.8))
    // Rayonnages d'archives visibles sur le côté : trois tiroirs.
    for (let i = 0; i < 3; i++) g.add(en(boite(0.2, 0.55, 1.4, 0.08, mat(C.lavandeF)), 2.35, 0.8 + i * 0.65, 0))
    // Cheminée et fumée.
    g.add(en(cylindre(0.3, 0.34, 1.2, mat(C.abricotF), 14), -1.4, 4.3, -0.5))
    const bulles: { b: THREE.Mesh<THREE.SphereGeometry, THREE.MeshToonMaterial>; ph: number }[] = []
    for (let i = 0; i < 7; i++) {
      const b = new THREE.Mesh(new THREE.SphereGeometry(0.3, 12, 9), new THREE.MeshToonMaterial({ color: C.nuage, transparent: true, opacity: 0 }))
      b.position.set(-1.4, 4.9, -0.5)
      g.add(b)
      bulles.push({ b, ph: i / 7 })
    }
    // Gyrophare : éteint tant que la base répond.
    const gyro = new THREE.MeshToonMaterial({ color: C.roseF, emissive: 0xd2697f, emissiveIntensity: 0 })
    const feu = en(sphere(0.32, gyro, 16), 1.4, 4.1, 0.3)
    g.add(en(cylindre(0.12, 0.15, 0.4, mat(C.prune), 10), 1.4, 3.75, 0.3), feu)
    const eclat = new THREE.PointLight(0xff6f80, 0, 8, 1.5)
    eclat.position.set(1.4, 4.4, 0.3)
    g.add(eclat)
    return { bulles, fenetres, porte, gyro, eclat }
  })()

  /* Tapis « À traiter » et bureau. */
  const TAPIS_LONGUEUR = 8
  const tapis = (() => {
    const g = nouvelleZone('file', C.abricotF)
    g.add(en(boite(TAPIS_LONGUEUR + 0.8, 0.5, 1.7, 0.2, mat(C.lavandeF)), 0, 0.45, 0))
    for (const x of [-TAPIS_LONGUEUR / 2 + 0.5, TAPIS_LONGUEUR / 2 - 0.5]) for (const z of [-0.6, 0.6]) g.add(en(cylindre(0.12, 0.16, 0.4, mat(C.prune), 10), x, 0.2, z))
    const cv = document.createElement('canvas')
    cv.width = 128
    cv.height = 32
    const x = cv.getContext('2d')!
    x.fillStyle = '#5D5585'
    x.fillRect(0, 0, 128, 32)
    x.strokeStyle = '#7C73A8'
    x.lineWidth = 10
    for (let i = -32; i < 160; i += 32) {
      x.beginPath()
      x.moveTo(i, 0)
      x.lineTo(i + 16, 16)
      x.lineTo(i, 32)
      x.stroke()
    }
    const tex = new THREE.CanvasTexture(cv)
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping
    tex.repeat.set(TAPIS_LONGUEUR / 1.2, 1)
    tex.colorSpace = THREE.SRGBColorSpace
    const bande = new THREE.Mesh(new THREE.PlaneGeometry(TAPIS_LONGUEUR, 1.25), new THREE.MeshToonMaterial({ map: tex }))
    bande.rotation.x = -Math.PI / 2
    bande.position.y = 0.71
    g.add(bande)
    for (const xx of [-TAPIS_LONGUEUR / 2, TAPIS_LONGUEUR / 2]) {
      const r = cylindre(0.36, 0.36, 1.5, mat(C.rose), 20)
      r.rotation.x = Math.PI / 2
      r.position.set(xx, 0.52, 0)
      g.add(r)
    }
    // Le bureau de l'administration, au bout du tapis : une lampe qui s'allume quand quelque chose attend.
    const bureau = new THREE.Group()
    bureau.add(en(boite(1.8, 0.18, 1.2, 0.08, mat(C.abricotF)), 0, 1, 0))
    for (const [a, b] of [[-0.75, -0.45], [0.75, -0.45], [-0.75, 0.45], [0.75, 0.45]] as const) bureau.add(en(cylindre(0.06, 0.06, 0.9, mat(C.prune), 8), a, 0.45, b))
    const lampe = lueur(C.abricot, 0.1, 0.6)
    bureau.add(en(cylindre(0.03, 0.03, 0.5, mat(C.prune), 6), 0.5, 1.35, -0.3), en(cone(0.2, 0.22, lampe.m, 12), 0.5, 1.65, -0.3))
    bureau.add(en(boite(0.6, 0.06, 0.45, 0.02, mat(C.creme)), -0.2, 1.12, 0.05))
    bureau.position.set(TAPIS_LONGUEUR / 2 + 1.5, 0, 0)
    g.add(bureau)
    return { g, tex, lampe, cartes: new Map<string, { o: THREE.Group; x: number; partir: boolean }>() }
  })()
  function carte(nature: NatureDeCarte): THREE.Group {
    const o = new THREE.Group()
    o.add(en(boite(0.7, 0.95, 0.12, 0.08, mat(COULEUR_CARTE[nature])), 0, 0.48, 0))
    o.add(en(boite(0.46, 0.08, 0.04, 0.02, mat(C.creme)), 0, 0.68, 0.07))
    o.add(en(boite(0.46, 0.08, 0.04, 0.02, mat(C.creme)), 0, 0.5, 0.07))
    o.add(en(boite(0.3, 0.08, 0.04, 0.02, mat(C.creme)), -0.08, 0.32, 0.07))
    o.scale.setScalar(0.001)
    o.traverse((x) => {
      if (x instanceof THREE.Mesh) x.castShadow = true
    })
    tapis.g.add(o)
    return o
  }

  /* Tour des comptes. */
  const tour = (() => {
    const g = nouvelleZone('comptes', C.saugeF)
    g.add(en(cylindre(2.1, 2.3, 0.4, mat(C.abricot), 36), 0, 0.2, 0))
    const corps = new THREE.Group()
    g.add(corps)
    const chapeau = en(cylindre(1.35, 1.2, 0.3, mat(C.rose), 28), 0, 0, 0)
    g.add(chapeau)
    const emblème = new THREE.Group()
    emblème.add(sphere(0.4, mat(C.sauge), 18), en(sphere(0.28, mat(C.abricot), 14), 0, 0.55, 0))
    g.add(emblème)
    return { corps, chapeau, emblème, fenetres: [] as Lueur[], cle: '' }
  })()
  const fenetreEteinte = mat(0x8d8476)
  const fenetreAllumee = lueur(C.abricot, 0.5, 1.6)
  function construireTour(etages: number, fenetres: number): void {
    const cle = `${etages}:${fenetres}`
    if (tour.cle === cle) return
    tour.cle = cle
    tour.corps.clear()
    const H = 1.15
    for (let i = 0; i < etages; i++) {
      tour.corps.add(en(boite(2.6 - (i % 2) * 0.12, H - 0.06, 2.6 - (i % 2) * 0.12, 0.3, mat(i % 2 ? C.saugeF : C.sauge)), 0, 0.4 + (i + 0.5) * H, 0))
    }
    const parEtage = fenetres / etages
    for (let i = 0; i < fenetres; i++) {
      const etage = Math.floor(i / parEtage)
      const k = i % parEtage
      const face = k < parEtage / 2 ? 0 : 1
      const col = k % (parEtage / 2)
      const f = boite(0.42, 0.5, 0.08, 0.06, fenetreEteinte)
      const decal = (col - (parEtage / 2 - 1) / 2) * 0.7
      const y = 0.4 + (etage + 0.5) * H
      if (face === 0) f.position.set(decal, y, 1.32)
      else {
        f.position.set(1.32, y, -decal)
        f.rotation.y = Math.PI / 2
      }
      f.userData.rang = i
      tour.corps.add(f)
    }
    const haut = 0.4 + etages * H
    tour.chapeau.position.y = haut + 0.15
    tour.emblème.position.y = haut + 0.8
  }

  /* Portail des invitations. */
  const portail = (() => {
    const g = nouvelleZone('portail', C.roseF)
    g.add(en(cylindre(1.4, 1.6, 0.3, mat(C.lavandeF), 32), 0, 0.15, 0))
    const tore = new THREE.TorusGeometry(1.4, 0.26, 18, 64, Math.PI)
    const arche = new THREE.Mesh(tore, mat(C.rose))
    arche.position.y = 1.7
    g.add(arche)
    for (const x of [-1.4, 1.4]) g.add(en(cylindre(0.26, 0.3, 1.7, mat(C.rose), 16), x, 0.85, 0))
    const voile = new THREE.Mesh(
      new THREE.CircleGeometry(1.15, 40, 0, Math.PI),
      new THREE.MeshBasicMaterial({ color: C.lavande, transparent: true, opacity: 0.15, side: THREE.DoubleSide, depthWrite: false }),
    )
    voile.position.y = 1.7
    const voileBas = new THREE.Mesh(
      new THREE.PlaneGeometry(2.3, 1.4),
      new THREE.MeshBasicMaterial({ color: C.lavande, transparent: true, opacity: 0.15, side: THREE.DoubleSide, depthWrite: false }),
    )
    voileBas.position.y = 1
    g.add(voile, voileBas)
    const n = 40
    const pos = new Float32Array(n * 3)
    const etincelles = Array.from({ length: n }, (_, i) => ({ a: hasard(i, 4) * 6.3, r: 1 + hasard(i, 5) * 0.8, v: 0.3 + hasard(i, 6) * 0.5 }))
    const points = new THREE.Points(
      new THREE.BufferGeometry().setAttribute('position', new THREE.BufferAttribute(pos, 3)),
      new THREE.PointsMaterial({ color: 0xd9b56a, size: 0.2, map: point, transparent: true, opacity: 0, depthWrite: false }),
    )
    g.add(points)
    const lanternes: THREE.Group[] = []
    const flamme = lueur(C.abricot, 0.5, 1.8)
    for (let i = 0; i < 8; i++) {
      const l = new THREE.Group()
      l.add(en(cylindre(0.03, 0.03, 0.7, mat(C.prune), 6), 0, 0.35, 0), en(sphere(0.13, flamme.m, 10), 0, 0.78, 0))
      const a = Math.PI * (0.15 + (i / 7) * 0.7) + Math.PI
      l.position.set(Math.cos(a) * 2.1, 0, Math.sin(a) * 2.1 + 0.4)
      l.visible = false
      g.add(l)
      lanternes.push(l)
    }
    return { voile, voileBas, points, etincelles, lanternes }
  })()

  /* Nuage du modèle de langage — hors de l'île, au-dessus. */
  const nuage = (() => {
    const g = nouvelleZone('nuage', C.bleuF)
    const corps = new THREE.Group()
    const m = mat(C.nuage)
    for (const [x, y, z, r] of [[0, 0, 0, 1.25], [1.2, -0.15, 0.1, 0.95], [-1.25, -0.2, 0.05, 0.9], [0.55, 0.55, -0.1, 0.9], [-0.55, 0.45, 0.2, 0.8], [2.05, -0.35, 0, 0.6], [-2.05, -0.4, 0, 0.55], [0, -0.35, 0.6, 0.85]] as const) {
      const s = en(sphere(r, m, 20), x, y, z)
      s.scale.y = 0.82
      corps.add(s)
    }
    g.add(corps)
    const n = 140
    const pos = new Float32Array(n * 3)
    const gouttes = Array.from({ length: n }, (_, i) => ({ x: (hasard(i, 7) - 0.5) * 3.6, z: (hasard(i, 8) - 0.5) * 1.6, y: -hasard(i, 9) * 9, v: 5 + hasard(i, 10) * 4 }))
    const pluie = new THREE.Points(
      new THREE.BufferGeometry().setAttribute('position', new THREE.BufferAttribute(pos, 3)),
      new THREE.PointsMaterial({ color: C.bleuF, size: 0.2, map: point, transparent: true, opacity: 0, depthWrite: false }),
    )
    g.add(pluie)
    const pts: THREE.Vector3[] = []
    let x = 0.2
    for (let i = 0; i <= 7; i++) {
      pts.push(new THREE.Vector3(x, -0.9 - i * 1.15, 0))
      x += (i % 2 ? -1 : 1) * (0.3 + hasard(i, 11) * 0.3)
    }
    const eclair = new THREE.Mesh(
      new THREE.TubeGeometry(new THREE.CatmullRomCurve3(pts, false, 'catmullrom', 0.05), 40, 0.07, 5),
      new THREE.MeshBasicMaterial({ color: 0xfff1c9, transparent: true, opacity: 0 }),
    )
    g.add(eclair)
    const flash = new THREE.PointLight(0xffe9c9, 0, 18, 1.2)
    flash.position.y = -2
    g.add(flash)
    return { corps, pluie, gouttes, eclair, flash, eclairT: 0, dernier: null as number | null }
  })()

  /* Le grand arbre — le chantier. */
  const arbre = (() => {
    const g = nouvelleZone('arbre', C.saugeF)
    g.add(en(cylindre(2.6, 2.8, 0.25, mat(C.abricot), 36), 0, 0.12, 0))
    g.add(en(cylindre(0.45, 0.7, 3.4, mat(C.abricotF), 12), 0, 1.9, 0))
    for (const [a, l] of [[0.7, 1.2], [-0.8, 1.1], [2.4, 1]] as const) {
      const b = cylindre(0.14, 0.22, l, mat(C.abricotF), 8)
      b.position.set(Math.cos(a) * 0.5, 3.2, Math.sin(a) * 0.5)
      b.rotation.z = Math.cos(a) * 0.7
      b.rotation.x = Math.sin(a) * 0.7
      g.add(b)
    }
    const houppier = new THREE.Group()
    for (const [x, y, z, r] of [[0, 4.6, 0, 1.9], [1.4, 4.1, 0.4, 1.3], [-1.4, 4.2, -0.2, 1.35], [0.3, 5.6, -0.3, 1.3], [-0.4, 4, 1.3, 1.1], [0.5, 4.1, -1.4, 1.15]] as const) {
      houppier.add(en(new THREE.Mesh(geo(`h${r}`, () => new THREE.IcosahedronGeometry(r, 1)), mat(C.saugeF)), x, y, z))
    }
    g.add(houppier)
    // Emplacements fixes (pseudo-hasard stable) des fruits et des feuilles, à la surface du houppier.
    const surface = (i: number, graine: number): THREE.Vector3 => {
      const u = hasard(i, graine)
      const v = hasard(i, graine + 1)
      const th = u * Math.PI * 2
      const ph = Math.acos(1 - v * 1.4)
      return new THREE.Vector3(Math.sin(ph) * Math.cos(th) * 2.15, 4.6 + Math.cos(ph) * 1.9, Math.sin(ph) * Math.sin(th) * 2.15)
    }
    const fruitMat = mat(C.roseF)
    const fruits = Array.from({ length: 24 }, (_, i) => {
      const f = sphere(0.2, fruitMat, 12)
      f.position.copy(surface(i, 20))
      f.visible = false
      g.add(f)
      return f
    })
    const feuille = lueur(0xd9edc8, 0.35, 1.2)
    const feuilles = Array.from({ length: 30 }, (_, i) => {
      const f = new THREE.Mesh(geo('feuille', () => new THREE.OctahedronGeometry(0.2, 0)), feuille.m)
      f.position.copy(surface(i, 40))
      f.scale.set(1, 0.5, 1)
      f.visible = false
      g.add(f)
      return f
    })
    return { fruits, feuilles }
  })()

  /* Le phare — la sécurité. */
  const phare = (() => {
    const g = nouvelleZone('phare', C.abricotF)
    g.add(en(cylindre(1.5, 1.7, 0.4, mat(C.lavandeF), 28), 0, 0.2, 0))
    for (let i = 0; i < 5; i++) g.add(en(cylindre(0.9 - i * 0.08, 0.98 - i * 0.08, 1.1, mat(i % 2 ? C.rose : C.creme), 24), 0, 0.95 + i * 1.1, 0))
    g.add(en(cylindre(0.75, 0.75, 0.12, mat(C.prune), 24), 0, 6.45, 0))
    const verre = new THREE.MeshToonMaterial({ color: C.abricot, emissive: C.abricot, emissiveIntensity: 0.3 })
    g.add(en(cylindre(0.5, 0.5, 0.75, verre, 16), 0, 6.9, 0))
    g.add(en(cone(0.65, 0.6, mat(C.roseF), 16), 0, 7.55, 0))
    // Le faisceau : deux plans croisés, dégradés vers le bout et vers les bords —
    // un cône ouvert finissait en disque bien visible.
    const cv = document.createElement('canvas')
    cv.width = 128
    cv.height = 64
    const x = cv.getContext('2d')!
    const long = x.createLinearGradient(0, 0, 128, 0)
    long.addColorStop(0, 'rgba(255,255,255,1)')
    long.addColorStop(1, 'rgba(255,255,255,0)')
    x.fillStyle = long
    x.beginPath()
    x.moveTo(0, 28)
    x.lineTo(128, 0)
    x.lineTo(128, 64)
    x.lineTo(0, 36)
    x.fill()
    const large = x.createLinearGradient(0, 0, 0, 64)
    large.addColorStop(0, 'rgba(0,0,0,1)')
    large.addColorStop(0.5, 'rgba(0,0,0,0)')
    large.addColorStop(1, 'rgba(0,0,0,1)')
    x.globalCompositeOperation = 'destination-out'
    x.fillStyle = large
    x.fillRect(0, 0, 128, 64)
    const texFaisceau = new THREE.CanvasTexture(cv)
    const faisceauMat = new THREE.MeshBasicMaterial({
      map: texFaisceau,
      color: 0xffe2b8,
      transparent: true,
      opacity: 0,
      side: THREE.DoubleSide,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    })
    const faisceau = new THREE.Group()
    for (const r of [0, Math.PI / 2]) {
      const plan = new THREE.Mesh(geo('faisceau', () => new THREE.PlaneGeometry(8, 2.6)), faisceauMat)
      plan.position.x = 4
      plan.rotation.x = r
      faisceau.add(plan)
    }
    const pivot = new THREE.Group()
    pivot.position.y = 6.9
    pivot.add(faisceau)
    g.add(pivot)
    const lumiere = new THREE.PointLight(0xffd29a, 0, 9, 1.4)
    lumiere.position.y = 6.9
    g.add(lumiere)
    const h = halo(0xffd29a, 3, 0.15, 0.6)
    h.position.y = 6.9
    g.add(h)
    return { verre, pivot, faisceauMat, lumiere }
  })()

  /* Le ciel : étoiles la nuit, pollen le jour. */
  const decor = (() => {
    const n = 800
    const p = new Float32Array(n * 3)
    for (let i = 0; i < n; i++) {
      const th = hasard(i, 30) * Math.PI * 2
      const ph = Math.acos(1 - (hasard(i, 31) * 0.85 + 0.05))
      p.set([Math.sin(ph) * Math.cos(th) * 150, Math.cos(ph) * 150 - 25, Math.sin(ph) * Math.sin(th) * 150], i * 3)
    }
    const etoiles = new THREE.Points(
      new THREE.BufferGeometry().setAttribute('position', new THREE.BufferAttribute(p, 3)),
      new THREE.PointsMaterial({ color: 0xe9cf8a, size: 0.9, map: point, transparent: true, opacity: 0, depthWrite: false }),
    )
    const m = 100
    const q = new Float32Array(m * 3)
    const graines = Array.from({ length: m }, (_, i) => {
      const a = hasard(i, 50) * 6.3
      const r = Math.sqrt(hasard(i, 51)) * 15
      return { x: Math.cos(a) * r, y: hasard(i, 52) * 6 + 0.6, z: Math.sin(a) * r, ph: hasard(i, 53) * 6 }
    })
    const pollen = new THREE.Points(
      new THREE.BufferGeometry().setAttribute('position', new THREE.BufferAttribute(q, 3)),
      new THREE.PointsMaterial({ color: 0xa07850, size: 0.12, map: point, transparent: true, opacity: 0.45, depthWrite: false }),
    )
    scene.add(etoiles, pollen)
    return { etoiles, pollen, graines }
  })()

  monde.traverse((o) => {
    if (!(o instanceof THREE.Mesh)) return
    const mm = o.material as THREE.Material
    if (mm.transparent) mm.userData.outlineParameters = { visible: false }
    else if (mm.visible !== false) {
      o.castShadow = true
      o.receiveShadow = true
    }
  })

  /* ───── Thème ───── */

  function appliquerTheme(): void {
    ciel.color.set(nuit ? 0x7f8db8 : 0xfff6e6)
    ciel.groundColor.set(nuit ? 0x2d3a2f : 0xa9b98f)
    ciel.intensity = nuit ? 1.1 : 1.3
    soleil.color.set(nuit ? 0xb9c6ff : 0xfff1dc)
    soleil.intensity = nuit ? 0.8 : 2.2
    contre.color.set(nuit ? 0xd9b56a : 0xe8c890)
    encre = creerEncre(renderer, nuit)
    for (const h of halos) h.s.material.opacity = nuit ? h.nuit : h.jour
    decor.etoiles.material.opacity = nuit ? 0.9 : 0
    decor.pollen.material.color.set(nuit ? 0xe9cf8a : 0xa07850)
  }
  appliquerTheme()

  /* ───── L'état → la scène ───── */

  function appliquer(e: EtatDuMonde): void {
    const premier = etat === null
    etat = e
    // Tapis : une carte par décision qui attend, placée selon son ancienneté.
    const vues = new Set<string>()
    const debut = -TAPIS_LONGUEUR / 2 + 0.5
    const fin = TAPIS_LONGUEUR / 2 - 0.5
    // De la plus ancienne (au bout) à la plus récente ; chacune garde 0,95 de place
    // derrière la précédente, et de quoi loger toutes celles qui la suivent.
    const triees = [...e.file.cartes].sort((a, b) => b.avancee - a.avancee)
    const pas = Math.min(0.95, (fin - debut) / Math.max(1, triees.length - 1))
    let precedente = Infinity
    for (const [i, c] of triees.entries()) {
      vues.add(c.cle)
      let x = debut + c.avancee * (fin - debut)
      x = Math.min(x, precedente - pas)
      x = Math.max(x, debut + (triees.length - 1 - i) * pas)
      precedente = x
      const deja = tapis.cartes.get(c.cle)
      if (deja) {
        deja.x = x
        deja.partir = false
      } else {
        const o = carte(c.nature)
        o.position.set(debut - 0.4, 0.72, 0)
        tapis.cartes.set(c.cle, { o, x, partir: false })
      }
    }
    for (const [cle, c] of tapis.cartes) if (!vues.has(cle)) c.partir = true
    // Tour.
    construireTour(e.comptes.etages, e.comptes.fenetres)
    for (const o of tour.corps.children) {
      if (typeof o.userData.rang === 'number' && o instanceof THREE.Mesh) o.material = o.userData.rang < e.comptes.allumees ? fenetreAllumee.m : fenetreEteinte
    }
    // Portail.
    portail.lanternes.forEach((l, i) => (l.visible = i < e.portail.lanternes))
    // Nuage : un nouvel appel fait un éclair (pas à l'ouverture : ce ne serait pas un nouvel appel).
    if (!premier && e.nuage.dernierAppel !== null && e.nuage.dernierAppel !== nuage.dernier) nuage.eclairT = 1
    nuage.dernier = e.nuage.dernierAppel
    // Arbre.
    arbre.fruits.forEach((f, i) => (f.visible = i < e.arbre.fruits))
    arbre.feuilles.forEach((f, i) => (f.visible = i < e.arbre.feuilles))
  }

  /* ───── Caméra ───── */

  let vol: { p0: THREE.Vector3; c0: THREE.Vector3; p1: THREE.Vector3; c1: THREE.Vector3; t: number; duree: number } | null = null
  const douceur = (t: number): number => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2)
  function voler(id: IdZone | null, duree = 1.8): void {
    let p1 = VUE.pos.clone()
    let c1 = VUE.cible.clone()
    if (id !== null) {
      const e = EMPLACEMENTS[id]
      c1 = e.pos.clone().add(new THREE.Vector3(...e.cible))
      p1 = c1.clone().add(new THREE.Vector3(...e.cam))
    }
    p1 = c1.clone().add(p1.sub(c1).multiplyScalar(recul()))
    vol = { p0: camera.position.clone(), c0: controles.target.clone(), p1, c1, t: 0, duree: m ? duree : 0.001 }
  }
  let decalage = 0
  let decalageVise = 0
  let decalageY = 0
  let decalageYVise = 0
  controles.addEventListener('start', () => {
    vol = null
    options.surGeste()
  })

  /* ───── Survol, clic ───── */

  const rayon = new THREE.Raycaster()
  const souris = new THREE.Vector2(-9, -9)
  let survol: IdZone | null = null
  let appui: { x: number; y: number } | null = null
  const toile = renderer.domElement
  function surMouvement(ev: PointerEvent): void {
    const r = toile.getBoundingClientRect()
    souris.set(((ev.clientX - r.left) / r.width) * 2 - 1, -((ev.clientY - r.top) / r.height) * 2 + 1)
  }
  function surSortie(): void {
    souris.set(-9, -9)
  }
  function surAppui(ev: PointerEvent): void {
    appui = { x: ev.clientX, y: ev.clientY }
  }
  function surRelache(ev: PointerEvent): void {
    if (!appui || Math.hypot(ev.clientX - appui.x, ev.clientY - appui.y) > 6) return
    if (survol) options.surClic(survol)
  }
  toile.addEventListener('pointermove', surMouvement)
  toile.addEventListener('pointerleave', surSortie)
  toile.addEventListener('pointerdown', surAppui)
  toile.addEventListener('pointerup', surRelache)

  /* ───── Étiquettes HTML ───── */

  const ancre = new THREE.Vector3()
  function placerEtiquettes(): void {
    const l = conteneur.clientWidth
    const h = conteneur.clientHeight
    for (const id of Object.keys(EMPLACEMENTS) as IdZone[]) {
      const el = options.etiquettes[id]
      if (!el) continue
      const e = EMPLACEMENTS[id]
      zones[id].g.getWorldPosition(ancre)
      ancre.y += e.etiquette * zones[id].g.scale.y
      ancre.project(camera)
      const visible = ancre.z < 1 && Math.abs(ancre.x) < 1.2 && Math.abs(ancre.y) < 1.2
      el.style.opacity = visible ? '' : '0'
      el.style.transform = `translate(${((ancre.x + 1) / 2) * l}px, ${((1 - ancre.y) / 2) * h}px) translate(-50%, -100%)`
    }
  }

  /* ───── La boucle ───── */

  const horloge = new THREE.Clock()
  let image = 0
  let arretee = false
  function boucle(): void {
    if (arretee) return
    image = requestAnimationFrame(boucle)
    const dt = Math.min(horloge.getDelta(), 0.1)
    const t = horloge.elapsedTime

    // Entrée en scène : l'île monte et se pose.
    const entree = m ? Math.min(1, t / 2.2) : 1
    const eb = 1 - Math.pow(1 - entree, 3)
    monde.position.y = (1 - eb) * -7 + (m ? Math.sin(t * 0.5) * 0.12 : 0)

    if (vol) {
      vol.t = Math.min(1, vol.t + dt / vol.duree)
      const k = douceur(vol.t)
      camera.position.lerpVectors(vol.p0, vol.p1, k)
      controles.target.lerpVectors(vol.c0, vol.c1, k)
      if (vol.t >= 1) vol = null
    }
    controles.update()
    decalage += (decalageVise - decalage) * Math.min(1, dt * (m ? 3 : 60))
    decalageY += (decalageYVise - decalageY) * Math.min(1, dt * (m ? 3 : 60))
    if (Math.abs(decalage) > 0.5 || Math.abs(decalageY) > 0.5)
      camera.setViewOffset(conteneur.clientWidth, conteneur.clientHeight, decalage, decalageY, conteneur.clientWidth, conteneur.clientHeight)
    else camera.clearViewOffset()

    // Survol : halo au sol et léger rebond.
    rayon.setFromCamera(souris, camera)
    const touche = rayon.intersectObjects(cliquables, false)[0]
    const nouveau = (touche?.object.userData.zone as IdZone | undefined) ?? null
    if (nouveau !== survol) {
      survol = nouveau
      toile.style.cursor = survol ? 'pointer' : ''
      options.surSurvol(survol)
    }
    for (const z of Object.values(zones)) {
      const vise = survol === z.id ? 0.9 : 0
      const am = z.anneau.material as THREE.MeshBasicMaterial
      am.opacity += (vise - am.opacity) * Math.min(1, dt * 8)
      const s = 1 + (survol === z.id ? 0.035 : 0)
      z.g.scale.setScalar(z.g.scale.x + (s - z.g.scale.x) * Math.min(1, dt * 10))
    }

    const e = etat
    const pouls = 0.5 + 0.5 * Math.sin(t * 3)

    // Maison : fumée tant que l'API répond ; plus haut quand elle est lente. Gyrophare en panne.
    const fume = e !== null && e.maison.ok
    const hauteurFumee = 2.4 + (e?.maison.lenteur ?? 0) * 3.5
    for (const b of maison.bulles) {
      const ph = (t * 0.25 + b.ph) % 1
      b.b.position.set(-1.4 + Math.sin(ph * 6 + b.ph * 9) * 0.3, 4.9 + ph * hauteurFumee, -0.5)
      b.b.scale.setScalar(0.4 + ph * 1.1)
      b.b.material.opacity = fume && m ? Math.sin(ph * Math.PI) * 0.75 : fume ? (b.ph < 0.3 ? 0.5 : 0) : 0
    }
    const panne = e !== null && e.maison.connue && !e.maison.ok
    maison.gyro.emissiveIntensity = panne ? (m ? 0.6 + 2 * Math.abs(Math.sin(t * 4)) : 2) : 0
    maison.eclat.intensity = panne ? (m ? 6 * Math.abs(Math.sin(t * 4)) : 4) : 0
    maison.fenetres.force = fume ? 0.4 : 0
    maison.porte.force = fume ? 0.3 : 0

    // Tapis : il ne défile que s'il porte quelque chose.
    const charge = (e?.file.cartes.length ?? 0) > 0
    if (charge) tapis.tex.offset.x -= dt * m * 0.18
    tapis.lampe.force = charge ? 0.9 : 0
    for (const [cle, c] of tapis.cartes) {
      if (c.partir) {
        // Décidée : elle file vers le bureau et disparaît.
        c.o.position.x += dt * (m ? 3 : 60)
        const s = c.o.scale.x * (1 - Math.min(1, dt * 4))
        c.o.scale.setScalar(s)
        if (s < 0.01) {
          tapis.g.remove(c.o)
          tapis.cartes.delete(cle)
        }
        continue
      }
      c.o.position.x += (c.x - c.o.position.x) * Math.min(1, dt * (m ? 1.6 : 60))
      c.o.scale.setScalar(c.o.scale.x + (1 - c.o.scale.x) * Math.min(1, dt * 5))
    }

    // Tour : l'emblème ne tourne que si quelqu'un s'est connecté aujourd'hui.
    if ((e?.comptes.allumees ?? 0) > 0) tour.emblème.rotation.y += dt * m * 0.6

    // Portail : il scintille tant qu'un lien est actif.
    const ouvert = (e?.portail.actives ?? 0) > 0
    const pm = portail.points.material as THREE.PointsMaterial
    pm.opacity += ((ouvert ? 0.9 : 0) - pm.opacity) * Math.min(1, dt * 2)
    ;(portail.voile.material as THREE.MeshBasicMaterial).opacity = ouvert ? 0.28 : 0.08
    ;(portail.voileBas.material as THREE.MeshBasicMaterial).opacity = ouvert ? 0.28 : 0.08
    if (pm.opacity > 0.01) {
      const a = portail.points.geometry.attributes.position!
      portail.etincelles.forEach((s, i) => {
        s.a += dt * m * s.v
        a.setXYZ(i, Math.cos(s.a) * s.r, 1.7 + Math.sin(s.a) * s.r, Math.sin(s.a * 2) * 0.25)
      })
      a.needsUpdate = true
    }

    // Nuage : pluie après un appel récent, éclair à chaque nouvel appel.
    nuage.corps.position.y = m ? Math.sin(t * 0.7) * 0.25 : 0
    const pmat = nuage.pluie.material as THREE.PointsMaterial
    pmat.opacity += ((e?.nuage.pluie ? 0.9 : 0) - pmat.opacity) * Math.min(1, dt * 2)
    if (pmat.opacity > 0.01) {
      const a = nuage.pluie.geometry.attributes.position!
      nuage.gouttes.forEach((g, i) => {
        if (m) {
          g.y -= g.v * dt
          if (g.y < -9.5) g.y = -0.6
        }
        a.setXYZ(i, g.x, g.y, g.z)
      })
      a.needsUpdate = true
    }
    nuage.eclairT = Math.max(0, nuage.eclairT - dt * (m ? 1.6 : 0.8))
    const fl = nuage.eclairT > 0 ? (nuage.eclairT > 0.7 || (nuage.eclairT > 0.35 && nuage.eclairT < 0.5) ? 1 : 0.25) * nuage.eclairT : 0
    ;(nuage.eclair.material as THREE.MeshBasicMaterial).opacity = fl
    nuage.flash.intensity = fl * 40

    // Phare : il tourne tant que l'API répond ; orange en alerte.
    const veille = e !== null && e.maison.ok
    if (veille) phare.pivot.rotation.y += dt * m * (e.phare.alerte ? 1.6 : 0.6)
    const alerte = e?.phare.alerte ?? false
    phare.verre.color.set(alerte ? 0xe3895a : C.abricot)
    phare.verre.emissive.set(alerte ? 0xe3895a : C.abricot)
    phare.verre.emissiveIntensity = veille ? (nuit ? 1.6 : 0.7) * (alerte && m ? 0.6 + 0.4 * pouls : 1) : 0.15
    phare.faisceauMat.opacity = veille ? (nuit ? 0.55 : 0.3) : 0
    phare.faisceauMat.color.set(alerte ? 0xffa36e : 0xffe2b8)
    phare.lumiere.intensity = veille ? (nuit ? 4 : 1.2) : 0

    // Lueurs : jour ou nuit, plus ce que la donnée allume.
    for (const l of lueurs) l.m.emissiveIntensity = (nuit ? l.nuit : l.jour) + l.force

    // Décor : il respire doucement.
    for (const f of flottants) f.position.y = (f.userData.y as number) + (m ? Math.sin(t * 0.6 + (f.userData.ph as number)) * 0.35 : 0)
    {
      const a = decor.pollen.geometry.attributes.position!
      decor.graines.forEach((q, i) =>
        a.setXYZ(i, q.x + (m ? Math.sin(t * 0.2 + q.ph) * 1.2 : 0), q.y + (m ? Math.sin(t * 0.35 + q.ph * 2) * 0.6 : 0), q.z + (m ? Math.cos(t * 0.17 + q.ph) * 1.2 : 0)),
      )
      a.needsUpdate = true
    }
    if (nuit) decor.etoiles.material.opacity = 0.75 + (m ? 0.2 * Math.sin(t * 0.9) : 0)

    encre.render(scene, camera)
    placerEtiquettes()
  }

  function surVisibilite(): void {
    cancelAnimationFrame(image)
    if (!document.hidden && !arretee) {
      horloge.getDelta()
      image = requestAnimationFrame(boucle)
    }
  }
  document.addEventListener('visibilitychange', surVisibilite)

  const observateur = new ResizeObserver(() => {
    const l = conteneur.clientWidth
    const h = conteneur.clientHeight
    if (l === 0 || h === 0) return
    camera.aspect = l / h
    camera.updateProjectionMatrix()
    renderer.setSize(l, h)
  })
  observateur.observe(conteneur)

  image = requestAnimationFrame(boucle)

  return {
    appliquer,
    voler,
    theme(n) {
      nuit = n
      appliquerTheme()
    },
    mouvementReduit(oui) {
      m = oui ? 0 : 1
    },
    decaler(x, y = 0) {
      decalageVise = x
      decalageYVise = y
    },
    detruire() {
      arretee = true
      cancelAnimationFrame(image)
      observateur.disconnect()
      document.removeEventListener('visibilitychange', surVisibilite)
      toile.removeEventListener('pointermove', surMouvement)
      toile.removeEventListener('pointerleave', surSortie)
      toile.removeEventListener('pointerdown', surAppui)
      toile.removeEventListener('pointerup', surRelache)
      controles.dispose()
      scene.traverse((o) => {
        if (o instanceof THREE.Mesh || o instanceof THREE.Points || o instanceof THREE.Sprite) {
          o.geometry?.dispose()
          const mats = Array.isArray(o.material) ? o.material : [o.material]
          for (const x of mats) x.dispose()
        }
      })
      viderAtelier()
      point.dispose()
      renderer.dispose()
      toile.remove()
    },
  }
}
