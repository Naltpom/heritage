import { describe, expect, it } from 'vitest'
import type { ArbreDAffiche } from '@/lib/api'
import { ArbreAffiche, estMarie } from './donnees'
import { annee, DATE_INCONNUE, FabriqueDArbre, jour } from './essai/arbreFictif'
import { ascendance, compter, descendance, lienDUnion, statutUnion, texteDeLien } from './structure'

/**
 * Un petit arbre écrit à la main, dont on connaît chaque place :
 *
 *            Basalte ∞ Ambre         (à deux places : l'implexe)
 *          ┌──────┴──────┐
 *   Natron ∞ Agate    Gypse ∞ Opale
 *          │               │
 *          │     Quartz ∞ Jade
 *          │            │
 *        Émeri  (H)  ∞  Marne (F)
 */
function petitArbre() {
  const a = new FabriqueDArbre(3)
  const basalte = a.personne('masculin', 'Sarriette', 1800, { prenoms: 'Basalte', deces: 1870 })
  const ambre = a.personne('feminin', 'Lichen', 1802, { prenoms: 'Ambre', deces: 1880 })
  const natron = a.personne('masculin', 'Sarriette', 1830, { prenoms: 'Natron', deces: 1900 })
  const agate = a.personne('feminin', 'Thym', 1832, { prenoms: 'Agate', deces: 1905 })
  const opale = a.personne('feminin', 'Sarriette', 1834, { prenoms: 'Opale', deces: 1910 })
  const gypse = a.personne('masculin', 'Orpin', 1830, { prenoms: 'Gypse', deces: 1890 })
  const jade = a.personne('feminin', 'Orpin', 1860, { prenoms: 'Jade', deces: 1930 })
  const emeri = a.personne('masculin', 'Sarriette', 1858, { prenoms: 'Émeri', deces: 1920 })
  const marne = a.personne('feminin', 'Sureau', 1885, { prenoms: 'Marne', deces: 1950 })
  const quartz = a.personne('masculin', 'Sureau', 1880, { prenoms: 'Quartz', deces: 1940 })
  const f1 = a.famille(basalte, ambre, annee(1828))
  a.enfant(f1, natron)
  a.enfant(f1, opale)
  const f2 = a.famille(natron, agate)
  a.enfant(f2, emeri)
  const f3 = a.famille(gypse, opale)
  a.enfant(f3, jade)
  const f4 = a.famille(quartz, jade)
  a.enfant(f4, marne)
  const couple = a.famille(emeri, marne, jour(1905, 4, 11))
  return { a, couple, ids: { basalte, ambre, natron, agate, opale, gypse, jade, emeri, marne, quartz, f1, f2, f3, f4 } }
}

describe('l’arbre en mémoire', () => {
  it('retient la filiation de naissance avant une adoption, puis la plus petite famille', () => {
    const { a, couple, ids } = petitArbre()
    const charge = a.charge(couple)
    // Une famille d'adoption, d'identifiant plus petit que la famille de naissance de Natron.
    const adoption = { id: 0, husband: ids.quartz, wife: null, marriage: DATE_INCONNUE, divorced: false, divorce: null, enfants: [{ personId: ids.natron, relation: 'adoption' }] }
    const A = new ArbreAffiche({ ...charge, familles: [adoption, ...charge.familles] })
    const [pere, mere] = A.parents(ids.natron)
    expect(pere?.id).toBe(ids.basalte)
    expect(mere?.id).toBe(ids.ambre)
  })

  it('dit « marié » selon `married` d’abord, puis la date, le lieu, la chaîne, puis le divorce', () => {
    const vide = { marriage: DATE_INCONNUE, divorced: false }
    expect(estMarie(vide)).toBe(false)
    expect(estMarie({ ...vide, marriage: { ...DATE_INCONNUE, place: 'Saule' } })).toBe(true)
    expect(estMarie({ ...vide, marriage: annee(1900) })).toBe(true)
    expect(estMarie({ ...vide, divorced: true }), 'un divorce suppose un mariage').toBe(true)
    expect(estMarie({ ...vide, married: true }), 'un mariage attesté sans date').toBe(true)
    expect(estMarie({ marriage: annee(1900), divorced: false, married: false }), 'le champ du serveur prime').toBe(false)
    expect(estMarie({ ...vide, married: null }), '`null` : on déduit').toBe(false)
  })

  it('range les unions d’une personne par date de mariage, sans date après', () => {
    const a = new FabriqueDArbre(5)
    const p = a.personne('masculin', 'Romarin', 1900, { prenoms: 'Cobalt', deces: 1980 })
    const sans = a.famille(p, a.personne('feminin', 'Thym', 1905, { prenoms: 'Agate', deces: 1990 }))
    const tard = a.famille(p, a.personne('feminin', 'Persil', 1910, { prenoms: 'Ambre', deces: 1995 }), annee(1950))
    const tot = a.famille(p, a.personne('feminin', 'Cresson', 1902, { prenoms: 'Jade', deces: 1940 }), annee(1925))
    const A = new ArbreAffiche(a.charge(tot))
    expect(A.unions(p).map((u) => u.fam)).toEqual([tot, tard, sans])
  })
})

describe('« masquer les dates des vivants »', () => {
  function avecVivants(): { A: ArbreAffiche; charge: ArbreDAffiche; ids: Record<string, number> } {
    const a = new FabriqueDArbre(9)
    const pere = a.personne('masculin', 'Romarin', 1950, { prenoms: 'Silex', deces: null })
    const mere = a.personne('feminin', 'Mélisse', 1952, { prenoms: 'Turquoise', deces: 2015 })
    const couple = a.famille(pere, mere, jour(1975, 6, 21, 'Saule, Ombrage, Sylvanie'))
    const enfant = a.personne('feminin', 'Romarin', 1980, { prenoms: 'Craie', deces: null })
    a.enfant(couple, enfant)
    const charge = a.charge(couple)
    return { A: new ArbreAffiche(charge), charge, ids: { pere, mere, enfant, couple } }
  }

  it('retire les dates d’une personne vivante et garde son nom', () => {
    const { A, ids } = avecVivants()
    const vivants = A.masquerVivants()
    expect([...vivants].sort()).toEqual([ids.pere, ids.enfant].sort())
    const pere = A.P.get(ids.pere)!
    expect(pere.naissance.connue).toBe(false)
    expect(pere.prenoms).toBe('Silex')
    expect(pere.nom).toBe('ROMARIN')
    // Une défunte garde les siennes.
    expect(A.P.get(ids.mere)!.naissance.texte()).not.toBe('')
  })

  it('retire la date et le lieu d’un mariage dont un conjoint est vivant, pas son signe', () => {
    const { A, ids } = avecVivants()
    A.masquerVivants()
    const [date, lieu] = A.mariage(ids.couple)
    expect(date.connue).toBe(false)
    expect(lieu).toBeNull()
    const [union] = A.unions(ids.pere)
    expect(union!.marie, 'le fait du mariage reste : signe ∞').toBe(true)
    expect(union!.mariageReel.connue, 'l’ordre des unions garde la vraie date').toBe(true)
  })
})

describe('l’ascendance', () => {
  it('numérote les places en sosa, colore les quatre quartiers et repère l’implexe', () => {
    const { a, couple, ids } = petitArbre()
    const A = new ArbreAffiche(a.charge(couple))
    const { racineH, racineF, noeuds } = ascendance(A, A.P.get(ids.emeri)!, A.P.get(ids.marne)!)
    expect(racineH.sosa).toBe(2)
    expect(racineF.sosa).toBe(3)
    const place = (id: number) => noeuds.filter((n) => n.p.id === id)
    expect(place(ids.natron)[0]!.sosa).toBe(4)
    expect(place(ids.natron)[0]!.quartier).toBe('HP')
    expect(place(ids.agate)[0]!.quartier).toBe('HM')
    expect(place(ids.quartz)[0]!.quartier).toBe('FP')
    expect(place(ids.jade)[0]!.quartier).toBe('FM')

    // Basalte et Ambre : grands-parents d'Émeri (sosa 8 et 9), arrière-grands-parents de
    // Marne par sa mère et sa grand-mère maternelle (sosa 30 et 31). Développés à leur première place seulement ; un même numéro
    // d'implexe pour les deux époux.
    expect(place(ids.basalte).map((n) => n.sosa).sort((x, y) => x - y)).toEqual([8, 30])
    expect(place(ids.basalte).map((n) => n.implexe)).toEqual([1, 1])
    expect(place(ids.ambre).map((n) => n.implexe)).toEqual([1, 1])
    expect(place(ids.basalte).find((n) => n.sosa === 30)!.tronque).toBe(true)
    expect(place(ids.basalte).find((n) => n.sosa === 8)!.tronque).toBe(false)
  })

  it('s’arrête au nombre de générations demandé, et ne compte les implexes que sur ce qui reste', () => {
    const { a, couple, ids } = petitArbre()
    const A = new ArbreAffiche(a.charge(couple))
    const { noeuds } = ascendance(A, A.P.get(ids.emeri)!, A.P.get(ids.marne)!, 2)
    expect(Math.max(...noeuds.map((n) => n.niveau))).toBe(2)
    expect(noeuds.some((n) => n.implexe !== null)).toBe(false)
  })

  it('accepte un couple sans aucune ascendance', () => {
    const a = new FabriqueDArbre(1)
    const h = a.personne('masculin', 'Tanaisie', 1880, { prenoms: 'Onyx', deces: 1950 })
    const f = a.personne('feminin', 'Armoise', 1885, { prenoms: 'Craie', deces: 1960 })
    const A = new ArbreAffiche(a.charge(a.famille(h, f)))
    const { noeuds } = ascendance(A, A.P.get(h)!, A.P.get(f)!)
    expect(noeuds).toHaveLength(2)
  })
})

describe('la descendance, et ce que dit chaque trait d’union', () => {
  function familleRecomposee() {
    const a = new FabriqueDArbre(11)
    const h = a.personne('masculin', 'Romarin', 1890, { prenoms: 'Natron', deces: 1960 })
    const f = a.personne('feminin', 'Sureau', 1892, { prenoms: 'Marne', deces: 1970 })
    const couple = a.famille(h, f, annee(1915))
    const fils = a.personne('masculin', 'Romarin', 1920, { prenoms: 'Cobalt', deces: 1999 })
    const fille = a.personne('feminin', 'Romarin', 1923, { prenoms: 'Opale', deces: 2010 })
    a.enfant(couple, fils)
    a.enfant(couple, fille)
    // Le fils : veuf de sa première femme (morte en 1950), remarié en 1953.
    const premiere = a.personne('feminin', 'Thym', 1921, { prenoms: 'Agate', deces: 1950 })
    const seconde = a.personne('feminin', 'Persil', 1925, { prenoms: 'Ambre', deces: 2012 })
    const u1 = a.famille(fils, premiere, annee(1945))
    const u2 = a.famille(fils, seconde, annee(1953))
    a.enfant(u1, a.personne('masculin', 'Romarin', 1946, { prenoms: 'Silex', deces: null }))
    // La fille : divorcée, puis en union sans mariage connu.
    const ex = a.personne('masculin', 'Fenouil', 1920, { prenoms: 'Galène', deces: 1990 })
    const compagnon = a.personne('masculin', 'Cerfeuil', 1922, { prenoms: 'Gypse', deces: 2000 })
    const ud = a.famille(ex, fille, DATE_INCONNUE, true)
    const ul = a.famille(compagnon, fille)
    return { A: new ArbreAffiche(a.charge(couple)), h, f, fils, fille, u1, u2, ud, ul }
  }

  it('écrit « veuf » devant un remariage, « div. » sur un divorce même sans date, et les rangs', () => {
    const t = familleRecomposee()
    const { enfants } = descendance(t.A, t.A.P.get(t.h)!, t.A.P.get(t.f)!)
    const [cobalt, opale] = enfants
    expect(cobalt!.unions.map(([, i]) => [i.statut, i.rang, i.total, i.marie])).toEqual([
      ['veuf', 1, 2, true],
      [null, 2, 2, true],
    ])
    expect(opale!.unions.map(([, i]) => [i.statut, i.rang, i.marie])).toEqual([
      ['div', 1, true], // un divorce suppose un mariage : ∞ barré, jamais « & »
      [null, 2, false], // union sans mariage connu : « & »
    ])
  })

  it('n’écrit pas « veuve » quand le conjoint est mort après le remariage, ni après la personne', () => {
    const a = new FabriqueDArbre(13)
    const p = a.personne('feminin', 'Romarin', 1900, { prenoms: 'Jade', deces: 1960 })
    const premier = a.personne('masculin', 'Thym', 1898, { prenoms: 'Onyx', deces: 1940 })
    const second = a.personne('masculin', 'Persil', 1899, { prenoms: 'Quartz', deces: 1970 })
    a.famille(premier, p, annee(1920))
    a.famille(second, p, annee(1935)) // avant la mort du premier : pas un veuvage
    const A = new ArbreAffiche(a.charge(1))
    const us = A.unions(p)
    expect(statutUnion(A.P.get(p)!, us[0]!, us.slice(1))).toBeNull()
  })

  it('écrit « veuve » au féminin', () => {
    const a = new FabriqueDArbre(17)
    const p = a.personne('feminin', 'Romarin', 1900, { prenoms: 'Jade', deces: 1980 })
    a.famille(a.personne('masculin', 'Thym', 1898, { prenoms: 'Onyx', deces: 1930 }), p, annee(1920))
    a.famille(a.personne('masculin', 'Persil', 1899, { prenoms: 'Quartz', deces: 1970 }), p, annee(1935))
    const A = new ArbreAffiche(a.charge(1))
    const us = A.unions(p)
    expect(statutUnion(A.P.get(p)!, us[0]!, us.slice(1))).toBe('veuve')
  })

  it('compte les descendants par génération, et rend une descendance vide sans erreur', () => {
    const t = familleRecomposee()
    const { enfants } = descendance(t.A, t.A.P.get(t.h)!, t.A.P.get(t.f)!)
    expect(Object.fromEntries(compter(enfants))).toEqual({ 1: 2, 2: 1 })

    const a = new FabriqueDArbre(1)
    const h = a.personne('masculin', 'Tanaisie', 1880, { prenoms: 'Onyx', deces: 1950 })
    const f = a.personne('feminin', 'Armoise', 1885, { prenoms: 'Craie', deces: 1960 })
    const A = new ArbreAffiche(a.charge(a.famille(h, f)))
    expect(descendance(A, A.P.get(h)!, A.P.get(f)!).enfants).toEqual([])
  })
})

describe('le signe d’une union (partagé par les boîtes, le soleil, les médaillons et l’éventail)', () => {
  const lien = (marie: boolean, statut: 'div' | 'veuf' | 'veuve' | null, rang = 1, total = 1) => lienDUnion({ marie, statut, rang, total })

  it('∞ pour un mariage connu, & pour une union sans mariage', () => {
    expect(lien(true, null)).toEqual({ signe: '∞', barre: false, rang: null })
    expect(lien(false, null)).toEqual({ signe: '&', barre: false, rang: null })
  })

  it('barre le signe d’un divorce, pas celui d’un veuvage', () => {
    expect(lien(true, 'div').barre).toBe(true)
    expect(lien(true, 'veuf').barre).toBe(false)
    expect(lien(true, 'veuve').barre).toBe(false)
  })

  it('n’écrit le rang que si la personne a plusieurs unions', () => {
    expect(texteDeLien(lien(true, null, 1, 1))).toBe('∞')
    expect(texteDeLien(lien(true, null, 2, 2))).toBe('∞2')
    expect(texteDeLien(lien(false, null, 2, 3))).toBe('&2')
    expect(texteDeLien(lien(true, 'div', 1, 2))).toBe('∞1')
  })
})
