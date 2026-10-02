/**
 * Le lecteur des vidéos de présentation — ce que ses gestes **émettent**.
 *
 * Monté dans le rendu en mémoire du dépôt (`test-utils/rendu-memoire.ts`) :
 * pas de DOM, pas de `window`, donc pas de lecture réelle. Ce qu'on y prouve,
 * ce sont les promesses faites au parent, celles dont dépend l'écran de
 * l'invitation :
 *
 * - la croix émet `fermer` (motif `croix`), tout de suite ;
 * - **un échec de chargement émet `fermer` (motif `echec`)**, sans rien
 *   afficher : c'est ce qui garantit qu'une vidéo absente ne bloque personne ;
 * - la fin émet `fin`, **après** le fondu, et une seule fois ;
 * - `chargee` n'arrive qu'avec les métadonnées — c'est sur elle que l'écran
 *   d'invitation retient « déjà vue ».
 *
 * Et une garde de plus : une fois fermé, le lecteur ne réémet rien. Un `ended`
 * qui suivrait une croix rouvrirait sinon la question chez le parent.
 */
import { h } from 'vue'
import { describe, expect, it } from 'vitest'
import LecteurVideo from './LecteurVideo.vue'
import { videoParId, type MotifFermeture } from '@/lib/videos'
import { attendre, cliquer, monterEnMemoire, parAttribut, un, type Noeud } from '@/test-utils/rendu-memoire'

interface Journal {
  fin: number
  fermer: MotifFermeture[]
  chargee: number
}

async function monter(): Promise<{ racine: Noeud; journal: Journal }> {
  const journal: Journal = { fin: 0, fermer: [], chargee: 0 }
  const racine = await monterEnMemoire({
    render: () =>
      h(LecteurVideo, {
        video: videoParId('bienvenue'),
        onFin: () => journal.fin++,
        onFermer: (motif: MotifFermeture) => journal.fermer.push(motif),
        onChargee: () => journal.chargee++,
      }),
  })
  return { racine, journal }
}

/** Déclenche l'événement `nom` de l'élément `<video>`. */
async function evenementVideo(racine: Noeud, nom: string): Promise<void> {
  const video = un(racine, (n) => n.tag === 'video')
  if (video === null) throw new Error('Pas de <video>.')
  const ecouteur = video.ecouteurs[nom]
  if (typeof ecouteur !== 'function') throw new Error(`Pas d'écouteur « ${nom} » sur <video>.`)
  ecouteur({ target: video })
  await attendre()
}

/** Le fondu de fin dure 300 ms : on attend un peu plus. */
const apresLeFondu = (): Promise<void> => new Promise((fin) => setTimeout(fin, 360))

describe('le lecteur vidéo, au geste', () => {
  it('émet « fermer » (croix) au toucher de la croix, sans attendre de fondu', async () => {
    const { racine, journal } = await monter()

    await cliquer(un(racine, parAttribut('data-fermer-video')))

    expect(journal.fermer).toEqual(['croix'])
    expect(journal.fin).toBe(0)
  })

  it('se ferme sans un mot quand la vidéo ne charge pas', async () => {
    const { racine, journal } = await monter()

    await evenementVideo(racine, 'error')

    expect(journal.fermer).toEqual(['echec'])
    expect(journal.chargee).toBe(0)
  })

  it('émet « fin » à la fin de la vidéo, après le fondu, une seule fois', async () => {
    const { racine, journal } = await monter()

    await evenementVideo(racine, 'ended')
    // Pendant le fondu : le lecteur s'efface, rien n'est encore émis.
    const dialogue = un(racine, (n) => n.attributs['role'] === 'dialog')
    expect(String(dialogue?.attributs['class'])).toContain('opacity-0')
    expect(journal.fin).toBe(0)

    await apresLeFondu()
    await evenementVideo(racine, 'ended')
    await apresLeFondu()

    expect(journal.fin).toBe(1)
    expect(journal.fermer).toEqual([])
  })

  it('ne réémet rien une fois fermé', async () => {
    const { racine, journal } = await monter()

    await cliquer(un(racine, parAttribut('data-fermer-video')))
    await evenementVideo(racine, 'error')
    await evenementVideo(racine, 'ended')
    await apresLeFondu()

    expect(journal.fermer).toEqual(['croix'])
    expect(journal.fin).toBe(0)
  })

  it('n’annonce « chargée » qu’avec les métadonnées de la vidéo', async () => {
    const { racine, journal } = await monter()
    expect(journal.chargee).toBe(0)

    await evenementVideo(racine, 'loadedmetadata')

    expect(journal.chargee).toBe(1)
  })

  it('remplace « Activer le son » par le bouton couper/rétablir, une fois le son activé', async () => {
    const { racine } = await monter()

    await cliquer(un(racine, parAttribut('data-activer-son')))

    expect(un(racine, parAttribut('data-activer-son'))).toBeNull()
    const bascule = un(racine, parAttribut('data-basculer-son'))
    expect(bascule?.attributs['aria-label']).toBe('Couper le son')

    await cliquer(bascule)
    expect(un(racine, parAttribut('data-basculer-son'))?.attributs['aria-label']).toBe('Rétablir le son')
  })

  it('suit la lecture dans le libellé du bouton pause', async () => {
    const { racine } = await monter()
    const libelle = (): unknown => un(racine, parAttribut('data-pause-video'))?.attributs['aria-label']

    expect(libelle()).toBe('Reprendre la lecture')
    await evenementVideo(racine, 'play')
    expect(libelle()).toBe('Mettre en pause')
    await evenementVideo(racine, 'pause')
    expect(libelle()).toBe('Reprendre la lecture')
  })
})
