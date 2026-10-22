import { beforeAll, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { installerLesPolicesDuDisque, cheminDePolice } from './essai/polices'
import { CLES_DE_POLICE, MetriquesDePolice, Style } from './polices'

beforeAll(installerLesPolicesDuDisque)

describe('la mesure des textes, sur les avances des glyphes', () => {
  /*
   * Les valeurs attendues sont celles de l'affiche d'origine, calculées par PyMuPDF
   * (`Style.largeur()` de `commun.py`) sur les instances complètes des mêmes polices. Elles
   * ne portent aucune donnée : des noms inventés, des signes. MuPDF calcule en flottants
   * simple précision, d'où la tolérance du cent-millième de millimètre ; un écart au-delà
   * voudrait dire que les sous-ensembles n'ont plus les métriques des polices d'origine — et
   * que tout le placement a glissé.
   */
  it.each([
    ['SS', 16.25, 0, 'Reg', 'Natron Basalte Émeri', 'SS-Txt-Reg', 58.19224574934691],
    ['SS', 16.25, 0.045, 'Semi', 'ROMARIN', 'SS-Txt-Semi', 30.043384761778512],
    ['SS', 8.8 * 1.3 * 0.86, 0, 'Ital', '° 3 févr. 1780  † v. 1840', 'SS-Cap-Ital', 36.90287993396267],
    ['SS', 7.2, 0, 'Semi', 'Pimprenelle de la Ciboulette ◆2', 'SS-Cap-Semi', 41.72224343719483],
    ['CG', 46, 0.01, 'Bold', 'Romarin · Sureau', 'CG-Bold', 117.67053851119277],
    ['SS', 9.5, 0, 'Reg', '&  union sans mariage connu     ∞2  deuxième union de la personne', 'SS-Cap-Reg', 114.09991197922825],
    ['SS', 30, 0, 'Ital', 'œuvre – ’ — ∞', 'SS-Dis-Ital', 60.41961406573653],
  ] as const)('%s %d pt, interlettrage %d, %s : « %s »', (famille, taille, espacement, graisse, texte, cle, attendu) => {
    const st = new Style(famille, taille, '#000', espacement, graisse)
    expect(st.cle).toBe(cle)
    expect(Math.abs(st.largeur(texte) - attendu)).toBeLessThan(1e-5)
  })

  it('choisit la taille optique de Source Serif selon le corps, comme l’original', () => {
    expect(new Style('SS', 10.4).cle).toBe('SS-Cap-Reg')
    expect(new Style('SS', 10.5).cle).toBe('SS-Txt-Reg')
    expect(new Style('SS', 22, '#000', 0, 'Reg').cle).toBe('SS-Dis-Semi')
    expect(new Style('SS', 15, '#000', 0, 'Bold').cle).toBe('SS-Txt-Semi')
    expect(new Style('CG', 46, '#000', 0, 'Bold').cle).toBe('CG-Bold')
  })

  it('compte les caractères comme Python : un interlettrage par point de code, pas par unité UTF-16', () => {
    const sans = new Style('SS', 12, '#000', 0, 'Reg')
    const avec = new Style('SS', 12, '#000', 0.1, 'Reg')
    // « 𝔄b » : un caractère hors du plan de base (deux unités UTF-16) et un « b » → un seul
    // interlettrage, de 0,1 em du corps.
    const ecart = avec.largeur('𝔄b') - sans.largeur('𝔄b')
    expect(ecart).toBeCloseTo(0.1 * 12 * (25.4 / 72) * 1.02, 9)
  })
})

describe('les fichiers de police', () => {
  it.each(CLES_DE_POLICE)('%s couvre les signes de l’affiche et ne porte plus le nom réservé « Source »', (cle) => {
    const octets = readFileSync(cheminDePolice(cle))
    const m = MetriquesDePolice.depuis(octets)
    for (const signe of '°†∞◆·—–’«»éèçœÉŒ') expect(m.couvre(signe.codePointAt(0)!), signe).toBe(true)
    // La table `name` en UTF-16 big-endian (plateforme Windows) et en Mac Roman : le nom
    // réservé ne doit apparaître dans aucun nom de police (le copyright, lui, le cite).
    const latin1 = octets.toString('latin1')
    const utf16 = Buffer.from(octets).swap16().toString('utf16le')
    for (const texte of [latin1, utf16]) {
      expect(texte).not.toMatch(/SourceSerif4Roman|Source Serif 4 (Regular|Italic)/)
    }
  })
})
