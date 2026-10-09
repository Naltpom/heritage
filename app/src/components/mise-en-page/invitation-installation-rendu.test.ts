/**
 * « Installer Heritage sur votre téléphone », rendu côté serveur : la fenêtre
 * de l'arrivée, et le panneau de « Moi » et de « Mon compte ».
 *
 * Ce qu'on tient ici : une vraie **boîte de dialogue** pour les lecteurs
 * d'écran (rôle, modalité, titre et description reliés), le bon contenu pour
 * chaque forme, **rien** quand elle est fermée ; et un panneau qui ne se montre
 * que s'il y a quelque chose à installer, sans « Plus tard » — il n'y a plus de
 * bandeau à écarter. Quand la fenêtre s'ouvre est l'affaire de
 * `composables/invitationInstallation.test.ts`.
 */
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return { useSession: () => ({ connecte: computed(() => true), incarnation: computed(() => null) }) }
})

import FenetreInvitationInstallation from './FenetreInvitationInstallation.vue'
import InvitationInstallation from './InvitationInstallation.vue'
import InstallationApplication from './InstallationApplication.vue'
import type { FormeInvitationInstallation } from '@/composables/invitationInstallation'
import { simulerInstallation, type InvitationDInstallation } from '@/composables/installation'

function rendre(forme: FormeInvitationInstallation | null, enCours = false): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(FenetreInvitationInstallation, { forme, enCours }) }))
}

/** Les libellés des boutons, dans l'ordre. */
function boutons(html: string): string[] {
  return [...html.matchAll(/<button[^>]*>([\s\S]*?)<\/button>/g)].map((m) => (m[1] ?? '').replace(/<[^>]+>/g, '').trim())
}

/** Le texte, balises ôtées et blancs ramenés à un seul. */
function texte(html: string): string {
  return html
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

const MESSAGE =
  'Il s’ouvrira depuis votre écran d’accueil, en plein écran, comme une application — et pourra vous prévenir par des notifications.'

afterEach(() => {
  simulerInstallation(null)
})

describe('la fenêtre, forme « android » (le navigateur sait installer)', () => {
  it('est une boîte de dialogue modale, titrée et décrite', async () => {
    const html = await rendre('android')
    expect(html).toMatch(/<dialog[^>]*role="dialog"/)
    expect(html).toMatch(/<dialog[^>]*aria-modal="true"/)
    expect(html).toMatch(/<dialog[^>]*data-forme="android"/)

    const titre = /aria-labelledby="([^"]+)"/.exec(html)?.[1]
    const message = /aria-describedby="([^"]+)"/.exec(html)?.[1]
    expect(titre).toBeTruthy()
    expect(message).toBeTruthy()
    expect(html).toMatch(new RegExp(`<h2[^>]*id="${titre}"[^>]*>\\s*Installer Heritage sur votre téléphone`))
    // La description reliée porte bien le message.
    const description = new RegExp(`id="${message}"[^>]*>([\\s\\S]*?)</div>`).exec(html)?.[1] ?? ''
    expect(texte(description)).toContain(MESSAGE)
  })

  it('montre l’icône de l’application, muette pour les lecteurs d’écran', async () => {
    const html = await rendre('android')
    expect(html).toMatch(/<img[^>]*src="\/icons\/icone-192\.png"[^>]*alt=""/)
  })

  it('dit pourquoi, et propose « Plus tard » puis « Installer » — sans les gestes de Safari', async () => {
    const html = await rendre('android')
    expect(texte(html)).toContain(MESSAGE)
    expect(boutons(html)).toEqual(['Plus tard', 'Installer'])
    expect(html).not.toContain('Partager')
    expect(html).not.toContain('Compris')
  })

  it('pendant la boîte du navigateur : « Installer » patiente, « Plus tard » est retenu', async () => {
    const html = await rendre('android', true)
    expect(html).toMatch(/<button[^>]*disabled[^>]*>[\s\S]*?Plus tard/)
    expect(html).toMatch(/<button[^>]*aria-busy="true"[^>]*>[\s\S]*?Installer/)
  })

  it('tient sur un téléphone : feuille du bas pleine largeur, zone du geste d’accueil réservée, boutons qui passent à la ligne', async () => {
    const html = await rendre('android')
    const classes = /<dialog[^>]*class="([^"]*)"/.exec(html)?.[1]?.split(/\s+/) ?? []
    expect(classes).toEqual(expect.arrayContaining(['mt-auto', 'mb-0', 'w-full', 'max-w-full', 'rounded-t-panneau', 'md:m-auto']))
    expect(html).toContain('pb-[max(1.25rem,env(safe-area-inset-bottom))]')
    expect(html).toMatch(/class="flex min-w-0 flex-col gap-1\.5"/)
    expect(html).toMatch(/class="flex flex-wrap justify-end gap-2"/)
  })
})

describe('la fenêtre, forme « ios » (Safari sur iPhone)', () => {
  it('dit les deux gestes de Safari, que c’est nécessaire aux notifications, et n’a qu’un bouton, « Compris »', async () => {
    const html = await rendre('ios')
    const lu = texte(html)
    expect(lu).toContain('Installer Heritage sur votre téléphone')
    expect(lu).toContain(MESSAGE)
    expect(lu).toContain('Sur iPhone, c’est même nécessaire pour recevoir les notifications.')
    expect(lu).toContain('Dans Safari, touchez Partager')
    // Safari récent range « Partager » derrière les trois points.
    expect(lu).toMatch(/\(ou les trois points\s*,\s*puis Partager\s*\)/)
    expect(lu).toContain('Sur l’écran d’accueil')
    expect(html).toMatch(/<ol[^>]*>[\s\S]*<li>[\s\S]*<li>/)
    expect(boutons(html)).toEqual(['Compris'])
    expect(html).not.toContain('Plus tard')
  })

  it('la description reliée comprend les gestes', async () => {
    const html = await rendre('ios')
    const message = /aria-describedby="([^"]+)"/.exec(html)?.[1]
    expect(html).toMatch(new RegExp(`id="${message}"[^>]*>[\\s\\S]*Sur l’écran d’accueil`))
  })
})

describe('fermée', () => {
  it('ne rend que la coquille du `<dialog>`, sans contenu ni bouton', async () => {
    const html = await rendre(null)
    expect(html).toContain('<dialog')
    expect(html).not.toContain(' open')
    expect(html).not.toContain('<button')
    expect(html).not.toContain('Installer Heritage')
  })

  it('montée par l’application, elle ne s’ouvre pas d’elle-même au rendu', async () => {
    simulerInstallation({ invitation: {} as InvitationDInstallation, ios: true })
    for (const avecCadre of [true, false]) {
      const html = await renderToString(createSSRApp({ render: () => h(InvitationInstallation, { avecCadre }) }))
      expect(html).toContain('data-invitation-installation')
      expect(html).not.toContain('<button')
    }
  })
})

/* ------------------------------------------------------------------ */
/* Le panneau de « Moi » et de « Mon compte »                          */
/* ------------------------------------------------------------------ */

function rendrePanneau(): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(InstallationApplication) }))
}

describe('le panneau', () => {
  it('ne se montre pas quand il n’y a rien à installer', async () => {
    simulerInstallation({ ios: false })
    expect(await rendrePanneau()).not.toContain('data-installation-application')
  })

  it('ne se montre pas une fois l’application installée', async () => {
    simulerInstallation({ invitation: {} as InvitationDInstallation, installee: true })
    expect(await rendrePanneau()).not.toContain('data-installation-application')
  })

  it('se montre quand le navigateur sait installer : un seul bouton, « Installer », et plus de « Plus tard »', async () => {
    simulerInstallation({ invitation: {} as InvitationDInstallation, ios: false })
    const html = await rendrePanneau()
    expect(html).toContain('data-installation-application')
    expect(texte(html)).toContain('Installez Heritage sur votre téléphone, comme une application.')
    expect(boutons(html)).toEqual(['Installer'])
    // Le bouton rejoue la boîte du navigateur : rien à déplier.
    expect(html).not.toContain('aria-expanded')
  })

  it('sur iPhone : « Installer » déplie les gestes de Safari, et le dit', async () => {
    simulerInstallation({ ios: true })
    const html = await rendrePanneau()
    expect(html).toContain('data-installation-application')
    expect(html).toMatch(/<button[^>]*aria-expanded="false"[^>]*>[\s\S]*?Installer/)
    // Repliés au départ.
    expect(html).not.toContain('Sur l’écran d’accueil')
  })

  it('tient à 320 px : icône et phrase sur une ligne, bouton dessous en pleine largeur, à côté dès `sm`', async () => {
    simulerInstallation({ invitation: {} as InvitationDInstallation })
    const html = await rendrePanneau()
    const racine = /<div[^>]*class="([^"]*)"[^>]*data-installation-application/.exec(html)?.[1]?.split(/\s+/) ?? []
    expect(racine).toEqual(expect.arrayContaining(['flex', 'flex-col', 'sm:flex-row', 'sm:flex-wrap']))
    // La phrase peut rétrécir et passer à la ligne ; l'icône, jamais.
    expect(html).toMatch(/<div class="flex min-w-0 items-center gap-3 sm:flex-1"><img[^>]*class="size-9 flex-none rounded-lg"/)
    expect(html).toMatch(/<span class="min-w-0">/)
    const bouton = /<button[^>]*class="([^"]*)"/.exec(html)?.[1]?.split(/\s+/) ?? []
    expect(bouton).toEqual(expect.arrayContaining(['w-full', 'sm:w-auto']))
  })
})
