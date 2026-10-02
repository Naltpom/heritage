/**
 * Le prochain événement sur l'accueil, rendu côté serveur (donc sans geste).
 *
 * 1. **Pas encore répondu** : « Venez-vous ? » et les deux boutons, sur la carte.
 * 2. **Déjà répondu** : la réponse marquée, l'autre bouton à portée.
 * 3. **Annulé** : plus de boutons — mais la carte n'apparaît alors pas sur
 *    l'accueil (`prochainPourMoi`), ce cas protège un écran qui l'oublierait.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { ResumeDEvenement } from '@/lib/api'
import CarteEvenementAccueil from './CarteEvenementAccueil.vue'

function evenement(partiel: Partial<ResumeDEvenement> = {}): ResumeDEvenement {
  return {
    id: 7,
    titre: 'Cousinade été 2027',
    debut: '2027-07-17',
    fin: '2027-07-18',
    lieu: 'Fleurance',
    description: null,
    annule: false,
    passe: false,
    organisateur: { libelle: 'Opale Tilleul', estMoi: false },
    peutGerer: false,
    suisInvite: true,
    maReponse: null,
    peutRepondre: true,
    nombreDInvites: 37,
    nombreDePresents: 12,
    ...partiel,
  }
}

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function rendre(e: ResumeDEvenement): Promise<string> {
  const app = createSSRApp({ render: () => h(CarteEvenementAccueil, { evenement: e, aujourdhui: new Date(2027, 6, 1, 12) }) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('CarteEvenementAccueil', () => {
  it('pas encore répondu : la question et les deux boutons, sur la carte', async () => {
    const html = await rendre(evenement())

    expect(html).toContain('Invitation · dans 16 jours')
    expect(html).toContain('Cousinade été 2027')
    expect(html).toContain('Du sam. 17 au dim. 18 juillet 2027')
    expect(html).toContain('12 présents sur 37 invités')
    expect(html).toContain('Venez-vous ?')
    expect(html).toContain('aria-label="Ma réponse"')
    expect(html.match(/aria-pressed="true"/g)).toBeNull()
  })

  it('déjà répondu : la réponse est marquée, l’autre reste à portée', async () => {
    const html = await rendre(evenement({ maReponse: 'absent' }))

    expect(html).toContain('Ma réponse')
    expect(html.match(/aria-pressed="true"/g)).toHaveLength(1)
  })

  it('sans réponse possible : pas de boutons', async () => {
    const html = await rendre(evenement({ annule: true, peutRepondre: false }))
    expect(html).not.toContain('aria-label="Ma réponse"')
  })
})
