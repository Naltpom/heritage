import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FichePersonneComplete, FinDeRecherche } from '@/lib/api'
import { ErreurApi } from '@/lib/api'
import {
  choixDeClotureOfferts,
  deposerFinDeRecherche,
  encartDisponible,
  erreursDuServeur,
  etatDeCote,
  etatsDeFiche,
  gestesDuCote,
  lesDeuxOfferts,
  natureDuCalque,
  saisieDepuis,
  valeurDeSaisie,
} from './fin-de-recherche'

/**
 * L'encart « Fin de recherche » (ADR 0023), **sans écran** : l'état de chaque côté, les
 * gestes qu'il offre, le dépôt de la demande et la lecture des refus du serveur.
 * `fin-de-recherche-rendu.test.ts` prouve ensuite que le gabarit l'imprime.
 */

const creerDemande = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  creerDemande,
}))

const FIN: FinDeRecherche = {
  reason: 'registres_inexistants',
  beforeYear: 1668,
  body: 'Les registres commencent en 1668',
  authorLabel: 'Opale Romarin',
  closedAt: '2026-10-12T09:30:00+02:00',
}

function fiche(surcharge: Partial<FichePersonneComplete> = {}): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    version: 8,
    parents: [],
    finDeRecherchePere: null,
    finDeRechercheMere: null,
    enAttente: {},
    demandesEnAttente: [],
    ...surcharge,
  } as unknown as FichePersonneComplete
}

function calque(propose: unknown, actuel: unknown) {
  return { propose, actuel, demandeId: 31, auteur: 'Opale Romarin', depuis: '2026-10-20T10:00:00+02:00', autresDemandes: [] }
}

beforeEach(() => creerDemande.mockReset())

describe('l’état de chaque côté', () => {
  it('rien de dit, aucun parent : à chercher', () => {
    const e = etatDeCote(fiche(), 'pere')
    expect(e).toMatchObject({ cote: 'pere', parentConnu: false, fin: null, enAttente: null, etat: 'a-chercher' })
  })

  it('une fin de recherche sans parent : close', () => {
    expect(etatDeCote(fiche({ finDeRechercheMere: FIN }), 'mere').etat).toBe('close')
    expect(etatDeCote(fiche({ finDeRechercheMere: FIN }), 'pere').etat).toBe('a-chercher')
  })

  it('un parent lié l’emporte, fin de recherche restée enregistrée ou non', () => {
    const parents = [{ id: 3, cote: 'pere' }] as never
    expect(etatDeCote(fiche({ parents }), 'pere')).toMatchObject({ parentConnu: true, etat: 'connus', fin: null })
    expect(etatDeCote(fiche({ parents, finDeRecherchePere: FIN }), 'pere')).toMatchObject({ etat: 'connus', fin: FIN })
  })

  it('rend les deux côtés, le père d’abord', () => {
    expect(etatsDeFiche(fiche()).map((e) => e.cote)).toEqual(['pere', 'mere'])
  })

  it('l’encart n’existe que si la charge porte les deux clés', () => {
    expect(encartDisponible(fiche())).toBe(true)
    expect(encartDisponible({ id: 1 })).toBe(false)
    expect(encartDisponible({ finDeRecherchePere: null })).toBe(false)
  })
})

describe('le calque d’un côté', () => {
  it('une pose : propose renseigné, actuel null', () => {
    const e = etatDeCote(fiche({ enAttente: { finDeRecherchePere: calque({ reason: 'lacune', beforeYear: null, body: null }, null) } }), 'pere')
    expect(e.enAttente).not.toBeNull()
    expect(natureDuCalque(e.enAttente!)).toBe('pose')
  })

  it('une modification : les deux renseignés', () => {
    const e = etatDeCote(
      fiche({
        finDeRechercheMere: FIN,
        enAttente: {
          finDeRechercheMere: calque({ reason: 'lacune', beforeYear: null, body: null }, { reason: 'registres_inexistants', beforeYear: 1668, body: null }),
        },
      }),
      'mere',
    )
    expect(natureDuCalque(e.enAttente!)).toBe('modification')
  })

  it('une levée : propose vaut null', () => {
    const e = etatDeCote(fiche({ finDeRecherchePere: FIN, enAttente: { finDeRecherchePere: calque(null, { reason: 'lacune', beforeYear: null, body: null }) } }), 'pere')
    expect(natureDuCalque(e.enAttente!)).toBe('levee')
  })

  it('un calque qui n’est pas une fin de recherche est ignoré', () => {
    expect(etatDeCote(fiche({ enAttente: { finDeRecherchePere: calque('autre chose', null) } }), 'pere').enAttente).toBeNull()
  })

  it('le calque de l’autre côté ne déborde pas', () => {
    const f = fiche({ enAttente: { finDeRecherchePere: calque({ reason: 'lacune', beforeYear: null, body: null }, null) } })
    expect(etatDeCote(f, 'mere').enAttente).toBeNull()
  })
})

describe('les gestes offerts', () => {
  it('aucun sans le droit de proposer', () => {
    const g = gestesDuCote(etatDeCote(fiche({ finDeRecherchePere: FIN }), 'pere'), false)
    expect(g).toMatchObject({ clore: false, modifier: false, rouvrir: false, bloque: false })
  })

  it('à chercher : clore', () => {
    expect(gestesDuCote(etatDeCote(fiche(), 'pere'), true)).toMatchObject({ clore: true, modifier: false, rouvrir: false })
  })

  it('close : modifier et rouvrir, pas clore', () => {
    expect(gestesDuCote(etatDeCote(fiche({ finDeRecherchePere: FIN }), 'pere'), true)).toMatchObject({
      clore: false,
      modifier: true,
      rouvrir: true,
    })
  })

  it('close, puis un parent lié depuis : rouvrir seulement (modifier serait refusé en 422)', () => {
    const etat = etatDeCote(fiche({ finDeRecherchePere: FIN, parents: [{ id: 3, cote: 'pere' }] as never }), 'pere')
    expect(etat.etat).toBe('connus')
    expect(gestesDuCote(etat, true)).toEqual({ clore: false, modifier: false, rouvrir: true, bloque: false })
  })

  it('parent connu sans fin de recherche : aucun geste', () => {
    const g = gestesDuCote(etatDeCote(fiche({ parents: [{ id: 3, cote: 'mere' }] as never }), 'mere'), true)
    expect(g).toMatchObject({ clore: false, modifier: false, rouvrir: false, bloque: false })
  })

  it('une demande en attente ferme tous les gestes du côté, et le dit', () => {
    const f = fiche({ finDeRecherchePere: FIN, enAttente: { finDeRecherchePere: calque(null, { reason: 'lacune', beforeYear: null, body: null }) } })
    expect(gestesDuCote(etatDeCote(f, 'pere'), true)).toEqual({ clore: false, modifier: false, rouvrir: false, bloque: true })
  })
})

describe('le choix « les deux »', () => {
  it('offert quand les deux côtés sont libres', () => {
    expect(lesDeuxOfferts(etatsDeFiche(fiche()), true)).toBe(true)
    expect(choixDeClotureOfferts(etatsDeFiche(fiche()), true)).toEqual(['pere', 'mere', 'les-deux'])
  })

  it('pas sans le droit de proposer', () => {
    expect(lesDeuxOfferts(etatsDeFiche(fiche()), false)).toBe(false)
    expect(choixDeClotureOfferts(etatsDeFiche(fiche()), false)).toEqual([])
  })

  it('pas quand un côté a un parent, une fin de recherche ou une demande', () => {
    expect(lesDeuxOfferts(etatsDeFiche(fiche({ parents: [{ id: 3, cote: 'pere' }] as never })), true)).toBe(false)
    expect(lesDeuxOfferts(etatsDeFiche(fiche({ finDeRechercheMere: FIN })), true)).toBe(false)
    const attente = fiche({ enAttente: { finDeRecherchePere: calque({ reason: 'lacune', beforeYear: null, body: null }, null) } })
    expect(lesDeuxOfferts(etatsDeFiche(attente), true)).toBe(false)
    expect(choixDeClotureOfferts(etatsDeFiche(attente), true)).toEqual(['mere'])
  })
})

describe('la saisie du formulaire', () => {
  it('se préremplit depuis la fin enregistrée, sans date ni auteur', () => {
    expect(saisieDepuis(FIN)).toEqual({ reason: 'registres_inexistants', beforeYear: '1668', body: 'Les registres commencent en 1668' })
    expect(saisieDepuis(null)).toEqual({ reason: '', beforeYear: '', body: '' })
  })

  it('sans motif, pas de valeur ; l’année vide est null ; le texte vide aussi', () => {
    expect(valeurDeSaisie({ reason: '', beforeYear: '', body: '' })).toBeNull()
    expect(valeurDeSaisie({ reason: 'lacune', beforeYear: '', body: '  ' })).toEqual({ reason: 'lacune', beforeYear: null, body: null })
    expect(valeurDeSaisie({ reason: 'registres_inexistants', beforeYear: '1668', body: '' })).toEqual({
      reason: 'registres_inexistants',
      beforeYear: 1668,
      body: null,
    })
  })

  it('l’année d’un motif qui n’en porte pas est écartée', () => {
    expect(valeurDeSaisie({ reason: 'lacune', beforeYear: '1668', body: '' })?.beforeYear).toBeNull()
  })
})

describe('les refus du serveur, posés sur leur champ', () => {
  it('traduit les clés d’op vers les champs du formulaire', () => {
    expect(
      erreursDuServeur({
        'ops[0].newValue.reason': 'Motif inconnu.',
        'ops[0].newValue.beforeYear': 'Année invalide.',
        'ops[1].newValue.beforeYear': 'Année invalide.',
        'ops[0].newValue.body': 'Trop long.',
        message: 'Trop long aussi.',
        autre: 'Ailleurs.',
      }),
    ).toEqual({
      reason: 'Motif inconnu.',
      beforeYear: 'Année invalide.',
      body: 'Trop long.',
      message: 'Trop long aussi.',
      general: 'Ailleurs.',
    })
  })
})

describe('le dépôt', () => {
  const SAISIE = { reason: 'registres_inexistants', beforeYear: 1668, body: ' Saint-Martin ' } as const

  it('une clôture sur un côté : une modification_personne, un seul op', async () => {
    creerDemande.mockResolvedValueOnce({ id: 77 })
    const issue = await deposerFinDeRecherche(fiche(), 'pere', { ...SAISIE }, ' Merci ', 2026)

    expect(issue).toEqual({ type: 'envoyee', demandeId: 77 })
    const corps = creerDemande.mock.calls[0]![0]
    expect(corps).toMatchObject({ kind: 'modification_personne', targetKind: 'personne', targetId: 17, soumettre: true, message: 'Merci' })
    expect(corps.ops).toHaveLength(1)
    expect(corps.ops[0]).toMatchObject({
      field: 'finDeRecherchePere',
      valueKind: 'fin_de_recherche',
      oldValue: null,
      newValue: { reason: 'registres_inexistants', beforeYear: 1668, body: 'Saint-Martin' },
      baseVersion: 8,
    })
  })

  it('« les deux » dépose deux ops dans une seule demande, père d’abord', async () => {
    creerDemande.mockResolvedValueOnce({ id: 78 })
    await deposerFinDeRecherche(fiche(), 'les-deux', { reason: 'lacune', beforeYear: null, body: null }, '', 2026)

    expect(creerDemande).toHaveBeenCalledTimes(1)
    const ops = creerDemande.mock.calls[0]![0].ops
    expect(ops.map((o: { field: string; seq: number }) => [o.seq, o.field])).toEqual([
      [1, 'finDeRecherchePere'],
      [2, 'finDeRechercheMere'],
    ])
  })

  it('une modification porte l’ancienne valeur, sans date ni auteur', async () => {
    creerDemande.mockResolvedValueOnce({ id: 79 })
    await deposerFinDeRecherche(fiche({ finDeRechercheMere: FIN }), 'mere', { reason: 'lacune', beforeYear: null, body: null }, '', 2026)
    const op = creerDemande.mock.calls[0]![0].ops[0]
    expect(op.oldValue).toEqual({ reason: 'registres_inexistants', beforeYear: 1668, body: 'Les registres commencent en 1668' })
    expect(op.newValue).toEqual({ reason: 'lacune', beforeYear: null, body: null })
  })

  it('la levée : newValue vaut null', async () => {
    creerDemande.mockResolvedValueOnce({ id: 80 })
    const issue = await deposerFinDeRecherche(fiche({ finDeRecherchePere: FIN }), 'pere', null)
    expect(issue.type).toBe('envoyee')
    expect(creerDemande.mock.calls[0]![0].ops[0]).toMatchObject({ field: 'finDeRecherchePere', newValue: null })
  })

  it('« registres inexistants » exige l’année : rien ne part', async () => {
    const issue = await deposerFinDeRecherche(fiche(), 'pere', { reason: 'registres_inexistants', beforeYear: null, body: null }, '', 2026)
    expect(issue).toMatchObject({ type: 'refusee', erreurs: { beforeYear: expect.stringContaining('année') } })
    expect(creerDemande).not.toHaveBeenCalled()
  })

  it('« autre » exige le texte : rien ne part', async () => {
    const issue = await deposerFinDeRecherche(fiche(), 'mere', { reason: 'autre', beforeYear: null, body: '  ' }, '', 2026)
    expect(issue).toMatchObject({ type: 'refusee', erreurs: { body: expect.any(String) } })
    expect(creerDemande).not.toHaveBeenCalled()
  })

  it('« père inconnu » ne vaut pas pour « les deux »', async () => {
    const issue = await deposerFinDeRecherche(fiche(), 'les-deux', { reason: 'pere_inconnu', beforeYear: null, body: null }, '', 2026)
    expect(issue.type).toBe('refusee')
    expect(creerDemande).not.toHaveBeenCalled()
  })

  it('une saisie identique à l’enregistré ne part pas', async () => {
    const issue = await deposerFinDeRecherche(fiche({ finDeRecherchePere: FIN }), 'pere', { reason: FIN.reason, beforeYear: 1668, body: FIN.body }, '', 2026)
    expect(issue).toEqual({ type: 'inchangee' })
    expect(creerDemande).not.toHaveBeenCalled()
  })

  it('un 422 se pose sur ses champs', async () => {
    creerDemande.mockRejectedValueOnce(new ErreurApi(422, 'validation', 'Refusé.', { 'ops[0].newValue.beforeYear': 'Année invalide.' }))
    const issue = await deposerFinDeRecherche(fiche(), 'pere', { ...SAISIE }, '', 2026)
    expect(issue).toEqual({ type: 'refusee', erreurs: { beforeYear: 'Année invalide.' } })
  })

  it('un 409 dit de recharger', async () => {
    creerDemande.mockRejectedValueOnce(new ErreurApi(409, 'conflit', 'La fiche a changé.'))
    expect(await deposerFinDeRecherche(fiche(), 'pere', { ...SAISIE }, '', 2026)).toEqual({ type: 'conflit' })
  })

  it('toute autre erreur est rendue telle quelle', async () => {
    const cause = new ErreurApi(500, 'erreur_serveur', 'Oups.')
    creerDemande.mockRejectedValueOnce(cause)
    expect(await deposerFinDeRecherche(fiche(), 'pere', { ...SAISIE }, '', 2026)).toEqual({ type: 'echec', erreur: cause })
  })
})
