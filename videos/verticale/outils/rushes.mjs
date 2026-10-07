// Les rushes : la VRAIE application (app/), en version smartphone, filmée image par image
// en 1080 × 1920 (viewport 405 × 720 à ×2,667), sur la famille fictive.
//
//   sh outils/serveurs.sh                 (fausse API :8000 + vrai front :5173)
//   node outils/rushes.mjs                → tous les plans
//   node outils/rushes.mjs arbre question → seulement ceux-là
//   FPS_RUSH=15 node outils/rushes.mjs    → plus vite, pour un essai
//
// Chaque plan ouvre une page, attend qu'elle soit prête, puis fige son horloge
// (outils/horloge-virtuelle.js) : chaque image avance d'exactement 1/FPS s, et les vraies
// animations de l'application (vol de caméra, feuillets, apparitions) passent à leur vraie
// vitesse. Les gestes sont placés sur la timeline (timeline.js) en secondes de la VIDÉO.
//
// Sortie : sortie/rushes/<plan>/00000.jpg… , sortie/rushes/<plan>.json (rectangles des
// éléments à mettre en valeur, en pixels du rush) et sortie/rushes/index.js.
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
const MOBILE = { l: 405, h: 720, dsf: 1080 / 405 }
const ASSETS = path.join(ici, '..', 'assets')

const V = A.vedette, [PERE_V, MERE_V] = A.parentsVedette, JEAN = A.jean

/* ------------------------------------------------------------------ outils */
const clamp = (v, a = 0, b = 1) => Math.max(a, Math.min(b, v))
const prog = (t, a, b) => clamp((t - a) / (b - a))
const eio = (k) => (k < 0.5 ? 4 * k ** 3 : 1 - Math.pow(-2 * k + 2, 3) / 2)
const esin = (k) => -(Math.cos(Math.PI * k) - 1) / 2
/** Une trajectoire de caméra de l'arbre {t, s, x, y} : zoom en logarithme, le point visé reste sous les yeux. */
function camera(t, cles, courbe = esin) {
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
/** Une valeur qui glisse d'une clé à l'autre : [[t, v], …]. */
function glisse(t, cles, courbe = eio) {
  let v = cles[0][1]
  for (let i = 1; i < cles.length; i++) if (t > cles[i - 1][0]) v = cles[i - 1][1] + (cles[i][1] - cles[i - 1][1]) * courbe(prog(t, cles[i - 1][0], cles[i][0]))
  return v
}

/** Dans la page de l'arbre : l'état du composant, la caméra, la place d'une fiche. */
const OUTILS_ARBRE = () => {
  let inst = document.querySelector('.grille')?.__vueParentComponent
  while (inst && !(inst.setupState && 'cadrage' in inst.setupState)) inst = inst.parent
  if (!inst) return false
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
/** Dans une page : le conteneur qui défile, la position d'un texte, le défilement. */
const OUTILS_PAGE = () => {
  window.__conteneur = () => [document.scrollingElement, ...document.querySelectorAll('main, main *')].find((e) => e && e.scrollHeight > e.clientHeight + 20 && (e === document.scrollingElement || /auto|scroll/.test(getComputedStyle(e).overflowY))) ?? document.scrollingElement
  window.__posTexte = (texte, marge = 90) => {
    const c = window.__conteneur()
    const el = [...document.querySelectorAll('h1,h2,h3,h4,p,span,div,a,button,dt,dd,li')].filter((e) => e.textContent.trim().startsWith(texte) && e.getBoundingClientRect().height > 0).sort((a, b) => a.textContent.length - b.textContent.length)[0]
    if (!el) return null
    const haut = c === document.scrollingElement ? 0 : c.getBoundingClientRect().top
    return Math.max(0, el.getBoundingClientRect().top - haut + c.scrollTop - marge)
  }
  window.__defiler = (y) => { const c = window.__conteneur(); c.scrollTop = y }
  window.__toucher = (texte, sel = 'a,button,[role=tab],img') => {
    const el = [...document.querySelectorAll(sel)].filter((e) => (e.textContent.includes(texte) || e.getAttribute('src')?.includes(texte)) && e.getBoundingClientRect().height > 0).sort((a, b) => a.textContent.length - b.textContent.length)[0]
    ;(el?.closest('button,a') ?? el)?.click()
    return !!el
  }
  return true
}
/** Le rectangle (pixels CSS) du plus petit élément visible qui contient ce texte. */
const RECT_TEXTE = (texte, dans) => {
  const racine = dans ? document.querySelector(dans) : document.body
  if (!racine) return null
  let mieux = null
  for (const el of racine.querySelectorAll('*')) {
    if (!el.textContent || !el.textContent.includes(texte)) continue
    const r = el.getBoundingClientRect()
    if (r.width === 0 || r.height === 0 || r.bottom < 0 || r.top > innerHeight) continue
    if (!mieux || r.width * r.height < mieux.w * mieux.h) mieux = { x: r.x, y: r.y, w: r.width, h: r.height }
  }
  return mieux
}
const RECT_SEL = (sel) => { const el = document.querySelector(sel); if (!el) return null; const r = el.getBoundingClientRect(); return r.width ? { x: r.x, y: r.y, w: r.width, h: r.height } : null }

/* ------------------------------------------------------------------ les plans */
// L'arbre seul, plein écran : l'interface est retirée de la mise en page (pas seulement cachée)
const PLEIN = `
  header, nav.barre-navigation, .histoire > div:first-child, .histoire > div.z-10, .bandeau-familles, .gouttiere,
  .grille > div.pointer-events-none.rounded-pastille { display: none !important; }
  main#contenu { padding-bottom: 0 !important; }
`
const liberer = () => fetch(APP + '/api/__liberer', { method: 'POST' }).catch(() => {})

const PLANS = {
  /** 1. « 2 307 personnes, 14 générations » : très près de Joséphine, puis on recule et on monte dans les générations. */
  'intro-arbre': {
    route: '/arbre', debut: R.recul - 0.6, fin: R.disperse + 1.4, css: PLEIN,
    async preparer(p, ctx) { ctx.v = await p.evaluate((id) => window.__fiche(id), V.id); ctx.m = await p.evaluate(() => window.__monde()) },
    image(t, { v, m }) {
      return { camera: camera(t, [
        { t: R.recul - 0.6, s: 1.75, x: v.cx, y: v.cy },
        { t: R.personnes + 0.3, s: 0.55, x: v.cx, y: v.cy - 520 },
        { t: R.generations + 1.4, s: 0.026, x: v.cx + 4000, y: m.h / 2 },
        { t: R.disperse + 1.4, s: 0.02, x: v.cx + 4500, y: m.h / 2 },
      ]) }
    },
    fiches: [V.id],
  },

  /** 3-5. L'arbre se réunit, on le parcourt, on cherche Joséphine, sa fiche, sa date, sa source, l'acte. */
  'arbre': {
    route: '/arbre', debut: R.reunie - 1.2, fin: R.fige + 0.6,
    async preparer(p, ctx) {
      ctx.v = await p.evaluate((id) => window.__fiche(id), V.id); ctx.m = await p.evaluate(() => window.__monde())
      await p.evaluate(() => { window.__H.cadrage = { ...window.__H.cadrage } })
    },
    async gestes(t, p, ctx, une) {
      if (une(R.reunie - 1.1)) await p.evaluate(() => window.__H.lancerRevelation())
      if (une(R.retrouver - 0.1)) { const c = await p.$('input[placeholder^="Aller"]'); await c?.tap() }
      const mot = 'Joséphine Pe'
      for (let i = 0; i < mot.length; i++) if (une(R.retrouver + 0.1 + i * 0.06)) await p.keyboard.type(mot[i])
      if (une(R.secondes - 0.05)) {
        await p.waitForFunction(() => document.body.innerText.includes('personnes ·') || document.querySelectorAll('[id^="resultat"]').length > 0, { timeout: 5000 }).catch(() => console.log('(résultats non vus)'))
        await p.keyboard.press('Enter')
        ctx.vol = true
      }
      // la fiche complète
      if (une(R.parents - 0.6)) { await p.evaluate(OUTILS_PAGE); const ok = await p.evaluate(() => { const a = [...document.querySelectorAll('a,button')].find((e) => e.textContent.trim() === 'Fiche' && e.getBoundingClientRect().height > 0); a?.click(); return !!a }); if (process.env.TRACE) console.log('fiche touchée :', ok) }
      if (process.env.TRACE && une(R.parents)) console.log('adresse :', await p.evaluate(() => location.pathname))
      if (une(R.parents - 0.2)) { await p.waitForFunction(() => location.pathname.startsWith('/personnes/'), { timeout: 8000 }).catch(() => {}); await p.evaluate(OUTILS_PAGE); ctx.page = true }
      if (une(R.parents - 0.15)) ctx.y = await p.evaluate(() => ({ parents: window.__posTexte('Parents', 140), enfants: window.__posTexte('Leurs enfants', 120), faits: window.__posTexte('Faits', 120), naissance: window.__posTexte('Naissance', 260) }))
      if (une(R.source - 0.05)) await p.evaluate(() => window.__toucher('Sources', 'button,a,[role=tab]'))
      if (une(R.source + 0.45)) ctx.yCit = await p.evaluate(() => window.__posTexte('Sources et notes', 40) ?? window.__posTexte('tenu par un acte', 200))
      if (une(R.verifiee - 0.35)) await p.evaluate(() => { const img = [...document.querySelectorAll('img')].find((i) => /\/api\/medias\/120\//.test(i.src) && i.getBoundingClientRect().height > 0); (img?.closest('button,a') ?? img)?.click() })
    },
    image(t, ctx) {
      const { v, m } = ctx
      if (ctx.page) {
        const y = ctx.y ?? {}
        // la fiche défile au rythme de la voix : parents, enfants, lieux… puis la date de naissance
        const d = glisse(t, [[R.parents - 0.15, 0], [R.parents + 0.25, y.parents ?? 0], [R.enfants - 0.1, y.parents ?? 0], [R.enfants + 0.3, y.enfants ?? 0], [R.lieux - 0.1, y.enfants ?? 0], [R.lieux + 0.35, y.faits ?? 0], [R.document - 0.5, y.faits ?? 0], [R.document + 0.2, y.naissance ?? 0], [R.source - 0.1, y.naissance ?? 0], [R.source, 0]])
        if (t < R.source) return { defilement: d }
        if (ctx.yCit != null) return { defilement: (ctx.yCit ?? 0) * eio(prog(t, R.source + 0.45, R.source + 1.05)) }
        return {}
      }
      if (ctx.vol) return {} // la recherche : c'est l'application qui vole jusqu'à Joséphine
      return { camera: camera(t, [
        { t: R.reunie - 1.2, s: 0.0105, x: m.l * 0.52, y: m.h / 2 },
        { t: R.parcourir - 0.2, s: 0.0115, x: m.l * 0.52, y: m.h / 2 },
        // « parcourir » : on glisse en diagonale vers la branche de Joséphine
        { t: R.zoomer - 0.15, s: 0.05, x: v.cx + 9000, y: m.h * 0.4 },
        // « zoomer dedans » : un zoom centré, jusqu'aux fiches
        { t: R.retrouver - 0.15, s: 0.62, x: v.cx + 2600, y: v.cy - 200 },
        { t: R.secondes, s: 0.64, x: v.cx + 2500, y: v.cy - 220 },
      ]) }
    },
    reperes: {
      recherche: ['sel', 'input[placeholder^="Aller"]'], feuillet: ['texte', 'arrière-arrière-grand-mère'], fiche: ['texte', 'Fiche'],
      parents: ['texte', 'Mathurin Perrin'], enfants: ['texte', 'Leurs enfants'], lieux: ['texte', 'Rue des Tanneurs'],
      naissance: ['texte', '12 mars 1868'], verifie: ['texte', 'Vérifié'], onglet: ['texte', 'Sources ·'], citation: ['texte', 'tenu par un acte'],
      vignette: ['sel', 'img[src*="/api/medias/120/"]'], loupe: ['sel', '[role=dialog] img'],
    },
    fiches: [V.id],
  },

  /** 6. « que l'arbre ne soit pas quelque chose de figé » : on descend doucement vers les générations d'aujourd'hui. */
  'arbre-vivant': {
    route: '/arbre', debut: R.fige - 0.5, fin: R.enrichir + 0.8, css: PLEIN,
    async preparer(p, ctx) { ctx.e = await p.evaluate((id) => window.__fiche(id), A.ego.id) },
    image(t, { e }) { return { camera: camera(t, [{ t: R.fige - 0.5, s: 0.7, x: e.cx - 300, y: e.cy - 700 }, { t: R.enrichir + 0.8, s: 0.78, x: e.cx - 150, y: e.cy - 120 }]) } },
  },

  /** 6. La famille enrichit : un souvenir, une vieille photo, un acte photographié, un enregistrement ; l'analyse. */
  'assistant': {
    route: '/personnes/proposer', debut: R.souvenir - 0.9, fin: R.relit + 0.2,
    async gestes(t, p, ctx, une) {
      const souvenir = 'Mon arrière-grand-père s’appelait Jean Martin. Il est né en 1903 à Mauron, il était menuisier, comme son père.'
      if (une(R.souvenir - 0.3)) await (await p.$('textarea'))?.tap()
      for (let i = 0; i < souvenir.length; i++) if (une(R.souvenir - 0.15 + i * 0.016)) await p.keyboard.type(souvenir[i])
      const deposer = async (f) => { const c = await p.$('input[type=file]'); await c?.uploadFile(f) }
      if (une(R.photo + 0.35)) await deposer(path.join(ASSETS, 'photos', 'dos-1.jpg'))
      if (une(R.acte + 0.75)) await deposer(path.join(ASSETS, 'documents', 'acte-mauron-1903.jpg'))
      if (une(R.enregistrer + 1.0)) await deposer(path.join(ici, '..', 'narration', 'souvenir-jeanne.m4a'))
      if (une(R.transforme - 0.1)) { await p.evaluate(() => window.__defiler(9999)); await p.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Analyser')?.click()) }
      if (une(R.aVerifier - 0.35)) { await liberer(); await p.waitForFunction(() => document.body.innerText.includes('demandes proposées'), { timeout: 8000 }).catch(() => console.log('(pas de propositions)')) }
      if (une(R.aVerifier - 0.3)) ctx.yProp = await p.evaluate(() => window.__posTexte('3 demandes proposées', 40))
    },
    image(t, ctx) {
      // avant l'analyse : la page suit ce qu'on ajoute ; après : les demandes proposées
      if (ctx.yProp != null) return { defilement: ctx.yProp * eio(prog(t, R.aVerifier - 0.3, R.aVerifier + 0.5)) + 160 * eio(prog(t, R.relit, R.valide + 0.3)) }
      if (t > R.photo) return { defilement: glisse(t, [[R.photo, 0], [R.photo + 0.6, 220], [R.transforme - 0.3, 260]]) }
      return {}
    },
    reperes: { texte: ['sel', 'textarea'], documents: ['texte', 'Importer une photo'], enregistrer: ['texte', 'Enregistrer'], analyse: ['texte', 'L’assistant'], compris: ['texte', 'Ce que l’assistant a compris'], confiance: ['texte', 'Confiance haute'], carte1: ['texte', 'Préciser la naissance'], changement: ['texte', '14 mai 1903'], deposer: ['texte', 'Déposer'] },
  },


  /** 6. « La famille peut continuer à l'enrichir » : l'accueil, et le bouton « Ajouter une personne ». */
  'accueil': {
    route: '/home', debut: R.enrichir - 0.6, fin: R.souvenir - 0.5,
    async gestes(t, p, ctx, une) { if (une(R.enrichir + 0.75)) await p.evaluate(() => window.__toucher('Ajouter une personne', 'a,button')) },
    reperes: { ajouter: ['texte', 'Ajouter une personne'], question: ['texte', 'Poser une question sur l’arbre'] },
  },

  /** 6. « la famille relit, corrige, valide » : la proposition, dans le formulaire « Ajouter une personne ». */
  'formulaire': {
    route: '/personnes/proposer?mode=formulaire', debut: R.relit - 0.7, fin: R.grandir + 0.3,
    async preparer(p, ctx) {
      // la proposition préparée par l'assistant (Marie Martin, la sœur de Jean), telle que la famille la relit
      const champ = async (label) => (await p.evaluateHandle((l) => { const lab = [...document.querySelectorAll('label, span, div, p')].find((e) => e.textContent.trim() === l && e.children.length === 0); return lab?.closest('label')?.querySelector('input,select') ?? lab?.parentElement?.querySelector('input,select') ?? lab?.nextElementSibling }, label)).asElement()
      await (await champ('Prénoms'))?.type('Marie'); await (await champ('Nom'))?.type('Martin')
      await p.evaluate(() => {
        const choisir = (s, re) => { s.value = [...s.options].find((o) => re.test(o.text)).value; s.dispatchEvent(new Event('change', { bubbles: true })) }
        const [sexe, nature] = document.querySelectorAll('select'); choisir(sexe, /Fém/); choisir(nature, /^Vers/)
      })
      await new Promise((r) => setTimeout(r, 300))
      const annee = await p.$('input[placeholder^="1901"]'); await annee?.type('1906')
      const lieu = (await p.$$('input[placeholder^="Commune"]'))[0]; await lieu?.type('Mauron')
      const parent = await p.$('input[placeholder^="Nom ou prénom"]'); await parent?.type('Pierre Martin', { delay: 30 })
      await new Promise((r) => setTimeout(r, 1500))
      await p.evaluate(() => { const o = [...document.querySelectorAll('[role=option], li button, ul li')].find((e) => /Pierre Martin/.test(e.textContent)); o?.click() })
      await new Promise((r) => setTimeout(r, 500))
      await p.evaluate(() => document.activeElement?.blur())
      ctx.y = await p.evaluate(() => ({ identite: 0, naissance: window.__posTexte('Naissance', 120), parents: window.__posTexte('Parents', 160), envoyer: window.__posTexte('Envoyer la proposition', 380) }))
    },
    async gestes(t, p, ctx, une) {
      // « corrige » : on précise le lieu de naissance
      if (une(R.corrige - 0.1)) { const lieu = (await p.$$('input[placeholder^="Commune"]'))[0]; await lieu?.tap(); await p.keyboard.press('End') }
      const ajout = ', Morbihan'
      for (let i = 0; i < ajout.length; i++) if (une(R.corrige + 0.05 + i * 0.035)) await p.keyboard.type(ajout[i])
      if (une(R.valide - 0.15)) await p.evaluate(() => document.activeElement?.blur())
    },
    image(t, { y }) {
      return { defilement: glisse(t, [[R.relit - 0.7, 0], [R.relit + 0.3, (y.naissance ?? 0) - 160], [R.corrige - 0.25, y.naissance ?? 0], [R.valide - 0.2, y.naissance ?? 0], [R.valide + 0.35, y.envoyer ?? 0]]) }
    },
    reperes: { prenoms: ['texte', 'Prénoms'], lieu: ['sel', 'input[placeholder^="Commune"]'], parent: ['texte', 'Pierre Martin'], envoyer: ['texte', 'Envoyer la proposition'], titre: ['texte', 'Ajouter une personne'] },
  },

  /** 6. « et l'arbre continue de grandir » : autour de Jean Martin. */
  'arbre-grandit': {
    route: '/arbre', debut: R.grandir - 0.5, fin: R.questions + 0.5, css: PLEIN,
    async preparer(p, ctx) { ctx.j = await p.evaluate((id) => window.__fiche(id), JEAN.id) },
    image(t, { j }) { return { camera: camera(t, [{ t: R.grandir - 0.5, s: 1.15, x: j.cx + 120, y: j.cy + 60 }, { t: R.questions + 0.5, s: 1.0, x: j.cx + 140, y: j.cy + 20 }]) } },
    fiches: [JEAN.id, ...A.enfantsDe(JEAN).map((e) => e.id), ...A.conjointsDe(JEAN).map((e) => e.id)],
  },

  /** 7. La question de suivi, tapée mot pour mot ; l'assistant cherche ; la réponse et ses liens. */
  'question': {
    route: '/personnes/proposer?mode=question', debut: R.questions - 0.5, fin: R.reponse + 1.0,
    async preparer(p) {
      await fetch(APP + '/api/__reinitialiser', { method: 'POST' })
      // la conversation sur Joséphine est déjà là : on l'ouvre (en mobile, la liste est repliée derrière un bouton)
      for (let essai = 0; essai < 4; essai++) {
        if (await p.evaluate(() => document.body.innerText.includes('est née le 12 mars 1868'))) break
        await p.evaluate(() => [...document.querySelectorAll('button')].find((b) => /Que sait-on de Joséphine/.test(b.textContent))?.click())
        await new Promise((r) => setTimeout(r, 900))
      }
      if (!(await p.evaluate(() => document.body.innerText.includes('est née le 12 mars 1868')))) console.log('(conversation non ouverte)')
    },
    async gestes(t, p, ctx, une) {
      const q = 'Qui étaient les parents de cette personne ?'
      if (une(R.question - 0.35)) await (await p.$('textarea'))?.tap()
      for (let i = 0; i < q.length; i++) if (une(R.question - 0.2 + i * 0.026)) await p.keyboard.type(q[i])
      if (une(R.question + 1.05)) await p.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Envoyer')?.click())
      if (une(R.reponse - 0.25)) { await liberer(); await p.waitForFunction(() => document.body.innerText.includes('Ses parents sont'), { timeout: 8000 }).catch(() => console.log('(pas de réponse)')) }
      if (une(R.reponse - 0.2)) ctx.yRep = await p.evaluate(() => window.__posTexte('Qui étaient les parents de cette personne', 60))
    },
    image(t, ctx) { return ctx.yRep != null ? { defilement: ctx.yRep * eio(prog(t, R.reponse - 0.2, R.reponse + 0.4)) } : { defilement: 9999 } },
    reperes: { question: ['sel', 'textarea'], reponse: ['texte', 'Ses parents sont'], lienPere: ['texte', 'Mathurin Perrin', 'p'], lienMere: ['texte', 'Perrine Le Gall', 'p'], cherche: ['texte', 'cherche dans l’arbre'] },
  },

  /** 7. « directement dans l'arbre » : on vole jusqu'à Joséphine ; ses parents au-dessus d'elle. */
  'arbre-parents': {
    route: '/arbre', debut: R.reponse + 0.6, fin: R.carte + 0.4,
    async preparer(p, ctx) { ctx.v = await p.evaluate((id) => window.__fiche(id), V.id); ctx.pe = await p.evaluate((id) => window.__fiche(id), PERE_V.id); ctx.m = await p.evaluate(() => window.__monde()) },
    image(t, { v, pe, m }) {
      const yC = (v.cy + pe.cy) / 2
      return { camera: camera(t, [
        { t: R.reponse + 0.6, s: 0.02, x: v.cx + 6000, y: m.h / 2 },
        { t: R.dansLArbre + 0.5, s: 1.05, x: v.cx + 60, y: yC, courbe: eio },
        { t: R.carte + 0.4, s: 1.1, x: v.cx + 60, y: yC },
      ]) }
    },
    fiches: [V.id, PERE_V.id, MERE_V.id],
  },

  /** 8. Les lieux : de la France entière jusqu'au pays d'origine. */
  'carte': {
    route: '/explorer/carte', debut: R.explorer - 0.5, fin: R.metiers + 0.3,
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
      const k = eio(prog(t, R.carte - 0.5, R.metiers + 0.3))
      return { carte: { lat: 47.0 + (47.85 - 47.0) * k, lon: 0.5 + (-2.35 - 0.5) * k, z: 5.0 + (8.4 - 5.0) * k } }
    },
  },
  /** 8. Les métiers, puis les prénoms transmis (deux cadrages de la page Stats). */
  'stats': {
    route: '/explorer/stats', debut: R.metiers - 0.4, fin: R.histoires + 0.3,
    async preparer(p, ctx) { ctx.y = await p.evaluate(() => ({ metiers: window.__posTexte('Les métiers, siècle par siècle', 20), prenoms: window.__posTexte('Prénoms transmis', 20) })) },
    image(t, { y }) {
      const [y0, a, b] = t < R.prenoms - 0.2 ? [y.metiers, R.metiers - 0.4, R.prenoms - 0.2] : [y.prenoms, R.prenoms - 0.2, R.histoires + 0.3]
      return { defilement: (y0 ?? 0) + 60 * prog(t, a, b) }
    },
    coupes: [R.prenoms - 0.2],
  },
  /** 8. « les histoires derrière les chiffres » : les vies de la lignée, dans le temps. */
  'chronologie': {
    route: '/explorer/chronologie', debut: R.histoires - 0.4, fin: R.retour + 0.6,
    image(t) { return { defilement: 380 * esin(prog(t, R.histoires - 0.4, R.retour + 0.6)) } },
  },

  /** 9. Le retour à l'arbre complet : de Joséphine à des milliers de fiches. */
  'arbre-fin': {
    route: '/arbre', debut: R.retour - 0.4, fin: R.pasJuste + 1.0,
    async preparer(p, ctx) { ctx.v = await p.evaluate((id) => window.__fiche(id), V.id); ctx.m = await p.evaluate(() => window.__monde()) },
    image(t, { v, m }) {
      return { camera: camera(t, [
        { t: R.retour - 0.4, s: 1.0, x: v.cx, y: v.cy },
        { t: R.pasJuste + 1.0, s: 0.03, x: v.cx + 3000, y: m.h / 2 },
      ], eio) }
    },
  },
  /** 9. Il ne reste que l'arbre, plein écran ; le mouvement ralentit, puis s'immobilise. */
  'arbre-fin-plein': {
    route: '/arbre', debut: R.auFond - 0.6, fin: T.DUREE, css: PLEIN,
    async preparer(p, ctx) { ctx.v = await p.evaluate((id) => window.__fiche(id), V.id); ctx.m = await p.evaluate(() => window.__monde()) },
    image(t, { v, m }) {
      return { camera: camera(t, [
        { t: R.auFond - 0.6, s: 0.05, x: v.cx + 2500, y: m.h / 2 },
        { t: R.trace, s: 0.034, x: v.cx + 3000, y: m.h / 2 },
        { t: R.immobile, s: 0.031, x: v.cx + 3150, y: m.h / 2 },
        { t: T.DUREE, s: 0.031, x: v.cx + 3150, y: m.h / 2 },
      ]) }
    },
    fiches: [V.id, PERE_V.id, JEAN.id, A.ego.id, ...A.enfantsDe(A.ego).map((e) => e.id), A.sosa.get(6)?.id, A.sosa.get(13)?.id].filter(Boolean),
  },
}

/* ------------------------------------------------------------------ l'enregistreur */
const navigateur = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--force-color-profile=srgb', '--font-render-hinting=none', '--hide-scrollbars'] })

async function filmer(nom, plan) {
  const dossier = path.join(SORTIE, nom)
  fs.rmSync(dossier, { recursive: true, force: true }); fs.mkdirSync(dossier, { recursive: true })
  const p = await navigateur.newPage()
  p.on('pageerror', (e) => console.log(`[${nom}]`, e.message))
  await p.setViewport({ width: MOBILE.l, height: MOBILE.h, deviceScaleFactor: MOBILE.dsf, isMobile: true, hasTouch: true })
  await p.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }])
  await p.evaluateOnNewDocument(() => {
    localStorage.setItem('heritage.cles-d-acces.invitation.plus-tard', JSON.stringify({ 1: Date.now() }))
    localStorage.setItem('heritage.installation.plus-tard', '1')
    localStorage.setItem('heritage.arbre.formatDesCartes', 'compact')
    localStorage.setItem('heritage.portrait.rappel.1', '1')
  })
  await p.evaluateOnNewDocument(HORLOGE)
  await p.goto(APP + plan.route, { waitUntil: 'networkidle0', timeout: 90000 })
  await p.addStyleTag({ content: `::-webkit-scrollbar{display:none} * { caret-color: #d9a066; -webkit-tap-highlight-color: transparent; } ${plan.css ?? ''}` })
  await new Promise((r) => setTimeout(r, 1500))
  if (plan.route.startsWith('/arbre')) await p.evaluate(OUTILS_ARBRE)
  await p.evaluate(OUTILS_PAGE)
  const ctx = {}
  if (plan.preparer) await plan.preparer(p, ctx)
  await new Promise((r) => setTimeout(r, 500))
  await p.evaluate(() => window.__figer())

  const n = Math.round((plan.fin - plan.debut) * FPS)
  const faits = new Set()
  const meta = { nom, route: plan.route, debut: plan.debut, fin: plan.fin, fps: FPS, largeur: Math.round(MOBILE.l * MOBILE.dsf), hauteur: Math.round(MOBILE.h * MOBILE.dsf), dsf: MOBILE.dsf, coupes: plan.coupes ?? [], images: [] }
  const t0 = Date.now()
  for (let i = 0; i < n; i++) {
    const t = plan.debut + i / FPS
    const une = (instant) => { const k = String(instant); if (t >= instant && !faits.has(k)) { faits.add(k); return true } return false }
    if (plan.gestes) await plan.gestes(t, p, ctx, une)
    const etat = plan.image ? plan.image(t, ctx) : {}
    if (etat.camera) await p.evaluate((c) => { window.__H?.arreterVol?.(); window.__cam(c.s, c.x, c.y) }, etat.camera)
    if (etat.defilement !== undefined) await p.evaluate((d) => window.__defiler?.(d), etat.defilement)
    if (etat.carte) await p.evaluate((c) => window.__carte(c), etat.carte)
    await p.evaluate((ms) => window.__avancer(ms), 1000 / FPS)
    const rects = {}
    for (const [cle, [genre, valeur, dans]] of Object.entries(plan.reperes ?? {})) {
      const r = await p.evaluate(genre === 'texte' ? RECT_TEXTE : RECT_SEL, valeur, dans)
      if (r) rects[cle] = [r.x, r.y, r.w, r.h].map((v) => Math.round(v * MOBILE.dsf))
    }
    if (plan.fiches) {
      const pos = await p.evaluate((ids) => {
        if (!window.__H || !location.pathname.startsWith('/arbre')) return {}
        const c = window.__H.cadrage, sy = window.__H.sy, zone = document.querySelector('.grille')?.getBoundingClientRect()
        if (!zone) return {}
        const res = {}
        for (const id of ids) { const f = window.__fiche(id); if (f) res[id] = [zone.x + c.tx + f.x * c.s, zone.y + c.ty + f.y * sy, f.w * c.s, f.h * sy] }
        return res
      }, plan.fiches)
      for (const [id, r] of Object.entries(pos)) rects['p' + id] = r.map((v) => Math.round(v * MOBILE.dsf * 10) / 10)
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
