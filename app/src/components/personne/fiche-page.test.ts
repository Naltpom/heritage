import { createSSRApp, defineComponent, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FichePersonneComplete, FichePersonneRestreinte } from '@/lib/api'
import { ongletDepuisRequete, ongletsDeFiche, requeteDOnglet } from './onglets'
import CarteRestreinte from './CarteRestreinte.vue'
import PageFiche from './PageFiche.vue'
import PersonneView from '@/views/PersonneView.vue'

/**
 * La page fiche refondue — ce que le dossier de refonte demande d'éprouver :
 *
 *  - l'onglet par défaut est Identité ;
 *  - `?onglet=famille` ouvre Famille — prouvé **sur la vue**, avec une route
 *    simulée, parce que c'est elle qui lit la requête ;
 *  - une fiche réduite n'affiche ni onglets ni barre d'action ;
 *  - la barre d'action mobile se remplit par ce qui est permis, et la
 *    suppression n'y est jamais à côté de « Proposer une modification ».
 *
 * (Le calque « à côté » de la valeur validée est éprouvé dans
 * `fiche-rendu.test.ts`, sur `IdentiteFiche`.)
 *
 * Rendu SSR, comme le reste de la suite : pas de DOM, et `onMounted` ne
 * s'exécute jamais. On lit le balisage.
 */

const etat = vi.hoisted(() => ({
  regime: null as unknown as { value: string },
  vueEmpruntee: null as unknown as { value: { memberId: number; memberLabel: string } | null },
  route: null as unknown as { params: Record<string, string>; query: Record<string, string> },
  fiche: null as unknown as { value: unknown },
}))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  etat.regime = sr('membre')
  etat.vueEmpruntee = sr(null)
  return {
    useSession: () => ({
      regime: etat.regime,
      vueEmpruntee: etat.vueEmpruntee,
      connecte: c(() => etat.regime.value !== 'anonyme'),
      rattache: c(() => etat.regime.value === 'membre' || etat.regime.value === 'administrateur'),
      administrateur: c(() => etat.regime.value === 'administrateur'),
    }),
  }
})

vi.mock('vue-router', async () => {
  const { reactive: r } = await import('vue')
  etat.route = r({ params: { id: '17' }, query: {} })
  return { useRoute: () => etat.route, useRouter: () => ({ push: () => Promise.resolve() }) }
})

/* La vue charge sa fiche par `useAppelApi` : on lui rend une charge déjà là,
   puisque le rendu serveur n'attend pas la promesse. */
vi.mock('@/composables/useAppelApi', async () => {
  const { shallowRef: sr } = await import('vue')
  etat.fiche = sr(null)
  return {
    useAppelApi: () => ({
      donnees: etat.fiche,
      erreur: sr(null),
      chargement: sr(false),
      executer: () => Promise.resolve(null),
    }),
  }
})

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

function rendre(composant: Component, props: Record<string, unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant, props) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

function ficheComplete(surcharge: Partial<FichePersonneComplete> = {}): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    gedcomXref: '@I500017@',
    displayName: 'Marne Dolomite Sureau',
    givenNames: 'Marne Dolomite',
    surname: 'Bourrache',
    marriedName: 'Sarriette',
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: {
      raw: 'BET 1901 AND 1909',
      qualifier: 'entre',
      min: '1901-01-01',
      max: '1909-12-31',
      place: 'Saule, Ombrage, Sylvanie',
      texte: 'entre 1901 et 1909',
    },
    death: null,
    ligneDates: '1901-1909 – 1987',
    branch: 'pm',
    generation: -3,
    bestQuay: 2,
    citationCount: 6,
    forcePrivacy: false,
    privacyNote: null,
    version: 8,
    parente: 'arrière-grand-mère',
    portrait: null,
    parents: [{ personId: 30, displayName: 'Galène Bourrache', relation: 'naissance', familyId: 4 }],
    fratrie: [],
    unions: [],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [],
    ...surcharge,
  }
}

const RESTREINTE: FichePersonneRestreinte = {
  id: 202,
  displayName: 'Pyroxène Tilleul',
  sex: 'masculin',
  generation: -2,
  branch: 'mm',
  vivant: true,
  visibilite: 'restreinte',
}

/** L'onglet marqué courant dans la rangée. */
function ongletCourant(html: string): string | null {
  const trouve = /<a[^>]*aria-current="page"[^>]*data-onglet="([a-z]+)"/.exec(html)
    ?? /<a[^>]*data-onglet="([a-z]+)"[^>]*aria-current="page"/.exec(html)
  return trouve?.[1] ?? null
}

beforeEach(() => {
  etat.regime.value = 'membre'
  etat.vueEmpruntee.value = null
  etat.route.query = {}
  etat.fiche.value = null
})

/* ------------------------------------------------------------------ */

describe('onglets.ts — l’onglet voyage dans la requête', () => {
  it('ouvre Identité par défaut, et pour toute valeur inconnue', () => {
    const fiche = ficheComplete()
    expect(ongletDepuisRequete(undefined, fiche)).toBe('identite')
    expect(ongletDepuisRequete('n-importe-quoi', fiche)).toBe('identite')
    expect(ongletDepuisRequete(['famille', 'sources'], fiche)).toBe('famille')
  })

  it('n’ouvre Demandes que si la fiche en porte', () => {
    expect(ongletDepuisRequete('demandes', ficheComplete())).toBe('identite')
    expect(ongletDepuisRequete('demandes', ficheComplete({ demandesEnAttente: [12] }))).toBe(
      'demandes',
    )
    expect(ongletsDeFiche(ficheComplete()).map((o) => o.cle)).not.toContain('demandes')
    expect(ongletsDeFiche(ficheComplete({ demandesEnAttente: [12, 14] })).at(-1)).toMatchObject({
      cle: 'demandes',
      compte: 2,
    })
  })

  it('Identité n’a pas de clé : l’URL nue reste l’URL canonique', () => {
    expect(requeteDOnglet('identite')).toEqual({})
    expect(requeteDOnglet('famille')).toEqual({ onglet: 'famille' })
  })
})

describe('La vue — l’onglet par défaut et `?onglet=famille`', () => {
  it('ouvre Identité quand la requête ne dit rien', async () => {
    etat.fiche.value = ficheComplete()
    const html = await rendre(PersonneView)

    expect(ongletCourant(html)).toBe('identite')
    expect(html).toContain('data-panneau="identite"')
    expect(html).not.toContain('data-panneau="famille"')
    // La fourchette arrive telle quelle jusqu'au HTML.
    expect(html).toContain('entre 1901 et 1909')
  })

  it('ouvre Famille sur `?onglet=famille`', async () => {
    etat.fiche.value = ficheComplete()
    etat.route.query = { onglet: 'famille' }
    const html = await rendre(PersonneView)

    expect(ongletCourant(html)).toBe('famille')
    expect(html).toContain('data-panneau="famille"')
    expect(html).toContain('Galène Bourrache')
    expect(html).not.toContain('data-panneau="identite"')
  })

  it('rend la carte réduite pour une fiche réduite, pas une erreur', async () => {
    etat.fiche.value = RESTREINTE
    const html = await rendre(PersonneView)
    expect(html).toContain('data-fiche-reduite')
    expect(html).not.toContain('aria-label="Sections de la fiche"')
  })
})

describe('PageFiche — bandeau, onglets, barre d’action', () => {
  it('affiche la ligne de dates du serveur, sans la recomposer', async () => {
    const html = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(html).toContain('1901-1909 – 1987')
    expect(html).toContain('arrière-grand-mère')
  })

  it('« Vérifié » ne s’écrit que sur un acte (bestQuay ≥ 2)', async () => {
    const verifie = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(verifie).toContain('Vérifié')

    const recopie = await rendre(PageFiche, {
      fiche: ficheComplete({ bestQuay: 0, citationCount: 3 }),
      onglet: 'identite',
    })
    expect(recopie).not.toMatch(/>Vérifié</)
    expect(recopie).toContain('Non vérifié')
  })

  it('compte les sources et les documents que la charge porte', async () => {
    const html = await rendre(PageFiche, {
      fiche: ficheComplete({
        notes: [{ id: 1, scope: 'personne', body: 'Note', authorLabel: null, createdAt: '2026-01-01' }],
        demandesEnAttente: [12, 14],
      }),
      onglet: 'identite',
    })
    expect(html).toContain('Sources et notes')
    expect(html).toContain('Preuves et documents')
    expect(html).toContain('data-onglet="demandes"')
    expect(html).toContain('2 demandes en attente')
  })

  it('la barre d’action se remplit par ce qui est permis', async () => {
    const membre = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(membre).toContain('data-barre-action')
    expect(membre).toContain('data-geste="proposer-modification"')

    // Compte en attente : aucun geste n'aboutirait, pas de barre du tout.
    etat.regime.value = 'en_attente'
    const attente = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(attente).not.toContain('data-barre-action')
    expect(attente).not.toContain('data-geste="proposer-modification"')
    // Mais la fiche, elle, s'affiche entière : le rôle ne choisit pas l'affichage.
    expect(attente).toContain('entre 1901 et 1909')

    // Sous « voir comme un membre », toute écriture rend 409.
    etat.regime.value = 'administrateur'
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin' }
    const empruntee = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(empruntee).not.toContain('data-barre-action')
  })

  it('la suppression n’est pas dans le bloc de « Proposer une modification »', async () => {
    // Le menu est fermé au rendu : la suppression n'est même pas dans le HTML,
    // et en aucun cas voisine du bouton principal.
    const html = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(html).not.toContain('data-geste="demander-suppression"')
    expect(html).toContain('aria-haspopup="menu"')
  })
})

describe('CarteRestreinte — ni onglets, ni barre d’action', () => {
  it('rend le nom, la phrase mot pour mot, Sexe et Génération seulement', async () => {
    const html = await rendre(CarteRestreinte, { fiche: RESTREINTE })

    expect(html).toContain('Pyroxène Tilleul')
    expect(html).toContain(
      '<b>Personne vivante.</b> Elle ne fait pas partie de votre cercle de visibilité : ni dates, ni lieux, ni sources, ni photos ne vous sont communiqués. Sa place dans l’arbre reste visible.',
    )
    expect(html).toContain('Sexe')
    expect(html).toContain('Génération')
    for (const interdit of ['Naissance', 'Décès', 'Branche', 'Prénoms', 'Référence GEDCOM']) {
      expect(html).not.toContain(interdit)
    }
    expect(html).toContain('Voir dans l’arbre')
  })

  it('n’a ni onglets, ni barre d’action, ni geste', async () => {
    const html = await rendre(CarteRestreinte, { fiche: RESTREINTE })
    expect(html).not.toContain('Sections de la fiche')
    expect(html).not.toContain('data-onglet')
    expect(html).not.toContain('data-barre-action')
    expect(html).not.toContain('data-geste=')
    expect(html).not.toContain('aria-haspopup="menu"')
  })

  it('ne dit pas « votre cercle » à un compte en attente, qui n’en a pas', async () => {
    etat.regime.value = 'en_attente'
    const html = await rendre(CarteRestreinte, { fiche: RESTREINTE })
    expect(html).toContain('Personne vivante.')
    expect(html).not.toContain('votre cercle')
  })
})

describe('le glissement entre onglets (téléphone)', () => {
  it('ne prend qu’un geste franc et horizontal', async () => {
    const { sensDuGlissement } = await import('./onglets')
    expect(sensDuGlissement(-80, 10)).toBe(1)
    expect(sensDuGlissement(90, -20)).toBe(-1)
    expect(sensDuGlissement(-40, 0)).toBe(0)
    // Un défilement vertical un peu de biais ne change pas d'onglet.
    expect(sensDuGlissement(-70, 60)).toBe(0)
  })

  it('passe à l’onglet voisin, sans boucler aux extrémités', async () => {
    const { ongletVoisin } = await import('./onglets')
    const rangee = [
      { cle: 'identite' as const, libelle: '', libelleCourt: '', compte: null },
      { cle: 'famille' as const, libelle: '', libelleCourt: '', compte: null },
      { cle: 'sources' as const, libelle: '', libelleCourt: '', compte: 0 },
    ]
    expect(ongletVoisin(rangee, 'identite', 1)).toBe('famille')
    expect(ongletVoisin(rangee, 'famille', -1)).toBe('identite')
    expect(ongletVoisin(rangee, 'identite', -1)).toBeNull()
    expect(ongletVoisin(rangee, 'sources', 1)).toBeNull()
  })
})
