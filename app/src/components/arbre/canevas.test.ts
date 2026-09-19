/**
 * La construction impérative du canevas, vérifiée **sans navigateur**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi un faux DOM plutôt qu'une vraie implémentation.** Le projet n'a ni
 * `jsdom` ni `happy-dom`, et il n'en prendra pas pour ce seul fichier — « ne pas
 * ajouter de dépendance qui ne sert pas » (§ 3 du dossier de mission). Les
 * quatre méthodes employées par `canevas.ts` — `createElementNS`,
 * `createDocumentFragment`, `setAttribute`, `classList` — tiennent dans
 * cinquante lignes, et c'est tout ce dont ce test a besoin.
 *
 * **Ce que cela vérifie, et ce que cela ne vérifie pas.** Ce test prouve que
 * l'arbre d'éléments est celui qu'on croit et qu'aucune propriété de peinture
 * n'est posée en ligne. Il ne prouve rien du **rendu** : ni la mise en page, ni
 * les polices, ni la fluidité, qui restent à voir dans un navigateur.
 *
 * Il porte en revanche l'assertion la plus importante du portage :
 * **aucun attribut `opacity` sur aucun élément SVG**. Le canevas met en retrait
 * par classes, et `canevas.css` n'emploie que `fill-opacity` / `stroke-opacity`.
 * Un `opacity` créerait un contexte d'empilement, donc une couche de
 * composition par arête — à 377 arêtes, la frappe dans la recherche devient
 * saccadée. C'est la règle n° 2, et elle ne se voit qu'à l'échelle réelle.
 * ────────────────────────────────────────────────────────────────────
 */
import { beforeAll, describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { construireCanevas } from './canevas'

/* ------------------------------------------------------------------ */
/* Un DOM minuscule                                                    */
/* ------------------------------------------------------------------ */

class ElementFactice {
  readonly tag: string
  readonly attributs: Record<string, string> = {}
  enfants: ElementFactice[] = []
  textContent = ''

  constructor(tag: string) {
    this.tag = tag
  }

  get classes(): string[] {
    return (this.attributs['class'] ?? '').split(' ').filter(Boolean)
  }

  get classList() {
    const ecrire = (liste: string[]): void => {
      this.attributs['class'] = liste.join(' ')
    }
    return {
      add: (...c: string[]) => ecrire([...new Set([...this.classes, ...c])]),
      remove: (...c: string[]) => ecrire(this.classes.filter((x) => !c.includes(x))),
      contains: (c: string) => this.classes.includes(c),
      toggle: (c: string, actif: boolean) =>
        actif
          ? ecrire([...new Set([...this.classes, c])])
          : ecrire(this.classes.filter((x) => x !== c)),
    }
  }

  get dataset(): Record<string, string> {
    return { id: this.attributs['data-id'] }
  }

  setAttribute(cle: string, valeur: string): void {
    this.attributs[cle] = String(valeur)
  }

  removeAttribute(cle: string): void {
    delete this.attributs[cle]
  }

  appendChild(noeud: ElementFactice): ElementFactice {
    // Un fragment se déverse dans le parent au lieu de s'y insérer.
    if (noeud.tag === '#fragment') {
      for (const e of noeud.enfants) this.appendChild(e)
      noeud.enfants = []
      return noeud
    }
    this.enfants.push(noeud)
    return noeud
  }

  removeChild(noeud: ElementFactice): ElementFactice {
    this.enfants = this.enfants.filter((e) => e !== noeud)
    return noeud
  }

  get firstChild(): ElementFactice | null {
    return this.enfants[0] ?? null
  }

  /** Parcours en profondeur — l'équivalent minimal de `querySelectorAll`. */
  tous(predicat: (e: ElementFactice) => boolean): ElementFactice[] {
    const trouves: ElementFactice[] = []
    const marcher = (e: ElementFactice): void => {
      if (predicat(e)) trouves.push(e)
      e.enfants.forEach(marcher)
    }
    marcher(this)
    return trouves
  }

  parId(id: string): ElementFactice | undefined {
    return this.tous((e) => e.attributs['data-id'] === id)[0]
  }
}

beforeAll(() => {
  ;(globalThis as unknown as Record<string, unknown>)['document'] = {
    createElementNS: (_ns: string, tag: string) => new ElementFactice(tag),
    createDocumentFragment: () => new ElementFactice('#fragment'),
  }
})

/* ------------------------------------------------------------------ */
/* Jeu d'essai                                                         */
/* ------------------------------------------------------------------ */

/**
 * Un nœud tel que le sérialiseur l'émet — les quinze clés d'un nœud complet.
 *
 * ⚠ Cette fixture posait `ligneDates`, au motif que l'API l'enverrait encore.
 * Vérifié à la source plutôt qu'au souvenir : `NormaliseurDeGraphe.php` pose
 * `etiquetteNom` (l. 275) sur **tout** nœud de personne et `etiquetteDates`
 * (l. 306) sur les nœuds complets ; il ne pose `ligneDates` **nulle part**. La
 * confusion venait du nom de la méthode qui la remplit —
 * `MesureDeFiche::ligneDates()` —, qui diffère du nom de la clé.
 *
 * La mise en garde qui accompagnait ce commentaire reste juste, et vaut pour
 * cette fixture-ci comme pour la précédente : **une fixture ne prouve jamais ce
 * que le serveur envoie.** Ce qui le prouve est
 * `api/tests/Contrat/InvariantsDuContratTest.php`, qui échoue sur tout nœud de
 * personne dépourvu de `etiquetteNom`.
 */
function fiche(
  id: string,
  personId: number,
  x: number,
  y: number,
  extra: Partial<NoeudGraphe> = {},
): NoeudGraphe {
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
    branch: 'pm',
    vivant: false,
    visibilite: 'complete',
    etiquetteDates: '1900 – 1980',
    ...extra,
  } as NoeudGraphe
}

/** Les treize clés du § 11.4, porte n° 2 — et pas une de plus. */
function ficheRestreinte(id: string, personId: number, x: number, y: number): NoeudGraphe {
  return {
    id,
    type: 'personne',
    personId,
    x,
    y,
    w: 120,
    h: 40,
    displayName: `Personne ${id}`,
    // `etiquetteNom` RESTE sur un nœud restreint — c'est une troncature de
    // `displayName`, donc strictement moins d'information. `etiquetteDates`,
    // elle, tombe. Les deux ne vont pas et ne viennent pas ensemble.
    etiquetteNom: `Personne ${id}`,
    sex: 'feminin',
    generation: Math.round(y / 150),
    branch: 'mm',
    vivant: true,
    visibilite: 'restreinte',
  }
}

function grapheEssai(): Graphe {
  const noeuds: NoeudGraphe[] = [
    fiche('p1', 1, 0, 0),
    fiche('p4', 4, 200, 0),
    fiche('p2', 2, -300, -150),
    fiche('p3', 3, 300, -150, { sex: 'feminin' }),
    // Vivante hors cercle : la ligne de dates est **absente**, pas vide (§ 11.2).
    // Elle est construite à part parce que `exactOptionalPropertyTypes` refuse
    // — à juste titre — de la poser à `undefined` : absent et vide ne sont pas
    // la même chose, et c'est toute la règle du § 1.3.
    ficheRestreinte('p9', 9, 900, -150),
    { id: 'f1', type: 'famille', familyId: 1, x: 0, y: -104, w: 22, h: 14, divorced: true },
  ]
  const aretes: AreteGraphe[] = [
    { de: 'p2', vers: 'f1', genre: 'union', divorce: true },
    { de: 'p3', vers: 'f1', genre: 'union', divorce: true },
    { de: 'f1', vers: 'p1', genre: 'filiation' },
    { de: 'f1', vers: 'p4', genre: 'filiation' },
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

function monter(): { svg: ElementFactice; pilote: ReturnType<typeof construireCanevas> } {
  const svg = new ElementFactice('svg')
  return { svg, pilote: construireCanevas(svg as unknown as SVGSVGElement, grapheEssai()) }
}

/* ------------------------------------------------------------------ */

describe('construireCanevas', () => {
  it('empile les arêtes sous les familles, puis les fiches', () => {
    const { svg } = monter()
    expect(svg.enfants.map((e) => e.attributs['class'])).toEqual([
      'aretes',
      'familles',
      'fiches',
    ])
  })

  it('crée un élément par nœud et par arête', () => {
    const { svg } = monter()
    expect(svg.tous((e) => e.tag === 'path' && e.classes.includes('ar'))).toHaveLength(4)
    expect(svg.tous((e) => e.classes.includes('fiche'))).toHaveLength(5)
    expect(svg.tous((e) => e.tag === 'polygon')).toHaveLength(1)
    expect(svg.tous((e) => e.classes.includes('barre'))).toHaveLength(1)
  })

  it('compose une fiche : titre, cadre, liseré de branche, silhouette, nom, dates', () => {
    const { svg } = monter()
    const ego = svg.parId('p1')
    expect(ego).toBeDefined()
    if (ego === undefined) return
    expect(ego.classes).toContain('ego')
    // Sans `portrait`, l'emplacement photo porte la silhouette — un rond et une
    // épaule — et jamais une image vide qui ferait une requête pour rien.
    expect(ego.enfants.map((e) => e.tag)).toEqual(['title', 'rect', 'rect', 'circle', 'path', 'text', 'text'])
    expect(ego.enfants[0].textContent).toBe('Personne p1')
    expect(ego.enfants[2].attributs['class']).toBe('br b-pm')
    expect(ego.enfants[3].attributs['class']).toBe('photo-vide')
    expect(ego.enfants[5].attributs['class']).toBe('nm')
    expect(ego.enfants[6].textContent).toBe('1900 – 1980')
  })

  it('ne dessine aucune ligne de dates sur un nœud restreint, et n’en invente pas', () => {
    const { svg } = monter()
    const restreint = svg.parId('p9')
    expect(restreint).toBeDefined()
    if (restreint === undefined) return
    expect(restreint.classes).toContain('prive')
    expect(restreint.tous((e) => e.tag === 'text')).toHaveLength(1)
    // La boîte reste celle du moteur : la largeur ne dépend pas du lecteur.
    expect(restreint.enfants[1].attributs['width']).toBe('120')
  })

  it('n’écrit QUE le viewBox — aucun transform, aucun style en ligne', () => {
    const { svg, pilote } = monter()
    pilote.appliquerViewBox({ x: -600, y: -300, w: 1200, h: 400 })
    expect(svg.attributs['viewBox']).toBe('-600 -300 1200 400')
    expect(svg.attributs['transform']).toBeUndefined()
    expect(svg.attributs['style']).toBeUndefined()
  })

  it('ne pose JAMAIS d’attribut `opacity` sur un élément SVG', () => {
    // Règle n° 2. `opacity` créerait une couche de composition par élément ;
    // les mises en retrait passent toutes par des classes et, dans la feuille,
    // par `fill-opacity` / `stroke-opacity`.
    const { svg, pilote } = monter()
    pilote.eclairer(new Set(['p1', 'f1', 'p2']), 'p1', new Set(['p1']))
    pilote.marquerTrouves(new Set(['p2']))
    for (const element of svg.tous(() => true)) {
      expect(Object.keys(element.attributs)).not.toContain('opacity')
      expect(Object.keys(element.attributs)).not.toContain('fill-opacity')
      expect(element.attributs['style'] ?? '').not.toContain('opacity')
    }
  })

  it('éclaire par classes : `on` sur ce qu’on garde, `sel` sur la sélection', () => {
    const { svg, pilote } = monter()
    pilote.eclairer(new Set(['p1', 'f1', 'p2']), 'p1', new Set(['p1']))
    expect(svg.classes).toContain('focus')
    expect(svg.parId('p1')?.classes).toContain('sel')
    expect(svg.parId('p2')?.classes).toContain('on')
    expect(svg.parId('p4')?.classes).not.toContain('on')
    // Une arête ne s'allume que si ses DEUX extrémités sont gardées.
    const versF1 = svg.tous((e) => e.attributs['data-de'] === 'p2')[0]
    const versP4 = svg.tous((e) => e.attributs['data-vers'] === 'p4')[0]
    expect(versF1.classes).toContain('on')
    expect(versP4.classes).not.toContain('on')
  })

  it('marque et démarque les résultats de recherche', () => {
    const { svg, pilote } = monter()
    pilote.marquerTrouves(new Set(['p2']))
    expect(svg.classes).toContain('recherche')
    expect(svg.parId('p2')?.classes).toContain('trouve')

    pilote.effacerRecherche()
    expect(svg.classes).not.toContain('recherche')
    expect(svg.parId('p2')?.classes).not.toContain('trouve')
  })

  it('rend le graphe à sa peinture pleine', () => {
    const { svg, pilote } = monter()
    pilote.eclairer(new Set(['p1']), 'p1', null)
    pilote.toutAfficher()
    expect(svg.classes).not.toContain('focus')
    expect(svg.parId('p1')?.classes).not.toContain('sel')
  })

  it('retrouve un nœud par son identifiant, y compris provisoire', () => {
    const { pilote } = monter()
    expect(pilote.boiteDe('p1')?.x).toBe(0)
    expect(pilote.boiteDe('#12/p1')).toBeNull()
    expect(pilote.boitesDe(['p1', 'p404', 'f1'])).toHaveLength(2)
  })

  it('vide le canevas à la destruction — une reconstruction ne s’empile pas', () => {
    const { svg, pilote } = monter()
    pilote.detruire()
    expect(svg.enfants).toHaveLength(0)
    expect(svg.attributs['viewBox']).toBeUndefined()
  })
})
