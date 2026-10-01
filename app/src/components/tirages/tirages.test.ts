/**
 * Ce que les écrans des tirages décident sans navigateur : les dates, l'année
 * à ouvrir, les phrases — et surtout que la liste ne nomme jamais le receveur.
 */
import { describe, expect, it } from 'vitest'
import type { EditionDuTirage, MonAttribution, ResumeDeTirage } from '@/lib/api'
import {
  anneeProposee,
  avertissementDeMemoire,
  confirmationDAnnulation,
  confirmationDePresence,
  confirmationDeRetrait,
  confirmationDeSuppression,
  confirmationDuTirage,
  consigneDeRemise,
  dateLongue,
  etatDansLaListe,
  libelleDeMemoire,
  libelleDuJour,
  phraseDAttribution,
} from './tirages'

function edition(partielle: Partial<EditionDuTirage>): EditionDuTirage {
  return {
    annee: 2026,
    date: '2026-12-24',
    passee: false,
    statut: 'ouverte',
    statutLibelle: 'Tirage à venir',
    memoireAppliquee: null,
    tireeLe: null,
    nombreDAttributions: 0,
    presences: [],
    arrivesApresLeTirage: [],
    mesAttributions: [],
    ...partielle,
  }
}

const attribution: MonAttribution = {
  donneur: { personId: 1, libelle: 'Natron Romarin' },
  receveur: { personId: 2, libelle: 'Cobalt Romarin' },
  presenceDuDonneur: 'sur_place',
}

describe('les dates', () => {
  it('écrit le jour habituel en français, 1er compris', () => {
    expect(libelleDuJour(24, 12)).toBe('24 décembre')
    expect(libelleDuJour(1, 5)).toBe('1er mai')
  })

  it('ne recule pas une date d’un jour à cause du fuseau', () => {
    // `new Date('2026-12-24')` est lu en UTC : à l'ouest de Greenwich, ce serait le 23.
    expect(dateLongue('2026-12-24')).toBe('jeudi 24 décembre 2026')
  })

  it('rend telle quelle une date illisible plutôt que d’inventer', () => {
    expect(dateLongue('bientôt')).toBe('bientôt')
  })
})

describe('l’année à ouvrir', () => {
  const noel = { jour: 24, mois: 12, editions: [] as EditionDuTirage[] }

  it('propose cette année tant que le jour n’est pas passé', () => {
    expect(anneeProposee(noel, new Date(2026, 8, 27))).toBe(2026)
    expect(anneeProposee(noel, new Date(2026, 11, 24))).toBe(2026)
  })

  it('propose l’année suivante une fois le jour passé', () => {
    expect(anneeProposee(noel, new Date(2026, 11, 26))).toBe(2027)
  })

  it('saute les années déjà ouvertes', () => {
    const avecEditions = { ...noel, editions: [edition({ annee: 2026 }), edition({ annee: 2027 })] }
    expect(anneeProposee(avecEditions, new Date(2026, 8, 27))).toBe(2028)
  })
})

describe('les phrases', () => {
  it('dit « vous » au lecteur, et nomme celui qu’il accompagne', () => {
    expect(phraseDAttribution(attribution, 1)).toBe('Vous offrez un cadeau à')
    expect(phraseDAttribution(attribution, 7)).toBe('Natron Romarin offre un cadeau à')
  })

  it('dit d’apporter ou de faire transmettre le cadeau', () => {
    expect(consigneDeRemise('sur_place')).toBe('À apporter le jour J.')
    expect(consigneDeRemise('a_distance')).toContain('faites transmettre')
  })

  it('décrit la mémoire en français courant', () => {
    expect(libelleDeMemoire(0)).toContain('peut retomber')
    expect(libelleDeMemoire(1)).toBe('Jamais la même personne que l’année précédente')
    expect(libelleDeMemoire(3)).toBe('Jamais la même personne pendant 3 tirages')
  })

  it('n’avertit que lorsque la mémoire dépasse ce que le groupe permet', () => {
    expect(avertissementDeMemoire(2, 3)).toBeNull()
    expect(avertissementDeMemoire(3, 3)).toBeNull()
    expect(avertissementDeMemoire(4, 3)).toContain('au-delà de 3 tirages')
    expect(avertissementDeMemoire(1, 0)).toContain('sera ignorée')
  })
})

describe('la liste', () => {
  function resume(prochaineEdition: EditionDuTirage | null): ResumeDeTirage {
    return {
      id: 1,
      nom: 'Noël côté papa',
      jour: 24,
      mois: 12,
      lieu: null,
      ouvert: false,
      memoire: 2,
      exclureConjoints: true,
      organisateur: { libelle: 'Natron', estMoi: true },
      peutGerer: true,
      suisParticipant: true,
      peutRejoindre: false,
      nombreDeParticipants: 5,
      prochaineEdition,
    }
  }

  it('ne nomme jamais le receveur, même à celui qui offre', () => {
    const ligne = etatDansLaListe(resume(edition({ statut: 'tiree', mesAttributions: [attribution] })))

    expect(ligne).toBe('2026 · tirage fait — à découvrir')
    expect(ligne).not.toContain(attribution.receveur.libelle)
  })

  it('distingue un tirage à venir et l’absence d’édition', () => {
    expect(etatDansLaListe(resume(edition({})))).toBe('2026 · tirage à venir')
    expect(etatDansLaListe(resume(null))).toBe('Aucune édition ouverte')
  })
})

describe('les étapes de validation', () => {
  it('dit à qui ne vient pas qu’il sort du tirage, et le marque comme un geste qui retire', () => {
    const moi = confirmationDePresence('Cobalt Romarin', true, 'absente', 2026)

    expect(moi.titre).toBe('Passer à « ne participe pas » pour 2026 ?')
    expect(moi.message).toBe('Vous ne serez pas dans le tirage 2026 : vous n’offrirez rien, et personne ne vous aura.')
    expect(moi.danger).toBe(true)

    const autre = confirmationDePresence('Cobalt Romarin', false, 'absente', 2026)
    expect(autre.message).toBe('Cobalt Romarin ne sera pas dans le tirage 2026 : n’offrira rien, et personne ne l’aura.')
  })

  it('rappelle qu’à distance le cadeau doit être transmis', () => {
    const c = confirmationDePresence('Cobalt Romarin', true, 'a_distance', 2026)

    expect(c.message).toContain('transmis')
    expect(c.danger).toBe(false)
  })

  it('distingue quitter soi-même et retirer quelqu’un', () => {
    expect(confirmationDeRetrait('Natron', true).confirmer).toBe('Quitter le tirage')
    expect(confirmationDeRetrait('Natron', false).titre).toBe('Retirer Natron ?')
  })

  it('compte les participants prévenus au lancement', () => {
    expect(confirmationDuTirage(2026, 5).message).toContain('5 participants seront prévenus')
    expect(confirmationDuTirage(2026, 1).message).toContain('1 participant sera prévenu')
  })

  it('annuler et supprimer sont des gestes qui effacent', () => {
    expect(confirmationDAnnulation(2026).danger).toBe(true)
    expect(confirmationDeSuppression('Noël').titre).toBe('Supprimer « Noël » ?')
  })
})
