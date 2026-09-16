/**
 * L'index du graphe : parcours, voisinage, classes, filtres.
 *
 * Le jeu d'essai est minuscule et explicite — huit personnes, trois familles —
 * parce que ce qu'on vérifie ici n'est pas une échelle mais une **règle** : que
 * la chaîne passe par les nœuds de famille, que le voisinage englobe la
 * fratrie, qu'aucune arête ne porte de marqueur « longue », et qu'un filtre
 * sans donnée se déclare indisponible au lieu de rendre une liste vide.
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudGraphe } from '@/lib/api'
import {
  FILTRES,
  chemin,
  classesDArete,
  classesDeFamille,
  classesDeFiche,
  ensembleAEclairer,
  datesDeFiche,
  nomDeFiche,
  filtresDisponibles,
  indexer,
  noeudsFiltres,
  voisinage,
  type NoeudPersonneEtendu,
} from './graphe'

/* ------------------------------------------------------------------ */
/* Jeu d'essai                                                         */
/* ------------------------------------------------------------------ */

function personne(
  id: string,
  personId: number | null,
  generation: number,
  extra: Partial<NoeudPersonneEtendu> = {},
): NoeudPersonneEtendu {
  return {
    id,
    type: 'personne',
    personId,
    x: personId === null ? 0 : personId * 100,
    y: generation * 150,
    w: 120,
    h: 40,
    displayName: `Personne ${id}`,
    // Posée sur **tout** nœud de personne par le sérialiseur, restreint compris.
    etiquetteNom: `Personne ${id}`,
    sex: 'masculin',
    generation,
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
    ...extra,
  }
}

function famille(id: string, x: number, y: number, divorced = false): NoeudFamille {
  return { id, type: 'famille', familyId: Number(id.slice(1)), x, y, w: 22, h: 14, divorced }
}

/**
 * ```
 *            f2 (p5 × p6)
 *                 │
 *   f1 (p2 × p3) ─┴─ p2          p9 flotte, sans aucune arête
 *        │
 *   ┌────┴────┐
 *  p1(ego)   p4          f3 (p1 × p7) → p8
 * ```
 */
function grapheEssai(): Graphe {
  const noeuds: NoeudGraphe[] = [
    personne('p1', 1, 0),
    personne('p2', 2, -1),
    personne('p3', 3, -1, { sex: 'feminin' }),
    personne('p4', 4, 0),
    personne('p5', 5, -2),
    personne('p6', 6, -2, { sex: 'feminin' }),
    personne('p7', 7, 0, { sex: 'feminin', vivant: true, visibilite: 'restreinte' }),
    personne('p8', 8, 1, { sex: 'inconnu' }),
    personne('p9', 9, -3),
    famille('f1', 0, -104),
    famille('f2', 0, -254, true),
    famille('f3', 700, -104),
  ]
  const aretes: AreteGraphe[] = [
    { de: 'p2', vers: 'f1', genre: 'union', divorce: false },
    { de: 'p3', vers: 'f1', genre: 'union', divorce: false },
    { de: 'f1', vers: 'p1', genre: 'filiation' },
    { de: 'f1', vers: 'p4', genre: 'filiation' },
    { de: 'p5', vers: 'f2', genre: 'union', divorce: true },
    { de: 'p6', vers: 'f2', genre: 'union', divorce: true },
    { de: 'f2', vers: 'p2', genre: 'filiation' },
    { de: 'p1', vers: 'f3', genre: 'union', divorce: false },
    { de: 'p7', vers: 'f3', genre: 'union', divorce: false },
    { de: 'f3', vers: 'p8', genre: 'filiation' },
  ]
  return {
    empreinte: '0'.repeat(64),
    ego: 'p1',
    boite: { x: -1000, y: -400, w: 2000, h: 800 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

/* ------------------------------------------------------------------ */

describe('indexer', () => {
  it('sépare personnes et familles, et indexe par personId', () => {
    const index = indexer(grapheEssai())
    expect(index.personnes).toHaveLength(9)
    expect(index.familles).toHaveLength(3)
    expect(index.parPersonId.get(7)?.id).toBe('p7')
    expect(index.parId.get('f2')?.type).toBe('famille')
  })

  it('compte les unions depuis les arêtes — rien à demander à l’API', () => {
    const graphe = grapheEssai()
    graphe.noeuds.push(famille('f4', 1400, -104))
    graphe.aretes.push({ de: 'p1', vers: 'f4', genre: 'union', divorce: false })
    const index = indexer(graphe)
    expect(index.unionsParPersonne.get('p1')).toBe(2)
    expect(index.unionsParPersonne.get('p2')).toBe(1)
    expect(index.unionsParPersonne.get('p8')).toBeUndefined()
  })

  it('ignore une arête dont une extrémité manque, sans rien casser', () => {
    const graphe = grapheEssai()
    graphe.aretes.push({ de: 'f1', vers: 'p404', genre: 'filiation' })
    const index = indexer(graphe)
    expect(index.enfantsParFamille.get('f1')).toEqual(['p1', 'p4'])
  })

  it('range époux et enfants ensemble comme membres d’une famille', () => {
    const index = indexer(grapheEssai())
    expect(index.membresParFamille.get('f1')?.sort()).toEqual(['p1', 'p2', 'p3', 'p4'])
    expect(index.enfantsParFamille.get('f1')).toEqual(['p1', 'p4'])
  })
})

describe('chemin', () => {
  const index = indexer(grapheEssai())

  it('rend le nœud seul quand départ et arrivée se confondent', () => {
    expect(chemin(index, 'p1', 'p1')).toEqual(['p1'])
  })

  it('traverse les nœuds de famille — sans eux la chaîne aurait des trous', () => {
    expect(chemin(index, 'p2', 'p1')).toEqual(['p2', 'f1', 'p1'])
  })

  it('remonte deux générations : grand-père → ego', () => {
    expect(chemin(index, 'p5', 'p1')).toEqual(['p5', 'f2', 'p2', 'f1', 'p1'])
  })

  it('relie une belle-famille par l’union, pas seulement par la filiation', () => {
    expect(chemin(index, 'p7', 'p1')).toEqual(['p7', 'f3', 'p1'])
  })

  it('prend le plus court chemin : p4 passe par f1, pas par un détour', () => {
    expect(chemin(index, 'p4', 'p8')).toEqual(['p4', 'f1', 'p1', 'f3', 'p8'])
  })

  it('rend une liste vide quand les deux nœuds ne communiquent pas', () => {
    expect(chemin(index, 'p9', 'p1')).toEqual([])
  })

  it('rend une liste vide sur un identifiant inconnu', () => {
    expect(chemin(index, 'p1', 'p404')).toEqual([])
    expect(chemin(index, '#12/p1', 'p1')).toEqual([])
  })

  it('est symétrique, à l’ordre près', () => {
    expect(chemin(index, 'p1', 'p5')).toEqual([...chemin(index, 'p5', 'p1')].reverse())
  })

  it('rattache un nœud en attente à sa parenté', () => {
    const graphe = grapheEssai()
    graphe.noeuds.push(
      {
        id: '#12/p1',
        type: 'personne',
        personId: null,
        demandeId: 12,
        brouillonRef: 'p1',
        enAttente: true,
        x: 0,
        y: -404,
        w: 140,
        h: 40,
        displayName: 'Tourmaline Muguet',
        etiquetteNom: 'Tourmaline Muguet',
        sex: 'feminin',
        generation: -3,
        branch: 'pm',
        vivant: false,
        visibilite: 'complete',
        // Forme **compacte** (§ 14.4) : `ABT 1889` donne « ~1889 ». « vers
        // 1889 » est la prose, et elle vit dans `texte`, pas sur un nœud.
        etiquetteDates: '~1889 – 1972',
      },
      { id: '#12/f1', type: 'famille', familyId: null, demandeId: 12, enAttente: true, x: 0, y: -404, w: 22, h: 14, divorced: false },
    )
    graphe.aretes.push(
      { de: '#12/p1', vers: '#12/f1', genre: 'union', divorce: false, enAttente: true },
      { de: '#12/f1', vers: 'p5', genre: 'filiation', enAttente: true },
    )
    const avecCalque = indexer(graphe)
    expect(chemin(avecCalque, '#12/p1', 'p1')).toEqual([
      '#12/p1',
      '#12/f1',
      'p5',
      'f2',
      'p2',
      'f1',
      'p1',
    ])
  })
})

describe('voisinage', () => {
  const index = indexer(grapheEssai())

  it('donne parents, conjoints, enfants ET fratrie d’un seul geste', () => {
    expect(voisinage(index, 'p1').sort()).toEqual(['p2', 'p3', 'p4', 'p7', 'p8'])
  })

  it('n’inclut jamais la personne elle-même', () => {
    expect(voisinage(index, 'p1')).not.toContain('p1')
  })

  it('rend une liste vide pour un nœud isolé', () => {
    expect(voisinage(index, 'p9')).toEqual([])
  })
})

describe('ensembleAEclairer', () => {
  const index = indexer(grapheEssai())

  it('garde la personne, son entourage et la chaîne jusqu’à l’ego', () => {
    const { garder, chaine } = ensembleAEclairer(index, 'p5')
    expect(chaine).toEqual(['p5', 'f2', 'p2', 'f1', 'p1'])
    for (const id of ['p5', 'p6', 'p2', 'f2', 'f1', 'p1']) {
      expect(garder.has(id)).toBe(true)
    }
  })

  it('ajoute toute famille dont un membre est gardé — sinon l’arête pend', () => {
    const { garder } = ensembleAEclairer(index, 'p1')
    // f2 n'est ni dans la chaîne ni dans le voisinage de p1, mais p2 l'est.
    expect(garder.has('f2')).toBe(true)
  })

  it('ne garde pas un nœud sans rapport', () => {
    const { garder } = ensembleAEclairer(index, 'p1')
    expect(garder.has('p9')).toBe(false)
  })

  it('reste utilisable quand l’ego n’est pas atteignable', () => {
    const { garder, chaine } = ensembleAEclairer(index, 'p9')
    expect(chaine).toEqual([])
    expect(garder.has('p9')).toBe(true)
  })
})

describe('classesDeFiche', () => {
  it('traduit le sexe en initiale de `arbre.html`', () => {
    expect(classesDeFiche(personne('p1', 1, 0), 'pX')).toContain('h')
    expect(classesDeFiche(personne('p1', 1, 0, { sex: 'feminin' }), 'pX')).toContain('f')
    expect(classesDeFiche(personne('p1', 1, 0, { sex: 'inconnu' }), 'pX')).toContain('x')
  })

  it('allume `prive` sur `vivant`, jamais sur l’absence de dates', () => {
    const vivante = personne('p7', 7, 0, { vivant: true, visibilite: 'restreinte' })
    expect(classesDeFiche(vivante, 'pX')).toContain('prive')

    // Décédée et sans ligne de dates : elle ne doit surtout pas être masquée.
    const sansDates = personne('p8', 8, -4)
    expect(classesDeFiche(sansDates, 'pX')).not.toContain('prive')
  })

  it('marque l’ego', () => {
    expect(classesDeFiche(personne('p1', 1, 0), 'p1')).toContain('ego')
    expect(classesDeFiche(personne('p2', 2, 0), 'p1')).not.toContain('ego')
  })

  it('marque un remariage à partir de deux unions, pas d’une', () => {
    expect(classesDeFiche(personne('p1', 1, 0), 'pX', 1)).not.toContain('remarie')
    expect(classesDeFiche(personne('p1', 1, 0), 'pX', 2)).toContain('remarie')
  })

  it('distingue un nœud en attente', () => {
    const proposee = personne('#12/p1', null, -3, { enAttente: true, demandeId: 12 })
    expect(classesDeFiche(proposee, 'p1')).toContain('attente')
  })

  it('commence toujours par `fiche`', () => {
    expect(classesDeFiche(personne('p1', 1, 0), 'p1')[0]).toBe('fiche')
  })
})

describe('classesDeFamille', () => {
  it('marque le divorce', () => {
    expect(classesDeFamille(famille('f2', 0, 0, true))).toEqual(['fam', 'div'])
    expect(classesDeFamille(famille('f1', 0, 0))).toEqual(['fam'])
  })
})

describe('classesDArete', () => {
  it('porte le genre et conserve le divorce', () => {
    expect(classesDArete({ de: 'p1', vers: 'f1', genre: 'union', divorce: true })).toEqual([
      'ar',
      'union',
      'divorce',
    ])
    expect(classesDArete({ de: 'f1', vers: 'p2', genre: 'filiation' })).toEqual([
      'ar',
      'filiation',
    ])
  })

  it('n’émet JAMAIS de classe `long`, quelle que soit l’arête', () => {
    // Le viewer d'origine posait `.ar.long` — pointillé translucide — dès que
    // les extrémités s'écartaient de plus de 700 unités. Le commanditaire l'a
    // fait retirer (§ 7.3). Ce test est le garde-fou : il échoue si quelqu'un
    // la réintroduit, y compris « pour information ».
    const graphe = grapheEssai()
    for (const arete of graphe.aretes) {
      expect(classesDArete(arete)).not.toContain('long')
    }
    expect(classesDArete({ de: 'p1', vers: 'f99', genre: 'union', divorce: false })).not.toContain(
      'long',
    )
  })

  it('marque une arête du calque', () => {
    expect(
      classesDArete({ de: '#12/p1', vers: '#12/f1', genre: 'union', enAttente: true }),
    ).toContain('attente')
  })
})

describe('nomDeFiche', () => {
  /** Une boîte telle que le moteur la rend, avec la largeur qu'il a mesurée. */
  function avecBoite(nom: string, w: number, extra: Partial<NoeudPersonneEtendu> = {}) {
    return { ...personne('p17', 17, -3, { displayName: nom, ...extra }), w }
  }

  /**
   * Le même nœud, **sans `etiquetteNom`** — ce que rend une API en retard d'une
   * image, ou une projection incomplète.
   *
   * La clé est retirée plutôt que mise à `undefined` : `etiquetteNom` est
   * *requise* au type, parce que `NormaliseurDeGraphe.php:275` la pose sur tout
   * nœud de personne, restreint compris. Ces tests-ci exercent le **filet**, et
   * un filet ne se teste qu'en retirant ce qu'il rattrape.
   */
  function sansNomMesure(nom: string, w: number): NoeudPersonneEtendu {
    const { etiquetteNom: _ignore, ...reste } = avecBoite(nom, w)
    return reste as NoeudPersonneEtendu
  }

  it('préfère le nom MESURÉ par le moteur dès que l’API l’envoie', () => {
    // `etiquetteNom` est la chaîne sur laquelle `w` a été calculée. Quand elle
    // est là, il n'y a rien à contraindre et le filet devient du code mort.
    const n = avecBoite('Ambre Célestin Jade Mica Romarin', 149, {
      etiquetteNom: 'Ambre Romarin',
    })
    expect(nomDeFiche(n)).toEqual({ texte: 'Ambre Romarin', largeurImposee: null })
  })

  it('garde le nom mesuré sur un nœud restreint — il y reste, lui', () => {
    // Asymétrie à ne pas deviner : `etiquetteNom` est une troncature de
    // `displayName`, donc strictement moins d'information, et elle survit au
    // cercle. `etiquetteDates`, elle, tombe.
    const n = avecBoite('Pyroxène Tilleul', 133, {
      visibilite: 'restreinte',
      vivant: true,
      etiquetteNom: 'Pyroxène Tilleul',
    })
    expect(nomDeFiche(n).texte).toBe('Pyroxène Tilleul')
    expect(datesDeFiche(n)).toBeNull()
  })

  it('dessine le nom mesuré tel quel, sans rien imposer', () => {
    // « Marne Sureau », et non « Marne Dolomite Sureau » : c'est sur la première
    // que `w` a été calculée. On ne la recompose jamais depuis `displayName` —
    // le texte et la boîte divergeraient, et « de la Tour » ne se découpe pas.
    const n = avecBoite('Marne Dolomite Sureau', 173, { etiquetteNom: 'Marne Sureau' })
    expect(nomDeFiche(n)).toEqual({ texte: 'Marne Sureau', largeurImposee: null })
  })

  it('n’impose rien quand le nom tient dans la boîte', () => {
    // « Natron Romarin » : 14 signes × 6,6 = 92,4 pour 120 − 28 = 92 de place.
    // Le moteur **tronque** `w` à l'entier, d'où ces quatre dixièmes de trop —
    // sans tolérance, on condenserait l'étiquette même du viewer d'origine.
    const n = avecBoite('Natron Romarin', 120, { etiquetteNom: 'Natron Romarin' })
    expect(nomDeFiche(n)).toEqual({ texte: 'Natron Romarin', largeurImposee: null })
  })

  it('condense un nom franchement trop long, sans le couper ni le récrire', () => {
    // Le filet, donc un nœud **sans** `etiquetteNom` : 44 signes pour une boîte
    // de 149, soit 290 unités pour 121.
    const n = sansNomMesure('Glauconie Malachite Lignite Rosasite Chardon', 149)
    const e = nomDeFiche(n)
    // Le nom reste **entier** : « Glauconie Chardon » serait un nom inventé, et
    // une coupure perdrait des lettres. On le contraint, c'est tout.
    expect(e.texte).toBe('Glauconie Malachite Lignite Rosasite Chardon')
    expect(e.largeurImposee).toBe(121)
  })

  it('ne contraint jamais au-delà de la place réellement disponible', () => {
    const n = sansNomMesure('Un nom démesurément long pour sa boîte', 104)
    expect(nomDeFiche(n).largeurImposee).toBe(104 - 28)
  })

  it('n’impose rien sur une boîte dégénérée, plutôt qu’une largeur négative', () => {
    const n = sansNomMesure('X', 20)
    expect(nomDeFiche(n).largeurImposee).toBeNull()
  })
})

describe('filtres', () => {
  const sansQualite = indexer(grapheEssai()).personnes

  const avecQualite: NoeudPersonneEtendu[] = [
    personne('p1', 1, 0, { bestQuay: 2, citationCount: 3, avecImage: true }),
    personne('p2', 2, -1, { bestQuay: 0, citationCount: 1, avecImage: false }),
    personne('p3', 3, -1, { bestQuay: null, citationCount: 0, avecImage: false }),
    personne('p7', 7, 0, { vivant: true, bestQuay: null, citationCount: 0, avecImage: false }),
  ]

  it('ne déclare disponible que « Vivants » quand le graphe n’envoie pas la qualité', () => {
    // C'est le cas d'aujourd'hui : `GET /api/arbre/graphe` ne porte ni
    // `bestQuay`, ni `citationCount`, ni `avecImage` (§ 5.2 du contrat). Un
    // filtre indisponible est désactivé à l'écran — « aucune fiche » et « la
    // donnée n'est pas arrivée » ne doivent pas se ressembler.
    expect(filtresDisponibles(sansQualite)).toEqual(new Set(['vivants']))
  })

  it('déclare les cinq filtres dès que la qualité arrive', () => {
    expect(filtresDisponibles(avecQualite).size).toBe(FILTRES.length)
  })

  it('« Vérifiés » retient QUAY ≥ 2 — un acte, pas une recopie', () => {
    expect(noeudsFiltres(avecQualite, 'verifies')).toEqual(['p1'])
  })

  it('« Non vérifiés » retient ce qui a des sources mais aucun acte', () => {
    expect(noeudsFiltres(avecQualite, 'non-verifies')).toEqual(['p2'])
  })

  it('« Sans source » retient les fiches à zéro citation', () => {
    expect(noeudsFiltres(avecQualite, 'sans-source').sort()).toEqual(['p3', 'p7'])
  })

  it('« Avec image » retient les fiches portant une image d’archive', () => {
    expect(noeudsFiltres(avecQualite, 'avec-image')).toEqual(['p1'])
  })

  it('« Vivants » marche sans aucune donnée de qualité', () => {
    expect(noeudsFiltres(sansQualite, 'vivants')).toEqual(['p7'])
  })

  it('distingue une clé absente d’une valeur nulle', () => {
    // `citationCount: 0` est une donnée ; `citationCount` absent est un silence.
    const muet = [personne('p1', 1, 0)]
    expect(filtresDisponibles(muet).has('sans-source')).toBe(false)
    const bavard = [personne('p1', 1, 0, { citationCount: 0 })]
    expect(filtresDisponibles(bavard).has('sans-source')).toBe(true)
  })
})

describe('datesDeFiche', () => {
  const noeud = (extra: Partial<NoeudPersonneEtendu>) => personne('p1', 1, 0, extra)

  it('lit `etiquetteDates`, le nom que le contrat adopte', () => {
    expect(datesDeFiche(noeud({ etiquetteDates: '1901-1909 – 1987' }))).toBe('1901-1909 – 1987')
  })

  it('lit `ligneDates` — le nom que le SERVEUR envoie aujourd’hui', () => {
    // Relevé sur la charge réelle : 274 nœuds sur 274 portent `ligneDates`,
    // zéro porte `etiquette` ou `etiquetteDates`. Sans cette lecture, aucune
    // des 377 fiches n'afficherait de date et aucun test ne tomberait.
    expect(datesDeFiche(noeud({ ligneDates: '† 05.08.1721' }))).toBe('† 05.08.1721')
  })

  it('préfère le nom du contrat à celui du serveur quand les deux arrivent', () => {
    const n = noeud({ ligneDates: 'ancien', etiquetteDates: 'nouveau' })
    expect(datesDeFiche(n)).toBe('nouveau')
  })

  it('préfère le nouveau nom quand les deux sont là', () => {
    expect(datesDeFiche(noeud({ ligneDates: 'ancien', etiquetteDates: 'nouveau' }))).toBe('nouveau')
  })

  it('rend `null` sur un nœud restreint — et ne reconstitue rien', () => {
    // La clé est **absente**, pas vide (§ 11.2). Le canevas ne dessine alors
    // aucune ligne sous le nom : la boîte reste dimensionnée par le moteur, et
    // reconstituer une date ferait diverger le texte de sa boîte.
    expect(datesDeFiche(noeud({ visibilite: 'restreinte', vivant: true }))).toBeNull()
  })

  it('traite la chaîne vide comme une absence', () => {
    expect(datesDeFiche(noeud({ etiquetteDates: '' }))).toBeNull()
  })

  it('rend la constante « vivant·e » telle quelle', () => {
    // C'est la seule des trois formes de date à court-circuiter sur `vivant` :
    // la largeur est mesurée à l'étage partagé du cache, sans filtre de
    // confidentialité, et de vraies dates y encoderaient leur longueur pour
    // tous les lecteurs.
    expect(datesDeFiche(noeud({ vivant: true, etiquetteDates: 'vivant·e' }))).toBe('vivant·e')
  })
})
