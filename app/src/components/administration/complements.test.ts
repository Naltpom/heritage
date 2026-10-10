import { describe, expect, it } from 'vitest'
import type { LienDemande, RapportDeFusion } from '@/lib/api'
import {
  annonceDeFusion,
  choixParDefaut,
  confirmationDeFusion,
  corpsDeFusion,
  etatDeLOrigine,
  etatDuComplement,
  origineNommee,
  rapportLisible,
  resumeDuRapport,
  valeurLisible,
} from './complements'

/**
 * La fusion d'un complément, côté écran (§ 6.7 bis, ADR 0017). Ce que ces tests
 * tiennent :
 *
 * 1. **`retenir` ne nomme que les conflits tranchés pour le complément** — un
 *    champ sans conflit rendrait `422`, et l'origine est le défaut ;
 * 2. **les deux empreintes partent**, et seulement si on les tient ;
 * 3. **le rapport se lit** : les dates par leur `texte`, les liens par la demande
 *    du complément — à défaut, par leur rang, jamais par un identifiant de
 *    personne.
 */

const RAPPORT: RapportDeFusion = {
  remplis: [
    { champ: 'nickname', valeur: 'Bébert' },
    { champ: 'death', valeur: { raw: '1968', qualifier: 'annee', min: '1968-01-01', max: '1968-12-31', place: 'Saule', texte: '1968' } },
    { champ: 'deceased', valeur: true },
  ],
  conflits: [
    {
      champ: 'birth',
      origine: { raw: '1901', qualifier: 'annee', min: '1901-01-01', max: '1901-12-31', place: null, texte: '1901' },
      complement: { raw: '1903', qualifier: 'annee', min: '1903-01-01', max: '1903-12-31', place: null, texte: '1903' },
      retenu: 'origine',
    },
    { champ: 'sex', origine: 'masculin', complement: 'feminin', retenu: 'origine' },
  ],
  liensAjoutes: [{ seqComplement: 2, seq: 3 }],
  liensIgnores: [
    { seqComplement: 1, raison: 'deja_present' },
    { seqComplement: 3, raison: 'fiche_proposee_ailleurs' },
  ],
  mediasDeplaces: 1,
  demandesRepointees: [23],
}

const LIENS_DU_COMPLEMENT: LienDemande[] = [
  { seq: 1, kind: 'enfant_de', subjectDraftRef: '#p1', otherPersonId: 61, otherLabel: 'Jargon Muguet' },
  { seq: 2, kind: 'parent_de', subjectDraftRef: '#p1', otherPersonId: 80, otherLabel: 'Céleste Muguet' },
]

describe('le rapport, en mots', () => {
  it('lit les valeurs : le texte des dates et leur lieu, le sexe, « oui »', () => {
    const lu = rapportLisible(RAPPORT, LIENS_DU_COMPLEMENT)

    expect(lu.remplis).toEqual([
      { champ: 'nickname', etiquette: 'Surnom', valeur: 'Bébert' },
      { champ: 'death', etiquette: 'Décès', valeur: '1968, Saule' },
      { champ: 'deceased', etiquette: 'Décès attesté', valeur: 'oui' },
    ])
    expect(lu.conflits).toEqual([
      { champ: 'birth', etiquette: 'Naissance', origine: '1901', complement: '1903' },
      { champ: 'sex', etiquette: 'Sexe', origine: 'Masculin', complement: 'Féminin' },
    ])
    expect(lu.medias).toBe('1 pièce jointe rejoindra la proposition.')
    expect(lu.repointees).toBe('La demande n° 23 désignera désormais la proposition.')
    expect(lu.vide).toBe(false)
  })

  it('nomme les liens par la demande du complément, et dit pourquoi l’un est écarté', () => {
    const lu = rapportLisible(RAPPORT, LIENS_DU_COMPLEMENT)

    expect(lu.liensAjoutes).toEqual([{ seqComplement: 2, libelle: 'Parent de Céleste Muguet' }])
    expect(lu.liensEcartes).toEqual([
      { seqComplement: 1, libelle: 'Enfant de Jargon Muguet', raison: 'déjà dans la proposition' },
      {
        seqComplement: 3,
        libelle: 'Lien n° 3 du complément',
        raison: 'il désigne une fiche d’une autre demande, que l’auteur de la proposition n’a pas forcément le droit de citer',
      },
    ])
  })

  it('sans la demande du complément, dit le rang du lien — jamais un identifiant de personne', () => {
    const lu = rapportLisible(RAPPORT, null)
    expect(lu.liensAjoutes[0]?.libelle).toBe('Lien n° 2 du complément')
    expect(JSON.stringify(lu)).not.toContain('80')
  })

  it('dit quand le complément n’apporte rien', () => {
    const vide: RapportDeFusion = { remplis: [], conflits: [], liensAjoutes: [], liensIgnores: [], mediasDeplaces: 0, demandesRepointees: [] }
    expect(rapportLisible(vide, null).vide).toBe(true)
    expect(resumeDuRapport(vide)).toBe('Le complément n’apporte rien de plus.')
  })

  it('une valeur absente se lit « — », un texte vide aussi', () => {
    expect(valeurLisible('marriedName', null)).toBe('—')
    expect(valeurLisible('note', '  ')).toBe('—')
    expect(valeurLisible('deceased', false)).toBe('non')
  })
})

describe('le corps de la fusion', () => {
  it('ne retient que les conflits tranchés pour le complément', () => {
    const choix = { ...choixParDefaut(RAPPORT.conflits), birth: 'complement' as const }

    expect(corpsDeFusion(choix, RAPPORT.conflits, { origine: '9f2c', complement: '41ab' })).toEqual({
      retenir: { birth: 'complement' },
      empreinte: '9f2c',
      empreinteComplement: '41ab',
    })
  })

  it('par défaut, l’origine l’emporte : pas de `retenir` du tout', () => {
    expect(choixParDefaut(RAPPORT.conflits)).toEqual({ birth: 'origine', sex: 'origine' })
    expect(corpsDeFusion(choixParDefaut(RAPPORT.conflits), RAPPORT.conflits, { origine: null, complement: null })).toEqual({})
  })

  it('n’envoie jamais un champ qui n’est pas en conflit — le serveur le refuserait en 422', () => {
    const corps = corpsDeFusion({ nickname: 'complement', birth: 'complement' }, RAPPORT.conflits, { origine: null, complement: null })
    expect(corps.retenir).toEqual({ birth: 'complement' })
  })
})

describe('avant et après le clic', () => {
  it('la question dit ce que fait la fusion, et qu’elle ne se défait pas', () => {
    const question = confirmationDeFusion(12, 21, RAPPORT, { birth: 'complement' })
    expect(question.titre).toBe('Fusionner la demande n° 21 dans la n° 12 ?')
    expect(question.message).toContain('3 champs remplis')
    expect(question.message).toContain('1 valeur reprise du complément')
    expect(question.message).toContain('1 désaccord tranché pour la proposition')
    expect(question.message).toContain('1 lien ajouté')
    expect(question.message).toContain('2 liens écartés')
    expect(question.message).toContain('1 pièce déplacée')
    expect(question.message).toContain('ne se défait pas')
    expect(question.confirmer).toBe('Fusionner')
  })

  it('l’annonce dit ce qui a été fait', () => {
    expect(annonceDeFusion(12, 21, RAPPORT)).toBe(
      'Demande n° 21 fusionnée dans la n° 12 : 3 champs remplis, 2 désaccords tranchés pour la proposition, 1 lien ajouté, 2 liens écartés, 1 pièce déplacée.',
    )
  })
})

describe('ce qu’une demande complète', () => {
  it('ne nomme l’origine que si la charge porte son numéro', () => {
    expect(origineNommee({ draftPersonId: 7 })).toBeNull()
    expect(origineNommee(null)).toBeNull()
    expect(origineNommee({ draftPersonId: 7, demandeId: 12, localRef: '#p1', displayName: 'Barnabé Muguet', status: 'en_attente' })).toEqual({
      demandeId: 12,
      libelle: 'Barnabé Muguet',
      statut: 'en_attente',
    })
  })

  it('dit ce que devient le complément quand son origine n’attend plus', () => {
    expect(etatDeLOrigine('en_attente')).toBeNull()
    expect(etatDeLOrigine('validee')).toMatch(/refuser/)
    expect(etatDeLOrigine('refusee')).toMatch(/se valide désormais seul/)
  })

  it('un complément clos ne prétend pas savoir s’il a été fusionné ou refusé', () => {
    expect(etatDuComplement('refusee')).toBe('close : fusionnée ou refusée')
    expect(etatDuComplement('en_attente')).toBe('en attente de fusion')
  })
})
