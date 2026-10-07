// Les rushes : la VRAIE application (app/), filmée image par image sur la famille fictive.
//
//   node outils/fausse-api.mjs &                                    (la famille fictive)
//   (cd ../../app && VITE_API_PROXY_TARGET=http://127.0.0.1:8000 npx vite) &
//   node outils/rushes.mjs                 → tous les plans
//   node outils/rushes.mjs arbre fiche     → seulement ceux-là
//   FPS_RUSH=15 node outils/rushes.mjs     → plus vite, pour l'aperçu basse définition
//
// Chaque plan ouvre une page, attend qu'elle soit prête, puis fige l'horloge de la page
// (outils/horloge-virtuelle.js) : chaque image avance d'exactement 1/FPS s. Les gestes
// (caméra de l'arbre, frappe, clics) sont placés sur la timeline (timeline.js), en
// secondes de la VIDÉO : le rush d'un plan couvre [debut, fin] de la vidéo.
//
// Sortie : sortie/rushes/<plan>/00000.jpg… et sortie/rushes/<plan>.json (le plan, et
// pour chaque image les rectangles des éléments à mettre en valeur, en pixels du rush).
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const ici = path.dirname(fileURLToPath(import.meta.url))
const require = createRequire(import.meta.url)
const T = require('./timeline-node.cjs')()
const { construireFamille } = require('../donnees/famille.js')
const A = construireFamille()
const R = T.R
const FPS = Number(process.env.FPS_RUSH || 30)
const APP = process.env.APP || 'http://127.0.0.1:5173'
const SORTIE = path.join(ici, '..', 'sortie', 'rushes')
const HORLOGE = fs.readFileSync(path.join(ici, 'horloge-virtuelle.js'), 'utf8')
const CHROME = process.env.CHROME || ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find((p) => fs.existsSync(p))

const V = A.vedette, [PERE_V, MERE_V] = A.parentsVedette
// la famille de Joséphine : celle que l'intro dessine avant de révéler l'arbre réel
const FAMILLE_INTRO = [V, PERE_V, MERE_V, ...A.conjointsDe(V), ...A.enfantsDe(V), ...A.enfantsDe(PERE_V).filter((p) => p !== V)].map((p) => p.id)

/* ------------------------------------------------------------------ outils de plan */
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v))
const prog = (t, a, b) => clamp((t - a) / (b - a))
const eio = (k) => (k < 0.5 ? 4 * k ** 3 : 1 - Math.pow(-2 * k + 2, 3) / 2)
const esin = (k) => -(Math.cos(Math.PI * k) - 1) / 2
/**
 * Une trajectoire de caméra de l'arbre : des cadrages {t, s, x, y} (x, y : le point du
 * monde au centre). Le zoom avance en logarithme, et la position suit l'inverse de
 * l'échelle : le point visé reste sous les yeux pendant tout le zoom.
 */
function camera(t, cles, courbe = eio) {
  if (t <= cles[0].t) return cles[0]
  for (let i = 1; i < cles.length; i++) {
    const a = cles[i - 1], b = cles[i]
    if (t > b.t) continue
    const k = (b.courbe ?? courbe)(prog(t, a.t, b.t))
    const s = Math.exp(Math.log(a.s) + (Math.log(b.s) - Math.log(a.s)) * k)
    const u = Math.abs(1 / b.s - 1 / a.s) < 1e-9 ? k : (1 / s - 1 / a.s) / (1 / b.s - 1 / a.s)
    return { s, x: a.x + (b.x - a.x) * u, y: a.y + (b.y - a.y) * u }
  }
  return cles[cles.length - 1]
}

/** Dans la page de l'arbre : l'état du composant, la caméra, la place d'une fiche. */
const OUTILS_ARBRE = () => {
  let inst = document.querySelector('.grille')?.__vueParentComponent
  while (inst && !(inst.setupState && 'cadrage' in inst.setupState)) inst = inst.parent
  window.__H = inst.setupState
  window.__cam = (s, x, y) => {
    const c = window.__H.canevas
    const fitY = Math.min(1, Math.max(0, c.hauteur - 90) / Math.max(1, c.hauteurMonde))
    const sy = Math.max(s, Math.min(fitY, 30 * s))
    window.__H.cadrage = { s, tx: c.largeur / 2 - x * s, ty: c.hauteur / 2 - y * sy }
  }
  window.__fiche = (pid) => { const f = window.__H.disposition.parPersonId.get(pid); return f ? { x: f.x, y: f.y, w: f.w, h: f.h, cx: f.cx, cy: f.y + f.h / 2 } : null }
  window.__monde = () => ({ l: window.__H.disposition.largeur, h: window.__H.disposition.hauteur, c: { ...window.__H.canevas } })
  return true
}
/** Dans une page : le conteneur qui défile, la position d'un titre, le défilement. */
const OUTILS_PAGE = () => {
  window.__conteneur = () => [document.scrollingElement, ...document.querySelectorAll('main, main *')].find((e) => e && e.scrollHeight > e.clientHeight + 20 && (e === document.scrollingElement || /auto|scroll/.test(getComputedStyle(e).overflowY))) ?? document.scrollingElement
  window.__posTexte = (texte, marge = 90) => {
    const c = window.__conteneur()
    const el = [...document.querySelectorAll('h1,h2,h3,h4,p,span,div,a,button')].filter((e) => e.textContent.trim().startsWith(texte)).sort((a, b) => a.textContent.length - b.textContent.length)[0]
    if (!el) return null
    const haut = c === document.scrollingElement ? 0 : c.getBoundingClientRect().top
    return Math.max(0, el.getBoundingClientRect().top - haut + c.scrollTop - marge)
  }
  window.__defiler = (y) => { const c = window.__conteneur(); c.scrollTop = y }
  return true
}
/** Le rectangle (pixels CSS) du plus petit élément visible qui contient ce texte. */
const RECT_TEXTE = (texte, dans) => {
  const racine = dans ? document.querySelector(dans) : document.body
  if (!racine) return null
  let mieux = null
  const tous = racine.querySelectorAll('*')
  for (const el of tous) {
    if (!el.textContent || !el.textContent.includes(texte)) continue
    const r = el.getBoundingClientRect()
    if (r.width === 0 || r.height === 0) continue
    if (!mieux || r.width * r.height < mieux.w * mieux.h) mieux = { x: r.x, y: r.y, w: r.width, h: r.height }
  }
  return mieux
}
const RECT_SEL = (sel) => { const el = document.querySelector(sel); if (!el) return null; const r = el.getBoundingClientRect(); return r.width ? { x: r.x, y: r.y, w: r.width, h: r.height } : null }

/* ------------------------------------------------------------------ les plans */
// Les masques : pendant l'intro, l'arbre est seul (l'interface n'existe pas encore).
const SANS_INTERFACE = `
  header, .histoire > div:first-child, .bandeau-familles, .gouttiere, .grille > div.absolute.right-4, .grille > div.pointer-events-none.rounded-pastille { visibility: hidden !important; }
`
const PLANS = {
  /** INTRO : l'arbre réel, seul, du niveau des fiches jusqu'à l'arbre entier (« plus de 2 300 personnes »). */
  'intro-recul': {
    route: '/arbre', vue: { l: 1920, h: 1080, dsf: 1 }, debut: R.recul1 - 0.6, fin: R.frNoms + 0.6, css: SANS_INTERFACE,
    async preparer(p, ctx) {
      ctx.v = await p.evaluate((id) => window.__fiche(id), V.id)
      ctx.monde = await p.evaluate(() => window.__monde())
    },
    image(t, ctx) {
      const { v, monde } = ctx
      const ens = { s: 0.0205, x: monde.l / 2, y: monde.h / 2 }
      return { camera: camera(t, [
        { t: R.recul1 - 0.6, s: 1.6, x: v.cx, y: v.cy - 30 },
        { t: R.zoomArriere, s: 0.42, x: v.cx, y: (v.cy + monde.h / 2) / 2 },
        { t: R.generations + 0.3, ...ens, courbe: eio },
        { t: R.frNoms + 0.6, ...ens, s: 0.0196 },
      ]) }
    },
    // la place à l'écran des fiches de la famille de Joséphine (pour que les cartes de l'intro s'y posent)
    fiches: FAMILLE_INTRO,
  },

  /** HERITAGE + ARBRE : l'arbre entier se révèle avec l'interface, on le parcourt, on cherche Joséphine, son volet s'ouvre. */
  'arbre': {
    route: '/arbre', vue: { l: 1920, h: 1080, dsf: 1.5 }, debut: R.interface - 1.2, fin: R.source - 0.2,
    async preparer(p, ctx) {
      ctx.v = await p.evaluate((id) => window.__fiche(id), V.id)
      ctx.monde = await p.evaluate(() => window.__monde())
      ctx.ego = await p.evaluate((id) => window.__fiche(id), A.ego.id)
      // la révélation de l'application (les fiches apparaissent depuis l'ego) part au début du plan
      await p.evaluate(() => { window.__H.cadrage = { ...window.__H.cadrage } })
    },
    async gestes(t, p, ctx, une) {
      if (une(R.interface - 1.1)) await p.evaluate(() => window.__H.lancerRevelation())
      if (une(R.recherche)) { await p.click('#recherche-entete input'); }
      // la frappe se termine 0,5 s avant « Entrée » : l'anti-rebond (140 ms) et la réponse ont le temps de passer
      const mot = 'Joséphine Pe'
      for (let i = 0; i < mot.length; i++) if (une(R.recherche + 0.1 + i * 0.06)) await p.keyboard.type(mot[i])
      if (une(R.vol - 0.1)) {
        await p.waitForFunction(() => document.querySelectorAll('[id^="resultat"], [role=option]').length > 0, { timeout: 5000 }).catch(() => console.log('(pas de résultats visibles)'))
        await p.keyboard.press('Enter')
      }
    },
    image(t, ctx) {
      const { v, monde, ego } = ctx
      const ens = { s: 0.0198, x: monde.l / 2, y: monde.h / 2 }
      // on entre dans la branche de Joséphine (côté mère : Dubois), on remonte, puis la recherche nous y emmène
      const familles = { s: 0.12, x: v.cx + 2600, y: ego.cy - 900 }
      const fiches = { s: 0.62, x: v.cx + 900, y: v.cy + 520 }
      const remonte = { s: 0.62, x: v.cx + 700, y: v.cy - 380 }
      if (t >= R.vol - 0.15) {
        // le vol de la recherche, plus posé que celui de l'application (1,1 s au lieu de 0,52 s)
        return { camera: camera(t, [{ t: R.vol - 0.15, ...remonte }, { t: R.vol + 0.95, s: 1.0, x: v.cx + 170, y: v.cy + 40 }, { t: R.source, s: 1.08, x: v.cx + 200, y: v.cy + 40 }]), arreterVol: true }
      }
      return { camera: camera(t, [
        { t: R.interface - 1.2, ...ens }, { t: R.parcourir - 0.4, ...ens, s: 0.0202 },
        { t: R.remonter - 0.2, ...familles }, { t: R.remonter + 0.4, ...fiches, courbe: esin },
        { t: R.recherche + 0.2, ...remonte }, { t: R.vol - 0.15, ...remonte, s: 0.6 },
      ]) }
    },
    reperes: { volet: ['sel', 'aside'], parents: ['texte', 'Mathurin Perrin', 'aside'], mere: ['texte', 'Perrine Le Gall', 'aside'], enfants: ['texte', 'Leurs enfants', 'aside'], enfant1: ['texte', 'Marcel Dubois', 'aside'], lieux: ['texte', 'Rue des Tanneurs', 'aside'], recherche: ['sel', '#recherche-entete input'] },
    fiches: [V.id],
  },

  /** SOURCE : la fiche complète, la date de naissance, l'onglet Sources, l'acte. */
  'fiche': {
    route: `/personnes/${V.id}`, vue: { l: 1280, h: 720, dsf: 1.5 }, debut: R.source - 0.6, fin: R.pasJuste + 1.2,
    async gestes(t, p, ctx, une) {
      if (une(R.sourceLien - 0.2)) await p.evaluate(() => [...document.querySelectorAll('a,button,[role=tab]')].find((e) => /Sources et documents/.test(e.textContent))?.click())
      if (une(R.acte + 0.35)) {
        await p.evaluate(() => {
          const img = [...document.querySelectorAll('img')].find((i) => /\/api\/medias\/120\//.test(i.src))
          ;(img?.closest('button,a') ?? img)?.click()
        })
      }
    },
    image(t, ctx) {
      // dans l'onglet Sources : on descend jusqu'aux citations (« tenu par un acte »)
      if (t < R.sourceLien + 0.25) return { defilement: 0 }
      ctx.citation ??= null
      return { defilement: 'citation' }
    },
    reperes: { naissance: ['texte', '12 mars 1868'], verifie: ['texte', 'Vérifié sur un acte'], onglet: ['texte', 'Sources et documents'], citation: ['texte', 'tenu par un acte'], acte: ['sel', 'img[src*="/api/medias/120/"]'], loupe: ['sel', '[role=dialog] img'] },
  },

  /** CHRONOLOGIE : les vies de la lignée directe ; on descend lentement dans le temps. */
  'chronologie': {
    route: '/explorer/chronologie', vue: { l: 1280, h: 720, dsf: 1.5 }, debut: R.pasJuste + 1.0, fin: R.carte + 0.6,
    image(t) { return { defilement: 520 * esin(prog(t, R.temps - 1.2, R.carte + 0.6)) } },
  },
  /** CARTE : de la France entière jusqu'au pays d'origine de la famille (Josselin, Ploërmel). */
  'carte': {
    route: '/explorer/carte', vue: { l: 1280, h: 720, dsf: 1.5 }, debut: R.carte - 0.6, fin: R.metiers + 0.6,
    async preparer(p) {
      await p.evaluate(() => {
        let i = document.querySelector('.leaflet-container').__vueParentComponent
        while (i && !(i.setupState && i.setupState.carte)) i = i.parent
        const carte = i.setupState.carte
        carte.options.zoomSnap = 0
        window.__carte = ({ lat, lon, z }) => carte.setView([lat, lon], z, { animate: false })
      })
    },
    image(t) {
      const k = eio(prog(t, R.carte - 0.4, R.metiers + 0.4))
      return { carte: { lat: 46.9 + (47.8 - 46.9) * k, lon: 1.2 + (-2.2 - 1.2) * k, z: 5.4 + (8.2 - 5.4) * k } }
    },
  },
  /**
   * STATISTIQUES : trois cadrages de la page, coupés sur la voix (métiers, prénoms transmis,
   * records) — la page les range dans l'autre ordre, la composition fond les coupes.
   */
  'stats': {
    route: '/explorer/stats', vue: { l: 1280, h: 720, dsf: 1.5 }, debut: R.metiers - 0.6, fin: R.participer + 0.4,
    async preparer(p, ctx) {
      ctx.y = await p.evaluate(() => ({ metiers: window.__posTexte('Les métiers, siècle par siècle', 60), prenoms: window.__posTexte('Les prénoms les plus donnés', 60), records: window.__posTexte('Records', 40) }))
    },
    image(t, ctx) {
      const { y } = ctx
      // dans chaque cadrage, la page glisse à peine (40 px) : l'image vit sans qu'on « fasse défiler »
      const plan = t < R.prenoms - 0.25 ? [y.metiers, R.metiers - 0.6, R.prenoms - 0.25] : t < R.stats - 0.25 ? [y.prenoms, R.prenoms - 0.25, R.stats - 0.25] : [y.records, R.stats - 0.25, R.participer + 0.4]
      return { defilement: plan[0] + 40 * prog(t, plan[1], plan[2]) }
    },
    coupes: [R.prenoms - 0.25, R.stats - 0.25],
  },

  /** PARTICIPATION : un souvenir écrit, la photo d'un acte, un enregistrement, puis « Analyser » et les propositions. */
  'assistant': {
    route: '/personnes/proposer', vue: { l: 1280, h: 720, dsf: 1.5 }, debut: R.participer - 0.6, fin: R.questions + 0.4,
    async gestes(t, p, ctx, une) {
      const souvenir = 'Mamie Jeanne racontait que sa grand-mère Joséphine était couturière, rue des Tanneurs à Josselin.'
      if (une(R.souvenir - 0.2)) await p.click('textarea')
      for (let i = 0; i < souvenir.length; i++) if (une(R.souvenir + i * 0.022)) await p.keyboard.type(souvenir[i])
      if (une(R.photoActe + 0.3)) {
        const champ = await p.$('input[type=file]')
        await champ.uploadFile(path.join(ici, '..', 'assets', 'documents', 'acte-josselin-1868.jpg'))
      }
      if (une(R.enregistrer + 0.6)) {
        const champ = await p.$('input[type=file]')
        await champ.uploadFile(path.join(ici, '..', 'narration', 'souvenir-jeanne.m4a'))
      }
      if (une(R.transforme - 0.1)) await p.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Analyser')?.click())
    },
    async apres(t, p, ctx) {
      // dès que les propositions sont là, la page descend vers elles (en 0,9 s)
      if (t < R.transforme) return
      ctx.yProp ??= await p.evaluate(() => window.__posTexte('Ce que l’assistant a compris', 30))
      if (ctx.yProp == null) return
      ctx.tProp ??= t
      await p.evaluate((d) => window.__defiler(d), ctx.yProp * eio(prog(t, ctx.tProp + 0.4, ctx.tProp + 1.3)))
    },
    reperes: { texte: ['sel', 'textarea'], documents: ['texte', 'Importer une photo ou un fichier'], enregistrer: ['texte', 'Enregistrer'], compris: ['texte', 'Ce que l’assistant a compris'], confiance: ['texte', 'Confiance haute'], carte1: ['texte', 'Ajouter le métier de'] },
  },

  /** IA : la question de suivi, tapée mot pour mot, puis la réponse et ses liens vers les fiches. */
  'question': {
    route: '/personnes/proposer?mode=question', vue: { l: 1280, h: 720, dsf: 1.5 }, debut: R.questions - 0.6, fin: R.navigation + 0.3,
    async preparer(p) {
      await fetch(APP + '/api/__reinitialiser', { method: 'POST' })
      await p.evaluate(() => [...document.querySelectorAll('button,a')].find((b) => /Que sait-on de/.test(b.textContent))?.click())
    },
    async gestes(t, p, ctx, une) {
      const q = 'Qui étaient les parents de cette personne ?'
      if (une(R.frappe - 0.3)) await p.click('textarea')
      for (let i = 0; i < q.length; i++) if (une(R.frappe + i * 0.045)) await p.keyboard.type(q[i])
      if (une(R.reponse - 0.9)) await p.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Envoyer')?.click())
    },
    reperes: { question: ['sel', 'textarea'], reponse: ['texte', 'Ses parents sont'], lienPere: ['texte', 'Mathurin Perrin', 'p'], lienMere: ['texte', 'Perrine Le Gall', 'p'] },
  },

  /** FIN : les parents de Joséphine dans l'arbre, puis le grand recul jusqu'à l'arbre entier. */
  'arbre-fin': {
    route: '/arbre', vue: { l: 1920, h: 1080, dsf: 1 }, debut: R.navigation - 0.5, fin: T.DUREE,
    async preparer(p, ctx) {
      ctx.pere = await p.evaluate((id) => window.__fiche(id), PERE_V.id)
      ctx.mere = await p.evaluate((id) => window.__fiche(id), MERE_V.id)
      ctx.v = await p.evaluate((id) => window.__fiche(id), V.id)
      ctx.monde = await p.evaluate(() => window.__monde())
      await p.evaluate((id) => { window.__H.selection = id }, `p${PERE_V.id}`)
    },
    fiches: [PERE_V.id, MERE_V.id],
    reperes: { volet: ['sel', 'aside'] },
    async gestes(t, p, ctx, une) {
      if (une(R.reculFinal - 0.4)) await p.evaluate(() => { window.__H.selection = null })
    },
    image(t, ctx) {
      const { pere, mere, v, monde } = ctx
      const couple = { s: 1.05, x: (pere.cx + mere.cx) / 2, y: (pere.cy + v.cy) / 2 }
      const ens = { s: 0.0198, x: monde.l / 2, y: monde.h / 2 }
      return { camera: camera(t, [
        { t: R.navigation - 0.5, s: 0.55, x: v.cx, y: v.cy },
        { t: R.navigation + 0.6, ...couple },
        { t: R.reculFinal - 0.2, ...couple, s: 0.95 },
        { t: R.epure - 0.5, s: 0.06, x: (couple.x * 0.4 + ens.x * 0.6), y: ens.y },
        { t: R.signature, ...ens },
        { t: T.DUREE, ...ens, s: 0.0192 },
      ], esin) }
    },
  },
}

// Les variantes sans interface des deux plans de l'arbre : la composition fait apparaître (puis
// disparaître) l'interface réelle région par région, en superposant les deux prises.
PLANS['arbre-nu'] = { ...PLANS['arbre'], fin: R.interface + 2.6, css: SANS_INTERFACE, reperes: {}, fiches: undefined }
PLANS['arbre-fin-nu'] = { ...PLANS['arbre-fin'], debut: R.epure - 0.6, css: SANS_INTERFACE, reperes: {}, fiches: undefined }

/* ------------------------------------------------------------------ l'enregistreur */
const navigateur = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--force-color-profile=srgb', '--font-render-hinting=none', '--hide-scrollbars'] })

async function filmer(nom, plan) {
  const dossier = path.join(SORTIE, nom)
  fs.rmSync(dossier, { recursive: true, force: true }); fs.mkdirSync(dossier, { recursive: true })
  const p = await navigateur.newPage()
  p.on('pageerror', (e) => console.log(`[${nom}]`, e.message))
  await p.setViewport({ width: plan.vue.l, height: plan.vue.h, deviceScaleFactor: plan.vue.dsf })
  await p.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }, { name: 'prefers-reduced-motion', value: 'no-preference' }])
  await p.evaluateOnNewDocument(() => {
    localStorage.setItem('heritage.cles-d-acces.invitation.plus-tard', JSON.stringify({ 1: Date.now() }))
    localStorage.setItem('heritage.installation.plus-tard', '1')
    localStorage.setItem('heritage.arbre.formatDesCartes', 'compact')
    localStorage.setItem('heritage.portrait.rappel.1', '1')
  })
  await p.evaluateOnNewDocument(HORLOGE)
  await p.goto(APP + plan.route, { waitUntil: 'networkidle0', timeout: 90000 })
  // pas de barre de défilement, pas de curseur de texte qui clignote au hasard
  await p.addStyleTag({ content: `::-webkit-scrollbar{display:none} * { caret-color: #d9a066; } ${plan.css ?? ''}` })
  await new Promise((r) => setTimeout(r, 1200))
  const arbre = plan.route.startsWith('/arbre')
  if (arbre) await p.evaluate(OUTILS_ARBRE)
  else await p.evaluate(OUTILS_PAGE)
  const ctx = {}
  if (plan.preparer) await plan.preparer(p, ctx)
  await new Promise((r) => setTimeout(r, 600))
  await p.evaluate(() => window.__figer())

  const n = Math.round((plan.fin - plan.debut) * FPS)
  const faits = new Set()
  const meta = { nom, route: plan.route, coupes: plan.coupes ?? [], debut: plan.debut, fin: plan.fin, fps: FPS, largeur: plan.vue.l * plan.vue.dsf, hauteur: plan.vue.h * plan.vue.dsf, dsf: plan.vue.dsf, images: [] }
  const t0 = Date.now()
  for (let i = 0; i < n; i++) {
    const t = plan.debut + i / FPS
    // `une(instant)` : vrai une seule fois, à la première image qui atteint cet instant
    const une = (instant) => { const k = String(instant); if (t >= instant && !faits.has(k)) { faits.add(k); return true } return false }
    if (plan.gestes) await plan.gestes(t, p, ctx, une)
    const etat = plan.image ? plan.image(t, ctx) : {}
    if (etat.camera) await p.evaluate((c, stop) => { if (stop) window.__H.arreterVol(); window.__cam(c.s, c.x, c.y) }, etat.camera, !!etat.arreterVol)
    if (etat.defilement === 'citation') {
      // glisse vers la première citation, en 0,6 s
      ctx.citationY ??= await p.evaluate(() => window.__posTexte('Preuves et documents', 30) ?? window.__posTexte('tenu par un acte', 200))
      ctx.citationT0 ??= t
      const y = (ctx.citationY ?? 0) * eio(prog(t, ctx.citationT0, ctx.citationT0 + 0.7))
      await p.evaluate((d) => window.__defiler(d), y)
    } else if (etat.defilement !== undefined) await p.evaluate((d) => window.__defiler(d), etat.defilement)
    if (etat.carte) await p.evaluate((c) => window.__carte(c), etat.carte)
    if (plan.apres) await plan.apres(t, p, ctx)
    await p.evaluate((ms) => window.__avancer(ms), 1000 / FPS)
    const rects = {}
    for (const [cle, [genre, valeur, dans]] of Object.entries(plan.reperes ?? {})) {
      const r = await p.evaluate(genre === 'texte' ? RECT_TEXTE : RECT_SEL, valeur, dans)
      if (r) rects[cle] = [r.x, r.y, r.w, r.h].map((v) => Math.round(v * plan.vue.dsf))
    }
    // la place à l'écran de fiches de l'arbre, d'après la caméra courante
    if (plan.fiches) {
      const pos = await p.evaluate((ids) => {
        const c = window.__H.cadrage, sy = window.__H.sy, zone = document.querySelector('.grille').getBoundingClientRect()
        const res = {}
        for (const id of ids) { const f = window.__fiche(id); if (f) res[id] = [zone.x + c.tx + f.x * c.s, zone.y + c.ty + f.y * sy, f.w * c.s, f.h * sy] }
        return res
      }, plan.fiches)
      for (const [id, r] of Object.entries(pos)) rects['p' + id] = r.map((v) => Math.round(v * plan.vue.dsf * 10) / 10)
    }
    meta.images.push(rects)
    await p.screenshot({ path: path.join(dossier, `${String(i).padStart(5, '0')}.jpg`), type: 'jpeg', quality: 90, optimizeForSpeed: true })
    if (i % 60 === 0) process.stdout.write(`\r${nom} : ${i}/${n} images (${((Date.now() - t0) / 1000).toFixed(0)} s)   `)
  }
  fs.writeFileSync(path.join(SORTIE, `${nom}.json`), JSON.stringify(meta))
  console.log(`\r${nom} : ${n} images en ${((Date.now() - t0) / 1000).toFixed(0)} s                `)
  await p.close()
}

const voulus = process.argv.slice(2)
for (const [nom, plan] of Object.entries(PLANS)) if (!voulus.length || voulus.includes(nom)) await filmer(nom, plan)
await navigateur.close()
const index = {}
for (const f of fs.readdirSync(SORTIE).filter((f) => f.endsWith('.json'))) index[f.slice(0, -5)] = JSON.parse(fs.readFileSync(path.join(SORTIE, f), 'utf8'))
fs.writeFileSync(path.join(SORTIE, 'index.js'), 'window.RUSHES = ' + JSON.stringify(index) + '\n')
console.log('index des rushes :', Object.keys(index).join(', '))
