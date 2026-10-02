/**
 * Le lecteur des vidéos de présentation, rendu côté serveur — ce que le
 * gabarit **écrit**.
 *
 * Le projet n'a ni `jsdom` ni `@vue/test-utils` (même arbitrage que
 * `questions-rendu.test.ts`). Sous Node, la téléportation dans `<body>` est
 * éteinte — il n'y a pas de `<body>` — et le lecteur se rend sur place : c'est
 * ce qui rend son balisage lisible ici.
 *
 * Ce qui ne se voit dans aucun navigateur quand c'est faux, et se prouve donc
 * ici :
 *
 * - **`muted` et `playsinline`** : sans eux, la lecture automatique est
 *   refusée (ou l'iPhone ouvre son propre lecteur) — et rien ne lève ;
 * - **la piste de sous-titres sans `default`** : avec, le navigateur peindrait
 *   le texte PAR-DESSUS les sous-titres incrustés, en double ;
 * - **la croix** nommée « Fermer la vidéo », à 44 px au moins ;
 * - **`role="dialog"` + `aria-modal`**, nommé et décrit.
 *
 * Le comportement — clics, fin, échec — est dans `lecteur-video.client.test.ts`.
 */
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import LecteurVideo from './LecteurVideo.vue'
import { videoParId } from '@/lib/videos'

function rendre(id = 'bienvenue'): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(LecteurVideo, { video: videoParId(id) }) }))
}

/** La balise ouvrante du premier élément `tag` qui porte `marque`. */
function balise(html: string, tag: string, marque = ''): string {
  const motif = new RegExp(`<${tag}\\b[^>]*${marque}[^>]*>`)
  return motif.exec(html)?.[0] ?? ''
}

describe('le lecteur vidéo, rendu', () => {
  it('est un dialogue modal, nommé par le titre et décrit par la phrase', async () => {
    const html = await rendre()
    const dialogue = balise(html, 'div', 'role="dialog"')

    expect(dialogue).toContain('aria-modal="true"')
    const nomme = /aria-labelledby="([^"]+)"/.exec(dialogue)?.[1]
    const decrit = /aria-describedby="([^"]+)"/.exec(dialogue)?.[1]
    expect(html).toMatch(new RegExp(`id="${nomme}"[^>]*>Bienvenue dans Heritage<`))
    expect(html).toMatch(new RegExp(`id="${decrit}"[^>]*>Se retrouver dans l’arbre`))
  })

  it('lit le MP4 de la vidéo, avec son affiche, en muet et sur place', async () => {
    const video = balise(await rendre('decouvrir'), 'video')

    expect(video).toContain('src="/videos/decouvrir.mp4"')
    expect(video).toContain('poster="/videos/decouvrir.jpg"')
    expect(video).toMatch(/\smuted[\s=>]/)
    expect(video).toMatch(/\splaysinline[\s=>]/)
    // La lecture est lancée par `play()`, qui dit si elle est refusée.
    expect(video).not.toMatch(/\sautoplay[\s=>]/)
    // `object-contain` : `cover` rognerait les sous-titres incrustés.
    expect(video).toContain('object-contain')
  })

  it('porte les sous-titres en piste « captions » française — sans `default`', async () => {
    const piste = balise(await rendre(), 'track')

    expect(piste).toContain('kind="captions"')
    expect(piste).toContain('srclang="fr"')
    expect(piste).toContain('src="/videos/bienvenue.vtt"')
    expect(piste).not.toMatch(/\sdefault[\s=>]/)
  })

  it('montre la croix dès la première image, nommée et de 44 px', async () => {
    const croix = balise(await rendre(), 'button', 'data-fermer-video')

    expect(croix).toContain('aria-label="Fermer la vidéo"')
    expect(croix).toContain('size-11')
  })

  it('met la croix en premier dans l’ordre du focus', async () => {
    const html = await rendre()
    const premierBouton = balise(html, 'button')

    expect(premierBouton).toContain('data-fermer-video')
  })

  it('propose d’activer le son, avec des mots, tant qu’il est coupé', async () => {
    const html = await rendre()

    expect(html).toMatch(/data-activer-son[^>]*>[\s\S]*?Activer le son/)
    expect(html).not.toContain('data-basculer-son')
  })

  it('a un bouton pause/lecture et une barre de progression muette pour les lecteurs d’écran', async () => {
    const html = await rendre()

    expect(balise(html, 'button', 'data-pause-video')).toMatch(/aria-label="(Mettre en pause|Reprendre la lecture)"/)
    expect(html).toMatch(/<div[^>]*aria-hidden="true"[^>]*>\s*<div[^>]*barre-progression/)
  })

  it('passe au-dessus de tout le reste de l’application', async () => {
    const dialogue = balise(await rendre(), 'div', 'role="dialog"')

    // Au-dessus des fenêtres automatiques (z-[600]/z-[610]) et du feuillet des filtres.
    expect(dialogue).toContain('z-[700]')
    expect(dialogue).toContain('fixed inset-0')
  })
})
