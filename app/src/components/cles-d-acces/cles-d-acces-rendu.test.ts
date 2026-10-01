/**
 * Les clés d'accès à l'écran, rendues côté serveur : la fenêtre d'invitation,
 * la section de Mon compte, et la porte de la connexion.
 *
 * Ce qu'on tient ici :
 *
 * - la fenêtre est une vraie **boîte de dialogue** (rôle, modalité, titre et
 *   description reliés), dit ce qu'elle propose (Windows Hello sur un PC),
 *   « Plus tard » avant « Activer », et **rien** quand elle est fermée ;
 * - à l'étape « mot de passe » : un vrai champ de mot de passe (que le
 *   gestionnaire sait remplir), « Confirmer », le refus sous le champ ; puis le
 *   second geste dit ce qu'il ouvre ;
 * - la liste dit, pour chaque clé, son nom, sa création, sa dernière
 *   utilisation (ou « jamais »), et laquelle vit sur cet appareil ;
 * - le bouton d'ajout n'existe que si le navigateur sait faire, et jamais
 *   pendant une incarnation ;
 * - à la connexion, le bouton de clé d'accès n'apparaît que si le navigateur
 *   sait faire — et le formulaire mot de passe est toujours là.
 *
 * Quand la fenêtre s'ouvre est l'affaire de `composables/clesDAcces.test.ts`.
 */
import { createSSRApp, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'

const etat = vi.hoisted(() => ({ incarnation: null as object | null }))

vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return {
    connecter: () => Promise.resolve(),
    connecterParCleDAcces: () => Promise.resolve(),
    useSession: () => ({
      session: computed(() => ({ utilisateur: { id: 7, email: 'cobalt@exemple.test' } })),
      connecte: computed(() => true),
      incarnation: computed(() => etat.incarnation),
    }),
  }
})
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: () => Promise.resolve() }),
}))

import { definirEnvironnementCleDAcces } from '@/lib/cleDAcces'
import type { CleDAcces } from '@/lib/api'
import FenetreInvitationCleDAcces from './FenetreInvitationCleDAcces.vue'
import ListeClesDAcces from './ListeClesDAcces.vue'
import SectionClesDAcces from './SectionClesDAcces.vue'
import ConnexionView from '@/views/ConnexionView.vue'

function rendre(composant: Component, proprietes: Record<string, unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant, proprietes) })
  app.component('RouterLink', { template: '<a href="#"><slot /></a>' })
  return renderToString(app)
}

/** Les libellés des boutons, dans l'ordre. */
function boutons(html: string): string[] {
  return [...html.matchAll(/<button[^>]*>([\s\S]*?)<\/button>/g)].map((m) =>
    (m[1] ?? '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim(),
  )
}

function navigateur(webauthn: boolean): void {
  definirEnvironnementCleDAcces({
    webauthn,
    plateforme: () => Promise.resolve(webauthn),
    conditionnel: () => Promise.resolve(false),
    creer: () => Promise.resolve(null),
    obtenir: () => Promise.resolve(null),
    agent: '',
  })
}

afterEach(() => {
  definirEnvironnementCleDAcces(null)
  etat.incarnation = null
})

/* ------------------------------------------------------------------ */

describe('la fenêtre « Se connecter plus vite ? »', () => {
  it('est une boîte de dialogue modale, titrée et décrite', async () => {
    const html = await rendre(FenetreInvitationCleDAcces, { ouverte: true })
    expect(html).toMatch(/<dialog[^>]*role="dialog"/)
    expect(html).toMatch(/<dialog[^>]*aria-modal="true"/)
    const titre = /aria-labelledby="([^"]+)"/.exec(html)?.[1]
    const message = /aria-describedby="([^"]+)"/.exec(html)?.[1]
    expect(html).toMatch(new RegExp(`<h2[^>]*id="${titre}"[^>]*>\\s*Se connecter plus vite \\?`))
    expect(html).toMatch(
      new RegExp(
        `id="${message}"[^>]*>\\s*Activez Face ID ou l’empreinte de ce téléphone pour vous connecter sans mot de passe\\.`,
      ),
    )
  })

  it('sur un PC : Windows Hello', async () => {
    const html = await rendre(FenetreInvitationCleDAcces, { ouverte: true, systeme: 'windows' })
    expect(html).toContain('Activez Windows Hello')
    expect(html).not.toContain('Face ID')
  })

  it('étape « mot de passe » : un champ de mot de passe, « Plus tard » puis « Confirmer »', async () => {
    const html = await rendre(FenetreInvitationCleDAcces, { ouverte: true, etape: 'mot-de-passe' })
    expect(html).toContain('Pour votre sécurité, confirmez votre mot de passe')
    expect(html).toMatch(/<input[^>]*type="password"/)
    expect(html).toMatch(/<input[^>]*autocomplete="current-password"/)
    expect(html).toMatch(/<label[^>]*>\s*Mot de passe/)
    expect(boutons(html)).toEqual(['Plus tard', 'Confirmer'])
    expect(html).toMatch(/<button[^>]*type="submit"[^>]*>[\s\S]*?Confirmer/)
  })

  it('étape « mot de passe » : le refus s’écrit sous le champ', async () => {
    const html = await rendre(FenetreInvitationCleDAcces, {
      ouverte: true,
      etape: 'mot-de-passe',
      erreurMotDePasse: 'Ce mot de passe ne correspond pas. Vérifiez-le et réessayez.',
    })
    expect(html).toContain('aria-invalid="true"')
    expect(html).toContain('Ce mot de passe ne correspond pas.')
  })

  it('mot de passe confirmé : le second geste dit ce qu’il ouvre', async () => {
    expect(
      boutons(await rendre(FenetreInvitationCleDAcces, { ouverte: true, motDePasseConfirme: true })),
    ).toEqual(['Plus tard', 'Activer Face ID / empreinte'])
    expect(
      boutons(await rendre(FenetreInvitationCleDAcces, { ouverte: true, motDePasseConfirme: true, systeme: 'windows' })),
    ).toEqual(['Plus tard', 'Activer Windows Hello'])
  })

  it('propose « Plus tard » puis « Activer »', async () => {
    expect(boutons(await rendre(FenetreInvitationCleDAcces, { ouverte: true }))).toEqual(['Plus tard', 'Activer'])
  })

  it('pendant la création : « Activer » patiente, « Plus tard » est retenu', async () => {
    const html = await rendre(FenetreInvitationCleDAcces, { ouverte: true, enCours: true })
    expect(html).toMatch(/<button[^>]*disabled[^>]*>[\s\S]*?Plus tard/)
    expect(html).toMatch(/<button[^>]*aria-busy="true"[^>]*>[\s\S]*?Activer/)
  })

  it('dit un refus du navigateur sans se fermer', async () => {
    const html = await rendre(FenetreInvitationCleDAcces, {
      ouverte: true,
      message: 'L’ajout de la clé d’accès a été annulé. Rien n’a changé sur votre compte.',
    })
    expect(html).toContain('role="alert"')
    expect(html).toContain('a été annulé')
    expect(boutons(html)).toEqual(['Plus tard', 'Activer'])
  })

  it('fermée : la coquille seule, sans contenu ni bouton', async () => {
    const html = await rendre(FenetreInvitationCleDAcces, { ouverte: false })
    expect(html).toContain('<dialog')
    expect(html).not.toContain('<button')
    expect(html).not.toContain('Face ID')
  })
})

/* ------------------------------------------------------------------ */

const CLES: CleDAcces[] = [
  { id: 31, nom: 'iPhone', creeeLe: '2026-03-12T10:00:00+01:00', derniereUtilisation: '2026-09-30T08:00:00+02:00', appareil: 'Trousseau iCloud' },
  { id: 32, nom: 'PC du bureau', creeeLe: '2026-05-02T10:00:00+02:00', derniereUtilisation: null },
]

describe('la liste des clés', () => {
  it('dit le nom, la création, la dernière utilisation — ou « jamais »', async () => {
    const html = await rendre(ListeClesDAcces, { cles: CLES })
    expect(html).toContain('iPhone')
    expect(html).toContain('Créée le 12 mars 2026')
    expect(html).toContain('Trousseau iCloud')
    expect(html).toContain('Dernière utilisation le 30 septembre 2026')
    expect(html).toContain('PC du bureau')
    expect(html).toContain('Jamais utilisée pour se connecter')
  })

  it('reconnaît la clé de cet appareil', async () => {
    const html = await rendre(ListeClesDAcces, { cles: CLES, deCetAppareil: [32] })
    expect(html.match(/cet appareil/g)?.length).toBe(1)
    expect(html.indexOf('cet appareil')).toBeGreaterThan(html.indexOf('PC du bureau'))
  })

  it('Renommer et Supprimer pour chaque clé ; rien pendant une incarnation', async () => {
    expect(boutons(await rendre(ListeClesDAcces, { cles: CLES }))).toEqual([
      'Renommer',
      'Supprimer',
      'Renommer',
      'Supprimer',
    ])
    expect(boutons(await rendre(ListeClesDAcces, { cles: CLES, desactive: true }))).toEqual([])
  })
})

/* ------------------------------------------------------------------ */

describe('la section « Clés d’accès » de Mon compte', () => {
  it('se présente, charge sa liste, et propose d’ajouter une clé sur cet appareil', async () => {
    navigateur(true)
    const html = await rendre(SectionClesDAcces)
    expect(html).toContain('Clés d’accès')
    expect(html).toContain('sans mot de passe')
    // `onMounted` ne tourne pas en SSR : on voit le chargement, jamais une
    // liste vide inventée.
    expect(html).not.toContain('Aucune clé d’accès')
    expect(boutons(html)).toContain('Ajouter une clé d’accès sur cet appareil')
  })

  it('sans WebAuthn : pas de bouton, une phrase', async () => {
    navigateur(false)
    const html = await rendre(SectionClesDAcces)
    expect(boutons(html)).not.toContain('Ajouter une clé d’accès sur cet appareil')
    expect(html).toContain('Ce navigateur ne gère pas les clés d’accès')
  })

  it('pendant une incarnation : rien ne s’ajoute', async () => {
    navigateur(true)
    etat.incarnation = { administrateur: 'admin@exemple.test' }
    const html = await rendre(SectionClesDAcces)
    expect(boutons(html)).not.toContain('Ajouter une clé d’accès sur cet appareil')
    expect(html).toContain('à la place de ce membre')
  })
})

/* ------------------------------------------------------------------ */

describe('la connexion', () => {
  it('montre le bouton de clé d’accès quand le navigateur sait faire, à côté du mot de passe', async () => {
    navigateur(true)
    const html = await rendre(ConnexionView)
    expect(html).toContain('data-connexion-cle-d-acces')
    // Libellé neutre tant que l'appareil n'a pas répondu (`onMounted`).
    expect(boutons(html)).toEqual(['Se connecter', 'Se connecter avec une clé d’accès'])
    expect(html).toContain('autocomplete="current-password"')
  })

  it('ne le montre pas sinon ; le champ courriel garde `username` seul', async () => {
    navigateur(false)
    const html = await rendre(ConnexionView)
    expect(html).not.toContain('data-connexion-cle-d-acces')
    expect(boutons(html)).toEqual(['Se connecter'])
    expect(html).toContain('autocomplete="username"')
    expect(html).not.toContain('webauthn')
  })
})
