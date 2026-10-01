/**
 * La fenêtre « Activer les notifications », rendue côté serveur.
 *
 * Ce qu'on tient ici : une vraie **boîte de dialogue** pour les lecteurs
 * d'écran (rôle, modalité, titre et description reliés), le bon contenu pour
 * chaque forme, et **rien** quand elle est fermée. Quand elle s'ouvre est
 * l'affaire de `composables/invitationPush.test.ts`.
 */
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  lireClePush: vi.fn(),
  enregistrerAbonnementPush: vi.fn(),
  supprimerAbonnementPush: vi.fn(),
}))
vi.mock('@/composables/useNotifications', () => ({ rafraichirCompteur: vi.fn() }))
vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return { useSession: () => ({ connecte: computed(() => true), incarnation: computed(() => null) }) }
})

import FenetreInvitationNotifications from './FenetreInvitationNotifications.vue'
import InvitationNotifications from './InvitationNotifications.vue'
import type { FormeInvitationPush } from '@/composables/usePush'

function rendre(forme: FormeInvitationPush | null, enCours = false): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(FenetreInvitationNotifications, { forme, enCours }) }))
}

/** Les libellés des boutons, dans l'ordre. */
function boutons(html: string): string[] {
  return [...html.matchAll(/<button[^>]*>([\s\S]*?)<\/button>/g)].map((m) => (m[1] ?? '').replace(/<[^>]+>/g, '').trim())
}

describe('la fenêtre « Activer »', () => {
  it('est une boîte de dialogue modale, titrée et décrite', async () => {
    const html = await rendre('activer')
    expect(html).toMatch(/<dialog[^>]*role="dialog"/)
    expect(html).toMatch(/<dialog[^>]*aria-modal="true"/)

    const titre = /aria-labelledby="([^"]+)"/.exec(html)?.[1]
    const message = /aria-describedby="([^"]+)"/.exec(html)?.[1]
    expect(titre).toBeTruthy()
    expect(message).toBeTruthy()
    expect(html).toMatch(new RegExp(`<h2[^>]*id="${titre}"[^>]*>\\s*Activer les notifications`))
    expect(html).toContain(`id="${message}"`)
  })

  it('dit pourquoi, et propose « Plus tard » puis « Activer »', async () => {
    const html = await rendre('activer')
    expect(html).toContain('Soyez prévenu quand votre inscription est validée, quand une demande est traitée, quand un tirage est fait.')
    expect(boutons(html)).toEqual(['Plus tard', 'Activer'])
  })

  it('pendant l’activation : « Activer » patiente, « Plus tard » est retenu', async () => {
    const html = await rendre('activer', true)
    expect(html).toMatch(/<button[^>]*disabled[^>]*>[\s\S]*?Plus tard/)
    expect(html).toMatch(/<button[^>]*aria-busy="true"[^>]*>[\s\S]*?Activer/)
  })
})

describe('la fenêtre « Installer » (iPhone hors écran d’accueil)', () => {
  it('dit les deux gestes de Safari et n’a qu’un bouton, « Compris »', async () => {
    const html = await rendre('installer')
    expect(html).toContain('Installez Heritage sur votre écran d’accueil pour recevoir les notifications')
    expect(html).toContain('Partager')
    expect(html).toContain('Sur l’écran d’accueil')
    expect(boutons(html)).toEqual(['Compris'])
    expect(html).not.toContain('Activer')
  })
})

describe('fermée', () => {
  it('ne rend que la coquille du `<dialog>`, sans contenu ni bouton', async () => {
    const html = await rendre(null)
    expect(html).toContain('<dialog')
    expect(html).not.toContain(' open')
    expect(html).not.toContain('<button')
    expect(html).not.toContain('Activer les notifications')
  })

  it('montée par l’application, elle ne s’ouvre pas d’elle-même au rendu', async () => {
    for (const avecCadre of [true, false]) {
      const html = await renderToString(createSSRApp({ render: () => h(InvitationNotifications, { avecCadre }) }))
      expect(html).not.toContain('<button')
      expect(html).not.toContain('Notifications activées')
    }
  })
})
