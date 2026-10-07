// Rend la vidéo verticale image par image et l'encode en H.264.
//
//   node rendre.mjs                      → sortie/presentation.muet.mp4 (1080×1920, 30 i/s)
//   APERCU_BD=1 node rendre.mjs          → sortie/presentation-bd.muet.mp4 (540×960, 15 i/s) : vérifier le rythme
//   IMAGES="3,12.5,40" node rendre.mjs   → sortie/images/<t>.png : quelques images, sans encoder
//   DE=30 A=45 node rendre.mjs           → seulement un passage (avec APERCU_BD ou non)
//
// Écrit aussi sortie/presentation.piste.json : la durée, la voix placée et les bruitages, pour mixer.py.
import puppeteer from 'puppeteer-core'
import { spawn } from 'node:child_process'
import { pathToFileURL, fileURLToPath } from 'node:url'
import fs from 'node:fs'
import path from 'node:path'
import os from 'node:os'

const ici = path.dirname(fileURLToPath(import.meta.url))
const sortie = path.join(ici, 'sortie')
fs.mkdirSync(path.join(sortie, 'images'), { recursive: true })

const BD = !!process.env.APERCU_BD
const FPS = Number(process.env.FPS || (BD ? 15 : 30))
const RESOLUTION = BD ? 0.5 : 1
const NOM = BD ? 'presentation-bd' : 'presentation'
const TRAVAILLEURS = Number(process.env.TRAVAILLEURS || Math.max(1, Math.min(6, os.cpus().length - 1)))
const CHROME = process.env.CHROME || ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium'].find((p) => fs.existsSync(p))

const navigateur = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--force-device-scale-factor=1', '--allow-file-access-from-files', '--disable-web-security', '--no-sandbox', '--font-render-hinting=none'],
})
async function ouvrir() {
  const page = await navigateur.newPage()
  await page.setViewport({ width: 700, height: 1100 })
  page.on('console', (m) => { if (['warn', 'warning', 'error'].includes(m.type())) console.log('[page]', m.text()) })
  page.on('pageerror', (e) => console.log('[page]', e.message))
  await page.evaluateOnNewDocument((r) => { window.__capture = true; window.RESOLUTION = r }, RESOLUTION)
  await page.goto(pathToFileURL(path.join(ici, 'index.html')).href)
  await page.waitForFunction(() => window.PRET === true, { timeout: 60000 })
  return page
}
const image = (page, t, type) => page.evaluate(async (t, type) => { await window.render(t); return document.getElementById('c').toDataURL(type, 0.95).split(',')[1] }, t, type)

const page0 = await ouvrir()
// la composition est nativement verticale : on le vérifie avant de rendre quoi que ce soit
const dims = await page0.evaluate(() => [document.getElementById('c').width, document.getElementById('c').height])
if (dims[0] * 16 !== dims[1] * 9) throw new Error(`le canevas n'est pas en 9:16 : ${dims}`)
const piste = await page0.evaluate(() => window.PISTE)
fs.writeFileSync(path.join(sortie, 'presentation.piste.json'), JSON.stringify(piste, null, 1))

if (process.env.IMAGES) {
  for (const t of process.env.IMAGES.split(',').map(Number)) {
    fs.writeFileSync(path.join(sortie, 'images', `${t.toFixed(2)}.png`), Buffer.from(await image(page0, t, 'image/png'), 'base64'))
  }
  console.log(`durée ${piste.duree.toFixed(2)} s`)
  await navigateur.close()
  process.exit(0)
}

const de = Number(process.env.DE || 0), a = Math.min(piste.duree, Number(process.env.A || piste.duree))
const i0 = Math.round(de * FPS), i1 = Math.round(a * FPS)
const pages = [page0, ...(await Promise.all(Array.from({ length: TRAVAILLEURS - 1 }, ouvrir)))]
const tranche = Math.ceil((i1 - i0) / TRAVAILLEURS)
const debutChrono = Date.now()
let faites = 0

async function rendreTranche(page, k) {
  const a0 = i0 + k * tranche, a1 = Math.min(i1, a0 + tranche)
  const fichier = path.join(sortie, `${NOM}.part${k}.mp4`)
  if (a1 <= a0) return null
  const ff = spawn('ffmpeg', ['-y', '-v', 'error', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-',
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', BD ? 'veryfast' : 'slow', '-crf', BD ? '26' : '16', '-tune', 'film',
    '-r', String(FPS), fichier], { stdio: ['pipe', 'inherit', 'inherit'] })
  for (let i = a0; i < a1; i++) {
    const ok = ff.stdin.write(Buffer.from(await image(page, i / FPS, 'image/jpeg'), 'base64'))
    if (!ok) await new Promise((r) => ff.stdin.once('drain', r))
    if (++faites % 150 === 0) {
      const s = (Date.now() - debutChrono) / 1000
      console.log(`${faites}/${i1 - i0} images — ${s.toFixed(0)} s, reste ≈ ${((s / faites) * (i1 - i0 - faites)).toFixed(0)} s`)
    }
  }
  ff.stdin.end()
  await new Promise((r) => ff.on('close', r))
  return fichier
}
const parts = (await Promise.all(pages.map((p, k) => rendreTranche(p, k)))).filter(Boolean)
const liste = path.join(sortie, `${NOM}.parts.txt`)
fs.writeFileSync(liste, parts.map((p) => `file '${p}'`).join('\n'))
await new Promise((ok, ko) => spawn('ffmpeg', ['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', liste, '-c', 'copy', '-movflags', '+faststart', path.join(sortie, `${NOM}.muet.mp4`)], { stdio: 'inherit' })
  .on('close', (c) => (c ? ko(new Error('concat')) : ok())))
for (const p of parts) fs.unlinkSync(p)
fs.unlinkSync(liste)
console.log(`${NOM} : ${i1 - i0} images en ${((Date.now() - debutChrono) / 1000).toFixed(0)} s → sortie/${NOM}.muet.mp4 (${dims[0]}×${dims[1]})`)
await navigateur.close()
