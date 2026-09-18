/**
 * ════════════════════════════════════════════════════════════════════
 * LE QUILT : UNE MATRICE, ET DEUX RÔLES QU'ON NE DOIT PAS CONFONDRE.
 *
 * Tout ce qui se lit dans un quilt tient dans deux choses :
 *
 * 1. **chaque appartenance a sa case**, et il n'en manque aucune — une matrice
 *    incomplète ne se voit pas, contrairement à un trait manquant ;
 * 2. **parent et enfant ne se confondent pas.** Les distinguer est toute la
 *    lecture : sans le rôle, on saurait qui appartient à quelle famille, mais
 *    plus dans quel sens se lit la parenté.
 *
 * Le reste des cas porte sur l'escalier — c'est lui qui donne à chaque personne
 * une ligne allant de sa famille d'origine, à gauche, à celles qu'elle forme, à
 * droite — et sur l'étalement, qui remplace ici le nombre de croisements.
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { H_LIGNE, L_COLONNE, quilt } from './quilt'

function personne(id: string, y: number): NoeudGraphe {
  return {
    type: 'personne',
    id,
    personId: null,
    displayName: id,
    etiquetteNom: id,
    ligneDates: '',
    sexe: 'inconnu',
    vivant: false,
    branche: null,
    x: 0,
    y,
    w: 100,
    h: 40,
  } as unknown as NoeudGraphe
}

function famille(id: string, y: number, divorced = false): NoeudGraphe {
  return { type: 'famille', id, familyId: null, divorced, x: 0, y, w: 22, h: 14 } as NoeudGraphe
}

const u = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'union' }) as AreteGraphe
const f = (de: string, vers: string): AreteGraphe =>
  ({ de, vers, genre: 'filiation' }) as AreteGraphe

function graphe(noeuds: NoeudGraphe[], aretes: AreteGraphe[]): Graphe {
  return {
    empreinte: 'a'.repeat(64),
    ego: 'moi',
    boite: { x: 0, y: 0, w: 100, h: 100 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  } as unknown as Graphe
}

/**
 * Trois générations : les aïeux, leurs deux enfants, et un petit-enfant.
 *
 * ```
 *   gen 0   aieul + aieule           → fAieux
 *   gen 1   pere + mere, oncle       → fParents
 *   gen 2   moi
 * ```
 */
const ARBRE = graphe(
  [
    personne('aieul', -300),
    personne('aieule', -300),
    famille('fAieux', -254),
    personne('pere', -150),
    personne('oncle', -150),
    personne('mere', -150),
    famille('fParents', -104),
    personne('moi', 0),
  ],
  [
    u('aieul', 'fAieux'),
    u('aieule', 'fAieux'),
    f('fAieux', 'pere'),
    f('fAieux', 'oncle'),
    u('pere', 'fParents'),
    u('mere', 'fParents'),
    f('fParents', 'moi'),
  ],
)

const q = quilt(ARBRE)

const caseDe = (personne: string, famille: string) =>
  q.cases.find((c) => c.personne === personne && c.famille === famille)

describe('la matrice est complète', () => {
  it('pose une case par appartenance, et pas une de plus', () => {
    /* Sept arêtes, sept cases. Une matrice incomplète ne se voit pas — c'est ce
       qui la distingue d'un trait manquant, qu'on repère à l'œil. */
    expect(q.cases).toHaveLength(7)
  })

  it('pose une ligne par personne et une colonne par famille', () => {
    expect(q.lignes.map((l) => l.id).sort()).toEqual([
      'aieul',
      'aieule',
      'mere',
      'moi',
      'oncle',
      'pere',
    ])
    expect(q.colonnes.map((c) => c.id).sort()).toEqual(['fAieux', 'fParents'])
  })
})

describe('les deux rôles', () => {
  it('marque les époux comme parents et les enfants comme enfants', () => {
    /*
     * LA LECTURE DU QUILT. Sans le rôle, on saurait qui appartient à quelle
     * famille, mais plus dans quel sens se lit la parenté — c'est-à-dire plus
     * rien du tout.
     */
    expect(caseDe('aieul', 'fAieux')?.role).toBe('parent')
    expect(caseDe('aieule', 'fAieux')?.role).toBe('parent')
    expect(caseDe('pere', 'fAieux')?.role).toBe('enfant')
    expect(caseDe('oncle', 'fAieux')?.role).toBe('enfant')
  })

  it('donne à une même personne les deux rôles, dans deux familles', () => {
    /* `pere` est enfant de `fAieux` et parent de `fParents`. C'est le cas normal,
       et c'est lui qui fait la diagonale. */
    expect(caseDe('pere', 'fAieux')?.role).toBe('enfant')
    expect(caseDe('pere', 'fParents')?.role).toBe('parent')
  })
})

describe('l’escalier', () => {
  it('range les colonnes d’une bande à droite de la précédente', () => {
    const aieux = q.colonnes.find((c) => c.id === 'fAieux')!
    const parents = q.colonnes.find((c) => c.id === 'fParents')!

    expect(parents.x).toBeGreaterThan(aieux.x)
  })

  it('range les lignes d’une génération sous la précédente', () => {
    const aieul = q.lignes.find((l) => l.id === 'aieul')!
    const pere = q.lignes.find((l) => l.id === 'pere')!
    const moi = q.lignes.find((l) => l.id === 'moi')!

    expect(pere.y).toBeGreaterThan(aieul.y)
    expect(moi.y).toBeGreaterThan(pere.y)
  })

  it('étend la ligne d’une personne de sa famille d’origine à celles qu’elle forme', () => {
    /* C'est ce que l'escalier rend possible : la famille dont on est enfant est à
       gauche, celles qu'on forme sont à droite. */
    const pere = q.lignes.find((l) => l.id === 'pere')!
    const aieux = q.colonnes.find((c) => c.id === 'fAieux')!
    const parents = q.colonnes.find((c) => c.id === 'fParents')!

    expect(pere.x).toBeLessThanOrEqual(aieux.x)
    expect(pere.x + pere.largeur).toBeGreaterThanOrEqual(parents.x + L_COLONNE)
  })

  it('donne à chaque colonne une hauteur qui couvre ses parents et ses enfants', () => {
    const aieux = q.colonnes.find((c) => c.id === 'fAieux')!
    const miennes = q.cases.filter((c) => c.famille === 'fAieux')

    for (const c of miennes) {
      expect(c.y).toBeGreaterThanOrEqual(aieux.y)
      expect(c.y + H_LIGNE).toBeLessThanOrEqual(aieux.y + aieux.hauteur)
    }
  })

  it('aligne chaque case sur la ligne de sa personne et la colonne de sa famille', () => {
    /* C'est l'invariant qui rend la matrice lisible : une case décalée d'un rang
       désignerait quelqu'un d'autre, en silence. */
    const parLigne = new Map(q.lignes.map((l) => [l.id, l.y]))
    const parColonne = new Map(q.colonnes.map((c) => [c.id, c.x]))

    for (const c of q.cases) {
      expect(c.y, `${c.personne} × ${c.famille}`).toBe(parLigne.get(c.personne))
      expect(c.x, `${c.personne} × ${c.famille}`).toBe(parColonne.get(c.famille))
    }
  })
})

describe('la boîte et la mesure', () => {
  it('englobe toutes les lignes et toutes les colonnes', () => {
    for (const ligne of q.lignes) {
      expect(ligne.x).toBeGreaterThanOrEqual(q.boite.x)
      expect(ligne.x + ligne.largeur).toBeLessThanOrEqual(q.boite.x + q.boite.w)
    }

    for (const colonne of q.colonnes) {
      expect(colonne.y).toBeGreaterThanOrEqual(q.boite.y)
      expect(colonne.y + colonne.hauteur).toBeLessThanOrEqual(q.boite.y + q.boite.h)
    }
  })

  it('rend un étalement positif, et nul quand il n’y a rien à serrer', () => {
    /* L'étalement remplace le nombre de croisements : il n'y a plus de trait, mais
       une matrice se lit d'autant mieux que ses cases sont serrées. */
    expect(q.etalement).toBeGreaterThanOrEqual(0)

    const seul = graphe([personne('moi', 0)], [])

    expect(quilt(seul).etalement).toBe(0)
  })
})

describe('les cas dégénérés', () => {
  it('traverse un graphe vide', () => {
    const vide = quilt(graphe([], []))

    expect(vide.lignes).toEqual([])
    expect(vide.cases).toEqual([])
  })

  it('garde une personne que rien ne rattache', () => {
    /* Elle n'a aucune case, donc aucune colonne ne la traverse — mais elle existe,
       et la taire serait perdre quelqu'un sans le dire. */
    const isolee = graphe([personne('seul', 0)], [])

    expect(quilt(isolee).lignes.map((l) => l.id)).toEqual(['seul'])
  })

  it('n’émet pas de colonne pour une famille sans membre visible', () => {
    /* Un losange qui ne traverse aucune ligne serait un trait vertical isolé, qui
       ne dit rien. */
    const orpheline = graphe([personne('moi', 0), famille('fVide', 46)], [])

    expect(quilt(orpheline).colonnes).toEqual([])
  })

  it('reporte le divorce sur la colonne', () => {
    const rompue = graphe(
      [personne('a', 0), personne('b', 0), famille('f', 46, true)],
      [u('a', 'f'), u('b', 'f')],
    )

    expect(quilt(rompue).colonnes[0]?.divorce).toBe(true)
  })

  it('est déterministe : deux appels rendent la même matrice', () => {
    /* Les balayages de barycentre départagent les ex æquo par l'ordre existant :
       sans cela, deux affichages du même arbre donneraient deux images. */
    expect(quilt(ARBRE).lignes.map((l) => l.id)).toEqual(q.lignes.map((l) => l.id))
    expect(quilt(ARBRE).colonnes.map((c) => c.id)).toEqual(q.colonnes.map((c) => c.id))
  })
})
