import { describe, expect, it } from 'vitest'
import type { PersonneDeposee } from '@/components/proposition/depot'
import {
  LARGEUR_VOLET_PROPOSITION,
  REQUETE_DEUX_VOLETS,
  REQUETE_VOLET_PROPOSITION,
  SELECTEUR_VOLET_PROPOSITION,
  cadrageApresVolet,
  confirmationDuDepot,
  toucheIgnoree,
  voletDroitVisible,
  type CibleDeTouche,
} from './voletProposition'

/**
 * Le volet « Ajouter une personne » à côté de l'arbre : ce qui se décide sans DOM.
 * Les cibles sont écrites à la main — l'environnement de vitest est `node`.
 */

/** Une cible de clavier : un élément `tag`, dans le volet ou non. */
function cible(tag: string, champs: { dansLeVolet?: boolean; editable?: boolean } = {}): CibleDeTouche {
  return {
    tagName: tag,
    isContentEditable: champs.editable ?? false,
    closest: (selecteur: string) => (selecteur === SELECTEUR_VOLET_PROPOSITION && champs.dansLeVolet ? {} : null),
  }
}

describe('toucheIgnoree : les raccourcis de l’arbre se taisent', () => {
  it('dans un champ de saisie, liste déroulante comprise', () => {
    expect(toucheIgnoree(cible('INPUT'))).toBe(true)
    expect(toucheIgnoree(cible('TEXTAREA'))).toBe(true)
    // Échap referme la liste, `-` et `0` y choisissent : ni désélection, ni zoom.
    expect(toucheIgnoree(cible('SELECT'))).toBe(true)
    expect(toucheIgnoree(cible('DIV', { editable: true }))).toBe(true)
  })

  it('n’importe où dans le volet, même sur un bouton', () => {
    expect(toucheIgnoree(cible('BUTTON', { dansLeVolet: true }))).toBe(true)
    expect(toucheIgnoree(cible('ASIDE', { dansLeVolet: true }))).toBe(true)
  })

  it('mais pas sur l’arbre, ni sur la page', () => {
    expect(toucheIgnoree(cible('BUTTON'))).toBe(false)
    expect(toucheIgnoree(cible('BODY'))).toBe(false)
    expect(toucheIgnoree(null)).toBe(false)
    // La fenêtre elle-même : ni balise, ni `closest`.
    expect(toucheIgnoree({})).toBe(false)
  })
})

describe('cadrageApresVolet : l’arbre ne saute pas', () => {
  const avant = { s: 0.6, tx: 120, ty: -40 }

  it('recule de la largeur du volet quand il paraît, avance d’autant quand il s’efface', () => {
    /* Le volet est AVANT le canevas : son apparition fait glisser l'origine du
       canevas de 440 px vers la droite. Retirer 440 px à `tx` laisse chaque fiche
       au même pixel de l'écran ; zoom et hauteur ne bougent pas. */
    const ouvert = cadrageApresVolet(avant, true, true)
    expect(ouvert).toEqual({ s: 0.6, tx: 120 - LARGEUR_VOLET_PROPOSITION, ty: -40 })
    expect(cadrageApresVolet(ouvert, false, true)).toEqual(avant)
  })

  it('ne touche à rien tant que l’arbre n’est ni disposé ni mesuré', () => {
    expect(cadrageApresVolet(avant, true, false)).toBe(avant)
  })

  it('a la largeur de son `<aside>`', () => {
    expect(LARGEUR_VOLET_PROPOSITION).toBe(440)
  })
})

describe('voletDroitVisible : la fiche à droite, la proposition à gauche', () => {
  const fiche = { id: 'p17' }

  it('montre la fiche choisie sur ordinateur, jamais sur téléphone ni sans choix', () => {
    expect(voletDroitVisible({ estMobile: false, fiche, propositionOuverte: false, tresLarge: false })).toBe(true)
    expect(voletDroitVisible({ estMobile: true, fiche, propositionOuverte: false, tresLarge: true })).toBe(false)
    expect(voletDroitVisible({ estMobile: false, fiche: null, propositionOuverte: false, tresLarge: true })).toBe(false)
  })

  it('cède la place à la proposition sous 90 rem, la garde au-delà', () => {
    expect(voletDroitVisible({ estMobile: false, fiche, propositionOuverte: true, tresLarge: false })).toBe(false)
    expect(voletDroitVisible({ estMobile: false, fiche, propositionOuverte: true, tresLarge: true })).toBe(true)
  })

  it('bascule aux seuils que Tailwind compile', () => {
    // `max-lg:hidden` sur le bouton, `lg` pour le volet : la même chaîne des deux côtés.
    expect(REQUETE_VOLET_PROPOSITION).toBe('(width >= 64rem)')
    expect(REQUETE_DEUX_VOLETS).toBe('(width >= 90rem)')
  })
})

describe('confirmationDuDepot : ce que le volet dit après l’envoi', () => {
  const depot: PersonneDeposee = {
    nature: 'creation',
    demandeIds: [8000],
    noeudId: '#8000/p1',
    personId: null,
    libelle: 'Marie Dupont',
    soumise: true,
  }

  it('annonce la demande envoyée et son numéro', () => {
    expect(confirmationDuDepot(depot, 'placee')).toEqual({
      titre: 'Proposition envoyée — demande n° 8000',
      phrase: 'La fiche de Marie Dupont est dans l’arbre, en pointillé jusqu’à sa validation ; elle y est choisie.',
    })
  })

  it('dit quand la personne n’est pas encore dessinée', () => {
    expect(confirmationDuDepot(depot, 'absente').phrase).toContain('pas encore dessinée dans l’arbre')
    expect(confirmationDuDepot(depot, 'masquee').phrase).toContain('l’affichage la masque')
    expect(confirmationDuDepot(depot, 'recherche').phrase).toContain('prend sa place')
  })

  it('ne dit pas « absente » quand l’arbre n’a pas pu se relire après le dépôt', () => {
    const echec = confirmationDuDepot(depot, 'echec')
    expect(echec.titre).toBe('Proposition envoyée — demande n° 8000')
    expect(echec.phrase).toContain('L’arbre n’a pas pu se relire')
    expect(echec.phrase).not.toContain('pas encore dessinée')
  })

  it('ne dit pas « envoyée » d’un brouillon, ni qu’il est dans l’arbre', () => {
    const brouillon = confirmationDuDepot({ ...depot, soumise: false }, 'absente')

    expect(brouillon.titre).toBe('Brouillon enregistré — demande n° 8000')
    expect(brouillon.phrase).toContain('qu’une fois la demande envoyée')
  })
})

describe('confirmationDuDepot : une fiche complétée (« C’est elle »)', () => {
  const fiche: PersonneDeposee = {
    nature: 'fiche',
    demandeIds: [41, 42],
    noeudId: null,
    personId: 17,
    libelle: 'Marne Sureau',
    soumise: true,
  }

  it('annonce les demandes déposées, et que rien ne paraît avant validation', () => {
    expect(confirmationDuDepot(fiche, 'placee')).toEqual({
      titre: 'Demandes n° 41 et 42 déposées',
      phrase: 'La fiche de Marne Sureau est choisie dans l’arbre ; vos compléments y paraîtront une fois validés.',
    })
  })

  it('dit quand l’arbre ne la montre pas, sans parler de proposition', () => {
    expect(confirmationDuDepot(fiche, 'absente').phrase).toContain('n’est pas dessinée dans l’arbre avec vos réglages')
    expect(confirmationDuDepot(fiche, 'masquee').phrase).toContain('l’affichage la masque')
    expect(confirmationDuDepot(fiche, 'recherche').phrase).toContain('se retrouve dans l’arbre')
    expect(confirmationDuDepot(fiche, 'placee').titre).not.toContain('Proposition envoyée')
  })
})

describe('confirmationDuDepot : une proposition complétée sur place (ADR 0017)', () => {
  const completion: PersonneDeposee = {
    nature: 'completion',
    demandeIds: [12],
    noeudId: '#12/p3',
    personId: null,
    libelle: 'Marie Dupont',
    soumise: true,
  }

  it('dit « complétée » — la même demande, réécrite, pas une nouvelle', () => {
    expect(confirmationDuDepot(completion, 'placee')).toEqual({
      titre: 'Proposition complétée — demande n° 12',
      phrase: 'La fiche de Marie Dupont est à jour dans l’arbre, en pointillé jusqu’à sa validation ; elle y est choisie.',
    })
    expect(confirmationDuDepot(completion, 'recherche').phrase).toContain('se met à jour')
    expect(confirmationDuDepot(completion, 'absente').phrase).toContain('Le suivi de la demande la montre')
    expect(confirmationDuDepot(completion, 'placee').titre).not.toContain('envoyée')
  })

  it('un brouillon complété n’est pas dans l’arbre', () => {
    const brouillon = confirmationDuDepot({ ...completion, soumise: false }, 'absente')
    expect(brouillon.titre).toBe('Brouillon complété — demande n° 12')
    expect(brouillon.phrase).toContain('qu’une fois la demande envoyée')
  })
})

describe('confirmationDuDepot : un complément (ADR 0017)', () => {
  const complement: PersonneDeposee = {
    nature: 'complement',
    demandeIds: [8200],
    noeudId: '#8200/p1',
    personId: null,
    libelle: 'Barnabé Muguet',
    soumise: true,
  }

  it('dit que l’administrateur le fusionnera, où qu’il soit dans l’arbre', () => {
    const placee = confirmationDuDepot(complement, 'placee')
    expect(placee.titre).toBe('Complément envoyé — demande n° 8200')
    expect(placee.phrase).toContain('en pointillé')
    for (const place of ['recherche', 'placee', 'masquee', 'absente', 'echec'] as const) {
      expect(confirmationDuDepot(complement, place).phrase).toMatch(/l’administrateur la fusionnera dans la proposition existante/i)
    }
  })

  it('un brouillon de complément se dit tel', () => {
    expect(confirmationDuDepot({ ...complement, soumise: false }, 'absente').titre).toBe(
      'Brouillon de complément enregistré — demande n° 8200',
    )
  })
})
