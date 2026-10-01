import { describe, expect, it } from 'vitest'
import type { Graphe, NoeudFamille, NoeudPersonne } from '@/lib/api'
import {
  FORMATS_DE_CARTE,
  FORMAT_PAR_DEFAUT,
  GABARIT_PORTRAIT,
  LIGNES_PORTRAIT,
  auFormat,
  entreePortrait,
  geometriePortrait,
  texteDePortrait,
  type EntreePortrait,
} from './cartes'

/** Noms inventés (ADR 0006). Un champ à `undefined` est RETIRÉ du nœud, comme l'API l'omet. */
function personne(id: string, champs: { [K in keyof NoeudPersonne]?: NoeudPersonne[K] | undefined } = {}): NoeudPersonne {
  const n: Record<string, unknown> = {
    id,
    type: 'personne',
    personId: Number(id.slice(1)),
    x: 0,
    y: 0,
    w: 172,
    h: 56,
    displayName: 'Jade Romarin',
    etiquetteNom: 'Jade Romarin',
    etiquetteDates: '1902 – 1981',
    sex: 'feminin',
    generation: 0,
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
    ...champs,
  }
  for (const [cle, valeur] of Object.entries(n)) if (valeur === undefined) delete n[cle]
  return n as unknown as NoeudPersonne
}

function graphe(): Graphe {
  const famille: NoeudFamille = { id: 'f9', type: 'famille', familyId: 9, x: 0, y: 0, w: 10, h: 10, divorced: false }
  return {
    empreinte: '0'.repeat(64),
    ego: 'p1',
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds: [
      personne('p1'),
      personne('p2', { vivant: true, w: 215, etiquetteDates: 'vivant·e' }),
      personne('p3', { visibilite: 'restreinte', vivant: true, w: 215, etiquetteNom: 'Personne vivante', etiquetteDates: undefined }),
      famille,
    ],
    aretes: [
      { de: 'p1', vers: 'f9', genre: 'union' },
      { de: 'f9', vers: 'p2', genre: 'filiation' },
    ],
  }
}

describe('les formats de carte', () => {
  it('« Compact » par défaut, deux formats en tout', () => {
    expect(FORMAT_PAR_DEFAUT).toBe('compact')
    expect(FORMATS_DE_CARTE).toEqual(['compact', 'portrait'])
  })

  it('le gabarit Portrait tient son budget vertical : photo, NOM, prénom, deux lignes de dates', () => {
    const { h, photo, marge, ecart } = GABARIT_PORTRAIT
    const { nom, prenom, dates } = LIGNES_PORTRAIT
    expect(marge + photo + ecart + nom.hauteur + prenom.hauteur + 2 * dates.hauteur + marge).toBe(h)
  })
})

describe('auFormat', () => {
  it('en Compact, rend le MÊME objet', () => {
    const g = graphe()
    expect(auFormat(g, 'compact')).toBe(g)
  })

  it('en Portrait, une seule boîte pour tout le monde — vivants et restreints compris', () => {
    const g = graphe()
    const p = auFormat(g, 'portrait')
    const personnes = p.noeuds.filter((n) => n.type === 'personne')
    expect(personnes).toHaveLength(3)
    for (const n of personnes) expect([n.w, n.h]).toEqual([GABARIT_PORTRAIT.w, GABARIT_PORTRAIT.h])
    // La largeur ne dit plus rien de qui est vivant (ADR 0003).
    expect(new Set(personnes.map((n) => n.w)).size).toBe(1)
  })

  it('ne touche ni aux familles, ni aux liens, ni au graphe reçu', () => {
    const g = graphe()
    const p = auFormat(g, 'portrait')
    expect(p.aretes).toBe(g.aretes)
    expect(p.noeuds.find((n) => n.id === 'f9')).toBe(g.noeuds.find((n) => n.id === 'f9'))
    expect(g.noeuds.filter((n) => n.type === 'personne').map((n) => n.w)).toEqual([172, 215, 215])
    expect(g.noeuds.every((n) => n.type !== 'personne' || n.h === 56)).toBe(true)
  })
})

describe('entreePortrait', () => {
  it('sépare le NOM et le prénom de l’étiquette mesurée, et garde la ligne de dates telle quelle', () => {
    expect(entreePortrait(personne('p1', { etiquetteNom: 'Jade de la Romarinière' }))).toEqual({
      nom: 'de la Romarinière',
      prenom: 'Jade',
      dates: '1902 – 1981',
      restreinte: false,
    })
  })

  it('un nœud restreint ne dit que « Personne vivante » — ni prénom, ni dates, quoi qu’il porte', () => {
    const n = personne('p3', { visibilite: 'restreinte', etiquetteNom: 'Jade Romarin', etiquetteDates: '1960 – ' })
    expect(entreePortrait(n)).toEqual({ nom: 'Personne vivante', prenom: '', dates: '', restreinte: true })
  })

  it('un seul mot (pas de nom de famille) monte sur la ligne du nom', () => {
    expect(entreePortrait(personne('p1', { etiquetteNom: 'Ambre' }))).toMatchObject({ nom: 'Ambre', prenom: '' })
  })

  it('sans étiquette mesurée (API antérieure), le nom de lecture entier', () => {
    const n = personne('p1', { etiquetteNom: undefined, displayName: 'Ambre Mica Romarin' })
    expect(entreePortrait(n)).toMatchObject({ nom: 'Ambre Mica Romarin', prenom: '' })
  })

  it('sans ligne de dates : aucune date inventée', () => {
    expect(entreePortrait(personne('p1', { etiquetteDates: undefined })).dates).toBe('')
  })
})

describe('texteDePortrait', () => {
  const jade: EntreePortrait = { nom: 'Romarin', prenom: 'Jade', dates: '1902 – 1981', restreinte: false }
  const largeur = (GABARIT_PORTRAIT.w - 2 * GABARIT_PORTRAIT.marge)
  const pleine = GABARIT_PORTRAIT.h - 2 * GABARIT_PORTRAIT.marge - GABARIT_PORTRAIT.photo - GABARIT_PORTRAIT.ecart

  it('au zoom 1 : NOM, prénom, et les dates sur une ligne', () => {
    expect(texteDePortrait(jade, largeur, pleine)).toEqual({ nom: 'Romarin', prenom: 'Jade', dates: ['1902 – 1981'] })
  })

  it('« vivant·e » s’écrit tel que reçu', () => {
    expect(texteDePortrait({ ...jade, dates: 'vivant·e' }, largeur, pleine).dates).toEqual(['vivant·e'])
  })

  it('coupe une date trop longue sur son dernier séparateur, sans perdre un signe', () => {
    const longue = 'n. 12.12.1985 · vivant·e'
    const t = texteDePortrait({ ...jade, dates: longue }, 90, pleine)
    expect(t.dates).toEqual(['n. 12.12.1985 ·', 'vivant·e'])
    const tiret = texteDePortrait({ ...jade, dates: '12.3.1901 – 4.5.1987' }, 90, pleine)
    expect(tiret.dates).toEqual(['12.3.1901 –', '4.5.1987'])
    expect(tiret.dates.join(' ')).toBe('12.3.1901 – 4.5.1987')
  })

  it('quand la hauteur manque, abandonne les dates, puis le prénom ; le NOM reste', () => {
    const { nom, prenom, dates } = LIGNES_PORTRAIT
    expect(texteDePortrait(jade, largeur, nom.hauteur + prenom.hauteur + dates.hauteur).dates).toEqual(['1902 – 1981'])
    expect(texteDePortrait(jade, largeur, nom.hauteur + prenom.hauteur + dates.hauteur - 1)).toEqual({
      nom: 'Romarin',
      prenom: 'Jade',
      dates: [],
    })
    expect(texteDePortrait(jade, largeur, nom.hauteur + 4)).toEqual({ nom: 'Romarin', prenom: '', dates: [] })
    expect(texteDePortrait(jade, largeur, nom.hauteur - 1)).toEqual({ nom: '', prenom: '', dates: [] })
  })

  it('une date longue sans la place de deux lignes reste entière (le CSS la tronque)', () => {
    const { nom, prenom, dates } = LIGNES_PORTRAIT
    const t = texteDePortrait({ ...jade, dates: 'n. 12.12.1985 · vivant·e' }, 90, nom.hauteur + prenom.hauteur + dates.hauteur)
    expect(t.dates).toEqual(['n. 12.12.1985 · vivant·e'])
  })

  it('restreinte : « Personne vivante », jamais rien d’autre — sur deux lignes si elle est étroite', () => {
    const restreinte: EntreePortrait = { nom: 'Personne vivante', prenom: '', dates: '', restreinte: true }
    expect(texteDePortrait(restreinte, largeur, pleine)).toEqual({ nom: 'Personne vivante', prenom: '', dates: [] })
    expect(texteDePortrait(restreinte, 60, pleine)).toEqual({ nom: 'Personne', prenom: 'vivante', dates: [] })
    expect(texteDePortrait(restreinte, 60, LIGNES_PORTRAIT.nom.hauteur)).toEqual({ nom: 'Personne vivante', prenom: '', dates: [] })
  })

  it('aucune police sous 10 px', () => {
    for (const l of Object.values(LIGNES_PORTRAIT)) expect(l.taille).toBeGreaterThanOrEqual(10)
  })
})

describe('geometriePortrait', () => {
  it('au zoom 1 : photo centrée en haut, texte dessous, le tout dans la carte', () => {
    const { w, h, photo, marge, ecart } = GABARIT_PORTRAIT
    const g = geometriePortrait(100, 50, 1, 1)
    expect(g.photo).toEqual({ x: 100 + (w - photo) / 2, y: 50 + marge, d: photo })
    expect(g.texte.y).toBe(50 + marge + photo + ecart)
    expect(g.texte.y + g.texte.h).toBe(50 + h - marge)
    expect(g.texte.x).toBe(100 + marge)
    expect(g.texte.w).toBe(w - 2 * marge)
  })

  it('échelle verticale plus forte : photo carrée sur la petite échelle, le surplus au texte', () => {
    const g = geometriePortrait(0, 0, 0.6, 1)
    expect(g.photo.d).toBeCloseTo(GABARIT_PORTRAIT.photo * 0.6)
    expect(g.texte.h).toBeGreaterThan((GABARIT_PORTRAIT.h - GABARIT_PORTRAIT.photo - 16 - 8) * 0.6)
    expect(g.texte.y + g.texte.h).toBeLessThanOrEqual(GABARIT_PORTRAIT.h)
    // Ni la photo ni le texte ne sortent de la carte.
    expect(g.photo.x).toBeGreaterThanOrEqual(0)
    expect(g.photo.x + g.photo.d).toBeLessThanOrEqual(GABARIT_PORTRAIT.w * 0.6)
  })
})
