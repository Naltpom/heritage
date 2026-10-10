/**
 * `/demandes/{id}` — « Voir la fiche » et « Annuler ma demande », **montés et
 * cliqués**.
 *
 * Les autres tests de rendu passent par le SSR, qui ne sait ni attendre une
 * lecture ni cliquer. Or les deux gestes de ce lot n'existent qu'après une
 * lecture (la demande) et ne se prouvent qu'au clic (la fiche s'ouvre, la
 * demande passe à « Annulée »). On monte donc la vue dans un **rendu en
 * mémoire** — `createRenderer` de Vue, sur des nœuds de quelques lignes —, sans
 * DOM ni dépendance de test supplémentaire. La chaîne qu'on lit à la fin est la
 * même forme que celle du SSR (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ces tests tiennent :
 *
 * 1. **le bouton « Voir la fiche » n'existe que si la demande désigne une
 *    fiche**, et il ouvre la **même** fiche en colonne (`max-md:hidden`) et en
 *    modale (`md:hidden`) — une seule lecture ;
 * 2. une création **en attente** montre la fiche du parent ou du conjoint ;
 * 3. **« Annuler ma demande »** : visible pour l'auteur seulement, en attente ou
 *    en brouillon seulement ; confirmé avant l'envoi ; la pastille dit ensuite
 *    « Annulée » ; un `409` relit la demande et le dit ; sous la vue empruntée,
 *    le bouton est éteint et dit pourquoi ;
 * 4. **compléter, et fusionner les compléments** (ADR 0017) : « Compléter cette
 *    proposition » mène au formulaire ; la fusion est **réservée à
 *    l'administration**, confirmée, envoyée avec les deux empreintes et le choix
 *    des conflits ; l'auteur d'un complément voit le bandeau, sans geste.
 */
import { defineComponent, h } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Demande, FichePersonneComplete } from '@/lib/api'
import { attendre, cliquer, monterEnMemoire, parAttribut, boutonNomme, texte, un, versHtml, type Noeud } from '@/test-utils/rendu-memoire'

/* ------------------------------------------------------------------ */
/* Les dépendances de la vue                                           */
/* ------------------------------------------------------------------ */

const etat = vi.hoisted(() => ({
  session: null as unknown as { value: unknown },
  administrateur: null as unknown as { value: boolean },
  vueEmpruntee: null as unknown as { value: unknown },
  incarnation: null as unknown as { value: unknown },
}))

vi.mock('@/composables/useSession', async () => {
  const { shallowRef } = await import('vue')
  etat.session = shallowRef(null)
  etat.administrateur = shallowRef(false)
  etat.vueEmpruntee = shallowRef(null)
  etat.incarnation = shallowRef(null)
  return {
    useSession: () => ({
      session: etat.session,
      administrateur: etat.administrateur,
      vueEmpruntee: etat.vueEmpruntee,
      incarnation: etat.incarnation,
    }),
    rafraichirSession: () => Promise.resolve(),
  }
})

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '12' }, query: {} }),
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

const api = vi.hoisted(() => ({
  lireDemande: vi.fn(),
  lireDemandeAdministration: vi.fn(),
  lireFichePersonne: vi.fn(),
  lireFicheFamille: vi.fn(),
  retirerDemande: vi.fn(),
  apercuFusion: vi.fn(),
  fusionnerComplement: vi.fn(),
  invaliderGraphe: vi.fn(),
  validerDemande: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  ...api,
}))

import { ErreurApi, type ComplementDeDemande, type DemandeAdministration, type RapportDeFusion } from '@/lib/api'
import DemandeView from './DemandeView.vue'

/** Un lien qui dit où il mène : la route nommée, sérialisée. */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () =>
    h('a', { href: typeof props.to === 'string' ? props.to : JSON.stringify(props.to) }, slots['default']?.()),
})

/* ------------------------------------------------------------------ */
/* Les charges                                                         */
/* ------------------------------------------------------------------ */

function demandeDe(surcharge: Partial<Demande> = {}): Demande {
  return {
    id: 12,
    kind: 'modification_personne',
    status: 'en_attente',
    authorUserId: 17,
    authorLabel: 'Opale Bourrache',
    targetKind: 'personne',
    targetId: 41,
    targetLabel: 'Marne Sureau',
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    submittedAt: '2026-09-16T10:56:18+00:00',
    createdAt: '2026-09-16T10:56:18+00:00',
    ops: [],
    brouillons: [],
    liens: [],
    revisions: [],
    ...surcharge,
  }
}

function ficheDe(id: number, displayName: string): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id,
    gedcomXref: null,
    displayName,
    givenNames: 'Marne',
    surname: 'Sureau',
    marriedName: null,
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: null,
    death: null,
    ligneDates: '1901 – 1987',
    branch: 'pm',
    generation: -3,
    bestQuay: 2,
    citationCount: 0,
    forcePrivacy: false,
    privacyNote: null,
    version: 8,
    parente: null,
    portrait: null,
    parents: [],
    fratrie: [],
    unions: [],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [12],
  } as unknown as FichePersonneComplete
}

function monter(): Promise<Noeud> {
  return monterEnMemoire(DemandeView, { RouterLink: LienFactice })
}

beforeEach(() => {
  vi.stubGlobal('window', { addEventListener: () => undefined, removeEventListener: () => undefined })
  Object.values(api).forEach((f) => f.mockReset())
  etat.session.value = { utilisateur: { id: 17, displayName: 'Opale Bourrache' } }
  etat.administrateur.value = false
  etat.vueEmpruntee.value = null
  etat.incarnation.value = null
  api.lireFichePersonne.mockImplementation((id: number) => Promise.resolve(ficheDe(id, `Personne ${id}`)))
})

/* ------------------------------------------------------------------ */
/* A — « Voir la fiche »                                               */
/* ------------------------------------------------------------------ */

describe('« Voir la fiche » ouvre la fiche visée, en lecture seule', () => {
  it('partage l’écran sur ordinateur et ouvre une modale sur téléphone, d’une seule lecture', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    const bouton = un(racine, parAttribut('data-voir-fiche'))
    expect(bouton).not.toBeNull()
    expect(String(bouton?.attributs['aria-expanded'])).toBe('false')
    // On ne lit pas la fiche tant qu'on ne la demande pas.
    expect(api.lireFichePersonne).not.toHaveBeenCalled()

    await cliquer(bouton)

    expect(api.lireFichePersonne).toHaveBeenCalledTimes(1)
    expect(api.lireFichePersonne.mock.calls[0]?.[0]).toBe(41)

    const colonne = un(racine, parAttribut('data-fiche-partagee'))
    const modale = un(racine, parAttribut('data-fiche-modale'))
    expect(colonne).not.toBeNull()
    expect(modale).not.toBeNull()
    // L'une pour l'ordinateur, l'autre pour le téléphone — jamais les deux à la fois.
    expect(String(colonne?.attributs['class'])).toContain('max-md:hidden')
    expect(String(modale?.attributs['class'])).toMatch(/(^|\s)md:hidden(\s|$)/)
    expect(modale?.attributs['role']).toBe('dialog')
    expect(String(modale?.attributs['aria-modal'])).toBe('true')

    // La fiche est là, avec ses vraies sections, et un lien vers la vraie page.
    for (const bloc of [colonne!, modale!]) {
      const contenu = texte(bloc)
      expect(contenu).toContain('Personne 41')
      expect(contenu).toContain('La fiche visée par la demande')
      expect(contenu).toContain('Identité')
      expect(contenu).toContain('Famille')
      expect(contenu).toContain('Sources et notes')
      expect(contenu).toContain('Ouvrir la page de Personne 41')
      // Lecture seule : aucun geste de proposition.
      expect(contenu).not.toMatch(/Proposer|Joindre un document|Demander la suppression/)
    }

    // Le partage élargit la page et pose la grille à deux colonnes.
    expect(versHtml(racine)).toContain('md:max-w-6xl')
    expect(versHtml(racine)).toContain('md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]')
    expect(String(un(racine, parAttribut('data-voir-fiche'))?.attributs['aria-expanded'])).toBe('true')

    // Le même bouton referme.
    await cliquer(un(racine, parAttribut('data-voir-fiche')))
    expect(un(racine, parAttribut('data-fiche-partagee'))).toBeNull()
    expect(un(racine, parAttribut('data-fiche-modale'))).toBeNull()
  })

  it('« × » ferme la fiche', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()
    await cliquer(un(racine, parAttribut('data-voir-fiche')))

    await cliquer(un(racine, (n) => n.tag === 'button' && n.attributs['aria-label'] === 'Fermer la fiche'))

    expect(un(racine, parAttribut('data-fiche-modale'))).toBeNull()
  })

  it('une création en attente montre la fiche du parent auquel elle se rattache', async () => {
    api.lireDemande.mockResolvedValue(
      demandeDe({
        kind: 'creation_personne',
        targetKind: null,
        targetId: null,
        targetLabel: null,
        brouillons: [
          {
            localRef: '#p1',
            noeudId: '#12/p1',
            givenNames: 'Céleste',
            surname: 'Sureau',
            sex: 'feminin',
            deceased: true,
            resolvedPersonId: null,
          },
        ],
        liens: [{ seq: 1, kind: 'enfant_de', subjectDraftRef: '#p1', otherPersonId: 9, otherLabel: 'Personne 9' }],
      }),
    )
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-voir-fiche')))

    expect(api.lireFichePersonne.mock.calls[0]?.[0]).toBe(9)
    expect(texte(un(racine, parAttribut('data-fiche-modale'))!)).toContain(
      'La personne à qui la proposition se rattache',
    )
  })

  it('pas de bouton quand la demande ne désigne aucune fiche', async () => {
    api.lireDemande.mockResolvedValue(
      demandeDe({ kind: 'creation_personne', targetKind: null, targetId: null, targetLabel: null }),
    )
    const racine = await monter()

    expect(un(racine, parAttribut('data-voir-fiche'))).toBeNull()
  })
})

/* ------------------------------------------------------------------ */
/* B — « Annuler ma demande »                                          */
/* ------------------------------------------------------------------ */

describe('« Annuler ma demande »', () => {
  it('confirme, envoie, puis la pastille dit « Annulée »', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    api.retirerDemande.mockResolvedValue(demandeDe({ status: 'annulee' }))
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))

    // Rien n'est parti : on demande d'abord.
    expect(api.retirerDemande).not.toHaveBeenCalled()
    const dialogue = un(racine, (n) => n.tag === 'dialog')
    expect(dialogue?.open).toBe(true)
    expect(texte(dialogue!)).toContain('Annuler la demande n° 12 ?')

    await cliquer(un(dialogue!, boutonNomme('Annuler ma demande')))

    expect(api.retirerDemande).toHaveBeenCalledWith(12)
    expect(texte(racine)).toContain('Annulée par son auteur')
    expect(un(racine, parAttribut('data-retirer'))).toBeNull()
    expect(dialogue?.open).toBe(false)
  })

  it('« Annuler » dans la question ne retire rien', async () => {
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))
    await cliquer(un(un(racine, (n) => n.tag === 'dialog')!, boutonNomme('Annuler')))

    expect(api.retirerDemande).not.toHaveBeenCalled()
    expect(un(racine, parAttribut('data-retirer'))).not.toBeNull()
  })

  it('s’offre aussi sur un brouillon, que le serveur accepte de retirer', async () => {
    api.lireDemande.mockResolvedValue(demandeDe({ status: 'brouillon' }))
    const racine = await monter()

    expect(un(racine, parAttribut('data-retirer'))).not.toBeNull()
  })

  it('ne s’offre ni sur la demande d’un autre, ni sur une demande tranchée', async () => {
    for (const surcharge of [
      { authorUserId: 99 },
      { status: 'validee' as const },
      { status: 'refusee' as const },
      { status: 'caduque' as const },
      { status: 'annulee' as const },
    ]) {
      api.lireDemande.mockResolvedValue(demandeDe(surcharge))
      const racine = await monter()
      expect(un(racine, parAttribut('data-retirer'))).toBeNull()
    }
  })

  it('validée entre-temps : le 409 se dit, et la demande est relue', async () => {
    api.lireDemande
      .mockResolvedValueOnce(demandeDe())
      .mockResolvedValueOnce(demandeDe({ status: 'validee' }))
    api.retirerDemande.mockRejectedValue(
      new ErreurApi(409, 'demande_deja_decidee', 'Cette demande a déjà été tranchée.', null, { statut: 'validee' }),
    )
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))
    await cliquer(un(un(racine, (n) => n.tag === 'dialog')!, boutonNomme('Annuler ma demande')))

    expect(api.lireDemande).toHaveBeenCalledTimes(2)
    const contenu = texte(racine)
    expect(contenu).toContain('Votre demande n’a pas pu être annulée.')
    expect(contenu).toContain('Cette demande a déjà été tranchée.')
    expect(un(racine, parAttribut('data-retirer'))).toBeNull()
  })

  it('sous la vue empruntée, le bouton reste visible, éteint, et dit pourquoi', async () => {
    etat.vueEmpruntee.value = { memberId: 7, memberLabel: 'Cobalt Romarin', depuis: '2026-09-15T21:12:00+02:00' }
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    const bouton = un(racine, parAttribut('data-retirer'))
    expect(bouton?.attributs['disabled']).toBe(true)
    expect(String(bouton?.attributs['title'])).toContain('Cobalt Romarin')

    await cliquer(bouton)
    expect(un(racine, (n) => n.tag === 'dialog')?.open).toBe(false)
  })

  it('sous incarnation, la question dit au nom de qui le geste est tracé', async () => {
    etat.incarnation.value = { par: { id: 1, displayName: 'Natron' } }
    api.lireDemande.mockResolvedValue(demandeDe())
    const racine = await monter()

    await cliquer(un(racine, parAttribut('data-retirer')))

    expect(texte(un(racine, (n) => n.tag === 'dialog')!)).toContain('enregistré au nom de Natron')
  })
})

/* ------------------------------------------------------------------ */
/* C — Compléter, et fusionner les compléments (ADR 0017)              */
/* ------------------------------------------------------------------ */

/** Barnabé Muguet, proposé par Opale (n° 17) dans la demande n° 12. */
function creationDe(surcharge: Partial<Demande> = {}): Demande {
  return demandeDe({
    kind: 'creation_personne',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    complementDe: null,
    empreinte: '9f2c',
    brouillons: [
      { localRef: '#p1', noeudId: '#12/p1', givenNames: 'Barnabé', surname: 'Muguet', sex: 'masculin', deceased: true, resolvedPersonId: null },
    ],
    liens: [{ seq: 1, kind: 'enfant_de', subjectDraftRef: '#p1', otherPersonId: 61, otherLabel: 'Jargon Muguet' }],
    ...surcharge,
  })
}

const RAPPORT: RapportDeFusion = {
  remplis: [{ champ: 'nickname', valeur: 'Bébert' }],
  conflits: [
    {
      champ: 'birth',
      origine: { raw: '1901', qualifier: 'annee', min: '1901-01-01', max: '1901-12-31', place: null, texte: '1901' },
      complement: { raw: '1903', qualifier: 'annee', min: '1903-01-01', max: '1903-12-31', place: null, texte: '1903' },
      retenu: 'origine',
    },
  ],
  liensAjoutes: [{ seqComplement: 2, seq: 3 }],
  liensIgnores: [{ seqComplement: 1, raison: 'deja_present' }],
  mediasDeplaces: 1,
  demandesRepointees: [],
}

/** Le complément n° 21 de Tuf, tel que la vue de l'origine le liste. */
const COMPLEMENT: ComplementDeDemande = {
  id: 21,
  authorLabel: 'Tuf Romarin',
  submittedAt: '2026-10-10T09:12:00+02:00',
  status: 'en_attente',
  draftPersonId: 31,
  origineDraftPersonId: 7,
  displayName: 'Barnabé Muguet',
  ligneDates: '1903 – 1968',
  apercu: RAPPORT,
  empreinte: '41ab',
}

/** L'origine vue par l'administration : un complément attend. */
function origineAdministration(surcharge: Partial<DemandeAdministration> = {}): DemandeAdministration {
  return {
    ...creationDe(),
    resolutionPrevue: 'complements_en_attente',
    dependances: [],
    demandesConcurrentes: [],
    doublonsProbables: [],
    complements: [COMPLEMENT],
    ...surcharge,
  }
}

/** Le complément n° 21 lui-même, avec ses deux liens — le premier déjà dans l'origine. */
function complementAdministration(surcharge: Partial<DemandeAdministration> = {}): DemandeAdministration {
  return {
    ...creationDe({
      id: 21,
      authorUserId: 40,
      authorLabel: 'Tuf Romarin',
      empreinte: '41ab',
      brouillons: [
        { localRef: '#p1', noeudId: '#21/p1', givenNames: 'Barnabé', surname: 'Muguet', sex: 'feminin', deceased: true, resolvedPersonId: null },
      ],
      liens: [
        { seq: 1, kind: 'enfant_de', subjectDraftRef: '#p1', otherPersonId: 61, otherLabel: 'Jargon Muguet' },
        { seq: 2, kind: 'parent_de', subjectDraftRef: '#p1', otherPersonId: 80, otherLabel: 'Céleste Muguet' },
      ],
    }),
    complementDe: { draftPersonId: 7, demandeId: 12, localRef: '#p1', displayName: 'Barnabé Muguet', status: 'en_attente' },
    resolutionPrevue: 'complement_a_fusionner',
    dependances: [],
    demandesConcurrentes: [],
    doublonsProbables: [],
    complements: [],
    ...surcharge,
  }
}

/** Un bouton radio choisi : son écouteur `change`. */
async function choisir(n: Noeud | null): Promise<void> {
  const ecouteur = n?.ecouteurs['change']
  if (typeof ecouteur !== 'function') throw new Error('Pas de bouton radio.')
  ecouteur({ target: n })
  await attendre()
}

const dialogueOuvert = (racine: Noeud) => un(racine, (n) => n.tag === 'dialog' && n.open === true)

describe('compléter, et fusionner les compléments', () => {
  it('« Compléter cette proposition » mène au formulaire, sur sa fiche — pour son auteur', async () => {
    api.lireDemande.mockResolvedValue(creationDe())
    const racine = await monter()

    const lien = un(un(racine, parAttribut('data-completer-la-fiche'))!, (n) => n.tag === 'a')
    expect(texte(lien!).trim()).toBe('Compléter cette proposition')
    expect(JSON.parse(String(lien?.attributs['href']))).toEqual({
      name: 'proposition.creation',
      query: { mode: 'formulaire', completer: '12', ref: 'p1' },
    })
  })

  it('ne s’offre ni sur la proposition d’un autre membre, ni sur une demande tranchée', async () => {
    for (const surcharge of [{ authorUserId: 99 }, { status: 'validee' as const }, { status: 'refusee' as const }]) {
      api.lireDemande.mockResolvedValue(creationDe(surcharge))
      const racine = await monter()
      expect(un(racine, parAttribut('data-completer-la-fiche'))).toBeNull()
    }
  })

  it('sur l’origine, l’administrateur lit ce que le complément apporte, et la validation attend', async () => {
    etat.administrateur.value = true
    etat.session.value = { utilisateur: { id: 1, displayName: 'Natron Romarin' } }
    api.lireDemandeAdministration.mockImplementation((id: number) =>
      Promise.resolve(id === 21 ? complementAdministration() : origineAdministration()),
    )
    const racine = await monter()

    const carte = un(racine, (n) => n.attributs['data-fusion'] === 21)
    expect(carte).not.toBeNull()
    const lu = texte(carte!)
    expect(lu).toContain('Complément n° 21')
    expect(lu).toContain('proposé par Tuf Romarin')
    expect(lu).toContain('Surnom : Bébert')
    expect(lu).toContain('Garder la proposition : 1901')
    expect(lu).toContain('Prendre le complément : 1903')
    expect(lu).toContain('Ajouté — Parent de Céleste Muguet')
    expect(lu).toContain('Écarté — Enfant de Jargon Muguet : déjà dans la proposition')
    expect(lu).toContain('1 pièce jointe rejoindra la proposition.')
    expect(texte(un(racine, parAttribut('data-complements-en-attente'))!)).toContain('avant de la valider')
    // Les liens se nomment par la demande du complément, lue une fois.
    expect(api.lireDemandeAdministration).toHaveBeenCalledWith(21)

    // « Valider » est éteint, et dit pourquoi.
    const valider = un(racine, (n) => n.tag === 'button' && n.attributs['aria-label'] === 'Fusionnez d’abord les compléments')
    expect(valider?.attributs['disabled']).toBe(true)
  })

  it('« Fusionner » demande confirmation, envoie le choix et les deux empreintes, puis relit', async () => {
    etat.administrateur.value = true
    etat.session.value = { utilisateur: { id: 1, displayName: 'Natron Romarin' } }
    // Après la fusion, l'origine relue porte son complément clos, sans aperçu.
    let fusionnee = false
    const apresFusion = () =>
      origineAdministration({
        resolutionPrevue: 'application',
        empreinte: 'aa11',
        complements: [{ ...COMPLEMENT, status: 'refusee', apercu: null }],
      })
    api.lireDemandeAdministration.mockImplementation((id: number) =>
      Promise.resolve(id === 21 ? complementAdministration() : fusionnee ? apresFusion() : origineAdministration()),
    )
    api.fusionnerComplement.mockImplementation(() => {
      fusionnee = true
      return Promise.resolve({
        origine: apresFusion(),
        complement: complementAdministration({ status: 'refusee' }),
        rapport: { ...RAPPORT, conflits: [{ ...RAPPORT.conflits[0]!, retenu: 'complement' }] },
      })
    })
    const racine = await monter()
    const lecturesAvant = api.lireDemandeAdministration.mock.calls.filter(([id]) => id === 12).length

    // Le conflit tranché pour le complément.
    await choisir(un(racine, (n) => n.tag === 'input' && n.attributs['value'] === 'complement'))
    await cliquer(un(racine, boutonNomme('Fusionner dans la demande n° 12')))

    // Rien n'est parti : on demande d'abord.
    expect(api.fusionnerComplement).not.toHaveBeenCalled()
    const dialogue = dialogueOuvert(racine)
    expect(texte(dialogue!)).toContain('Fusionner la demande n° 21 dans la n° 12 ?')
    expect(texte(dialogue!)).toContain('1 valeur reprise du complément')

    await cliquer(un(dialogue!, boutonNomme('Fusionner')))

    expect(api.fusionnerComplement).toHaveBeenCalledWith(12, 21, {
      retenir: { birth: 'complement' },
      empreinte: '9f2c',
      empreinteComplement: '41ab',
    })
    expect(api.invaliderGraphe).toHaveBeenCalledTimes(1)
    expect(api.lireDemandeAdministration.mock.calls.filter(([id]) => id === 12).length).toBe(lecturesAvant + 1)
    expect(texte(un(racine, parAttribut('data-annonce-fusion'))!)).toContain('Demande n° 21 fusionnée dans la n° 12')
    // Relue, l'origine ne porte plus de complément à fusionner.
    expect(un(racine, (n) => n.attributs['data-fusion'] === 21)).toBeNull()
    expect(texte(un(racine, parAttribut('data-complements-clos'))!)).toContain('close : fusionnée ou refusée')
  })

  it('une empreinte périmée : rien n’est fusionné, « Recharger » relit', async () => {
    etat.administrateur.value = true
    etat.session.value = { utilisateur: { id: 1, displayName: 'Natron Romarin' } }
    api.lireDemandeAdministration.mockImplementation((id: number) =>
      Promise.resolve(id === 21 ? complementAdministration() : origineAdministration()),
    )
    api.fusionnerComplement.mockRejectedValue(
      new ErreurApi(409, 'demande_modifiee_entre_temps', 'La demande a changé depuis votre lecture.', null, {
        demandeId: 12,
        empreinte: 'aaaa',
        rejouable: true,
      }),
    )
    const racine = await monter()
    await cliquer(un(racine, boutonNomme('Fusionner dans la demande n° 12')))
    await cliquer(un(dialogueOuvert(racine)!, boutonNomme('Fusionner')))

    expect(texte(racine)).toContain('La demande a changé depuis votre lecture.')
    expect(api.invaliderGraphe).not.toHaveBeenCalled()
    const lectures = api.lireDemandeAdministration.mock.calls.filter(([id]) => id === 12).length

    await cliquer(un(racine, boutonNomme('Recharger')))
    expect(api.lireDemandeAdministration.mock.calls.filter(([id]) => id === 12).length).toBe(lectures + 1)
  })

  it('sur le complément, l’administrateur lit ce qu’il complète, et fusionne d’ici', async () => {
    etat.administrateur.value = true
    etat.session.value = { utilisateur: { id: 1, displayName: 'Natron Romarin' } }
    api.lireDemandeAdministration.mockResolvedValue(complementAdministration())
    api.apercuFusion.mockResolvedValue({ origine: { id: 12, empreinte: '9f2c' }, complement: { id: 21, empreinte: '41ab' }, rapport: RAPPORT })
    const racine = await monter()

    const bandeau = un(racine, parAttribut('data-bandeau-complement-de'))
    expect(texte(bandeau!)).toContain('Cette demande complète la demande n° 12 — Barnabé Muguet')
    // L'aperçu se lit sur la route de l'**origine** (n° 12), pour ce complément (n° 21).
    expect(api.apercuFusion).toHaveBeenCalledWith(12, 21)
    expect(un(bandeau!, boutonNomme('Fusionner dans la demande n° 12'))).not.toBeNull()
    // « Valider » éteint : le geste est la fusion.
    expect(
      un(racine, (n) => n.tag === 'button' && n.attributs['aria-label'] === 'À fusionner dans la demande n° 12')?.attributs['disabled'],
    ).toBe(true)
  })

  it('l’auteur d’un complément voit le bandeau, sans numéro ni geste : la fusion est réservée', async () => {
    api.lireDemande.mockResolvedValue(creationDe({ complementDe: { draftPersonId: 7 } }))
    const racine = await monter()

    const bandeau = un(racine, parAttribut('data-bandeau-complement-de'))
    expect(texte(bandeau!)).toContain('Cette demande complète une proposition déjà en attente, faite par un autre membre.')
    expect(texte(bandeau!)).toContain('L’administrateur y fusionnera ses informations.')
    expect(texte(bandeau!)).not.toMatch(/n° \d/)
    expect(un(racine, (n) => n.tag === 'button' && texte(n).includes('Fusionner'))).toBeNull()
    expect(api.apercuFusion).not.toHaveBeenCalled()
    expect(api.lireDemandeAdministration).not.toHaveBeenCalled()
  })

  it('clos, il dit à quelle demande il a été rattaché', async () => {
    api.lireDemande.mockResolvedValue(
      creationDe({ status: 'refusee', complementDe: { draftPersonId: 7 }, duplicateOfId: 12, decisionMessage: 'Fusionné dans la demande n° 12.' }),
    )
    const racine = await monter()

    expect(texte(un(racine, parAttribut('data-bandeau-complement-de'))!)).toContain('Elle est close, rattachée à la demande n° 12.')
    expect(texte(racine)).toContain('Fusionné dans la demande n° 12.')
  })
})
