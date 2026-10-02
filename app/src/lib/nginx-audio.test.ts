/**
 * `app/nginx.conf` sert les **consignes parlées** (`/audio/*.mp3`) comme il
 * sert les vidéos : bon type, 404 franc, en-têtes hérités, cache d'un jour sur
 * une réponse réussie seulement — et la CSP laisse réécouter un message vocal
 * qu'on vient d'enregistrer (`blob:`).
 *
 * On lit le fichier servi, pas une copie. Ce n'est pas nginx qui tourne ici :
 * ces cas tiennent les quelques lignes dont l'absence ne se verrait qu'en
 * production — une consigne servie en `application/octet-stream` (refusée par
 * le navigateur sous `nosniff`), un fichier manquant remplacé par
 * `index.html` en 200, ou un lecteur de réécoute muet.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const CONF = readFileSync(fileURLToPath(new URL('../../nginx.conf', import.meta.url)), 'utf8')

/** Le corps du bloc `location ^~ /audio/ { … }` (sans bloc imbriqué). */
function blocAudio(): string {
  const debut = CONF.indexOf('location ^~ /audio/ {')
  if (debut === -1) throw new Error('Le bloc `location ^~ /audio/` a disparu de nginx.conf.')
  // Le bloc contient un `types { … }` : on compte les accolades.
  let profondeur = 0
  for (let i = CONF.indexOf('{', debut); i < CONF.length; i++) {
    if (CONF[i] === '{') profondeur++
    if (CONF[i] === '}') profondeur--
    if (profondeur === 0) return CONF.slice(debut, i + 1)
  }
  throw new Error('Bloc /audio/ non refermé.')
}

/** Les lignes actives, commentaires retirés. */
function actif(texte: string): string {
  return texte
    .split('\n')
    .map((ligne) => ligne.replace(/#.*$/, '').trim())
    .filter((ligne) => ligne.length > 0)
    .join('\n')
}

describe('nginx et les consignes parlées', () => {
  it('rend un 404 franc pour un fichier absent, pas `index.html`', () => {
    expect(actif(blocAudio())).toContain('try_files $uri =404;')
  })

  it('sert le MP3 en `audio/mpeg`', () => {
    expect(actif(blocAudio())).toMatch(/audio\/mpeg\s+mp3;/)
  })

  it('n’ajoute aucun en-tête : il hérite des sept en-têtes de sécurité du parent', () => {
    expect(actif(blocAudio())).not.toContain('add_header')
  })

  it('met /audio/ en cache un jour, sur une réponse réussie seulement', () => {
    expect(actif(CONF)).toMatch(/"~\^\/audio\/"\s+\$cache_videos;/)
  })

  it('laisse lire une URL `blob:` — la réécoute d’un message vocal', () => {
    expect(actif(CONF)).toMatch(/media-src 'self' blob:/)
  })

  it('le micro reste permis à ce seul site', () => {
    expect(actif(CONF)).toContain('microphone=(self)')
  })
})
