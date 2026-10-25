import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudPersonne, Parente, Sexe } from '@/lib/api'
import {
  aDesFamillesParAlliance,
  classerParente,
  estParAlliance,
  familleDesConjoints,
  groupesParAlliance,
  sansFamillesParAlliance,
} from './alliances'

/**
 * Ce que ces tests protègent : la règle de l'utilisateur du 2026-10-01 — est « de la
 * famille » du lecteur ce qui est ancêtre, descendant d'ancêtre, ou conjoint de l'un
 * d'eux (lui seul) ; la famille du conjoint du lecteur garde son régime ; tout le reste
 * qui est relié est « par alliance », rattaché à un PONT — et les clés servies par le
 * serveur, quand elles sont là, font foi.
 */

function arbre(): { graphe: Graphe; id: Record<string, string> } {
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  let n = 1
  const p = (cle: string, sex: Sexe = 'inconnu'): string => {
    const noeud: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n++,
      x: 0,
      y: 0,
      w: 140,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom`,
      sex,
      generation: 0,
      branch: 'tr',
      vivant: false,
      visibilite: 'complete',
    }
    noeuds.push(noeud)
    id[cle] = noeud.id
    return noeud.id
  }
  const f = (epoux: string[], enfants: string[]): void => {
    const fam: NoeudFamille = { id: `f${n++}`, type: 'famille', familyId: n, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(fam)
    for (const e of epoux) aretes.push({ de: e, vers: fam.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: fam.id, vers: e, genre: 'filiation' })
  }

  const ego = p('ego')
  f([p('pere', 'masculin'), p('mere', 'feminin')], [ego, p('soeur')])
  f([p('gpm', 'masculin'), p('gmm', 'feminin')], [id['mere']!, p('tante', 'feminin')])
  // La tante épouse un homme qui a ses parents, un frère, et une ex-femme avec un fils.
  const mari = p('mari', 'masculin')
  f([mari, id['tante']!], [p('cousin')])
  f([p('mariPere', 'masculin'), p('mariMere', 'feminin')], [mari, p('mariFrere')])
  f([mari, p('exFemme', 'feminin')], [p('demiCousin')])
  // La sœur de l'ego épouse un homme dont la mère est connue.
  const beauFrere = p('beauFrere', 'masculin')
  f([id['soeur']!, beauFrere], [p('neveu')])
  f([p('beauFrereMere', 'feminin')], [beauFrere])
  // Le conjoint de l'ego et ses parents : « Famille du conjoint ».
  const conjoint = p('conjoint')
  f([ego, conjoint], [p('enfant')])
  f([p('beauPere'), p('belleMere')], [conjoint, p('belleSoeur', 'feminin')])
  // Le conjoint de l'ego a un fils d'une autre union : « conjoint » aussi, comme au serveur.
  f([conjoint, p('exDuConjoint')], [p('beauFils')])
  // La sœur du conjoint a épousé un homme qui a son père et un frère (vu de l'oncle par
  // alliance du commanditaire, 25/10/2026) : lui et leurs enfants sont du côté du conjoint,
  // sa famille à lui est par alliance.
  const mariDeLaBelleSoeur = p('mariDeLaBelleSoeur', 'masculin')
  f([mariDeLaBelleSoeur, id['belleSoeur']!], [p('neveuDuConjoint')])
  f([p('pereDuMariDeLaBelleSoeur', 'masculin')], [mariDeLaBelleSoeur, p('frereDuMariDeLaBelleSoeur')])
  // Le conjoint de l'enfant, et ses parents : par alliance (conjoint d'un descendant).
  const gendre = p('gendre')
  f([id['enfant']!, gendre], [])
  f([p('gendrePere')], [gendre])
  // Un îlot.
  p('ilot')

  return {
    graphe: {
      empreinte: '0'.repeat(64),
      ego,
      boite: { x: 0, y: 0, w: 1, h: 1 },
      calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
      noeuds,
      aretes,
    },
    id,
  }
}

describe('classerParente — sur les liens', () => {
  const { graphe, id } = arbre()
  const c = classerParente(graphe)
  const de = (cle: string) => c.get(id[cle]!)!

  it('met dans la famille le sang et les conjoints du sang, eux seuls', () => {
    for (const cle of ['ego', 'pere', 'mere', 'soeur', 'gpm', 'gmm', 'tante', 'cousin', 'neveu', 'enfant'])
      expect(de(cle).parente, cle).toBe('famille')
    // Les conjoints du sang : le mari de la tante, le beau-frère, le gendre.
    for (const cle of ['mari', 'beauFrere', 'gendre', 'conjoint']) expect(de(cle).parente, cle).toBe('famille')
  })

  it('garde le régime « Famille du conjoint » pour celle du conjoint de l’ego', () => {
    expect(de('beauPere').parente).toBe('conjoint')
    expect(de('belleMere').parente).toBe('conjoint')
  })

  it('range comme le serveur les enfants d’une autre union du conjoint : « conjoint », pas alliance', () => {
    // § 5.2 : le sang du conjoint du lecteur, et les conjoints de ce sang. Le repli local
    // ne doit pas masquer ce que le serveur aurait montré.
    expect(de('beauFils')).toEqual({ parente: 'conjoint', pont: null })
    expect(de('exDuConjoint')).toEqual({ parente: 'conjoint', pont: null })
  })

  it('arrête la famille du conjoint à son sang et aux conjoints de ce sang', () => {
    for (const cle of ['belleSoeur', 'neveuDuConjoint', 'mariDeLaBelleSoeur']) expect(de(cle), cle).toEqual({ parente: 'conjoint', pont: null })
    // Le père et le frère du mari de sa sœur : aucun lien avec le lecteur.
    for (const cle of ['pereDuMariDeLaBelleSoeur', 'frereDuMariDeLaBelleSoeur'])
      expect(de(cle), cle).toEqual({ parente: 'alliance', pont: id['mariDeLaBelleSoeur'] })
  })

  it('range tout le reste par alliance, chacun derrière son pont', () => {
    for (const cle of ['mariPere', 'mariMere', 'mariFrere', 'exFemme', 'demiCousin']) {
      expect(de(cle).parente, cle).toBe('alliance')
      expect(de(cle).pont, cle).toBe(id['mari'])
    }
    expect(de('beauFrereMere')).toEqual({ parente: 'alliance', pont: id['beauFrere'] })
    expect(de('gendrePere')).toEqual({ parente: 'alliance', pont: id['gendre'] })
  })

  it('laisse un îlot hors des alliances : il n’est relié à rien', () => {
    expect(de('ilot').parente).toBe('famille')
  })

  it('groupe les alliances par pont', () => {
    const g = groupesParAlliance(graphe)
    expect(new Set(g.keys())).toEqual(new Set([id['mari'], id['beauFrere'], id['gendre'], id['mariDeLaBelleSoeur']]))
    expect(g.get(id['mari']!)!.sort()).toEqual(['mariPere', 'mariMere', 'mariFrere', 'exFemme', 'demiCousin'].map((k) => id[k]!).sort())
  })

  it('les retire du graphe, pont compris dans ce qui reste', () => {
    const sans = sansFamillesParAlliance(graphe)
    const restants = new Set(sans.noeuds.map((x) => x.id))
    for (const cle of ['mariPere', 'exFemme', 'demiCousin', 'beauFrereMere', 'gendrePere', 'pereDuMariDeLaBelleSoeur', 'frereDuMariDeLaBelleSoeur'])
      expect(restants.has(id[cle]!), cle).toBe(false)
    for (const cle of ['mari', 'beauFrere', 'gendre', 'beauPere', 'ilot', 'belleSoeur', 'mariDeLaBelleSoeur', 'neveuDuConjoint'])
      expect(restants.has(id[cle]!), cle).toBe(true)
    expect(sans.aretes.every((a) => restants.has(a.de) && restants.has(a.vers))).toBe(true)
    expect(aDesFamillesParAlliance(graphe)).toBe(true)
    expect(aDesFamillesParAlliance(sans)).toBe(false)
    // Rien à retirer : le même graphe, sans copie.
    expect(sansFamillesParAlliance(sans)).toBe(sans)
  })

  it('dit à la recherche qui est d’une famille par alliance', () => {
    const personId = (cle: string): number =>
      (graphe.noeuds.find((x) => x.id === id[cle]) as NoeudPersonne).personId!
    expect(estParAlliance(graphe, personId('mariPere'))).toBe(true)
    expect(estParAlliance(graphe, personId('mari'))).toBe(false)
    expect(estParAlliance(graphe, 99_999)).toBe(false)
  })

  it('reconnaît la famille du conjoint comme avant', () => {
    // Ses parents, et tout ce qui s'y rattache sans repasser par le lecteur : la sœur du
    // conjoint, son ménage, et la famille de son mari — décocher le filtre les retire.
    const attendus = ['beauPere', 'belleMere', 'belleSoeur', 'mariDeLaBelleSoeur', 'neveuDuConjoint', 'pereDuMariDeLaBelleSoeur', 'frereDuMariDeLaBelleSoeur']
    expect(familleDesConjoints(graphe)).toEqual(new Set(attendus.map((cle) => id[cle])))
  })
})

describe('classerParente — les clés servies font foi', () => {
  it('lit `parente` et `pont` du serveur, et complète un pont absent par les liens', () => {
    const { graphe, id } = arbre()
    const servi = (cle: string, parente: Parente, pont?: string): void => {
      const n = graphe.noeuds.find((x) => x.id === id[cle]) as NoeudPersonne
      n.parente = parente
      if (pont !== undefined) n.pont = pont
    }
    // Le serveur décide autrement que les liens pour deux personnes…
    servi('mariFrere', 'famille')
    servi('ilot', 'alliance')
    // …donne un pont explicite à une autre, et en omet un.
    servi('exFemme', 'alliance', id['mari'])
    servi('mariPere', 'alliance')
    const c = classerParente(graphe)
    expect(c.get(id['mariFrere']!)).toEqual({ parente: 'famille', pont: null })
    expect(c.get(id['exFemme']!)).toEqual({ parente: 'alliance', pont: id['mari'] })
    expect(c.get(id['mariPere']!)).toEqual({ parente: 'alliance', pont: id['mari'] })
    // Une alliance sans pont connu ne fait pas de groupe : elle reste rattachée à l'arbre.
    expect(c.get(id['ilot']!)).toEqual({ parente: 'alliance', pont: null })
    expect([...groupesParAlliance(graphe).values()].flat()).not.toContain(id['ilot'])
  })
})
