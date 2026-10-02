/**
 * Le prochain événement sur l'accueil, rendu côté serveur (donc sans geste).
 *
 * 1. **Pas encore répondu** : « Venez-vous ? » et les deux boutons, sur la carte.
 * 2. **Déjà répondu** : la réponse marquée, l'autre bouton à portée.
 * 3. **Annulé** : plus de boutons — mais la carte n'apparaît alors pas sur
 *    l'accueil (`mesEvenements`), ce cas protège un écran qui l'oublierait.
 * 4. **Dates changées** : le bandeau, et la réponse marquée se confirme.
 * 5. **Réponse notée** par l'organisateur : la carte le dit.
 * 6. **Organisé sans y être invité** : « Vous organisez », pas de boutons.
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
    maReponseNoteePar: null,
    reponseAConfirmer: false,
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

    expect(html).toContain('Rassemblement · Invitation · dans 16 jours')
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

  it('dates changées depuis la réponse : le bandeau le dit', async () => {
    const html = await rendre(evenement({ maReponse: 'present', reponseAConfirmer: true }))
    expect(html).toContain('Les dates ont changé : confirmez votre réponse.')
    expect(html.match(/aria-pressed="true"/g)).toHaveLength(1)
  })

  it('réponse notée par l’organisateur : la carte le dit', async () => {
    const html = await rendre(evenement({ maReponse: 'present', maReponseNoteePar: 'Opale Tilleul' }))
    expect(html).toContain('Réponse notée par Opale Tilleul.')
  })

  it('organisé sans y être invité : « Vous organisez », sans boutons', async () => {
    const html = await rendre(evenement({ suisInvite: false, peutRepondre: false, organisateur: { libelle: 'Moi', estMoi: true } }))
    expect(html).toContain('Vous organisez')
    expect(html).not.toContain('aria-label="Ma réponse"')
  })

  it('sans réponse possible : pas de boutons', async () => {
    const html = await rendre(evenement({ annule: true, peutRepondre: false }))
    expect(html).not.toContain('aria-label="Ma réponse"')
  })
})
