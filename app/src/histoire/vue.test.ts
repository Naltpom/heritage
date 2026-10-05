import { describe, expect, it } from 'vitest'
import {
  ECHELLE_MAX,
  echelleMin,
  jaugeDuZoom,
  bornerSeuilDesNoms,
  SEUIL_DES_NOMS,
  reductionDuTexte,
  echelleVerticale,
  niveau,
  syDe,
  texteDeFiche,
  trajectoire,
  versMonde,
  zoomAutour,
  type Canevas,
} from './vue'

const canevas: Canevas = { largeur: 1200, hauteur: 700, largeurMonde: 24000, hauteurMonde: 900 }

describe('niveaux de détail', () => {
  it('suit les seuils 0,16 / 0,45 : Ensemble, Familles, Fiches', () => {
    expect(niveau(0.05)).toBe(0)
    expect(niveau(0.159)).toBe(0)
    expect(niveau(0.16)).toBe(1)
    expect(niveau(0.449)).toBe(1)
    expect(niveau(0.45)).toBe(2)
    expect(niveau(0.44)).toBe(1)
    expect(niveau(1.8)).toBe(2)
  })

  it('écrit les noms dès le seuil choisi par le lecteur (grand écran, télévision)', () => {
    expect(niveau(0.3, 0.25)).toBe(2)
    expect(niveau(0.2, 0.25)).toBe(1)
    // Relevé : les noms attendent davantage de zoom.
    expect(niveau(0.6, 0.7)).toBe(1)
    expect(niveau(0.7, 0.7)).toBe(2)
  })

  it('passe de l’Ensemble aux Fiches quand le seuil descend sous celui des familles', () => {
    expect(niveau(0.11, 0.1)).toBe(2)
    expect(niveau(0.09, 0.1)).toBe(0)
  })

  it('borne le seuil des noms, et rend le défaut pour une valeur illisible', () => {
    expect(bornerSeuilDesNoms(0)).toBe(SEUIL_DES_NOMS.min)
    expect(bornerSeuilDesNoms(5)).toBe(SEUIL_DES_NOMS.max)
    expect(bornerSeuilDesNoms(Number.NaN)).toBe(0.45)
    expect(bornerSeuilDesNoms(0.3)).toBe(0.3)
  })
})

describe('le texte d’une fiche', () => {
  const natron = { nom: 'Natron Romarin', prenom: 'Natron', dates: '2012 –' }

  it('écrit le nom complet et les dates quand ils tiennent', () => {
    expect(texteDeFiche(natron, 120, 40)).toEqual({ t1: 'Natron Romarin', t2: '2012 –', taille: 12 })
  })

  it('laisse tomber les dates sur une fiche trop basse', () => {
    expect(texteDeFiche(natron, 120, 24).t2).toBe('')
  })

  it('se replie sur le prénom quand le nom ne tient pas', () => {
    expect(texteDeFiche(natron, 60, 40)).toEqual({ t1: 'Natron', t2: '', taille: 11 })
  })

  it('écrit le nom quand il n’y a pas de prénom connu', () => {
    expect(texteDeFiche({ nom: 'Sarriette', prenom: '', dates: '' }, 20, 40).t1).toBe('Sarriette')
  })

  it('n’annonce jamais complet un nom qui serait tronqué', () => {
    for (let largeur = 20; largeur < 200; largeur += 5) {
      const { t1, taille } = texteDeFiche(natron, largeur, 40)
      if (t1 === natron.nom) expect(natron.nom.length * taille * 0.55).toBeLessThanOrEqual(largeur)
    }
  })
})

describe('échelle verticale', () => {
  it('garde les rangées lisibles au dézoom', () => {
    // fitY = (700 − 90) / 900 ≈ 0,68 ; à s = 0,01, 30·s = 0,3.
    expect(echelleVerticale(0.01, 700, 900)).toBeCloseTo(0.3)
    expect(echelleVerticale(0.05, 700, 900)).toBeCloseTo(610 / 900)
  })

  it('redevient uniforme dès que s atteint fitY', () => {
    expect(echelleVerticale(0.8, 700, 900)).toBe(0.8)
    expect(echelleVerticale(1.5, 700, 900)).toBe(1.5)
  })

  it('est continue', () => {
    for (let s = 0.005; s < 1.5; s += 0.001) {
      expect(Math.abs(echelleVerticale(s + 0.001, 700, 900) - echelleVerticale(s, 700, 900))).toBeLessThan(0.031)
    }
  })

  it('ne descend jamais sous s', () => {
    for (const s of [0.01, 0.1, 0.5, 1]) expect(echelleVerticale(s, 200, 5000)).toBeGreaterThanOrEqual(s)
  })
})

describe('zoom autour d’un point', () => {
  it('garde fixe le point du monde sous le curseur, sur les deux axes', () => {
    const depart = { s: 0.2, tx: 100, ty: 40 }
    const avant = versMonde(depart, canevas, 640, 300)
    for (const facteur of [1.6, 1 / 1.6, 3, 0.9]) {
      const apres = zoomAutour(depart, canevas, 640, 300, facteur)
      const point = versMonde(apres, canevas, 640, 300)
      expect(point.x).toBeCloseTo(avant.x, 6)
      expect(point.y).toBeCloseTo(avant.y, 6)
    }
  })

  it('reste dans les bornes', () => {
    expect(zoomAutour({ s: 1, tx: 0, ty: 0 }, canevas, 0, 0, 100).s).toBe(ECHELLE_MAX)
    expect(zoomAutour({ s: 0.1, tx: 0, ty: 0 }, canevas, 0, 0, 0.0001).s).toBeCloseTo((0.8 * 1200) / 24000)
  })
})

describe('vol vers une personne', () => {
  it('part du cadrage courant et arrive centré sur la cible', () => {
    const depart = { s: 0.05, tx: 150, ty: 20 }
    const vol = trajectoire(depart, canevas, 1, 5000, 400)
    expect(vol(0).s).toBeCloseTo(depart.s)
    expect(vol(0).tx).toBeCloseTo(depart.tx)
    const fin = vol(1)
    expect(fin.s).toBeCloseTo(1)
    expect(fin.tx + 5000 * fin.s).toBeCloseTo(600)
    expect(fin.ty + 400 * syDe(fin.s, canevas)).toBeCloseTo(350)
  })
})

describe('la jauge de zoom (administration)', () => {
  it('donne le zoom en pour cent et sa place entre le minimum et le maximum', () => {
    const min = echelleMin(canevas.largeur, canevas.largeurMonde)
    expect(jaugeDuZoom(1, canevas).pourcent).toBe(100)
    expect(jaugeDuZoom(0.304, canevas).pourcent).toBe(30)
    expect(jaugeDuZoom(min, canevas).position).toBe(0)
    expect(jaugeDuZoom(ECHELLE_MAX, canevas).position).toBe(1)
  })

  it('range les seuils des niveaux dans l’ordre, à l’intérieur de la jauge', () => {
    const { seuils, position } = jaugeDuZoom(0.3, canevas)
    expect(seuils[0]).toBeGreaterThan(0)
    expect(seuils[0]).toBeLessThan(position)
    expect(position).toBeLessThan(seuils[1])
    expect(seuils[1]).toBeLessThan(1)
  })
})

describe('la réduction du texte sous 80 %', () => {
  it('écrit à 45 % la fiche de 80 % en plus petit, et ne grossit plus au-delà', () => {
    expect(reductionDuTexte(0.4)).toBeCloseTo(0.5)
    expect(reductionDuTexte(0.8)).toBe(1)
    expect(reductionDuTexte(1.5)).toBe(1)
  })
})
