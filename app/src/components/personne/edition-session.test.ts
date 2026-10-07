import { shallowRef } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FichePersonneComplete } from '@/lib/api'
import { cleDeConjoint, cleDeParent } from './edition'
import { useEditionFiche } from './useEditionFiche'

/**
 * La session d'édition — entrer, abandonner, relire, déposer — **sans page**.
 *
 * `creerDemande` est simulé : on vérifie ce qui part, dans quel ordre, et qu'un
 * second « Confirmer » après un échec partiel ne redépose pas ce qui est déjà
 * dans la file.
 */

const api = vi.hoisted(() => ({
  creerDemande: vi.fn(),
  invaliderGraphe: vi.fn(),
}))

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  creerDemande: api.creerDemande,
  invaliderGraphe: api.invaliderGraphe,
}))

function fiche(id = 17): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id,
    gedcomXref: null,
    displayName: 'Marne Sureau',
    givenNames: 'Marne',
    surname: 'Sureau',
    marriedName: null,
    nickname: null,
    sex: 'feminin',
    deceased: false,
    vivant: false,
    birth: null,
    death: null,
    ligneDates: null,
    branch: 'pm',
    generation: -3,
    bestQuay: null,
    citationCount: 0,
    forcePrivacy: false,
    privacyNote: null,
    version: 3,
    parente: null,
    portrait: null,
    parents: [{ personId: 30, displayName: 'Galène Bourrache', relation: 'naissance', familyId: 4 }],
    fratrie: [],
    unions: [
      { familyId: 9, conjoint: { personId: 52, displayName: 'Cobalt Romarin' }, marriage: null, divorced: false, divorce: null, version: 1, enfants: [] },
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
  api.creerDemande.mockReset()
  api.invaliderGraphe.mockReset()
})

describe('entrer et sortir du mode édition', () => {
  it('le crayon ouvre une session ; « Annuler » sans modification en sort sans rien demander', () => {
    const surEntree = vi.fn()
    const session = useEditionFiche(shallowRef(fiche()), { surEntree })

    session.entrer()
    expect(session.edition.value).not.toBeNull()
    expect(surEntree).toHaveBeenCalledOnce()

    session.annuler()
    expect(session.confirmationAnnulation.value).toBeNull()
    expect(session.edition.value).toBeNull()
  })

  it('« Annuler » avec des modifications demande confirmation, et rien ne part', () => {
    const session = useEditionFiche(shallowRef(fiche()))
    session.entrer()
    session.edition.value!.saisie.nickname = 'Mamie'

    session.annuler()
    expect(session.confirmationAnnulation.value?.confirmer).toBe('Abandonner')
    expect(session.edition.value).not.toBeNull()

    session.quitter()
    expect(session.edition.value).toBeNull()
    expect(api.creerDemande).not.toHaveBeenCalled()
  })

  it('changer de fiche referme la session', async () => {
    const source = shallowRef(fiche(17))
    const session = useEditionFiche(source)
    session.entrer()
    source.value = fiche(18)
    await Promise.resolve()
    expect(session.edition.value).toBeNull()
  })
})

describe('« Corriger » puis « Confirmer »', () => {
  it('« Corriger » ouvre le récapitulatif et n’envoie rien', () => {
    const session = useEditionFiche(shallowRef(fiche()))
    session.entrer()
    session.edition.value!.retraits = [cleDeConjoint(52, 9)]

    session.corriger()
    expect(session.recap.value?.map((c) => c.texte)).toEqual(['Lien retiré : conjoint de Cobalt Romarin'])
    expect(api.creerDemande).not.toHaveBeenCalled()
  })

  it('« Confirmer » dépose dans l’ordre, sort du mode et annonce les demandes', async () => {
    api.creerDemande.mockResolvedValueOnce({ id: 801 }).mockResolvedValueOnce({ id: 802 })
    const surDepot = vi.fn()
    const session = useEditionFiche(shallowRef(fiche()), { surDepot })
    session.entrer()
    session.edition.value!.saisie.nickname = 'Mamie'
    session.edition.value!.retraits = [cleDeParent(30, 4)]
    session.message.value = 'Acte relu.'

    await session.confirmer()

    expect(api.creerDemande.mock.calls.map(([corps]) => corps.kind)).toEqual(['modification_personne', 'retrait_lien'])
    expect(api.creerDemande.mock.calls[1]![0]).toMatchObject({
      message: 'Acte relu.',
      retraits: [{ kind: 'enfant_de', personId: 17, familyId: 4 }],
    })
    expect(session.edition.value).toBeNull()
    expect(session.annonce.value).toEqual([
      { kind: 'modification_personne', cle: 'modification_personne', id: 801 },
      { kind: 'retrait_lien', cle: 'retrait_lien', id: 802 },
    ])
    expect(api.invaliderGraphe).toHaveBeenCalledOnce()
    expect(surDepot).toHaveBeenCalledOnce()
  })

  it('un échec partiel garde la session ; le second « Confirmer » ne redépose pas ce qui est parti', async () => {
    const refus = new Error('422')
    api.creerDemande
      .mockResolvedValueOnce({ id: 801 })
      .mockRejectedValueOnce(refus)
      .mockResolvedValueOnce({ id: 803 })
    const session = useEditionFiche(shallowRef(fiche()))
    session.entrer()
    session.edition.value!.saisie.nickname = 'Mamie'
    session.edition.value!.retraits = [cleDeConjoint(52, 9)]

    await session.confirmer()
    expect(session.erreurEnvoi.value).toBe(refus)
    expect(session.edition.value).not.toBeNull()

    await session.confirmer()
    expect(api.creerDemande.mock.calls.map(([corps]) => corps.kind)).toEqual([
      'modification_personne',
      'retrait_lien',
      'retrait_lien',
    ])
    expect(session.annonce.value.map((d) => d.id)).toEqual([801, 803])
  })

  it('une saisie invalide ne part pas : l’erreur s’affiche dans la modale', async () => {
    const session = useEditionFiche(shallowRef(fiche()))
    session.entrer()
    session.edition.value!.saisie.deces = { forme: 'connue', debut: '1960', fin: '', lieu: '', origine: null }

    session.corriger()
    expect(session.erreursSaisie.value[0]?.champ).toBe('deceased')
    await session.confirmer()
    expect(api.creerDemande).not.toHaveBeenCalled()
    expect(session.erreursChamps.value['deceased']).toBeDefined()
  })
})
