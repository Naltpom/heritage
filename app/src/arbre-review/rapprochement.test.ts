import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'
import { sansFamillesParAlliance } from '@/histoire/alliances'
import { genealogieSynthetique } from '@/histoire/banc/genealogie'
import { auFormat } from '@/histoire/cartes'
import { noeudsSansLien, sansPersonnesSansLien } from '@/histoire/sansLien'
import { brancherCommeLeServeur } from './banc/branches'
import { placer, type Abscisses, type OptionsDAbscisses } from './abscisses'
import { ECART_COUPLE, ECART_FAMILLE, ECART_FRATRIE, PRIX_D_UN_CROISEMENT, PRIX_D_UNE_FRATRIE_COUPEE } from './disposition'
import { affiner, compterBus, construireCouches, ordreEnSablier, type Couches } from './ordre'
import { preparer, type Structure } from './preparation'
import { ecartTotal, rapprocher, type OptionsDeRapprochement } from './rapprochement'

/**
 * Ce que ces tests protègent : les retours du 23/10/2026 — « si un trait doit couper certains
 * c'est ok, genre entre 2 parents et leur enfant si c'est direct et qu'il y a personne
 * au-dessus [...] et qu'il y a la place ». L'ordre des rangées est DÉRANGÉ à la main (une
 * fratrie, un parent, envoyés au bout de leur rangée, là où l'alignement ne peut plus les
 * ramener) ; le rapprochement doit les remettre sous leurs parents, ou au-dessus de leurs
 * enfants — et ne jamais payer plus d'un croisement par `PRIX_D_UN_CROISEMENT` pixels.
 */

function nouvelArbre() {
  let n = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const personne = (cle: string, sex: Sexe, annee: number, branch: Branche = 'tr'): string => {
    const k = n++
    const p: NoeudPersonne = {
      id: `p${k}`,
      type: 'personne',
      personId: k,
      x: 0,
      y: 0,
      w: 160,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom${k}`,
      sex,
      generation: 0,
      branch,
      parente: 'famille',
      vivant: false,
      visibilite: 'complete',
      etiquetteDates: `${annee} – `,
    }
    noeuds.push(p)
    return p.id
  }
  const famille = (epoux: string[], enfants: string[]): string => {
    const f: NoeudFamille = { id: `f${n++}`, type: 'famille', familyId: n, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(f)
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
    return f.id
  }
  const finir = (ego: string): Graphe => ({
    empreinte: '0'.repeat(64),
    ego,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  })
  return { personne, famille, finir }
}

/** Le moteur pas à pas, jusqu'aux abscisses : de quoi déranger l'ordre, puis rapprocher. */
function jusquAuxAbscisses(g: Graphe) {
  const s = preparer(auFormat(g, 'compact'), { ecartCouple: ECART_COUPLE })
  const o = { fratriesParNaissance: false, largeurFactice: 16 }
  const c = construireCouches(s, ordreEnSablier(s, 'servi'), o)
  affiner(s, c, o)
  const abscisses: OptionsDAbscisses = {
    ecartCouple: ECART_COUPLE,
    ecartFratrie: ECART_FRATRIE,
    ecartFamille: ECART_FAMILLE,
    ecartFactice: 28,
    ecartParties: 2 * s.largeurDeCarte + 44,
    tours: 3,
    passes: 24,
    aligner: true,
  }
  const rapprochement: OptionsDeRapprochement = {
    ecartFamille: ECART_FAMILLE,
    ecartFratrie: ECART_FRATRIE,
    ecartFactice: 28,
    ecartParties: abscisses.ecartParties,
    prixDUnCroisement: PRIX_D_UN_CROISEMENT,
    prixDUneFratrieCoupee: PRIX_D_UNE_FRATRIE_COUPEE,
    gainMinimal: s.largeurDeCarte,
    elementsMax: 400,
  }
  return { s, c, abscisses, rapprochement }
}

const elementDe = (s: Structure, c: Couches, id: string): number => c.elementDuFoyer[s.foyerDe[s.indexDe.get(id)!]!]!
/** Envoie des éléments au bout de leur rangée : à droite, ou à gauche (`debut`). */
function auBout(c: Couches, els: number[], debut = false): void {
  const k = c.ordre.findIndex((l) => l.includes(els[0]!))
  const reste = c.ordre[k]!.filter((e) => !els.includes(e))
  c.ordre[k] = debut ? [...els, ...reste] : [...reste, ...els]
}
/** Le centre d'un élément, ou de plusieurs (de bord à bord). */
const centre = (c: Couches, a: Abscisses, els: number[]): number =>
  (Math.min(...els.map((e) => a.x[e]!)) + Math.max(...els.map((e) => a.x[e]! + c.elements[e]!.largeur))) / 2
const cx = (s: Structure, a: Abscisses, id: string): number => a.cx[s.indexDe.get(id)!]!

describe('le rapprochement', () => {
  it('ramène sous ses parents une fratrie envoyée au bout de sa rangée', () => {
    // Un couple d'ancêtres et leurs quatre enfants mariés, chacun avec ses enfants ; la fratrie
    // des premiers part au bout de la rangée, par-delà toutes les autres.
    const a = nouvelArbre()
    const ancetres = [a.personne('g1', 'masculin', 1900), a.personne('g2', 'feminin', 1902)]
    const couples = ['p', 'q', 'r', 't'].map((k, i) => {
      const lui = a.personne(`${k}1`, 'masculin', 1925 + i)
      const elle = a.personne(`${k}2`, 'feminin', 1926 + i)
      const enfants = [a.personne(`${k}a`, 'masculin', 1950 + i), a.personne(`${k}b`, 'feminin', 1953 + i)]
      a.famille([lui, elle], enfants)
      return { lui, elle, enfants }
    })
    a.famille(ancetres, couples.map((x) => x.lui))
    const { s, c, abscisses, rapprochement } = jusquAuxAbscisses(a.finir(couples[0]!.enfants[0]!))
    // La fratrie la plus à gauche de sa rangée part au bout, à droite.
    const rangee = c.ordre.find((l) => l.includes(elementDe(s, c, couples[0]!.enfants[0]!)))!
    const premiere = couples.find((x) => x.enfants.some((e) => elementDe(s, c, e) === rangee[0]))!
    const fratrie = premiere.enfants.map((e) => elementDe(s, c, e))
    const parents = (ab: Abscisses) => (cx(s, ab, premiere.lui) + cx(s, ab, premiere.elle)) / 2
    const ecart = (ab: Abscisses) => Math.abs(centre(c, ab, fratrie) - parents(ab))
    const depart = placer(s, c, abscisses)
    const avant = compterBus(s, c)
    auBout(c, fratrie)
    const derangee = placer(s, c, abscisses)
    expect(ecart(derangee)).toBeGreaterThan(ecart(depart) + 2 * s.largeurDeCarte)
    expect(compterBus(s, c)).toBeGreaterThan(avant)
    expect(rapprocher(s, c, derangee, rapprochement)).toBeGreaterThan(0)
    const ramenee = placer(s, c, abscisses)
    // Revenue à sa place : sous ses parents, autant que les fratries voisines le laissent.
    expect(ecart(ramenee)).toBeLessThan(ecart(depart) + 1)
    expect(compterBus(s, c)).toBe(avant)
    expect(ecartTotal(s, c, ramenee, ECART_FRATRIE)).toBeLessThan(ecartTotal(s, c, derangee, ECART_FRATRIE))
  })

  it('met un parent sans personne au-dessus au-dessus de son enfant', () => {
    // Le père de la belle-fille n'a personne au-dessus de lui dans l'arbre : rangé au bout de
    // sa rangée, rien ne le retient là, il va au-dessus d'elle.
    const a = nouvelArbre()
    const g1 = a.personne('g1', 'masculin', 1900)
    const g2 = a.personne('g2', 'feminin', 1902)
    const fils = a.personne('fils', 'masculin', 1930)
    const belleFille = a.personne('belleFille', 'feminin', 1932)
    const sonPere = a.personne('sonPere', 'masculin', 1901)
    a.famille([sonPere], [belleFille])
    const enfant = a.personne('enfant', 'masculin', 1960)
    a.famille([fils, belleFille], [enfant])
    // D'autres enfants de g1 et g2, mariés à des enfants de couples sans personne au-dessus.
    const autres = ['x', 'y', 'z', 'w'].map((k, i) => {
      const lui = a.personne(`${k}`, 'masculin', 1934 + i)
      const elle = a.personne(`${k}Femme`, 'feminin', 1935 + i)
      a.famille([a.personne(`${k}PereDeLaFemme`, 'masculin', 1905 + i), a.personne(`${k}MereDeLaFemme`, 'feminin', 1906 + i)], [elle])
      a.famille([lui, elle], [a.personne(`${k}Enfant`, 'feminin', 1962 + i)])
      return lui
    })
    a.famille([g1, g2], [fils, ...autres])
    const { s, c, abscisses, rapprochement } = jusquAuxAbscisses(a.finir(enfant))
    const lui = elementDe(s, c, sonPere)
    // Au bout de sa rangée qui est loin de sa fille.
    const depart = placer(s, c, abscisses)
    const milieuDeLaRangee = (Math.min(...c.ordre.flat().map((e) => depart.x[e]!)) + Math.max(...c.ordre.flat().map((e) => depart.x[e]!))) / 2
    auBout(c, [lui], cx(s, depart, belleFille) > milieuDeLaRangee)
    const derange = placer(s, c, abscisses)
    const sousLui = (ab: Abscisses) => Math.abs(cx(s, ab, sonPere) - cx(s, ab, belleFille))
    expect(sousLui(derange)).toBeGreaterThan(2 * s.largeurDeCarte)
    // Comme `disposition.ts` : un passage, les abscisses refaites, et un autre tant qu'il en bouge.
    let ramene = derange
    for (let fois = 0; fois < 3; fois++) {
      if (!rapprocher(s, c, ramene, rapprochement)) break
      ramene = placer(s, c, abscisses)
    }
    expect(sousLui(ramene)).toBeLessThan(s.largeurDeCarte)
  })

  it('pose une fratrie au bord d’une fratrie étrangère, jamais en son milieu', () => {
    // Deux frères mariés : l'un a un enfant, l'autre six — si larges, centrés sous leurs
    // parents, qu'ils passent aussi sous l'oncle. L'enfant unique, envoyé au bout de la rangée,
    // revient sous son père en se posant AU BORD des six cousins, pas entre deux d'entre eux.
    const a = nouvelArbre()
    const g1 = a.personne('g1', 'masculin', 1900)
    const g2 = a.personne('g2', 'feminin', 1902)
    const p = a.personne('p', 'masculin', 1925)
    const pFemme = a.personne('pFemme', 'feminin', 1926)
    const q = a.personne('q', 'masculin', 1928)
    const qFemme = a.personne('qFemme', 'feminin', 1929)
    a.famille([g1, g2], [p, q])
    const unique = a.personne('unique', 'feminin', 1950)
    a.famille([p, pFemme], [unique])
    const cousins = ['a', 'b', 'c', 'd', 'e', 'f'].map((k, i) => a.personne(`cousin${k}`, i % 2 ? 'feminin' : 'masculin', 1952 + i))
    a.famille([q, qFemme], cousins)
    const { s, c, abscisses, rapprochement } = jusquAuxAbscisses(a.finir(unique))
    const lui = elementDe(s, c, unique)
    const eux = cousins.map((e) => elementDe(s, c, e))
    const sousSonPere = (ab: Abscisses) => Math.abs(cx(s, ab, unique) - (cx(s, ab, p) + cx(s, ab, pFemme)) / 2)
    const depart = placer(s, c, abscisses)
    const avant = compterBus(s, c)
    auBout(c, [lui], cx(s, depart, unique) > centre(c, depart, eux))
    const derange = placer(s, c, abscisses)
    expect(sousSonPere(derange)).toBeGreaterThan(sousSonPere(depart) + 2 * s.largeurDeCarte)
    expect(rapprocher(s, c, derange, rapprochement)).toBeGreaterThan(0)
    const ramene = placer(s, c, abscisses)
    expect(sousSonPere(ramene)).toBeLessThan(sousSonPere(depart) + 1)
    // Les six cousins d'un seul tenant dans leur rangée, et pas un croisement de plus.
    const rangee = c.ordre.find((l) => l.includes(lui))!
    const rangs = eux.map((e) => rangee.indexOf(e)).sort((u, v) => u - v)
    expect(rangs[rangs.length - 1]! - rangs[0]! + 1).toBe(eux.length)
    expect(compterBus(s, c)).toBe(avant)
  })

  it('ne coupe jamais une fratrie dont les membres ont leurs propres enfants', () => {
    // Croisements et fratries coupées à prix nul : l'enfant unique se glisse entre ses six
    // cousins s'ils n'ont pas d'enfants — jamais s'ils en ont (coupée, leur fratrie ne
    // s'alignerait plus d'un bloc, et chacun filerait vers ses propres enfants).
    const coupe = (cousinsParents: boolean): boolean => {
      const a = nouvelArbre()
      const g1 = a.personne('g1', 'masculin', 1900)
      const g2 = a.personne('g2', 'feminin', 1902)
      const p = a.personne('p', 'masculin', 1925)
      const pFemme = a.personne('pFemme', 'feminin', 1926)
      const q = a.personne('q', 'masculin', 1928)
      const qFemme = a.personne('qFemme', 'feminin', 1929)
      a.famille([g1, g2], [p, q])
      const unique = a.personne('unique', 'feminin', 1950)
      a.famille([p, pFemme], [unique])
      const cousins = ['a', 'b', 'c', 'd', 'e', 'f'].map((k, i) => a.personne(`cousin${k}`, i % 2 ? 'feminin' : 'masculin', 1952 + i))
      a.famille([q, qFemme], cousins)
      if (cousinsParents)
        cousins.forEach((cousin, i) => {
          const conjoint = a.personne(`conjoint${i}`, i % 2 ? 'masculin' : 'feminin', 1953 + i)
          a.famille(i % 2 ? [conjoint, cousin] : [cousin, conjoint], [a.personne(`petit${i}`, 'masculin', 1980 + i)])
        })
      const { s, c, abscisses, rapprochement } = jusquAuxAbscisses(a.finir(unique))
      const lui = elementDe(s, c, unique)
      const eux = cousins.map((e) => elementDe(s, c, e))
      const depart = placer(s, c, abscisses)
      auBout(c, [lui], cx(s, depart, unique) > centre(c, depart, eux))
      rapprocher(s, c, placer(s, c, abscisses), { ...rapprochement, prixDUnCroisement: 0, prixDUneFratrieCoupee: 0 })
      const rangee = c.ordre.find((l) => l.includes(lui))!
      const rangs = eux.map((e) => rangee.indexOf(e)).sort((u, v) => u - v)
      return rangs[rangs.length - 1]! - rangs[0]! + 1 !== eux.length
    }
    expect(coupe(false)).toBe(true)
    expect(coupe(true)).toBe(false)
  })

  it('ne paie jamais un croisement qui ne rapporte rien : à prix démesuré, aucun de plus', () => {
    const brut = brancherCommeLeServeur(genealogieSynthetique({ personnes: 1000, graine: 7 }))
    const graphe = sansPersonnesSansLien(sansFamillesParAlliance(brut), noeudsSansLien(brut))
    const { s, c, abscisses, rapprochement } = jusquAuxAbscisses(graphe)
    const a = placer(s, c, abscisses)
    const avant = compterBus(s, c)
    rapprocher(s, c, a, { ...rapprochement, prixDUnCroisement: 1e12, prixDUneFratrieCoupee: 1e12 })
    expect(compterBus(s, c)).toBeLessThanOrEqual(avant)
  }, 60_000)
})
