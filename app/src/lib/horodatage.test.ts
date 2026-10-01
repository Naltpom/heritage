import { describe, expect, it } from 'vitest'
import { horodatageAvecHeure } from './horodatage'

describe('horodatageAvecHeure — un instant d’horloge, avec son heure', () => {
  it('écrit le jour, le mois abrégé, l’année et l’heure en français', () => {
    // Midi UTC : le même jour sous tous les fuseaux d'Europe, quel que soit
    // celui de la machine qui fait tourner la suite.
    const texte = horodatageAvecHeure('2026-09-12T12:04:00+00:00')
    expect(texte).toMatch(/^12 sept\. 2026/)
    expect(texte).toMatch(/\d{2}:04$/)
  })

  it('rend telle quelle une valeur illisible plutôt que « Invalid Date »', () => {
    expect(horodatageAvecHeure('hier')).toBe('hier')
  })
})
