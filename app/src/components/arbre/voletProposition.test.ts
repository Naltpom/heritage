import { describe, expect, it } from 'vitest'
import { SELECTEUR_VOLET_PROPOSITION, toucheIgnoree, type CibleDeTouche } from './voletProposition'

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
