/**
 * Les zones de branche, vérifiées sur la géométrie seule — aucun DOM ici.
 *
 * Le test qui compte est {@link « deux bandes d'une même rangée ne se
 * chevauchent jamais »} : c'est la propriété que la forme est censée garantir
 * *par construction*, et non par un réglage heureux. Elle est vérifiée sur le
 * cas qui l'a motivée — le tronc, dont les deux membres de la rangée des parents
 * sont aux deux bouts de l'arbre, avec une autre branche entre eux.
 *
 * **Les noms sont inventés.** Le dépôt est public et l'arbre est celui d'une
 * famille réelle : aucune fixture de ce dépôt ne recopie un nom de l'arbre.
 */
import { describe, expect, it } from 'vitest'
import type { NoeudGraphe } from '@/lib/api'
import { cleDeZone, zonesDeBranche } from './zones'

function p(id: string, x: number, y: number, branch: string | null, w = 120): NoeudGraphe {
  return {
    id,
    type: 'personne',
    personId: Number(id.replace(/\D/g, '')),
    x,
    y,
    w,
    h: 40,
    displayName: `Untel ${id}`,
    etiquetteNom: `Untel ${id}`,
    sex: 'masculin',
    generation: Math.round(y / 150),
    branch,
    vivant: false,
    visibilite: 'complete',
    etiquetteDates: '1900 – 1980',
  } as unknown as NoeudGraphe
}

describe('cleDeZone', () => {
  it('rend la branche pour les cinq valeurs du contrat', () => {
    expect(cleDeZone('pp')).toBe('pp')
    expect(cleDeZone('tr')).toBe('tr')
  })

  it('range sous `hors` tout ce qui n’est pas une branche — `null` compris', () => {
    // Le sérialiseur pose `branch: null` pour qui n'est relié à rien, alors que
    // le type `Branche` déclare la clé obligatoire. C'est ce cas-là qui produisait
    // la classe `b-null`, à laquelle aucune règle ne répond.
    expect(cleDeZone(null)).toBe('hors')
    expect(cleDeZone(undefined)).toBe('hors')
  })
})

describe('zonesDeBranche', () => {
  it('rend une zone par région présente, et aucune pour une région vide', () => {
    const zones = zonesDeBranche([p('p1', 0, 0, 'tr'), p('p2', 400, 0, 'mm')])
    expect(zones.map((z) => z.cle)).toEqual(['tr', 'mm'])
    expect(zones.every((z) => z.d.length > 0)).toBe(true)
  })

  it('compte les personnes de chaque région', () => {
    const zones = zonesDeBranche([
      p('p1', 0, 0, 'tr'),
      p('p2', 400, 0, 'mm'),
      p('p3', 600, 0, 'mm'),
    ])
    expect(zones.find((z) => z.cle === 'mm')?.personnes).toBe(2)
  })

  it('ignore les nœuds de famille : un losange n’a pas de branche', () => {
    const zones = zonesDeBranche([
      p('p1', 0, 0, 'tr'),
      { id: 'f1', type: 'famille', familyId: 1, x: 3000, y: 0, w: 22, h: 14, divorced: false },
    ] as NoeudGraphe[])
    expect(zones).toHaveLength(1)
    // Le losange, à 3 000, n'étire pas la bande du tronc jusqu'à lui.
    expect(zones[0].bandes[0].x1).toBeLessThan(100)
  })

  it('empile une bande par génération, et les bandes voisines se touchent', () => {
    const zones = zonesDeBranche([p('p1', 0, -150, 'pm'), p('p2', 0, 0, 'pm')])
    const bandes = zones[0].bandes.sort((a, b) => a.y - b.y)
    expect(bandes).toHaveLength(2)
    // Bord commun posé au milieu de l'espace entre les deux générations : la
    // région se lit comme un aplat continu, pas comme des barreaux.
    expect(bandes[0].bas).toBe(bandes[1].haut)
  })

  /* ---------------------------------------------------------------- */
  /* La propriété qui justifie la forme                                */
  /* ---------------------------------------------------------------- */

  it('coupe la bande là où une personne d’une AUTRE branche s’intercale', () => {
    // Le cas réel, aux valeurs mesurées sur le graphe servi : à la rangée des
    // parents, le tronc n'a que deux personnes — le père et la mère de la racine
    // — et le moteur les pose chacune au-dessus de sa moitié d'arbre, à
    // 9 544 unités l'une de l'autre. Deux personnes d'une autre branche se
    // trouvent entre les deux.
    const zones = zonesDeBranche([
      p('p1', -4700, -150, 'tr'),
      p('p2', -1896, -150, 'pm'),
      p('p3', -1648, -150, 'pm'),
      p('p4', 4980, -150, 'tr'),
    ])

    const tronc = zones.find((z) => z.cle === 'tr')
    expect(tronc).toBeDefined()
    // DEUX bandes, et non une seule large de dix mille unités qui avalerait `pm`.
    expect(tronc?.bandes).toHaveLength(2)
    expect(tronc?.d.match(/M/g)).toHaveLength(2)
  })

  it('ne laisse JAMAIS deux bandes d’une même rangée se chevaucher', () => {
    // La même rangée, plus deux branches serrées l'une contre l'autre : 8 unités
    // d'écart, bien moins que le débord de 16 demandé. Le débord doit être raboté,
    // pas appliqué tel quel.
    const zones = zonesDeBranche([
      p('p1', -4700, -150, 'tr'),
      p('p2', -1896, -150, 'pm'),
      p('p3', -1648, -150, 'pm'),
      p('p4', -1520, -150, 'mp'),
      p('p5', 4980, -150, 'tr'),
      p('p6', -3000, 0, 'pp'),
      p('p7', -2872, 0, 'pm'),
    ])

    const toutes = zones.flatMap((z) => z.bandes)
    for (const a of toutes) {
      for (const b of toutes) {
        if (a === b || a.y !== b.y) continue
        const recouvrement = Math.min(a.x1, b.x1) - Math.max(a.x0, b.x0)
        expect(recouvrement).toBeLessThanOrEqual(0)
      }
    }
  })

  it('n’inverse pas une bande quand deux fiches voisines se chevauchent déjà', () => {
    // Deux branches dont les boîtes se recouvrent : le débord calculé est négatif.
    // La bande doit rester d'aire positive plutôt que de se retourner.
    const zones = zonesDeBranche([p('p1', 0, 0, 'pm'), p('p2', 60, 0, 'mp')])
    for (const z of zones) for (const b of z.bandes) expect(b.x1).toBeGreaterThan(b.x0)
  })

  it('rend une liste vide sur un graphe sans personne', () => {
    expect(zonesDeBranche([])).toEqual([])
  })
})
