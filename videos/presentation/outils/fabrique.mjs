// Fabrique les images de la vidéo (portraits anciens, avatar, actes) : outils/fabrique.html, rendu dans Chromium.
//   node outils/fabrique.mjs   → assets/photos/*.jpg, assets/documents/*.jpg
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ici = path.dirname(fileURLToPath(import.meta.url))
const assets = path.join(ici, '..', 'assets')
for (const d of ['photos', 'documents']) fs.mkdirSync(path.join(assets, d), { recursive: true })
const CHROME = process.env.CHROME || ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find((p) => fs.existsSync(p))
const b = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--allow-file-access-from-files'] })
const page = await b.newPage()
page.on('pageerror', (e) => console.log('[page]', e.message))
await page.goto(pathToFileURL(path.join(ici, 'fabrique.html')).href)
await page.evaluate(() => window.PRET)
async function enregistrer(fichier, appel, { largeur = null, qualite = 0.9 } = {}) {
  const b64 = await page.evaluate((appel, largeur, qualite) => {
    let c = eval(appel)
    if (largeur) { const d = document.createElement('canvas'); d.width = largeur; d.height = Math.round(c.height * largeur / c.width); d.getContext('2d').drawImage(c, 0, 0, d.width, d.height); c = d }
    return c.toDataURL('image/jpeg', qualite).split(',')[1]
  }, appel, largeur, qualite)
  fs.writeFileSync(path.join(assets, fichier), Buffer.from(b64, 'base64'))
  console.log(fichier)
}
// pas de visages inventés : des lieux, et des dos de photos annotés
const LEGENDES = ['La route de Josselin, 1902', 'Les champs de la Ville-Ruaud', 'Guilliers, vers 1910', 'Ploërmel, 1923']
for (let i = 1; i <= 4; i++) await enregistrer(`photos/lieu-${i}.jpg`, `FABRIQUE.lieu(${i}, { carte: true, legende: ${JSON.stringify(LEGENDES[i - 1])} })`)
for (let i = 5; i <= 8; i++) await enregistrer(`photos/lieu-${i}.jpg`, `FABRIQUE.lieu(${i})`)
const DOS = ['Joséphine et Pierre, été 1899', 'Mémé Perrine, à 70 ans', 'Le mariage de Louis — 1907', 'Maman et nous, 1931']
for (let i = 1; i <= 4; i++) await enregistrer(`photos/dos-${i}.jpg`, `FABRIQUE.dosDePhoto(${i}, ${JSON.stringify(DOS[i - 1])})`)
await enregistrer('photos/avatar-thomas.jpg', 'FABRIQUE.avatar()')
await enregistrer('documents/acte-josselin-1868.jpg', 'FABRIQUE.acteNaissance()', { qualite: 0.92 })
await enregistrer('documents/acte-josselin-1868-vignette.jpg', 'FABRIQUE.acteNaissance()', { largeur: 400 })
await enregistrer('documents/bapteme-1783.jpg', 'FABRIQUE.bapteme()')
await enregistrer('documents/deces-1901.jpg', 'FABRIQUE.deces()')
await b.close()
