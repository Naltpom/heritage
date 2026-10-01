import { createSSRApp, defineComponent, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FichePersonneComplete, FichePersonneRestreinte } from '@/lib/api'
import { ongletDepuisRequete, ongletsDeFiche, requeteDOnglet } from './onglets'
import CarteRestreinte from './CarteRestreinte.vue'
import PageFiche from './PageFiche.vue'
import PersonneView from '@/views/PersonneView.vue'

/**
 * La page fiche refondue — ce que la refonte du 30/09/2026 demande d'éprouver :
 *
 *  - trois onglets, toujours, dans cet ordre : Identité et famille, Sources et
 *    documents, Historique — ce dernier avec ses demandes en attente en tête,
 *    puis la frise de ce qui a été ajouté ou changé ; les anciennes clés
 *    (`famille`, `documents`, `demandes`) restent acceptées ;
 *  - l'onglet par défaut est Identité, et `?onglet=` est lu **sur la vue**, avec
 *    une route simulée, parce que c'est elle qui lit la requête ;
 *  - une fiche réduite n'affiche ni onglets ni barre d'action ;
 *  - un seul bouton « Proposer », dont le menu mène à l'assistant, au dépôt et,
 *    derrière un filet, à la demande de suppression ; la barre d'action mobile
 *    porte le même bouton, et se remplit par ce qui est permis.
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
  return {
    useRoute: () => etat.route,
    useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
  }
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

/* L'ancre factice écrit sa cible dans `data-vers` : c'est ce qui permet de
   vérifier où mène chaque entrée du menu « Proposer ». */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () =>
    h('a', { href: '#', 'data-vers': JSON.stringify(props.to) }, slots['default']?.()),
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

const NOTE = { id: 1, scope: 'personne', body: 'Note', authorLabel: null, createdAt: '2026-01-01' } as const

/** L'onglet marqué courant dans la rangée. */
function ongletCourant(html: string): string | null {
  const trouve = /<a[^>]*aria-current="page"[^>]*data-onglet="([a-z]+)"/.exec(html)
    ?? /<a[^>]*data-onglet="([a-z]+)"[^>]*aria-current="page"/.exec(html)
  return trouve?.[1] ?? null
}

/** La cible (`to`) du premier lien qui porte ce `data-geste`. */
function cibleDuGeste(html: string, geste: string): unknown {
  const trouve = new RegExp(`<a[^>]*data-vers="([^"]*)"[^>]*data-geste="${geste}"`).exec(html)
  if (!trouve?.[1]) return null
  return JSON.parse(trouve[1].replaceAll('&quot;', '"'))
}

beforeEach(() => {
  etat.regime.value = 'membre'
  etat.vueEmpruntee.value = null
  etat.route.query = {}
  etat.fiche.value = null
})

/* ------------------------------------------------------------------ */

describe('onglets.ts — trois onglets, et l’onglet voyage dans la requête', () => {
  it('toujours trois onglets, dans l’ordre, avec leurs libellés courts', () => {
    for (const fiche of [ficheComplete(), ficheComplete({ demandesEnAttente: [12] })]) {
      expect(ongletsDeFiche(fiche).map((o) => [o.cle, o.libelle, o.libelleCourt])).toEqual([
        ['identite', 'Identité et famille', 'Identité'],
        ['sources', 'Sources et documents', 'Sources'],
        ['historique', 'Historique', 'Historique'],
      ])
    }
  })

  it('Sources compte les sources, les notes et les documents que la charge porte', () => {
    const fiche = ficheComplete({ notes: [NOTE], medias: [{} as never, {} as never] })
    expect(ongletsDeFiche(fiche).find((o) => o.cle === 'sources')?.compte).toBe(3)
    expect(ongletsDeFiche(fiche)[0]?.compte).toBeNull()
  })

  it('ouvre Identité par défaut, et pour toute valeur inconnue', () => {
    expect(ongletDepuisRequete(undefined)).toBe('identite')
    expect(ongletDepuisRequete('n-importe-quoi')).toBe('identite')
    // Une clé héritée d'`Object.prototype` n'est pas une ancienne clé.
    expect(ongletDepuisRequete('constructor')).toBe('identite')
    expect(ongletDepuisRequete(['sources', 'identite'])).toBe('sources')
  })

  it('accepte encore les anciennes clés des liens déjà envoyés', () => {
    expect(ongletDepuisRequete('famille')).toBe('identite')
    expect(ongletDepuisRequete('documents')).toBe('sources')
    expect(ongletDepuisRequete('demandes')).toBe('historique')
  })

  it('ouvre Historique même sans demande en attente ; son compteur est celui des demandes', () => {
    expect(ongletDepuisRequete('historique')).toBe('historique')
    expect(ongletDepuisRequete('demandes')).toBe('historique')
    expect(ongletsDeFiche(ficheComplete()).at(-1)).toMatchObject({ cle: 'historique', compte: null })
    expect(ongletsDeFiche(ficheComplete({ demandesEnAttente: [12, 14] })).at(-1)).toMatchObject({
      cle: 'historique',
      compte: 2,
    })
  })

  it('Identité n’a pas de clé : l’URL nue reste l’URL canonique', () => {
    expect(requeteDOnglet('identite')).toEqual({})
    expect(requeteDOnglet('sources')).toEqual({ onglet: 'sources' })
  })
})

describe('La vue — l’onglet par défaut et `?onglet=`', () => {
  it('ouvre Identité et famille quand la requête ne dit rien', async () => {
    etat.fiche.value = ficheComplete()
    const html = await rendre(PersonneView)

    expect(ongletCourant(html)).toBe('identite')
    // La famille vit désormais dans le même onglet que l'identité.
    expect(html).toContain('data-panneau="identite"')
    expect(html).toContain('data-panneau="famille"')
    expect(html).toContain('Galène Bourrache')
    expect(html).not.toContain('data-panneau="sources"')
    // La fourchette arrive telle quelle jusqu'au HTML.
    expect(html).toContain('entre 1901 et 1909')
  })

  it('l’ancien `?onglet=famille` ouvre Identité et famille', async () => {
    etat.fiche.value = ficheComplete()
    etat.route.query = { onglet: 'famille' }
    const html = await rendre(PersonneView)

    expect(ongletCourant(html)).toBe('identite')
    expect(html).toContain('Galène Bourrache')
  })

  it('l’ancien `?onglet=documents` ouvre Sources et documents : sources, galerie, actes', async () => {
    etat.fiche.value = ficheComplete()
    etat.route.query = { onglet: 'documents' }
    const html = await rendre(PersonneView)

    expect(ongletCourant(html)).toBe('sources')
    expect(html).toContain('data-panneau="sources"')
    expect(html).toContain('data-panneau="documents"')
    expect(html).toContain('id="depot"')
    expect(html).toContain('data-panneau="actes"')
    expect(html).not.toContain('data-panneau="identite"')
  })

  it('`?onglet=historique` liste les demandes en attente, puis la frise', async () => {
    etat.fiche.value = ficheComplete({ demandesEnAttente: [12, 14] })
    etat.route.query = { onglet: 'historique' }
    const html = await rendre(PersonneView)

    expect(ongletCourant(html)).toBe('historique')
    expect(html).toContain('data-panneau="demandes"')
    expect(html).toContain('data-panneau="historique"')
    expect(html).toContain('Demande n° 14')
    // Les demandes en attente viennent en tête du panneau.
    expect(html.indexOf('data-panneau="demandes"')).toBeLessThan(html.indexOf('data-panneau="historique"'))
  })

  it('`?onglet=historique` sans demande en attente ouvre la frise, sans bloc vide', async () => {
    etat.fiche.value = ficheComplete({
      notes: [{ ...NOTE, createdAt: '2026-09-13T21:13:00+02:00', body: 'Recopiée de l’acte.' }],
    })
    etat.route.query = { onglet: 'historique' }
    const html = await rendre(PersonneView)

    expect(ongletCourant(html)).toBe('historique')
    expect(html).not.toContain('data-panneau="demandes"')
    expect(html).toContain('data-panneau="historique"')
    expect(html).toContain('data-entree="note"')
    expect(html).toContain('Recopiée de l’acte.')
  })

  it('rend la carte réduite pour une fiche réduite, pas une erreur', async () => {
    etat.fiche.value = RESTREINTE
    const html = await rendre(PersonneView)
    expect(html).toContain('data-fiche-reduite')
    expect(html).not.toContain('aria-label="Sections de la fiche"')
  })
})

describe('PageFiche — bandeau, onglets, bouton Proposer', () => {
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

  it('écrit les trois libellés, longs et courts, avec les compteurs', async () => {
    const html = await rendre(PageFiche, {
      fiche: ficheComplete({ notes: [NOTE], demandesEnAttente: [12, 14] }),
      onglet: 'identite',
    })
    expect(html).toContain('aria-label="Sections de la fiche"')
    for (const libelle of ['Identité et famille', 'Sources et documents', 'Historique']) {
      expect(html).toContain(libelle)
    }
    expect(html).toContain('>Identité<')
    expect(html).toContain('>Sources<')
    for (const ancien of ['famille', 'documents', 'demandes']) {
      expect(html).not.toContain(`data-onglet="${ancien}"`)
    }
    expect(html).toContain('data-onglet="historique"')
    expect(html).toContain('2 demandes en attente')
  })

  it('l’onglet Historique est dans la rangée même sans demande en attente', async () => {
    const html = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(html).toContain('data-onglet="historique"')
    // Ni bandeau mobile « … en attente sur cette fiche », ni encart latéral.
    expect(html).not.toContain('en attente sur cette fiche')
    expect(html.replace(/<!--.*?-->/gs, '')).not.toMatch(/demandes? en attente/)
  })

  it('un seul bouton « Proposer » : plus de « Proposer une modification » ni de ⋯', async () => {
    const html = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(html).not.toContain('Proposer une modification')
    expect(html).not.toContain('⋯')
    // Le bandeau desktop et la barre mobile portent le même bouton — l'un est
    // caché sous `md`, l'autre au-dessus — et il n'y en a pas de troisième.
    expect(html.match(/data-geste="proposer"/g)).toHaveLength(2)
  })

  it('le menu Proposer mène à l’assistant, au dépôt et à la suppression', async () => {
    const html = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    for (const libelle of [
      'Corriger ou ajouter un proche',
      'Joindre un document',
      'Demander la suppression',
    ]) {
      expect(html).toContain(libelle)
    }

    const assistant = { name: 'proposition.creation', query: { personne: '17' } }
    expect(cibleDuGeste(html, 'corriger')).toEqual(assistant)
    // Une seule entrée vers l'assistant : deux libellés pour une même porte, non.
    expect(html).not.toContain('data-geste="ajouter-proche"')
    expect(cibleDuGeste(html, 'joindre-document')).toEqual({
      name: 'personne.detail',
      params: { id: 17 },
      query: { onglet: 'sources' },
      hash: '#depot',
    })
    expect(cibleDuGeste(html, 'demander-suppression')).toEqual({
      name: 'proposition.suppression',
      params: { id: 17 },
    })
  })

  it('le menu est fermé au rendu, et la suppression vient en dernier, derrière un filet', async () => {
    const html = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(html).toContain('aria-expanded="false"')
    const menu = /<div[^>]*role="menu"[^>]*>/.exec(html)?.[0] ?? ''
    expect(menu).toContain('display:none')

    const contenu = html.slice(html.indexOf('role="menu"'))
    const suppression = contenu.indexOf('data-geste="demander-suppression"')
    expect(suppression).toBeGreaterThan(contenu.indexOf('data-geste="joindre-document"'))
    expect(suppression).toBeGreaterThan(contenu.indexOf('data-geste="corriger"'))
  })

  it('la barre d’action et le bouton se remplissent par ce qui est permis', async () => {
    const membre = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(membre).toContain('data-barre-action')

    // Compte en attente : aucun geste n'aboutirait, ni barre ni bouton.
    etat.regime.value = 'en_attente'
    const attente = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(attente).not.toContain('data-barre-action')
    expect(attente).not.toContain('data-geste=')
    // Mais la fiche, elle, s'affiche entière : le rôle ne choisit pas l'affichage.
    expect(attente).toContain('entre 1901 et 1909')

    // Sous « voir comme un membre », toute écriture rend 409.
    etat.regime.value = 'administrateur'
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin' }
    const empruntee = await rendre(PageFiche, { fiche: ficheComplete(), onglet: 'identite' })
    expect(empruntee).not.toContain('data-barre-action')
    expect(empruntee).not.toContain('data-geste="proposer"')
  })
})

describe('CarteRestreinte — ni onglets, ni barre d’action', () => {
  it('rend « Personne vivante », la phrase mot pour mot, Sexe et Génération seulement', async () => {
    const html = await rendre(CarteRestreinte, { fiche: RESTREINTE })

    // Révision du 2026-10-01 (ADR 0003) : plus de nom. Même face à un serveur antérieur
    // qui l'enverrait encore, la carte écrit le libellé neutre, jamais `displayName`.
    expect(html).toContain('>Personne vivante<')
    // Le prénom seul : « Tilleul » est aussi le nom d'une branche (`champs.ts`).
    expect(html).not.toContain('Pyroxène')
    expect(html).toContain(
      '<b>Fiche confidentielle.</b> Sa diffusion est restreinte par décision d’administration : ni dates, ni lieux, ni sources, ni photos ne vous sont communiqués. Sa place dans l’arbre reste visible.',
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
      { cle: 'sources' as const, libelle: '', libelleCourt: '', compte: 0 },
      { cle: 'historique' as const, libelle: '', libelleCourt: '', compte: 1 },
    ]
    expect(ongletVoisin(rangee, 'identite', 1)).toBe('sources')
    expect(ongletVoisin(rangee, 'historique', -1)).toBe('sources')
    expect(ongletVoisin(rangee, 'identite', -1)).toBeNull()
    expect(ongletVoisin(rangee, 'historique', 1)).toBeNull()
  })
})
