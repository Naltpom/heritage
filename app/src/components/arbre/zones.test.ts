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
 *
 * ── ⚠ CE QUE CES TESTS NE COUVRENT PAS, ET IL FAUT LE SAVOIR AVANT D'EN AJOUTER ──
 *
 * **Ils lisent les BANDES. Aucun ne lit le `d`.** Les bandes sont des nombres ; le `d` est
 * la chaîne de commandes qu'on en tire, et rien ici ne la relit.
 *
 * Ce n'est pas théorique. Le 23/09/2026, {@link contourDeLaPile} accumulait les commandes
 * du bord gauche pendant la descente puis appelait `.reverse()` — sur un tableau **plat de
 * chaînes**, ce qui inverse aussi les commandes à l'intérieur de chaque groupe. Un `Q`
 * passait avant le `L` qui l'amenait, et les nuages partaient en pointes de **plusieurs
 * milliers d'unités**. Les seize tests de ce fichier sont restés **verts**.
 *
 * **Le défaut a été trouvé en rasterisant l'image et en la regardant**, pas en relisant le
 * code ni en ajoutant une assertion. Un dix-septième test sur les bandes ne fermerait pas
 * ce trou — il l'élargirait, en donnant l'impression qu'il est fermé.
 *
 * **Ce qui couvre le `d` est donc un RENDU**, et il se fait à la main : produire le SVG d'un
 * relevé, le rasteriser, le regarder. Tant que ce fichier n'a pas de cas qui lise le chemin
 * lui-même, **son vert ne dit rien de la forme dessinée**.
 */
import { describe, expect, it } from 'vitest'
import type { NoeudGraphe } from '@/lib/api'
import { cleDeZone, enveloppesDeBranche, zonesDeBranche } from './zones'

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
    // La rangée `y = -300` du graphe servi, en réduction : c'est la seule des
    // quinze où les branches sont ENTRELACÉES et non simplement voisines. Elle se
    // lit `pp×10 | pm×1 | pp×1 | pm×17 | …` — un `pm` isolé tombe au milieu du
    // `pp`, et un `pp` isolé au milieu du `pm`. Une bande unique par
    // (branche, rangée) enjamberait les intrus.
    const zones = zonesDeBranche([
      p('p1', -13000, -300, 'pp'),
      p('p2', -12800, -300, 'pp'),
      p('p3', -12600, -300, 'pm'), // l'intrus
      p('p4', -12400, -300, 'pp'),
      p('p5', -12200, -300, 'pm'),
      p('p6', -12000, -300, 'pm'),
    ])

    // `pp` en DEUX bandes, et non une seule qui recouvrirait le `pm` du milieu.
    const pp = zones.find((z) => z.cle === 'pp')
    expect(pp?.bandes).toHaveLength(2)
    expect(pp?.d.match(/M/g)).toHaveLength(2)
    // `pm` aussi : l'isolé d'un côté, la paire de l'autre.
    expect(zones.find((z) => z.cle === 'pm')?.bandes).toHaveLength(2)
  })

  it('ne laisse JAMAIS deux bandes d’une même rangée se chevaucher', () => {
    // Le débord vaut 16 de chaque côté, mais sur le graphe servi deux suites
    // voisines ne sont séparées que de 20 unités (`y = -300`, `pp` puis `pm`).
    // Appliqué tel quel, le débord les ferait se recouvrir de 12 ; il doit être
    // raboté à la moitié de l'espace libre. C'est ce rabot que ce test tient.
    const zones = zonesDeBranche([
      p('p1', -13000, -300, 'pp'),
      p('p2', -12860, -300, 'pm'), // 20 unités d'écart exactement (120 de large)
      p('p3', -12600, -300, 'pp'),
      p('p4', -12000, -300, 'mp'),
      p('p5', -11860, -300, 'mm'), // 20 unités, de nouveau
      p('p6', 0, -150, 'tr'),
      p('p7', 300, -150, 'pm'),
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

  it('tient la propriété sur la rangée qui a motivé la forme, telle qu’elle est servie', () => {
    // Les huit suites de `y = -300`, dans l'ordre et aux effectifs mesurés. Une
    // bande par (branche, rangée) donnerait ici 2 paires superposées ; la forme
    // livrée en donne zéro, et rend 8 bandes au lieu de 4.
    const suites: [string, number][] = [
      ['pp', 10],
      ['pm', 1],
      ['pp', 1],
      ['pm', 17],
      ['mp', 2],
      ['mm', 1],
      ['mp', 7],
      ['mm', 12],
    ]
    const noeuds: NoeudGraphe[] = []
    let x = -13700
    let i = 0
    for (const [branche, combien] of suites) {
      for (let k = 0; k < combien; k++) {
        noeuds.push(p(`p${++i}`, x, -300, branche))
        x += 140
      }
      x += 60
    }

    const zones = zonesDeBranche(noeuds)
    expect(zones.flatMap((z) => z.bandes)).toHaveLength(8)
    expect(zones.find((z) => z.cle === 'pp')?.bandes).toHaveLength(2)
    expect(zones.find((z) => z.cle === 'mm')?.bandes).toHaveLength(2)

    const toutes = zones.flatMap((z) => z.bandes)
    for (const a of toutes)
      for (const b of toutes) {
        if (a === b) continue
        expect(Math.min(a.x1, b.x1) - Math.max(a.x0, b.x0)).toBeLessThanOrEqual(0)
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

describe('enveloppesDeBranche', () => {
  it('réunit ses deux moitiés, et ne rend rien pour une enveloppe vide', () => {
    const enveloppes = enveloppesDeBranche([
      p('p1', -800, -300, 'pp'),
      p('p2', -600, -300, 'pm'),
      p('p3', 600, -300, 'mp'),
    ])

    // `p` et `m` existent ; celles du conjoint, dont aucune moitié n'est peuplée, non.
    expect(enveloppes.map((e) => e.cle)).toEqual(['p', 'm'])
    expect(enveloppes.find((e) => e.cle === 'p')?.personnes).toBe(2)
    expect(enveloppes.find((e) => e.cle === 'm')?.personnes).toBe(1)
    expect(enveloppes.every((e) => e.d.length > 0)).toBe(true)
  })

  it('CONTIENT ses deux moitiés : aucune bande de région ne déborde de son enveloppe', () => {
    // C'est la propriété de l'imbrication. Un gros nuage qui ne couvre pas ses petits
    // laisserait dépasser un bord coloré, et le dessin cesserait de se lire comme un
    // contenant et son contenu.
    const noeuds = [
      p('p1', -800, -300, 'pp'),
      p('p2', -600, -300, 'pm'),
      p('p3', -700, -150, 'pp'),
      p('p4', 600, -300, 'mp'),
      p('p5', 900, -300, 'mm'),
    ]
    const enveloppes = enveloppesDeBranche(noeuds)
    const zones = zonesDeBranche(noeuds)

    for (const { cle, membres } of [
      { cle: 'p', membres: ['pp', 'pm'] },
      { cle: 'm', membres: ['mp', 'mm'] },
    ]) {
      const enveloppe = enveloppes.find((e) => e.cle === cle)
      expect(enveloppe).toBeDefined()

      for (const bande of zones.filter((z) => membres.includes(z.cle)).flatMap((z) => z.bandes)) {
        const couvrante = enveloppe!.bandes.find((b) => b.y === bande.y)
        expect(couvrante, `rangée ${bande.y} de ${bande.cle}`).toBeDefined()
        expect(couvrante!.x0).toBeLessThanOrEqual(bande.x0)
        expect(couvrante!.x1).toBeGreaterThanOrEqual(bande.x1)
      }
    }
  })

  it('ne laisse JAMAIS deux enveloppes d’une même rangée se chevaucher', () => {
    // Le même argument que pour deux régions, et c'est pour cela que les bandes d'une
    // enveloppe passent par la même fonction : le rabot vaut ici sans être redémontré.
    const enveloppes = enveloppesDeBranche([
      p('p1', -800, -300, 'pp'),
      p('p2', -660, -300, 'pm'), // 20 unités d'écart exactement
      p('p3', -520, -300, 'mp'), // 20 de nouveau : `p` et `m` se touchent presque
      p('p4', -380, -300, 'mm'),
    ])

    const parRangee = new Map<number, { x0: number; x1: number }[]>()
    for (const e of enveloppes) {
      for (const b of e.bandes) parRangee.set(b.y, [...(parRangee.get(b.y) ?? []), b])
    }

    for (const [y, bandes] of parRangee) {
      const triees = [...bandes].sort((a, b) => a.x0 - b.x0)
      for (let i = 1; i < triees.length; i += 1) {
        expect(triees[i]!.x0, `rangée ${y}`).toBeGreaterThanOrEqual(triees[i - 1]!.x1)
      }
    }
  })

  it('descend sous sa dernière rangée, et en se resserrant — le ▽ du croquis', () => {
    const enveloppe = enveloppesDeBranche([
      p('p1', -800, -300, 'pp'),
      p('p2', -600, -300, 'pm'),
      p('p3', -700, -150, 'pp'),
    ]).find((e) => e.cle === 'p')

    expect(enveloppe).toBeDefined()
    const basDesBandes = Math.max(...enveloppe!.bandes.map((b) => b.bas))

    // La surface de convergence n'est pas une bande : elle ne vit que dans le `d`, pour
    // qu'elle ne compte pas dans les propriétés de non-chevauchement, qu'elle enfreindrait
    // en dépassant sous la dernière rangée.
    const ordonnees = [...enveloppe!.d.matchAll(/[ ,](-?\d+(?:\.\d+)?)(?=[ Z]|$)/g)].map((m) =>
      Number(m[1]),
    )
    expect(Math.max(...ordonnees)).toBeGreaterThan(basDesBandes)
  })

  it('rend une liste vide sur un graphe sans personne', () => {
    expect(enveloppesDeBranche([])).toEqual([])
  })
})
