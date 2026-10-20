import { describe, expect, it } from 'vitest'
import type { JourDesStats } from '@/lib/api'
import { ajoutsDeLaPeriode, COULEUR_AJOUTEES, COULEUR_VERIFIEES, hautDeLEchelle, PERIODES_DES_JOURS } from './parJour'

/**
 * Ajouts et vérifications, jour par jour. Ce que ces tests tiennent : une
 * branche absente d'un jour y vaut **zéro**, la période se coupe par la fin
 * (aujourd'hui), le total est celui de ce qui est montré, et le jour le plus
 * actif n'existe que s'il s'est passé quelque chose.
 */

/** Une série de `n` jours finissant le 20 octobre 2026, vide sauf `pleins`. */
function serie(n: number, pleins: Record<string, Omit<JourDesStats, 'jour'>> = {}): JourDesStats[] {
  const fin = Date.UTC(2026, 9, 20)
  return Array.from({ length: n }, (_, i) => {
    const jour = new Date(fin - (n - 1 - i) * 86_400_000).toISOString().slice(0, 10)
    return { jour, ...(pleins[jour] ?? { ajoutees: 0, verifiees: 0, branches: {} }) }
  })
}

const SERIE = serie(120, {
  '2026-06-30': { ajoutees: 400, verifiees: 0, branches: { pp: { ajoutees: 400, verifiees: 0 } } },
  '2026-10-02': { ajoutees: 5, verifiees: 2, branches: { pp: { ajoutees: 3, verifiees: 0 }, mm: { ajoutees: 2, verifiees: 2 } } },
  '2026-10-14': { ajoutees: 0, verifiees: 8, branches: { mm: { ajoutees: 0, verifiees: 8 } } },
  '2026-10-20': { ajoutees: 1, verifiees: 0, branches: { tr: { ajoutees: 1, verifiees: 0 } } },
})

describe('toutes les branches', () => {
  it('les 30 derniers jours finissent aujourd’hui', () => {
    const p = ajoutsDeLaPeriode(SERIE, null, 30)

    expect(p.jours).toHaveLength(30)
    expect(p.jours[0]!.jour).toBe('2026-09-21')
    expect(p.jours[29]).toEqual({ jour: '2026-10-20', ajoutees: 1, verifiees: 0 })
  })

  it('le total est celui de la période montrée', () => {
    expect(ajoutsDeLaPeriode(SERIE, null, 30)).toMatchObject({ ajoutees: 6, verifiees: 10 })
    expect(ajoutsDeLaPeriode(SERIE, null, 'tout')).toMatchObject({ ajoutees: 406, verifiees: 10 })
  })

  it('« tout » rend la série entière ; une série plus courte que la période n’est pas prolongée', () => {
    expect(ajoutsDeLaPeriode(SERIE, null, 'tout').jours).toHaveLength(120)
    expect(ajoutsDeLaPeriode(serie(10), null, 90).jours).toHaveLength(10)
  })

  it('le jour le plus actif compte les ajouts et les vérifications réunis', () => {
    // Le 2 octobre a plus d'ajouts (5 + 2) ; le 14, plus de mouvement (0 + 8).
    expect(ajoutsDeLaPeriode(SERIE, null, 30).plusActif).toEqual({ jour: '2026-10-14', ajoutees: 0, verifiees: 8 })
    expect(ajoutsDeLaPeriode(SERIE, null, 'tout').plusActif?.jour).toBe('2026-06-30')
  })
})

describe('une branche', () => {
  it('lit le détail du jour, et une branche absente ce jour-là vaut zéro', () => {
    const p = ajoutsDeLaPeriode(SERIE, 'mm', 30)

    expect(p.jours.find((j) => j.jour === '2026-10-02')).toEqual({ jour: '2026-10-02', ajoutees: 2, verifiees: 2 })
    expect(p.jours.find((j) => j.jour === '2026-10-20')).toEqual({ jour: '2026-10-20', ajoutees: 0, verifiees: 0 })
    expect(p).toMatchObject({ ajoutees: 2, verifiees: 10 })
  })

  it('une branche sans rien sur la période n’a pas de jour le plus actif', () => {
    const p = ajoutsDeLaPeriode(SERIE, 'cpp', 'tout')

    expect(p.jours).toHaveLength(120)
    expect(p).toMatchObject({ ajoutees: 0, verifiees: 0, plusActif: null })
  })

  it('à égalité, le jour le plus récent', () => {
    const egalite = serie(3, {
      '2026-10-18': { ajoutees: 2, verifiees: 0, branches: {} },
      '2026-10-20': { ajoutees: 1, verifiees: 1, branches: {} },
    })

    expect(ajoutsDeLaPeriode(egalite, null, 30).plusActif?.jour).toBe('2026-10-20')
  })
})

describe('l’échelle', () => {
  it('monte à une valeur ronde et paire', () => {
    expect([0, 1, 2, 3, 5, 7, 9, 10, 11, 23, 45, 130, 275, 400, 401].map(hautDeLEchelle)).toEqual([
      2, 2, 2, 4, 6, 8, 10, 10, 20, 30, 50, 200, 300, 400, 500,
    ])
  })
})

describe('le panneau', () => {
  it('trois périodes, du plus court au plus long', () => {
    expect(PERIODES_DES_JOURS.map((p) => p.valeur)).toEqual([30, 90, 'tout'])
  })

  it('les couleurs sont des jetons existants', () => {
    expect([COULEUR_AJOUTEES, COULEUR_VERIFIEES]).toEqual(['var(--h-accent)', 'var(--h-ok)'])
  })
})
