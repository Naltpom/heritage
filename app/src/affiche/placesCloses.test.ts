import { describe, expect, it } from 'vitest'
import { ArbreAffiche } from './donnees'
import { arbreDeCousins, arbreFictif, avecFinsDeRecherche, avecLeCoupleEnFinDeRecherche } from './essai/arbreFictif'
import { ascendance, chiffresDeLAscendance, type Anc } from './structure'

/*
 * Les places closes (ADR 0023, révision du 18/11/2026) : la case « Recherche close » posée par
 * `ascendance()` à la place d'un parent introuvable — elle remplace le petit « mur » qu'on
 * dessinait dans le coin de la case de l'enfant, et qu'on ne voyait pas.
 */
describe('les places closes de l’ascendance', () => {
  const { essai, sommets, parentServi } = avecFinsDeRecherche(arbreFictif())
  const A = new ArbreAffiche(essai.charge)
  const H = A.P.get(essai.mari)!
  const F = A.P.get(essai.femme)!
  const { noeuds } = ascendance(A, H, F, Infinity)
  const places = noeuds.filter((n) => n.fin !== null)
  const placeDe = (enfant: number, cote: 'pere' | 'mere'): Anc | undefined => places.find((n) => n.enfant?.p.id === enfant && n.fin!.cote === cote)

  it('une place par côté clos d’un sommet de branche, et aucune autre', () => {
    expect(places).toHaveLength(4)
    expect(placeDe(sommets[0]!, 'pere')?.fin).toEqual({ cote: 'pere', reason: 'pere_inconnu', beforeYear: null })
    expect(placeDe(sommets[1]!, 'mere')?.fin).toEqual({ cote: 'mere', reason: 'registres_inexistants', beforeYear: 1668 })
    expect(placeDe(sommets[2]!, 'pere')?.fin?.reason).toBe('lacune')
    expect(placeDe(sommets[2]!, 'mere')?.fin?.reason).toBe('lacune')
  })

  it('la place est celle du parent : génération suivante, sosa 2n ou 2n + 1, quartier de l’enfant, liée à lui', () => {
    for (const place of places) {
      const enfant = place.enfant!
      expect(place.niveau).toBe(enfant.niveau + 1)
      expect(place.sosa).toBe(2 * enfant.sosa + (place.fin!.cote === 'pere' ? 0 : 1))
      expect(place.quartier).toBe(enfant.quartier)
      expect(place.fin!.cote === 'pere' ? enfant.pere : enfant.mere).toBe(place)
      expect(place.parents).toEqual([])
    }
  })

  it('n’est pas une personne : identifiant négatif et unique, ni nom, ni date', () => {
    const ids = places.map((n) => n.p.id)
    expect(new Set(ids).size).toBe(ids.length)
    for (const place of places) {
      expect(place.p.id).toBeLessThan(0)
      expect(A.P.has(place.p.id)).toBe(false)
      expect([place.p.prenoms, place.p.nom]).toEqual(['', ''])
      expect(place.p.annee).toBeNull()
      expect(place.implexe).toBeNull()
    }
  })

  it('un parent servi l’emporte sur une fin de recherche restée enregistrée', () => {
    expect(A.P.get(parentServi)!.finsDeRecherche.finDeRecherchePere).toBeDefined()
    expect(places.some((n) => n.enfant?.p.id === parentServi)).toBe(false)
  })

  it('sans fin de recherche, aucune place close', () => {
    const sans = new ArbreAffiche(arbreFictif().charge)
    const s = arbreFictif()
    expect(ascendance(sans, sans.P.get(s.mari)!, sans.P.get(s.femme)!, Infinity).noeuds.some((n) => n.fin !== null)).toBe(false)
  })

  it('une personne restreinte n’a jamais de fin de recherche, même si la charge en portait une', () => {
    const base = arbreFictif().charge
    const restreinte = { id: 99999, visibilite: 'restreinte', sex: 'inconnu', finDeRecherchePere: { reason: 'lacune', beforeYear: null } }
    const B = new ArbreAffiche({ ...base, personnes: [...base.personnes, restreinte] } as unknown as typeof base)
    expect(B.P.get(99999)!.finsDeRecherche).toEqual({})
  })

  it('rien au-delà de la profondeur dessinée, rien sur une place secondaire d’implexe', () => {
    const profondeur = Math.min(...places.map((n) => n.niveau))
    const coupe = ascendance(A, H, F, profondeur - 1).noeuds
    expect(coupe.filter((n) => n.fin !== null && n.niveau > profondeur - 1)).toEqual([])
    for (const n of coupe) expect(n.niveau).toBeLessThanOrEqual(profondeur - 1)
    for (const n of noeuds.filter((x) => x.tronque)) expect([n.pere, n.mere]).toEqual([null, null])
  })

  it('les chiffres du pied ne comptent que les vrais ancêtres', () => {
    const s = arbreFictif()
    const sans = new ArbreAffiche(s.charge)
    expect(chiffresDeLAscendance(noeuds)).toEqual(chiffresDeLAscendance(ascendance(sans, sans.P.get(s.mari)!, sans.P.get(s.femme)!, Infinity).noeuds))
  })
})

describe('la place close du couple lui-même', () => {
  it('le père inconnu de la femme : sosa 6, premier anneau, quartier de son père', () => {
    const couple = avecLeCoupleEnFinDeRecherche(arbreDeCousins())
    const A = new ArbreAffiche(couple.charge)
    const { noeuds } = ascendance(A, A.P.get(couple.mari)!, A.P.get(couple.femme)!, Infinity)
    const places = noeuds.filter((n) => n.fin !== null)
    expect(places.map((n) => [n.niveau, n.sosa, n.quartier, n.fin!.reason])).toEqual([[1, 6, 'FP', 'pere_inconnu']])
    // La mère, elle, est servie : elle est l'autre parent de la femme.
    expect(places[0]!.enfant!.mere).not.toBeNull()
  })
})
