/**
 * La scène 3D de l'arbre (`/arbre/monde`) — Three.js, chargée paresseusement.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Elle ne lit aucune donnée** : elle reçoit une `DispositionDeLArbre`
 * (calculée et testée dans `disposition.ts`) et la fait pousser. Même atelier
 * que le Monde du suivi (`monde/atelier.ts`) : carnet d'archiviste, trois tons,
 * trait d'encre.
 *
 * - **Le tronc** monte de l'île jusqu'au lecteur ; **le bois** relie chaque
 *   couple (un nœud) à ses enfants et à ses époux. La ligne directe est en bois
 *   épais, le reste en rameaux fins — fusionnés en deux maillages : quelques
 *   centaines de tubes ne coûtent que deux appels de rendu.
 * - **Les feuilles** sont des médaillons, tous dans **un seul `InstancedMesh`** :
 *   une couleur par feuille (`apparence.ts`), un seul appel de rendu pour tout
 *   l'arbre.
 * - **Les bourgeons** (une demande en attente sur la fiche) luisent.
 *
 * **Rien ne bouge seul** : la sève ne monte que si la ligne directe est
 * demandée, les bourgeons ne battent que s'il y a une demande. Le décor
 * (rochers flottants, pollen, étoiles) respire doucement, et rien du tout sous
 * `prefers-reduced-motion`.
 *
 * **Les étiquettes** sont des éléments HTML de la vue, que la scène place à
 * chaque image : le lecteur, la feuille survolée et la feuille choisie toujours ;
 * les feuilles proches de la caméra quand on s'approche. Jamais 470 étiquettes.
 * ────────────────────────────────────────────────────────────────────
 */

import * as THREE from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js'
import { C, TONS, construireIle, creerEncre, hasard, lueur, lueurs, mat, sphere, texturePoint, viderAtelier } from '@/components/monde/atelier'
import type { DispositionDeLArbre, Feuille, Point } from './disposition'
import { couleurDeFeuille, melanger } from './apparence'

export interface SceneDeLArbre {
  charger(d: DispositionDeLArbre): void
  /** La feuille choisie (`null` : aucune) ; `voler` emmène la caméra jusqu'à elle. */
  choisir(id: string | null, voler?: boolean): void
  /** Les feuilles à garder vives, les autres pâlissent (`null` : toutes vives). */
  surligner(ids: ReadonlySet<string> | null, seve?: boolean): void
  /** Un cadrage d'ensemble : tout l'arbre, la couronne, ou les racines. */
  cadrer(vue: 'ensemble' | 'couronne' | 'racines'): void
  theme(nuit: boolean): void
  mouvementReduit(oui: boolean): void
  decaler(x: number, y?: number): void
  detruire(): void
}

export interface OptionsDeLaScene {
  /** Le réservoir d'étiquettes HTML que la scène remplit et place. */
  etiquettes: HTMLElement[]
  /** Les repères d'étage (« Grands-parents »), par génération. */
  etages: Map<number, HTMLElement>
  surSurvol: (f: Feuille | null) => void
  surClic: (f: Feuille) => void
  surGeste: () => void
  nuit: boolean
  mouvementReduit: boolean
}

const BOIS = 0x8a6646
const BOIS_FIN = 0xc2a47e
const RACINE = 0x7a5a3e
const OR = 0xd9a54a

export function creerSceneDeLArbre(conteneur: HTMLElement, options: OptionsDeLaScene): SceneDeLArbre {
  let nuit = options.nuit
  let m = options.mouvementReduit ? 0 : 1

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' })
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
  renderer.setSize(conteneur.clientWidth, conteneur.clientHeight)
  renderer.shadowMap.enabled = true
  renderer.shadowMap.type = THREE.PCFSoftShadowMap
  renderer.outputColorSpace = THREE.SRGBColorSpace
  renderer.domElement.setAttribute('aria-hidden', 'true')
  conteneur.appendChild(renderer.domElement)
  let encre = creerEncre(renderer, nuit, 0.0034)

  const scene = new THREE.Scene()
  const camera = new THREE.PerspectiveCamera(36, conteneur.clientWidth / conteneur.clientHeight, 0.1, 600)
  const controles = new OrbitControls(camera, renderer.domElement)
  controles.enableDamping = true
  controles.dampingFactor = 0.07
  controles.minDistance = 3
  controles.maxDistance = 160
  controles.rotateSpeed = 0.6

  const ciel = new THREE.HemisphereLight(0xfff6e6, 0xa9b98f, 1.35)
  const soleil = new THREE.DirectionalLight(0xfff1dc, 2.1)
  soleil.position.set(18, 40, 16)
  soleil.castShadow = true
  soleil.shadow.mapSize.set(1024, 1024)
  Object.assign(soleil.shadow.camera, { left: -30, right: 30, top: 40, bottom: -20, near: 1, far: 120 })
  soleil.shadow.bias = -0.0004
  soleil.shadow.normalBias = 0.03
  const contre = new THREE.DirectionalLight(0xe8c890, 0.5)
  contre.position.set(-20, 14, -18)
  scene.add(ciel, soleil, contre)

  const point = texturePoint()
  const monde = new THREE.Group()
  scene.add(monde)
  const flottants = construireIle(monde, { rayon: 9, profondeur: 2.6 })

  /* ───── Décor : étoiles la nuit, pollen le jour ───── */
  const etoiles = (() => {
    const n = 700
    const p = new Float32Array(n * 3)
    for (let i = 0; i < n; i++) {
      const th = hasard(i, 30) * Math.PI * 2
      const ph = Math.acos(1 - (hasard(i, 31) * 0.85 + 0.05))
      p.set([Math.sin(ph) * Math.cos(th) * 220, Math.cos(ph) * 220 - 20, Math.sin(ph) * Math.sin(th) * 220], i * 3)
    }
    const o = new THREE.Points(
      new THREE.BufferGeometry().setAttribute('position', new THREE.BufferAttribute(p, 3)),
      new THREE.PointsMaterial({ color: 0xe9cf8a, size: 1.1, map: point, transparent: true, opacity: 0, depthWrite: false }),
    )
    scene.add(o)
    return o
  })()

  /* ───── Ce que `charger` remplit ───── */
  let disposition: DispositionDeLArbre | null = null
  const arbre = new THREE.Group()
  monde.add(arbre)
  let feuillage: THREE.InstancedMesh | null = null
  let couleursDeBase: THREE.Color[] = []
  let boisDirect: THREE.Mesh | null = null
  const seve = lueur(OR, 0, 0)
  const bourgeons: THREE.Sprite[] = []
  let anneauEgo: THREE.Mesh | null = null
  const anneauChoix = new THREE.Mesh(new THREE.TorusGeometry(0.52, 0.05, 8, 32), new THREE.MeshBasicMaterial({ color: OR }))
  anneauChoix.visible = false
  anneauChoix.material.userData.outlineParameters = { visible: false }
  arbre.add(anneauChoix)

  let choisie: number | null = null
  let survolee: number | null = null
  let vives: ReadonlySet<string> | null = null
  let seveActive = false

  const centre = new THREE.Vector3()
  const vueEnsemble = { pos: new THREE.Vector3(0, 14, 40), cible: new THREE.Vector3(0, 8, 0) }

  function tube(de: Point, vers: Point, rayon: number, sortant: number): THREE.BufferGeometry {
    const a = new THREE.Vector3(...de)
    const b = new THREE.Vector3(...vers)
    const milieu = a.clone().lerp(b, 0.5)
    // Courbé vers l'extérieur, comme une branche qui s'écarte du tronc.
    const radial = new THREE.Vector3(milieu.x, 0, milieu.z)
    if (radial.lengthSq() > 0.0001) milieu.add(radial.normalize().multiplyScalar(sortant))
    const courbe = new THREE.QuadraticBezierCurve3(a, milieu, b)
    return new THREE.TubeGeometry(courbe, 8, rayon, 5, false)
  }

  function charger(d: DispositionDeLArbre): void {
    disposition = d
    for (const o of [...arbre.children]) {
      if (o === anneauChoix) continue
      arbre.remove(o)
      o.traverse((x) => {
        if (x instanceof THREE.Mesh || x instanceof THREE.Sprite) x.geometry?.dispose()
      })
    }
    bourgeons.length = 0
    choisie = null
    survolee = null

    // Le tronc, et quelques racines apparentes à son pied.
    const tronc = new THREE.Mesh(new THREE.CylinderGeometry(0.42, 0.85, d.hautDuTronc, 14), mat(BOIS))
    tronc.position.y = d.hautDuTronc / 2
    arbre.add(tronc)
    for (let i = 0; i < 5; i++) {
      const a = (i / 5) * Math.PI * 2 + 0.3
      const r = new THREE.Mesh(new THREE.ConeGeometry(0.22, 1.6, 6), mat(BOIS))
      r.position.set(Math.cos(a) * 0.75, 0.25, Math.sin(a) * 0.75)
      r.rotation.set(Math.sin(a) * 1.25, 0, -Math.cos(a) * 1.25)
      arbre.add(r)
    }

    // Le bois : la ligne directe épaisse, le reste fin, les racines plus sombres.
    const direct: THREE.BufferGeometry[] = []
    const fin: THREE.BufferGeometry[] = []
    const racines: THREE.BufferGeometry[] = []
    for (const r of d.rameaux) {
      const sous = r.de[1] < 0 || r.vers[1] < 0
      const g = tube(r.de, r.vers, r.direct ? 0.11 : 0.026, r.genre === 'filiation' ? 0.35 : 0.1)
      ;(r.direct ? direct : sous ? racines : fin).push(g)
    }
    // Les descendants pendent sous l'île : un câble de racine relie le pied du tronc au premier étage.
    if (d.feuilles.some((f) => f.generation > 0)) direct.push(tube([0, 0.2, 0], [0, -1.4, 0], 0.16, 0))
    // Le trait d'encre doublerait l'épaisseur des rameaux fins : sur les étages
    // peuplés, il en faisait un nid noir. Il est réservé au tronc et à la ligne directe.
    const fusion = (gs: THREE.BufferGeometry[], couleur: number, encre = true): THREE.Mesh | null => {
      if (gs.length === 0) return null
      const g = mergeGeometries(gs)
      for (const x of gs) x.dispose()
      const matiere = encre ? mat(couleur) : mat(couleur, { name: 'sans-encre' })
      if (!encre) matiere.userData.outlineParameters = { visible: false }
      const mesh = new THREE.Mesh(g, matiere)
      mesh.castShadow = true
      arbre.add(mesh)
      return mesh
    }
    boisDirect = fusion(direct, BOIS)
    if (boisDirect) boisDirect.material = seve.m
    fusion(fin, BOIS_FIN, false)
    fusion(racines, RACINE, false)

    // Les nœuds des couples.
    for (const n of d.noeuds) {
      const k = sphere(n.direct ? 0.16 : 0.09, mat(n.divorce ? C.roseF : BOIS), 10)
      k.position.set(...n.position)
      arbre.add(k)
    }

    // Les feuilles : un seul maillage instancié, un médaillon par personne.
    const disque = new THREE.CylinderGeometry(0.34, 0.34, 0.09, 18)
    disque.rotateX(Math.PI / 2)
    // Le trait d'encre (`OutlineEffect`) ne sait pas dessiner un maillage instancié :
    // le liseré du médaillon est sa tranche, d'une encre que la couleur de la feuille teinte.
    const tranche = new THREE.MeshToonMaterial({ color: 0x4a3a2c, gradientMap: TONS })
    const face = new THREE.MeshToonMaterial({ color: 0xffffff, gradientMap: TONS })
    for (const x of [tranche, face]) x.userData.outlineParameters = { visible: false }
    feuillage = new THREE.InstancedMesh(disque, [tranche, face, face], d.feuilles.length)
    feuillage.castShadow = true
    couleursDeBase = d.feuilles.map((f) => new THREE.Color(couleurDeFeuille(f)))
    const o = new THREE.Object3D()
    d.feuilles.forEach((f, i) => {
      o.position.set(...f.position)
      // Face tournée vers l'extérieur de l'arbre ; le lecteur, vers la caméra.
      o.lookAt(f.ego ? new THREE.Vector3(0, f.position[1], 10) : new THREE.Vector3(f.position[0] * 2, f.position[1], f.position[2] * 2))
      o.scale.setScalar(f.ego ? 1.7 : 1)
      o.updateMatrix()
      feuillage!.setMatrixAt(i, o.matrix)
      feuillage!.setColorAt(i, couleursDeBase[i]!)
      if (f.enAttente) {
        const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: point, color: OR, transparent: true, opacity: 0.7, depthWrite: false, blending: THREE.AdditiveBlending }))
        s.position.set(...f.position)
        s.scale.setScalar(1.6)
        arbre.add(s)
        bourgeons.push(s)
      }
    })
    feuillage.instanceMatrix.needsUpdate = true
    arbre.add(feuillage)

    // Le lecteur : un anneau d'or autour de son médaillon.
    const ego = d.feuilles.find((f) => f.ego)
    if (ego) {
      anneauEgo = new THREE.Mesh(new THREE.TorusGeometry(0.62, 0.07, 8, 36), mat(OR))
      anneauEgo.position.set(...ego.position)
      anneauEgo.lookAt(0, ego.position[1], 10)
      arbre.add(anneauEgo)
    }

    // Les cadrages.
    const hauteur = d.haut - d.bas
    centre.set(0, (d.haut + Math.min(0, d.bas)) / 2, 0)
    // Le champ vertical de la caméra (36°) voit 0,65 × la distance, au centre de l'arbre ;
    // les feuilles du premier plan de la couronne sont plus près d'un rayon : on recule d'autant.
    const distance = Math.max((hauteur + 4) / 0.65 + d.rayon, d.rayon * 2.8, 24)
    vueEnsemble.cible.copy(centre)
    vueEnsemble.pos.set(0, centre.y + distance * 0.12, distance)
    appliquerSurlignage()
    if (!vol) cadrer('ensemble', 0.001)
  }

  /* ───── Surlignage : la ligne directe vive, le reste pâli ───── */
  function appliquerSurlignage(): void {
    if (!feuillage || !disposition) return
    const papier = nuit ? 0x1b2235 : 0xf4ecdc
    disposition.feuilles.forEach((f, i) => {
      const base = couleursDeBase[i]!
      const c = vives === null || vives.has(f.id) ? base : new THREE.Color(melanger(base.getHex(), papier, 0.72))
      feuillage!.setColorAt(i, c)
    })
    if (feuillage.instanceColor) feuillage.instanceColor.needsUpdate = true
  }

  /* ───── Caméra ───── */
  let vol: { p0: THREE.Vector3; c0: THREE.Vector3; p1: THREE.Vector3; c1: THREE.Vector3; t: number; duree: number } | null = null
  const douceur = (t: number): number => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2)
  const recul = (): number => (camera.aspect < 1 ? Math.min(2.2, 1 / Math.pow(camera.aspect, 0.8)) : 1)
  function volerVers(pos: THREE.Vector3, cible: THREE.Vector3, duree = 1.6): void {
    const p1 = cible.clone().add(pos.clone().sub(cible).multiplyScalar(recul()))
    vol = { p0: camera.position.clone(), c0: controles.target.clone(), p1, c1: cible.clone(), t: 0, duree: m ? duree : 0.001 }
  }
  function cadrer(vue: 'ensemble' | 'couronne' | 'racines', duree = 1.8): void {
    if (!disposition) return
    if (vue === 'ensemble') return volerVers(vueEnsemble.pos, vueEnsemble.cible, duree)
    if (vue === 'couronne') {
      const c = new THREE.Vector3(0, (disposition.hautDuTronc + disposition.haut) / 2, 0)
      return volerVers(c.clone().add(new THREE.Vector3(0, 2, Math.max(18, disposition.rayon * 2.2))), c, duree)
    }
    const c = new THREE.Vector3(0, Math.min(-1, disposition.bas / 2), 0)
    volerVers(c.clone().add(new THREE.Vector3(0, -3, 16)), c, duree)
  }
  camera.position.copy(vueEnsemble.pos)
  controles.target.copy(vueEnsemble.cible)

  function choisir(id: string | null, voler = false): void {
    if (!disposition) return
    const i = id === null ? -1 : disposition.feuilles.findIndex((f) => f.id === id)
    choisie = i < 0 ? null : i
    anneauChoix.visible = choisie !== null
    if (choisie === null) return
    const f = disposition.feuilles[choisie]!
    anneauChoix.position.set(...f.position)
    anneauChoix.lookAt(f.ego ? new THREE.Vector3(0, f.position[1], 10) : new THREE.Vector3(f.position[0] * 2, f.position[1], f.position[2] * 2))
    if (voler) {
      const p = new THREE.Vector3(...f.position)
      const dehors = new THREE.Vector3(p.x, 0, p.z)
      if (dehors.lengthSq() < 0.01) dehors.set(0, 0, 1)
      dehors.normalize()
      // La caméra se pose HORS de l'étage, au-delà de son anneau le plus large : posée
      // contre la feuille, elle se retrouvait au milieu des voisines d'un étage peuplé.
      const rayonEtage = Math.max(0, ...disposition.feuilles.filter((x) => x.generation === f.generation).map((x) => Math.hypot(x.position[0], x.position[2])))
      const pied = new THREE.Vector3(0, p.y, 0).add(dehors.multiplyScalar(rayonEtage + 7))
      volerVers(pied.add(new THREE.Vector3(0, 2, 0)), p, 1.6)
    }
  }

  let decalX = 0
  let decalY = 0
  let decalXVise = 0
  let decalYVise = 0
  controles.addEventListener('start', () => {
    vol = null
    options.surGeste()
  })

  /* ───── Survol, clic ───── */
  const rayon = new THREE.Raycaster()
  const souris = new THREE.Vector2(-9, -9)
  const toile = renderer.domElement
  let appui: { x: number; y: number } | null = null
  const surMouvement = (e: PointerEvent): void => {
    const r = toile.getBoundingClientRect()
    souris.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1)
  }
  const surSortie = (): void => void souris.set(-9, -9)
  const surAppui = (e: PointerEvent): void => void (appui = { x: e.clientX, y: e.clientY })
  const surRelache = (e: PointerEvent): void => {
    if (!appui || Math.hypot(e.clientX - appui.x, e.clientY - appui.y) > 6) return
    if (survolee !== null && disposition) options.surClic(disposition.feuilles[survolee]!)
  }
  toile.addEventListener('pointermove', surMouvement)
  toile.addEventListener('pointerleave', surSortie)
  toile.addEventListener('pointerdown', surAppui)
  toile.addEventListener('pointerup', surRelache)

  /* ───── Étiquettes ───── */
  const v = new THREE.Vector3()
  const ecrites = new Map<HTMLElement, string>()
  function placer(el: HTMLElement, p: Point, decalage = 0.55): boolean {
    v.set(p[0], p[1] + decalage, p[2]).applyMatrix4(monde.matrixWorld).project(camera)
    const visible = v.z < 1 && Math.abs(v.x) < 1.1 && Math.abs(v.y) < 1.1
    el.style.opacity = visible ? '1' : '0'
    el.style.transform = `translate(${((v.x + 1) / 2) * conteneur.clientWidth}px, ${((1 - v.y) / 2) * conteneur.clientHeight}px) translate(-50%, -100%)`
    return visible
  }
  const proche = new THREE.Vector3()
  function placerEtiquettes(): void {
    const pool = options.etiquettes
    if (!disposition) {
      for (const el of pool) el.style.opacity = '0'
      return
    }
    const f = disposition.feuilles
    // Toujours : le lecteur, la feuille choisie, la feuille survolée. Puis les plus proches.
    const retenues: number[] = []
    const ajouter = (i: number | null): void => {
      if (i !== null && i >= 0 && !retenues.includes(i)) retenues.push(i)
    }
    ajouter(f.findIndex((x) => x.ego))
    ajouter(choisie)
    ajouter(survolee)
    const dist = camera.position.distanceTo(controles.target)
    if (dist < 26) {
      const cands = f
        .map((x, i) => ({ i, d: proche.set(...x.position).distanceTo(camera.position) }))
        .filter((c) => c.d < 16 && (vives === null || vives.has(f[c.i]!.id)))
        .sort((a, b) => a.d - b.d)
      for (const c of cands) {
        if (retenues.length >= pool.length) break
        ajouter(c.i)
      }
    }
    pool.forEach((el, k) => {
      const i = retenues[k]
      if (i === undefined) {
        el.style.opacity = '0'
        el.dataset.etat = ''
        return
      }
      const feuille = f[i]!
      const texte = feuille.nom + (feuille.dates ? ` ${feuille.dates}` : '')
      if (ecrites.get(el) !== texte) {
        el.querySelector('[data-nom]')!.textContent = feuille.nom
        el.querySelector('[data-dates]')!.textContent = feuille.dates
        ecrites.set(el, texte)
      }
      el.dataset.etat = i === choisie ? 'choisie' : i === survolee ? 'survol' : feuille.ego ? 'ego' : ''
      placer(el, feuille.position, feuille.ego ? 0.8 : 0.5)
    })
    // Les repères d'étage, au bord gauche de chaque étage.
    for (const [g, el] of options.etages) {
      const autre = f.find((x) => x.generation === g && !x.ego)
      const y = autre ? autre.position[1] : g === 0 ? disposition.hautDuTronc : null
      if (y === null) {
        el.style.opacity = '0'
        continue
      }
      const cote = camera.position.clone().sub(controles.target).cross(new THREE.Vector3(0, 1, 0)).normalize()
      const r = Math.hypot(autre?.position[0] ?? 0, autre?.position[2] ?? 2) + 1.4
      placer(el, [-cote.x * r, y, -cote.z * r], 0)
    }
  }

  /* ───── La boucle ───── */
  const horloge = new THREE.Clock()
  let image = 0
  let arretee = false
  const s = new THREE.Object3D()
  function boucle(): void {
    if (arretee) return
    image = requestAnimationFrame(boucle)
    const dt = Math.min(horloge.getDelta(), 0.1)
    const t = horloge.elapsedTime

    const entree = m ? Math.min(1, t / 2) : 1
    monde.position.y = (1 - (1 - Math.pow(1 - entree, 3))) * -6 + (m ? Math.sin(t * 0.45) * 0.1 : 0)

    if (vol) {
      vol.t = Math.min(1, vol.t + dt / vol.duree)
      const k = douceur(vol.t)
      camera.position.lerpVectors(vol.p0, vol.p1, k)
      controles.target.lerpVectors(vol.c0, vol.c1, k)
      if (vol.t >= 1) vol = null
    }
    controles.update()
    decalX += (decalXVise - decalX) * Math.min(1, dt * (m ? 3 : 60))
    decalY += (decalYVise - decalY) * Math.min(1, dt * (m ? 3 : 60))
    if (Math.abs(decalX) > 0.5 || Math.abs(decalY) > 0.5)
      camera.setViewOffset(conteneur.clientWidth, conteneur.clientHeight, decalX, decalY, conteneur.clientWidth, conteneur.clientHeight)
    else camera.clearViewOffset()

    // Survol d'une feuille.
    if (feuillage && disposition) {
      rayon.setFromCamera(souris, camera)
      const h = rayon.intersectObject(feuillage, false)[0]
      const i = h?.instanceId ?? null
      if (i !== survolee) {
        const avant = survolee
        survolee = i
        toile.style.cursor = i !== null ? 'pointer' : ''
        options.surSurvol(i !== null ? disposition.feuilles[i]! : null)
        // Le médaillon survolé grossit un peu ; celui qu'on quitte reprend sa taille.
        for (const j of [avant, i]) {
          if (j === null) continue
          const f = disposition.feuilles[j]!
          s.position.set(...f.position)
          s.lookAt(f.ego ? new THREE.Vector3(0, f.position[1], 10) : new THREE.Vector3(f.position[0] * 2, f.position[1], f.position[2] * 2))
          s.scale.setScalar((f.ego ? 1.7 : 1) * (j === i ? 1.35 : 1))
          s.updateMatrix()
          feuillage.setMatrixAt(j, s.matrix)
        }
        feuillage.instanceMatrix.needsUpdate = true
      }
    }

    // La sève : elle ne monte que quand la ligne directe est demandée.
    seve.m.color.setHex(seveActive ? OR : BOIS)
    seve.m.emissive.setHex(seveActive ? OR : 0x000000)
    seve.m.emissiveIntensity = seveActive ? (nuit ? 0.9 : 0.45) * (m ? 0.65 + 0.35 * Math.sin(t * 2.4) : 1) : 0
    for (const b of bourgeons) b.material.opacity = (nuit ? 0.85 : 0.6) * (m ? 0.6 + 0.4 * Math.sin(t * 3) : 1)
    if (choisie !== null) anneauChoix.rotation.z += dt * m * 0.8

    for (const f of flottants) f.position.y = (f.userData.y as number) + (m ? Math.sin(t * 0.6 + (f.userData.ph as number)) * 0.3 : 0)
    if (nuit) etoiles.material.opacity = 0.75 + (m ? 0.2 * Math.sin(t * 0.9) : 0)
    for (const l of lueurs) l.m.emissiveIntensity = l === seve ? l.m.emissiveIntensity : (nuit ? l.nuit : l.jour) + l.force

    encre.render(scene, camera)
    placerEtiquettes()
  }

  function appliquerTheme(): void {
    ciel.color.set(nuit ? 0x7f8db8 : 0xfff6e6)
    ciel.groundColor.set(nuit ? 0x2d3a2f : 0xa9b98f)
    soleil.color.set(nuit ? 0xb9c6ff : 0xfff1dc)
    soleil.intensity = nuit ? 0.85 : 2.1
    contre.color.set(nuit ? 0xd9b56a : 0xe8c890)
    etoiles.material.opacity = nuit ? 0.9 : 0
    encre = creerEncre(renderer, nuit, 0.0034)
    appliquerSurlignage()
  }
  appliquerTheme()

  const surVisibilite = (): void => {
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
    charger,
    choisir,
    surligner(ids, avecSeve = false) {
      vives = ids
      seveActive = avecSeve
      appliquerSurlignage()
    },
    cadrer,
    theme(n) {
      nuit = n
      appliquerTheme()
    },
    mouvementReduit(oui) {
      m = oui ? 0 : 1
    },
    decaler(x, y = 0) {
      decalXVise = x
      decalYVise = y
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
