import { createSSRApp, defineComponent, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FichePersonneComplete } from '@/lib/api'
import ActionsFiche from './ActionsFiche.vue'
import BandeauFiche from './BandeauFiche.vue'
import EditionIdentite from './EditionIdentite.vue'
import EditionParente from './EditionParente.vue'
import ModaleCorrection from './ModaleCorrection.vue'
import SectionFiche from './SectionFiche.vue'
import { cleDeConjoint, entrerEnEdition } from './edition'

/**
 * Le mode édition, **tel qu'il se dessine** — rendu serveur, comme le reste de la
 * suite : les deux icônes qui remplacent les quatre, le violet du bandeau et des
 * panneaux, les champs de saisie, les croix (et l'absence de croix sur la
 * fratrie), et la modale qui récapitule.
 *
 * L'entrée elle-même — le clic sur le crayon — est éprouvée par
 * `edition-session.test.ts`, sur la session que la page tient.
 */

const etat = vi.hoisted(() => ({
  regime: null as unknown as { value: string },
}))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  etat.regime = sr('membre')
  return {
    useSession: () => ({
      regime: etat.regime,
      vueEmpruntee: sr(null),
      connecte: c(() => etat.regime.value !== 'anonyme'),
      rattache: c(() => etat.regime.value === 'membre' || etat.regime.value === 'administrateur'),
      administrateur: c(() => etat.regime.value === 'administrateur'),
    }),
  }
})

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () => h('a', { href: '#', 'data-vers': JSON.stringify(props.to) }, slots['default']?.()),
})

function rendre(composant: Component, props: Record<string, unknown> = {}, slots: Record<string, () => unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant, props, slots) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

function fiche(): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    gedcomXref: null,
    displayName: 'Marne Sureau',
    givenNames: 'Marne',
    surname: 'Sureau',
    marriedName: null,
    nickname: 'Mamie',
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: { raw: '1894', qualifier: 'annee', min: '1894-01-01', max: '1894-12-31', place: 'Saule', texte: '1894' },
    death: null,
    ligneDates: '1894 – ?',
    branch: 'pm',
    generation: -3,
    bestQuay: 2,
    citationCount: 1,
    forcePrivacy: false,
    privacyNote: null,
    version: 8,
    parente: null,
    portrait: null,
    parents: [
      { personId: 30, displayName: 'Galène Bourrache', relation: 'naissance', familyId: 4 },
      { personId: 31, displayName: 'Opale Sureau', relation: 'naissance', familyId: 4 },
    ],
    fratrie: [{ personId: 40, displayName: 'Jaspe Sureau', siblingOrder: 1 }],
    unions: [
      {
        familyId: 9,
        conjoint: { personId: 52, displayName: 'Cobalt Romarin' },
        marriage: null,
        divorced: false,
        divorce: null,
        enfants: [{ personId: 60, displayName: 'Pivoine Romarin' }],
      },
    ],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [],
  }
}

beforeEach(() => {
  etat.regime.value = 'membre'
})

describe('ActionsFiche — quatre icônes, ou deux en édition', () => {
  it('hors édition : poubelle, assistant, document, crayon', async () => {
    const html = await rendre(ActionsFiche, { fiche: fiche() })
    expect(html.match(/data-geste="([a-z-]+)"/g)).toEqual([
      'data-geste="demander-suppression"',
      'data-geste="assistant"',
      'data-geste="joindre-document"',
      'data-geste="editer"',
    ])
  })

  it('en édition : « Annuler » et « Corriger », et plus rien d’autre', async () => {
    const html = await rendre(ActionsFiche, { fiche: fiche(), enEdition: true })
    expect(html.match(/data-geste="([a-z-]+)"/g)).toEqual(['data-geste="annuler-edition"', 'data-geste="corriger"'])
    expect(html).toContain('aria-label="Annuler les corrections"')
    expect(html).toContain('aria-label="Corriger : relire puis envoyer"')
    expect(html).toContain('aria-label="Mode édition"')
  })

  it('pendant l’envoi, les deux sont éteintes', async () => {
    const html = await rendre(ActionsFiche, { fiche: fiche(), enEdition: true, envoi: true })
    // L'attribut, pas la variante `disabled:` des classes.
    expect(html.match(/\sdisabled(?=[\s>=])/g)).toHaveLength(2)
  })

  it('un compte en attente n’a aucune icône', async () => {
    etat.regime.value = 'en_attente'
    expect(await rendre(ActionsFiche, { fiche: fiche() })).not.toContain('data-geste')
  })

  it('sur téléphone, les icônes font 44 px et se répartissent la barre', async () => {
    const html = await rendre(ActionsFiche, { fiche: fiche(), disposition: 'barre' })
    expect(html).toContain('h-11 min-w-11 flex-1')
  })
})

describe('le violet du mode édition', () => {
  it('le bandeau passe au violet, dit qu’on est en édition, et retire les onglets', async () => {
    const html = await rendre(BandeauFiche, { fiche: fiche(), onglet: 'identite', edition: true })
    expect(html).toContain('data-edition')
    expect(html).toContain('bg-edition-doux')
    expect(html).toContain('Mode édition')
    expect(html).not.toContain('aria-label="Sections de la fiche"')
  })

  it('hors édition, le bandeau garde ses onglets et son fond', async () => {
    const html = await rendre(BandeauFiche, { fiche: fiche(), onglet: 'identite' })
    expect(html).not.toContain('data-edition')
    expect(html).toContain('aria-label="Sections de la fiche"')
  })

  it('un panneau en édition porte le liseré et le fond teinté', async () => {
    const html = await rendre(SectionFiche, { titre: 'Identité', edition: true })
    expect(html).toContain('border-l-edition')
    expect(html).toContain('bg-edition-doux')
    expect(html).toContain('data-edition')
  })
})

describe('EditionIdentite — les champs de la fiche, devenus des champs de saisie', () => {
  it('reprend les valeurs validées dans les composants de l’écran de modification', async () => {
    const html = await rendre(EditionIdentite, { fiche: fiche(), modelValue: entrerEnEdition(fiche()).saisie })
    expect(html).toContain('value="Marne"')
    expect(html).toContain('value="Sureau"')
    expect(html).toContain('value="Mamie"')
    for (const etiquette of ['Prénoms', 'Nom', 'Nom d’usage', 'Surnom', 'Sexe', 'Décès attesté', 'Naissance', 'Décès']) {
      expect(html).toContain(etiquette)
    }
    // La date incertaine : sa forme et son lieu se saisissent.
    expect(html).toContain('value="Saule"')
  })
})

describe('EditionParente — une croix par lien, aucune sur la fratrie', () => {
  it('une croix pour chaque parent, le conjoint et l’enfant', async () => {
    const html = await rendre(EditionParente, {
      fiche: fiche(),
      retraits: [],
      ajouts: { parents: [], conjoints: [], enfants: [] },
    })
    expect(html.match(/data-croix="parent"/g)).toHaveLength(2)
    expect(html.match(/data-croix="conjoint"/g)).toHaveLength(1)
    expect(html.match(/data-croix="enfant"/g)).toHaveLength(1)
    expect(html).toContain('aria-label="Retirer Cobalt Romarin comme conjoint"')
    // Le parent barré dit ce qui reste : l'autre parent du couple.
    expect(html).toContain('ne sera plus l’enfant de Galène Bourrache, et restera celui de Opale Sureau')
  })

  it('les frères et sœurs n’ont pas de croix, et l’écran dit pourquoi', async () => {
    const html = await rendre(EditionParente, {
      fiche: fiche(),
      retraits: [],
      ajouts: { parents: [], conjoints: [], enfants: [] },
    })
    expect(html).toContain('Jaspe Sureau')
    expect(html).toContain('data-aide-fratrie')
    expect(html).toContain('se déduisent des parents')
    expect(html).not.toContain('data-croix="fratrie"')
  })

  it('un lien barré se lit barré, et la croix devient « Rétablir »', async () => {
    const html = await rendre(EditionParente, {
      fiche: fiche(),
      retraits: [cleDeConjoint(52, 9)],
      ajouts: { parents: [], conjoints: [], enfants: [] },
    })
    expect(html).toMatch(/line-through[^>]*>\s*Cobalt Romarin/)
    expect(html).toContain('aria-label="Garder Cobalt Romarin comme conjoint"')
    expect(html).toContain('Rétablir')
  })

  it('les ajouts s’affichent, et la recherche d’un troisième parent est éteinte', async () => {
    const html = await rendre(EditionParente, {
      fiche: fiche(),
      retraits: [],
      ajouts: { parents: [], conjoints: [{ personId: 70, libelle: 'Tourmaline Thym' }], enfants: [] },
    })
    expect(html).toContain('Conjoint : Tourmaline Thym')
    expect(html).toContain('Deux parents au plus')
    expect(html).toContain('+ Ajouter un conjoint')
    expect(html).toContain('+ Ajouter un enfant de ce couple')
  })
})

describe('ModaleCorrection — tout ce qui va partir', () => {
  it('récapitule chaque changement, avec un message facultatif et « Confirmer »', async () => {
    const html = await rendre(ModaleCorrection, {
      changements: [
        { nature: 'champ', texte: 'Naissance : 1894 → 18 mai 1893' },
        { nature: 'retrait', texte: 'Lien retiré : enfant de Galène Bourrache' },
        { nature: 'ajout', texte: 'Lien ajouté : conjoint de Tourmaline Thym' },
      ],
      message: '',
    })
    expect(html).toContain('Relire avant d’envoyer')
    expect(html.match(/data-changement="/g)).toHaveLength(3)
    expect(html).toContain('Naissance : 1894 → 18 mai 1893')
    expect(html).toContain('Lien retiré : enfant de Galène Bourrache')
    expect(html).toContain('Lien ajouté : conjoint de Tourmaline Thym')
    expect(html).toContain('(facultatif)')
    expect(html).toContain('data-geste="confirmer"')
  })

  it('rien de changé : pas de « Confirmer »', async () => {
    const html = await rendre(ModaleCorrection, { changements: [], message: '' })
    expect(html).toContain('Rien à corriger')
    expect(html).not.toContain('data-geste="confirmer"')
  })

  it('une erreur de saisie éteint « Confirmer » et se dit', async () => {
    const html = await rendre(ModaleCorrection, {
      changements: [{ nature: 'champ', texte: 'Décès : (vide) → 1960' }],
      message: '',
      erreursSaisie: [{ champ: 'deceased', message: 'Une date de décès est indiquée : cochez « personne décédée ».' }],
    })
    expect(html).toContain('role="alert"')
    expect(/<button[^>]*disabled[^>]*data-geste="confirmer"|data-geste="confirmer"[^>]*disabled/.test(html)).toBe(true)
  })
})
