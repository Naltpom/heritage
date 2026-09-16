/**
 * Du graphe de l'API au dessin — la chaîne complète, sans navigateur.
 *
 * C'est le test qui attrape ce que ni la géométrie ni l'index ne voient seuls :
 * une filiation branchée sur la mauvaise barre de peigne, une arête qui pend
 * parce qu'un nœud manque, un `#12/p1` traité comme un identifiant ordinaire.
 * Sur 377 arêtes, aucune de ces trois erreurs ne se voit à l'œil.
 *
 * Les chaînes attendues se comparent à celles de `arbre.html` : mêmes rayons,
 * mêmes hauteurs de barre (-87.8 / -78.8 / -69.8 pour la rangée de l'ego). Le
 * seul écart voulu tient au **losange 22 × 14** qui remplace le cercle de rayon
 * 6 des premiers rendus : la pointe est à 7 unités du centre, non à 6.
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { indexer } from './graphe'
import { barreDeDivorce, peignesDeFamille, pointsDeLosange, tracerAretes } from './rendu'

function fiche(id: string, personId: number, x: number, y: number): NoeudGraphe {
  return {
    id,
    type: 'personne',
    personId,
    x,
    y,
    w: 120,
    h: 40,
    displayName: `Personne ${id}`,
    etiquetteNom: `Personne ${id}`,
    sex: 'masculin',
    generation: Math.round(y / 150),
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
    etiquetteDates: '1900 – 1980',
  }
}

/**
 * ```
 *        p2 ────┬──── p3        (rangée -150)
 *              f1 (-104)
 *          ┌────┴────┐
 *         p1        p4          (rangée 0)
 * ```
 * Plus une filiation vers `p404`, absent du graphe : la projection peut
 * légitimement ne pas envoyer un nœud, et le rendu ne doit pas tomber pour ça.
 */
function grapheEssai(): Graphe {
  const noeuds: NoeudGraphe[] = [
    fiche('p1', 1, 0, 0),
    fiche('p4', 4, 200, 0),
    fiche('p2', 2, -300, -150),
    fiche('p3', 3, 300, -150),
    { id: 'f1', type: 'famille', familyId: 1, x: 0, y: -104, w: 22, h: 14, divorced: false },
  ]
  const aretes: AreteGraphe[] = [
    { de: 'p2', vers: 'f1', genre: 'union', divorce: false },
    { de: 'p3', vers: 'f1', genre: 'union', divorce: false },
    { de: 'f1', vers: 'p1', genre: 'filiation' },
    { de: 'f1', vers: 'p4', genre: 'filiation' },
    { de: 'f1', vers: 'p404', genre: 'filiation' },
  ]
  return {
    empreinte: '0'.repeat(64),
    ego: 'p1',
    boite: { x: -600, y: -300, w: 1200, h: 400 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

describe('peignesDeFamille', () => {
  it('rattache à chaque famille les enfants réellement présents', () => {
    const peignes = peignesDeFamille(indexer(grapheEssai()))
    expect(peignes).toHaveLength(1)
    expect(peignes[0].enfants.map((e) => e.id)).toEqual(['p1', 'p4'])
  })
})

describe('tracerAretes', () => {
  const tracees = tracerAretes(indexer(grapheEssai()), grapheEssai().aretes)
  const par = (de: string, vers: string) =>
    tracees.find((t) => t.de === de && t.vers === vers)

  it('laisse tomber l’arête dont une extrémité manque, sans rien casser', () => {
    expect(tracees).toHaveLength(4)
    expect(par('f1', 'p404')).toBeUndefined()
  })

  it('trace une union du bas de la fiche à la pointe haute du losange', () => {
    // Même forme que `arbre.html` ; le dernier coin vaut 4,5 et non 5 parce que
    // la pointe du losange est à 7 unités du centre, là où le cercle était à 6.
    expect(par('p2', 'f1')?.d).toBe(
      'M-300.0,-130.0 L-300.0,-125.0 Q-300.0,-120.0 -291.0,-120.0 ' +
        'L-9.0,-120.0 Q0.0,-120.0 0.0,-115.5 L0.0,-111.0',
    )
  })

  it('trace une filiation verticale en fusionnant le coin dégénéré', () => {
    // p1 est exactement sous le losange : les deux points du milieu se
    // confondent, il ne reste qu'un coin. Les trois ordonnées -87.8 / -78.8 /
    // -69.8 sont celles du viewer d'origine.
    expect(par('f1', 'p1')?.d).toBe('M0.0,-97.0 L0.0,-87.8 Q0.0,-78.8 0.0,-69.8 L0.0,-20.0')
  })

  it('fait passer toute la fratrie par la MÊME barre de peigne', () => {
    // Sans barre partagée, chaque arête prendrait sa propre médiane et les
    // traits d'une rangée se superposeraient : « le graphe devient illisible
    // dès 40 familles » — il y en a 103.
    const barre = (d: string | undefined): string | undefined =>
      /Q-?[\d.]+,(-?[\d.]+)/.exec(d ?? '')?.[1]
    expect(barre(par('f1', 'p1')?.d)).toBe('-78.8')
    expect(barre(par('f1', 'p4')?.d)).toBe('-78.8')
  })

  it('porte les classes du genre, et jamais `long`', () => {
    expect(par('p2', 'f1')?.classes).toEqual(['ar', 'union'])
    expect(par('f1', 'p1')?.classes).toEqual(['ar', 'filiation'])
    for (const t of tracees) expect(t.classes).not.toContain('long')
  })

  it('déduit les rôles du type des nœuds, pas de l’ordre de l’arête', () => {
    // Le contrat écrit l'époux en `de` et la famille en `vers` sur une union.
    // Si un jour l'ordre s'inverse côté serveur, le tracé doit rester le même :
    // une arête qui partirait du mauvais bord donnerait un arbre plausible mais
    // faux, et cela ne se voit pas sur 377 arêtes.
    const graphe = grapheEssai()
    const inversees: AreteGraphe[] = [
      { de: 'f1', vers: 'p2', genre: 'union', divorce: false },
      { de: 'p1', vers: 'f1', genre: 'filiation' },
    ]
    const tracees = tracerAretes(indexer(graphe), inversees)
    expect(tracees[0].d).toBe(par('p2', 'f1')?.d)
    expect(tracees[1].d).toBe(par('f1', 'p1')?.d)
  })

  it('marque le divorce sur l’arête d’union', () => {
    const graphe = grapheEssai()
    graphe.aretes[0] = { de: 'p2', vers: 'f1', genre: 'union', divorce: true }
    const t = tracerAretes(indexer(graphe), graphe.aretes)[0]
    expect(t.classes).toContain('divorce')
  })

  it('retombe sur la médiane si aucune barre n’a été calculée pour la famille', () => {
    // Garde-fou porté de `rendu.py` : une famille sans peigne ne fait pas
    // tomber le tracé, elle passe par la médiane. Ici on force le cas en
    // indexant un graphe sans arêtes. Médiane de -104 et 0 : -52.
    const graphe = grapheEssai()
    const index = indexer({ ...graphe, aretes: [] })
    const t = tracerAretes(index, [{ de: 'f1', vers: 'p1', genre: 'filiation' }])[0]
    expect(t.d).toBe('M0.0,-97.0 L0.0,-61.0 Q0.0,-52.0 0.0,-43.0 L0.0,-20.0')
  })
})

describe('les nœuds du calque', () => {
  /** Les identifiants provisoires du § 5.2, repris tels quels — jamais découpés. */
  function grapheAvecCalque(): Graphe {
    const graphe = grapheEssai()
    // Une fiche proposée, et elle est placée : `places` vaut 1 et `nonPlaces`
    // est vide. Les trois compteurs sont toujours servis — `actif` dit qu'il y a
    // quelque chose en attente, eux disent si tout est là.
    graphe.calque = { actif: true, demandes: [12], proposes: 1, places: 1, nonPlaces: [] }
    graphe.noeuds.push(
      {
        id: '#12/p1',
        type: 'personne',
        personId: null,
        demandeId: 12,
        brouillonRef: 'p1',
        enAttente: true,
        x: 268,
        y: -750,
        w: 140,
        h: 40,
        displayName: 'Tourmaline Muguet',
        etiquetteNom: 'Tourmaline Muguet',
        sex: 'feminin',
        generation: -5,
        branch: 'pm',
        vivant: false,
        visibilite: 'complete',
        // Forme **compacte**, pas la prose : `ABT 1889` donne « ~1889 », jamais
        // « vers 1889 » — cette dernière est `texte`, dans le bloc date d'une
        // fiche (§ 14.4). C'est la valeur qu'emploie le contrat lui-même.
        etiquetteDates: '~1889 – 1972',
      },
      {
        id: '#12/f1',
        type: 'famille',
        familyId: null,
        demandeId: 12,
        enAttente: true,
        x: 216.5,
        y: -704,
        w: 22,
        h: 14,
        divorced: false,
      },
    )
    graphe.aretes.push(
      { de: '#12/p1', vers: '#12/f1', genre: 'union', divorce: false, enAttente: true },
      { de: '#12/f1', vers: 'p2', genre: 'filiation', enAttente: true },
    )
    return graphe
  }

  it('trace une arête du calque comme une autre, avec sa classe `attente`', () => {
    const graphe = grapheAvecCalque()
    const tracees = tracerAretes(indexer(graphe), graphe.aretes)
    const union = tracees.find((t) => t.de === '#12/p1')
    expect(union).toBeDefined()
    expect(union?.classes).toEqual(['ar', 'union', 'attente'])
    expect(union?.d.startsWith('M268.0,-730.0')).toBe(true)
  })

  it('conserve l’identifiant provisoire **entier** dans le tracé', () => {
    // `#12/p1` n'est jamais découpé : le `#` est le séparateur de fragment
    // d'URL et `local_ref` n'est unique que dans sa demande. Les deux morceaux
    // utiles (`demandeId`, `brouillonRef`) arrivent séparément sur le nœud.
    const graphe = grapheAvecCalque()
    const tracees = tracerAretes(indexer(graphe), graphe.aretes)
    expect(tracees.map((t) => t.de)).toContain('#12/p1')
    expect(tracees.map((t) => t.vers)).toContain('#12/f1')
  })
})

describe('le losange de famille', () => {
  it('fait bien 22 × 14, et non un cercle', () => {
    expect(pointsDeLosange({ x: -337.25, y: -404, w: 22, h: 14 })).toBe(
      '-348.25,-404 -337.25,-411 -326.25,-404 -337.25,-397',
    )
  })

  it('porte une barre oblique quand l’union est rompue', () => {
    expect(barreDeDivorce({ x: 0, y: -104 })).toBe('M-8,-96 L8,-112')
  })
})
