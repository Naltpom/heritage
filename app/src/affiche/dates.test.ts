import { describe, expect, it } from 'vitest'
import { DateAffiche, ligneVie, normNom, normPrenoms, titre } from './dates'

const d = (raw: string | null, qual: string, min: string | null = null, max: string | null = null): DateAffiche => new DateAffiche(raw, qual, min, max)

describe('les dates de l’affiche, depuis la chaîne GEDCOM (le chemin de l’original)', () => {
  it.each([
    ['25 MAY 1946', 'exacte', '1946-05-25', '1946-05-25', '25 mai 1946', '25 mai 1946', '25.05.1946', '1946'],
    ['1 FEB 1780', 'exacte', '1780-02-01', '1780-02-01', '1er février 1780', '1er févr. 1780', '01.02.1780', '1780'],
    ['MAR 1902', 'mois', '1902-03-01', '1902-03-31', 'mars 1902', 'mars 1902', '03.1902', '1902'],
    ['1946', 'annee', '1946-01-01', '1946-12-31', '1946', '1946', '1946', '1946'],
    ['ABT 1745', 'environ', '1745-01-01', '1745-12-31', 'vers 1745', 'v. 1745', 'v. 1745', 'v. 1745'],
    ['BEF 1735', 'avant', null, '1734-12-31', 'av. 1735', 'av. 1735', 'av. 1735', 'av. 1735'],
    ['AFT 3 JUN 1801', 'apres', '1801-06-04', null, 'ap. 3 juin 1801', 'ap. 1801', 'ap. 1801', 'ap. 1801'],
    ['BET 1901 AND 1909', 'entre', '1901-01-01', '1909-12-31', 'entre 1901 et 1909', '1901–1909', '1901–1909', '1901–1909'],
  ])('« %s »', (raw, qual, min, max, long, abr, court, annee) => {
    const date = d(raw, qual, min, max)
    expect(date.texte('long')).toBe(long)
    expect(date.texte('abr')).toBe(abr)
    expect(date.texte('court')).toBe(court)
    expect(date.texte('annee')).toBe(annee)
  })

  it('rend la chaîne en minuscules quand elle ne se lit pas, et rien quand rien n’est connu', () => {
    expect(d('INT 1900 (ILLISIBLE)', 'inconnue').texte()).toBe('int 1900 (illisible)')
    expect(DateAffiche.inconnue().texte()).toBe('')
    expect(DateAffiche.inconnue().connue).toBe(false)
  })

  it('trie sur la borne basse, puis la haute', () => {
    expect(d('BEF 1735', 'avant', null, '1734-12-31').tri).toBe('1734-12-31')
    expect(d('1946', 'annee', '1946-01-01', '1946-12-31').annee).toBe(1946)
  })
})

describe('les dates sans chaîne GEDCOM (naissances réduites par la réciprocité, ADR 0016)', () => {
  it('lit la forme sur le qualificatif : « vers » reste un « vers »', () => {
    expect(d(null, 'environ', '1934-01-01', '1934-12-31').texte('abr')).toBe('v. 1934')
    expect(d(null, 'annee', '2012-01-01', '2012-12-31').texte('long')).toBe('2012')
    expect(d(null, 'mois', '1985-03-01', '1985-03-31').texte('long')).toBe('mars 1985')
    expect(d(null, 'entre', '1901-01-01', '1909-12-31').texte('long')).toBe('entre 1901 et 1909')
    // `BEF X` réduite : la borne exclue est rendue, comme `UncertainDate::format()`.
    expect(d(null, 'avant', null, '1920-12-31').texte('abr')).toBe('av. 1921')
  })

  it('écrit le jour et le mois d’une naissance dont l’année n’est pas partagée', () => {
    const partiel = new DateAffiche(null, 'inconnue', null, null, { jour: 12, mois: 3 })
    expect(partiel.connue).toBe(true)
    expect(partiel.texte('long')).toBe('12 mars')
    expect(partiel.texte('court')).toBe('12.03')
    expect(partiel.texte('annee')).toBe('')
    expect(partiel.annee).toBeNull()
  })
})

describe('la ligne de vie', () => {
  const vie = (naissance: DateAffiche, deces: DateAffiche, decede: boolean) => ({ naissance, deces, decede })

  it('assemble naissance et décès, et pose une croix seule pour un défunt sans date', () => {
    expect(ligneVie(vie(d('1894', 'annee', '1894-01-01', '1894-12-31'), d('11 OCT 1971', 'exacte', '1971-10-11', '1971-10-11'), true))).toBe('° 1894  † 11 octobre 1971')
    expect(ligneVie(vie(d('1894', 'annee', '1894-01-01', '1894-12-31'), DateAffiche.inconnue(), true))).toBe('° 1894  †')
    expect(ligneVie(vie(DateAffiche.inconnue(), DateAffiche.inconnue(), false))).toBe('')
  })
})

describe('les noms', () => {
  it('met le NOM en capitales et laisse les particules en minuscules', () => {
    expect(normNom('de la Sauge')).toBe('de la SAUGE')
    expect(normNom('  le   cerfeuil ')).toBe('LE CERFEUIL')
    // Comme l'original : la particule n'est reconnue que séparée par une espace.
    expect(normNom("d' Origan")).toBe("d' ORIGAN")
    expect(normNom("d'Origan")).toBe("D'ORIGAN")
    expect(normNom('Pimprenelle de la Ciboulette')).toBe('PIMPRENELLE de la CIBOULETTE')
    expect(normNom('')).toBe('')
  })

  it('remet en casse de titre des prénoms saisis en capitales, et seulement ceux-là', () => {
    expect(normPrenoms('JADE MARIE-OPALE')).toBe('Jade Marie-Opale')
    expect(normPrenoms('Jade de Saint-Onyx')).toBe('Jade de Saint-Onyx')
    expect(normPrenoms('A')).toBe('A')
  })

  it('normalise en NFC : un accent décomposé se mesure comme le caractère composé', () => {
    expect(normPrenoms('Émeri')).toBe('Émeri')
  })

  it('reproduit `str.title()` de Python, apostrophes comprises', () => {
    expect(titre('LE CERFEUIL')).toBe('Le Cerfeuil')
    expect(titre("D'ORIGAN")).toBe("D'Origan")
    expect(titre('ROMARIN-SUREAU')).toBe('Romarin-Sureau')
  })
})
