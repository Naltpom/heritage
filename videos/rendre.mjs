// Rend une vidéo image par image (30 i/s) et pipe les JPEG dans ffmpeg.
//
//   node rendre.mjs bienvenue                 → sortie/bienvenue.muet.mp4 + sortie/bienvenue.piste.json
//   APERCU="1,4.5,9" node rendre.mjs bienvenue → sortie/apercu/bienvenue_<t>.png
import puppeteer from 'puppeteer-core'
import { spawn } from 'node:child_process'
import { pathToFileURL } from 'node:url'
import fs from 'node:fs'
import path from 'node:path'

const FPS = Number(process.env.FPS || 30)
const SEULES = process.env.APERCU
const id = process.argv[2]
if (!id) throw new Error('usage : node rendre.mjs <id>')
const ici = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Z]:)/, '$1'))
const sortie = path.join(ici, 'sortie')
fs.mkdirSync(path.join(sortie, 'apercu'), { recursive: true })
const voix = JSON.parse(fs.readFileSync(path.join(ici, 'voix', `${id}.json`), 'utf8'))

const navigateur = await puppeteer.launch({
  executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  headless: true,
  args: ['--force-device-scale-factor=1', '--allow-file-access-from-files'],
})
const page = await navigateur.newPage()
page.on('console', (m) => { if (m.type() === 'warning' || m.type() === 'error') console.log('[page]', m.text()) })
page.on('pageerror', (e) => console.log('[page]', e.message))
await page.evaluateOnNewDocument((v) => { window.__capture = true; window.VOIX_BRUTE = v }, voix)
await page.goto(pathToFileURL(path.join(ici, 'studio.html')).href + `?v=${id}`)
await page.waitForFunction(() => typeof window.render === 'function', { timeout: 10000 })
const image = (t, type) => page.evaluate((t, type) => { window.render(t); return document.getElementById('c').toDataURL(type, 0.93).split(',')[1] }, t, type)
const piste = await page.evaluate(() => window.PISTE)
fs.writeFileSync(path.join(sortie, `${id}.piste.json`), JSON.stringify(piste, null, 1))

if (SEULES) {
  for (const t of SEULES.split(',').map(Number)) fs.writeFileSync(path.join(sortie, 'apercu', `${id}_${t}.png`), Buffer.from(await image(t, 'image/png'), 'base64'))
  console.log(`durée ${piste.duree.toFixed(2)} s`)
} else {
  fs.writeFileSync(path.join(sortie, `${id}.jpg`), Buffer.from(await image(piste.affiche, 'image/jpeg'), 'base64'))
  const ff = spawn('ffmpeg', ['-y', '-v', 'error', '-f', 'image2pipe', '-framerate', String(FPS), '-c:v', 'mjpeg', '-i', '-', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'slow', '-crf', '20', '-movflags', '+faststart', path.join(sortie, `${id}.muet.mp4`)], { stdio: ['pipe', 'inherit', 'inherit'] })
  const n = Math.round(piste.duree * FPS)
  for (let i = 0; i < n; i++) {
    const ok = ff.stdin.write(Buffer.from(await image(i / FPS, 'image/jpeg'), 'base64'))
    if (!ok) await new Promise((r) => ff.stdin.once('drain', r))
  }
  ff.stdin.end()
  await new Promise((r) => ff.on('close', r))
  console.log(`${id} : ${n} images, ${piste.duree.toFixed(2)} s`)
}
await navigateur.close()
