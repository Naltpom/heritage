import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeAll, describe, expect, it } from 'vitest'
import type { RessemblanceDansLArbre, RessemblancePropositionComplete, RessemblancePropositionDiscrete } from '@/lib/api'
import { creationVide } from './creation'

/**
 * Le panneau « Est-ce déjà… ? », rendu côté serveur.
 *
 * Ce qui se voit et que la logique seule ne prouve pas :
 *
 * 1. **Une proposition discrète n'affiche que sa phrase** — ni nom, ni date, ni
 *    lieu, ni auteur, ni numéro de demande, ni lien : il n'y en a pas dans la
 *    charge, et l'écran n'en invente pas (décision du 09/10/2026) ;
 * 2. **les raisons du serveur s'affichent telles quelles**, en pastilles ;
 * 3. **les boutons de chaque carte**, et « Voir » qui, sur la page, ouvre un
 *    nouvel onglet — la saisie reste là — et, dans le volet, montre l'arbre ;
 * 4. **le rappel avant l'envoi**.
 */

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () =>
    h('a', { href: typeof props.to === 'string' ? props.to : JSON.stringify(props.to) }, slots['default']?.()),
})

const PERSONNE: RessemblanceDansLArbre = {
  personId: 233,
  displayName: 'Pyroxène Tilleul',
  sex: 'masculin',
  vivant: true,
  generation: -2,
  branch: 'mm',
  ligneDates: '—',
  lieuNaissance: 'Sorbière, Fougerie, Florie',
  criteres: ['nom', 'prenom', 'sexe', 'lieu'],
  raisons: ['nom proche : Tilleule / Tilleul', 'prénom Pyroxène en commun', 'même sexe', 'même lieu de naissance : Sorbière'],
  score: 4,
}

const COMPLETE: RessemblancePropositionComplete = {
  forme: 'complete',
  draftPersonId: 7,
  demandeId: 12,
  localRef: '#p1',
  noeudId: '#12/p1',
  displayName: 'Barnabé Muguet',
  sex: 'masculin',
  deceased: true,
  ligneDates: '1901 – 1968',
  authorLabel: 'Cobalt Romarin',
  enAttente: true,
  lieuNaissance: 'Saule, Ombrage, Sylvanie',
  statut: 'en_attente',
  action: 'completer',
  criteres: ['nom', 'prenom', 'sexe', 'naissance'],
  raisons: ['même nom', 'prénom Barnabé en commun', 'même sexe', 'né en 1901, comme dans la fiche'],
  score: 4,
}

const DISCRETE: RessemblancePropositionDiscrete = {
  forme: 'discrete',
  draftPersonId: 9,
  action: 'complement',
  criteres: ['nom', 'prenom', 'sexe'],
  raisons: ['nom semblable', 'un prénom en commun', 'même sexe'],
}

async function rendre(props: Record<string, unknown>): Promise<string> {
  const { default: Panneau } = await import('./PanneauRessemblances.vue')
  const app = createSSRApp({
    render: () =>
      h(Panneau, {
        arbre: [],
        propositions: [],
        contexte: 'page',
        saisie: creationVide(),
        message: '',
        photos: [],
        ...props,
      }),
  })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

/** Le texte visible, balises retirées. */
function texte(html: string): string {
  return html.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ')
}

describe('le panneau « Est-ce déjà… ? »', () => {
  beforeAll(async () => {
    await import('./PanneauRessemblances.vue')
  }, 30_000)

  it('une proposition discrète : la phrase et les raisons génériques, rien d’autre', async () => {
    const html = await rendre({ propositions: [DISCRETE] })
    const lu = texte(html)

    expect(lu).toContain('Une proposition semblable attend déjà la validation.')
    for (const raison of DISCRETE.raisons) expect(lu).toContain(raison)
    // Ni nom, ni date, ni lieu, ni auteur, ni demande, ni nœud.
    expect(lu).not.toContain('Proposée par')
    expect(lu).not.toContain('Votre proposition')
    expect(lu).not.toContain('Lieu de naissance')
    expect(lu).not.toContain('Voir la demande')
    expect(lu).not.toMatch(/\d{4}/)
    expect(html).not.toContain('demande.detail')
    expect(html).not.toContain('data-etat')
    expect(html).toContain('data-forme="discrete"')
    // On peut quand même l'écarter.
    expect(lu).toContain('Ce n’est pas elle')
  })

  it('une proposition en clair : nom, dates, lieu, auteur, statut, et la phrase sans promesse', async () => {
    const lu = texte(await rendre({ propositions: [COMPLETE] }))

    expect(lu).toContain('Barnabé Muguet')
    expect(lu).toContain('1901 – 1968')
    expect(lu).toContain('Lieu de naissance : Saule, Ombrage, Sylvanie')
    expect(lu).toContain('Proposée par Cobalt Romarin')
    expect(lu).toContain('En attente de validation')
    expect(lu).toContain('Une proposition semblable est déjà en attente : vérifiez avant d’envoyer la vôtre.')
    expect(lu).toContain('Voir la demande n° 12')
    for (const raison of COMPLETE.raisons) expect(lu).toContain(raison)
  })

  it('dit « votre proposition », et ne mène pas à une demande que le lecteur ne peut pas lire', async () => {
    const sienne = texte(await rendre({ propositions: [COMPLETE], mesDemandes: [12] }))
    expect(sienne).toContain('Votre proposition')

    const dUnAutre = texte(await rendre({ propositions: [{ ...COMPLETE, action: 'complement', noeudId: null }] }))
    expect(dUnAutre).toContain('Proposée par Cobalt Romarin')
    expect(dUnAutre).not.toContain('Voir la demande')

    const brouillon = texte(await rendre({ propositions: [{ ...COMPLETE, statut: 'brouillon', enAttente: false }] }))
    expect(brouillon).toContain('Brouillon non envoyé')
    expect(brouillon).toContain('Votre proposition')
  })

  it('une personne de l’arbre : nom, dates, lieu, raisons en pastilles et ses trois gestes', async () => {
    const html = await rendre({ arbre: [PERSONNE] })
    const lu = texte(html)

    expect(lu).toContain('Pyroxène Tilleul')
    expect(lu).toContain('Lieu de naissance : Sorbière, Fougerie, Florie')
    expect(html.match(/data-raison/g)).toHaveLength(4)
    expect(lu).toContain('C’est elle : compléter sa fiche')
    expect(lu).toContain('Ce n’est pas elle')
    expect(html).toContain('data-forte="true"')
  })

  it('sur la page, « Voir » ouvre sa fiche dans un nouvel onglet', async () => {
    const html = await rendre({ arbre: [PERSONNE] })
    const lien = /<a[^>]*personne\.detail[^>]*>/.exec(html)?.[0] ?? ''

    expect(lien).toContain('target="_blank"')
    expect(lien).toContain('rel="noopener"')
    expect(texte(html)).not.toContain('Voir dans l’arbre')
  })

  it('dans le volet, « Voir dans l’arbre » : la personne, et la proposition qui a un nœud', async () => {
    const lu = texte(await rendre({ arbre: [PERSONNE], propositions: [COMPLETE, DISCRETE], contexte: 'volet' }))

    expect(lu.match(/Voir dans l’arbre/g)).toHaveLength(2)
  })

  it('le rappel avant l’envoi', async () => {
    const sans = texte(await rendre({ arbre: [PERSONNE] }))
    expect(sans).not.toContain('Vérifiez d’abord ces personnes.')

    const html = await rendre({ arbre: [PERSONNE], rappel: true })
    expect(texte(html)).toContain('Vérifiez d’abord ces personnes.')
    expect(texte(html)).toContain('Envoyer quand même')
    expect(html).toMatch(/<div[^>]*role="alert"[^>]*data-rappel/)
  })
})
