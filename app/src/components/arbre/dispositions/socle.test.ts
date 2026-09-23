/**
 * ════════════════════════════════════════════════════════════════════
 * LE SOCLE, TESTÉ POUR LUI-MÊME.
 *
 * Ces cas vivaient dans `vueBornee.test.ts`, parce que `/arbre-3` était le seul
 * écran à lire `?haut=` et `?bas=`. La maquette est partie ; **`optionsDepuis`
 * est resté**, et il borne toujours ces deux paramètres pour `/arbre`, qui les
 * accepte encore.
 *
 * Les déplacer ici plutôt que les laisser tomber avec le fichier qui les
 * hébergeait : sans ce fichier, la seule fonction du socle que rien d'autre
 * n'exerce perdait toute couverture **sans qu'aucune porte ne rougisse** — une
 * borne retirée par accident aurait alors rendu un écran vide sur une URL tapée
 * à la main, en silence.
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import { optionsDepuis, OPTIONS_PAR_DEFAUT } from './socle'

describe('les réglages venus de l’adresse', () => {
  it('borne une valeur absurde au lieu de rendre un écran vide', () => {
    expect(optionsDepuis({ haut: '-4', bas: 'oui' })).toEqual({ haut: 0, bas: OPTIONS_PAR_DEFAUT.bas })
    expect(optionsDepuis({ haut: '999' })).toEqual({ haut: 20, bas: OPTIONS_PAR_DEFAUT.bas })
  })

  it('retombe sur le défaut quand rien n’est demandé', () => {
    expect(optionsDepuis({})).toEqual(OPTIONS_PAR_DEFAUT)
  })

  it('accepte `0`, qui est une profondeur et non une absence', () => {
    expect(optionsDepuis({ haut: '0', bas: '0' })).toEqual({ haut: 0, bas: 0 })
  })
})
