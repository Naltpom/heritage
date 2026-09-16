/**
 * La construction impérative du canevas de la chronologie, vérifiée **sans
 * navigateur**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi un faux DOM plutôt qu'une vraie implémentation.** Le projet n'a ni
 * `jsdom` ni `happy-dom`, et il n'en prendra pas pour ce seul fichier — « ne pas
 * ajouter de dépendance qui ne sert pas » (§ 3 du dossier de mission). Les cinq
 * méthodes employées par `canevas.ts` tiennent dans soixante lignes.
 *
 * `arbre/canevas.test.ts` porte un faux DOM voisin, qu'il garde pour lui. Le
 * mutualiser demanderait de modifier ce fichier-là, tenu par un autre chantier,
 * pendant qu'il tourne : le coût du partage dépasse aujourd'hui celui de la
 * redite. C'est un arbitrage, pas un oubli.
 *
 * **Ce que cela vérifie, et ce que cela ne vérifie pas.** Ce test prouve que
 * l'arbre d'éléments est celui qu'on croit — hachures comprises — et qu'aucune
 * propriété de peinture n'est posée en ligne. Il ne prouve rien du **rendu** :
 * ni les couleurs, ni les polices, ni la fluidité, qui restent à voir dans un
 * navigateur.
 *
 * Il porte en revanche les deux assertions qui comptent :
 * **aucun attribut `opacity`** (règle n° 2 — un contexte d'empilement par
 * barre), et **la hachure de Marne Dolomite Sureau**, qui est l'exigence du
 * § 7.4 du dossier et non une finition.
 * ────────────────────────────────────────────────────────────────────
 */
import { beforeAll, describe, expect, it } from 'vitest'
import { construireCanevasChronologie, ID_HACHURE } from './canevas'
import { boiteDuContenu, positionAnnee, UNITE_ANNEE, type AxeTemps } from './geometrie'
import { EXEMPLE_BORNES_PARTIELLES, EXEMPLE_CHRONOLOGIE, EXEMPLE_VIDE } from './_exemple'

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
    return { personne: this.attributs['data-personne'] }
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

  parClasse(classe: string): ElementFactice[] {
    return this.tous((e) => e.classes.includes(classe))
  }

  barreDe(personId: number): ElementFactice | undefined {
    return this.tous((e) => e.attributs['data-personne'] === String(personId))[0]
  }
}

beforeAll(() => {
  ;(globalThis as unknown as Record<string, unknown>)['document'] = {
    createElementNS: (_ns: string, tag: string) => new ElementFactice(tag),
    createDocumentFragment: () => new ElementFactice('#fragment'),
  }
})

const AXE: AxeTemps = { origine: 1657, finExclusive: 2057 }
const ANNEE_COURANTE = 2037

function construire(
  options: { orientation?: 'lignes' | 'colonnes'; montrerIncertitude?: boolean } = {},
) {
  const svg = new ElementFactice('svg')
  const pilote = construireCanevasChronologie(
    svg as unknown as SVGSVGElement,
    EXEMPLE_CHRONOLOGIE,
    {
      orientation: options.orientation ?? 'colonnes',
      montrerIncertitude: options.montrerIncertitude ?? true,
      anneeCourante: ANNEE_COURANTE,
    },
  )
  return { svg, pilote: pilote! }
}

/* ------------------------------------------------------------------ */
/* Les deux règles déjà payées                                         */
/* ------------------------------------------------------------------ */

describe('les règles de rendu', () => {
  it('ne pose AUCUN attribut `opacity` — c’est la règle n° 2', () => {
    // `opacity` créerait un contexte d'empilement, donc une couche de
    // composition par barre. La mise en retrait passe par des classes, et
    // `canevas.css` n'emploie que `fill-opacity` / `stroke-opacity`.
    const { svg } = construire()
    for (const element of svg.tous(() => true)) {
      expect(element.attributs).not.toHaveProperty('opacity')
    }
  })

  it('ne pose aucune propriété de peinture en ligne', () => {
    const { svg } = construire()
    for (const element of svg.tous(() => true)) {
      expect(element.attributs).not.toHaveProperty('fill')
      expect(element.attributs).not.toHaveProperty('stroke')
      expect(element.attributs).not.toHaveProperty('style')
    }
  })

  it('ne pose aucun `transform`, sauf la rotation des noms en mode lignes', () => {
    // La règle n° 1 vise le **zoom** : il passe par le `viewBox`, jamais par un
    // `transform: scale()`. Faire tourner un texte de 9 px n'a rien à voir avec
    // elle — mais on vérifie que c'est bien le seul endroit.
    const colonnes = construire({ orientation: 'colonnes' })
    for (const element of colonnes.svg.tous(() => true)) {
      expect(element.attributs).not.toHaveProperty('transform')
    }

    const lignes = construire({ orientation: 'lignes' })
    for (const element of lignes.svg.tous((e) => 'transform' in e.attributs)) {
      expect(element.tag).toBe('text')
      expect(element.classes).toContain('nom')
      expect(element.attributs['transform']).toMatch(/^rotate\(-90 /)
    }
  })
})

/* ------------------------------------------------------------------ */
/* La hachure des dates incertaines                                    */
/* ------------------------------------------------------------------ */

describe('la hachure', () => {
  it('définit le motif que la feuille de style référence', () => {
    // Si l'identifiant change ici sans changer dans `canevas.css`, la hachure
    // disparaît **sans erreur**. C'est ce que ce test attrape.
    const { svg } = construire()
    const motifs = svg.tous((e) => e.tag === 'pattern')
    expect(motifs).toHaveLength(1)
    expect(motifs[0].attributs['id']).toBe(ID_HACHURE)
    expect(motifs[0].attributs['patternUnits']).toBe('userSpaceOnUse')
    expect(motifs[0].enfants[0].classes).toContain('trait')
  })

  it('Sureau, `BET 1901 AND 1909` : ses deux extrémités sont hachurées', () => {
    const { svg } = construire()
    const sureau = svg.barreDe(17)!
    const hachures = sureau.enfants.filter((e) => e.classes.includes('hachure'))
    expect(hachures).toHaveLength(2)

    // La naissance : du premier jour de 1901 au dernier jour de 1909.
    expect(Number(hachures[0].attributs['x'])).toBe(positionAnnee(AXE, 1901))
    expect(Number(hachures[0].attributs['width'])).toBe(9 * UNITE_ANNEE)
    // Le décès : une année connue sans jour, une case pleine.
    expect(Number(hachures[1].attributs['x'])).toBe(positionAnnee(AXE, 1987))
    expect(Number(hachures[1].attributs['width'])).toBe(UNITE_ANNEE)
  })

  it('la hachure est peinte APRÈS le corps, sinon elle disparaît dessous', () => {
    const { svg } = construire()
    const sureau = svg.barreDe(17)!
    const rangs = sureau.enfants.map((e) => e.classes.join(' '))
    expect(rangs.findIndex((c) => c.includes('corps'))).toBeLessThan(
      rangs.findIndex((c) => c.includes('hachure')),
    )
  })

  it('une date certaine n’est pas hachurée', () => {
    const { svg } = construire()
    const silice = svg.barreDe(58)!
    expect(silice.enfants.filter((e) => e.classes.includes('hachure'))).toHaveLength(0)
  })

  it('le lecteur peut tout éteindre — mais rien ne l’éteint à sa place', () => {
    const { svg } = construire({ montrerIncertitude: false })
    expect(svg.parClasse('hachure')).toHaveLength(0)
    // Le motif reste défini : c'est la structure, pas la décision.
    expect(svg.tous((e) => e.tag === 'pattern')).toHaveLength(1)
  })
})

/* ------------------------------------------------------------------ */
/* Les barres                                                          */
/* ------------------------------------------------------------------ */

describe('les barres', () => {
  it('une barre par personne, avec sa couleur de branche', () => {
    const { svg } = construire()
    const barres = svg.parClasse('barre')
    // La taille de l'ensemble est affirmée : un parcours sur un ensemble vide
    // resterait vert en ne vérifiant plus rien.
    expect(EXEMPLE_CHRONOLOGIE.barres).toHaveLength(6)
    expect(barres).toHaveLength(EXEMPLE_CHRONOLOGIE.barres.length)
    expect(svg.barreDe(17)!.classes).toContain('b-pm')
    expect(svg.barreDe(72)!.classes).toContain('b-mm')
  })

  it('porte le nom et la ligne de dates du serveur, jamais aplatis ni recomposés', () => {
    // Forme **compacte** du § 14.4 — c'est la clé `ligneDates` qui la porte sur
    // une barre de chronologie. Le front n'assemble rien : il affiche la chaîne
    // reçue, fourchette comprise.
    const { svg } = construire()
    const titre = svg.barreDe(17)!.enfants.find((e) => e.tag === 'title')
    expect(titre?.textContent).toBe('Marne Dolomite Sureau — 1901-1909 – 1987')
  })

  it('marque ce que la donnée ne sait pas — décès non daté, naissance inconnue', () => {
    const svg = new ElementFactice('svg')
    construireCanevasChronologie(svg as unknown as SVGSVGElement, EXEMPLE_BORNES_PARTIELLES, {
      orientation: 'lignes',
      montrerIncertitude: true,
      anneeCourante: ANNEE_COURANTE,
    })
    // Six barres reçues, cinq dessinées : celle sans aucune borne n'a nulle
    // part où se poser.
    expect(EXEMPLE_BORNES_PARTIELLES.barres).toHaveLength(6)
    expect(svg.parClasse('barre')).toHaveLength(5)

    expect(svg.barreDe(53)!.classes).toContain('sans-fin')
    expect(svg.barreDe(53)!.classes).not.toContain('ouverte')
    expect(svg.barreDe(36)!.classes).toContain('sans-debut')
    expect(svg.barreDe(1)!.classes).toContain('ouverte')
    expect(svg.barreDe(1)!.classes).not.toContain('sans-fin')
    expect(svg.barreDe(999)).toBeUndefined()

    // `† <1899` : borne basse nulle ⇒ une pointe **vers le passé**, et aucune
    // barre ne part de l'origine de l'axe.
    const glycine = svg.barreDe(88)!
    const pointes = glycine.enfants.filter((e) => e.tag === 'polygon')
    expect(pointes).toHaveLength(1)
    expect(pointes[0].classes).toContain('avant')
    expect(Number(glycine.enfants.find((e) => e.classes.includes('corps'))!.attributs['y']))
      .toBeGreaterThan(0)
  })

  it('une personne vivante a une barre ouverte, terminée par une pointe', () => {
    const { svg } = construire()
    const natron = svg.barreDe(1)!
    expect(natron.classes).toContain('ouverte')
    const pointe = natron.enfants.find((e) => e.tag === 'polygon')
    expect(pointe?.classes).toContain('pointe')
    expect(natron.enfants.filter((e) => e.classes.includes('hachure'))).toHaveLength(0)
  })

  it('n’écrit pas le nom d’une barre trop courte', () => {
    const { svg } = construire()
    // Deux ans de vie : le nom ne tiendrait pas, il reste dans le `<title>`.
    expect(svg.barreDe(58)!.enfants.filter((e) => e.classes.includes('nom'))).toHaveLength(0)
    expect(svg.barreDe(17)!.enfants.filter((e) => e.classes.includes('nom'))).toHaveLength(1)
  })
})

/* ------------------------------------------------------------------ */
/* Bandes, jalons, cadre                                               */
/* ------------------------------------------------------------------ */

describe('les bandes et les jalons', () => {
  it('dessine une bande par bande du serveur, en alternance', () => {
    const { svg } = construire()
    const bandes = svg.parClasse('bande')
    expect(EXEMPLE_CHRONOLOGIE.bandes).toHaveLength(16)
    expect(bandes).toHaveLength(EXEMPLE_CHRONOLOGIE.bandes.length)
    expect(bandes[0].classes).toContain('paire')
    expect(bandes[1].classes).toContain('impaire')
  })

  it('écrit les libellés du serveur tels quels', () => {
    const { svg } = construire()
    const etiquettes = svg.parClasse('etiquette-bande')
    expect(etiquettes[0].textContent).toBe('1657–1681')
  })

  it('nomme chaque groupe de branche', () => {
    const { svg } = construire()
    const entetes = svg.parClasse('entete-branche')
    expect(entetes.map((e) => e.textContent)).toEqual(['Bourrache', 'Tilleul', 'Tronc'])
  })

  it('pose le jalon sur la barre de sa personne', () => {
    const { svg } = construire()
    const jalons = svg.parClasse('jalon')
    expect(jalons).toHaveLength(1)
    expect(jalons[0].enfants[0].textContent).toBe(
      'Recensement, Saint-Ronce-de-Verçay (1957)',
    )
  })

  it('ignore un jalon dont la personne n’a pas de barre — il flotterait', () => {
    const svg = new ElementFactice('svg')
    construireCanevasChronologie(
      svg as unknown as SVGSVGElement,
      {
        ...EXEMPLE_CHRONOLOGIE,
        jalons: [{ type: 'residence', annee: 1900, libelle: 'Ailleurs', personId: 9999 }],
      },
      { orientation: 'colonnes', montrerIncertitude: true, anneeCourante: ANNEE_COURANTE },
    )
    expect(svg.parClasse('jalon')).toHaveLength(0)
  })
})

describe('le pilote', () => {
  it('rend la boîte du contenu, qui alimentera le `viewBox`', () => {
    const { pilote } = construire()
    expect(pilote.boite).toEqual(
      boiteDuContenu('colonnes', pilote.disposition.longueur, pilote.disposition.epaisseur),
    )
  })

  it('écrit le `viewBox` et rien d’autre', () => {
    const { svg, pilote } = construire()
    pilote.appliquerViewBox({ x: 1, y: 2, w: 3, h: 4 })
    expect(svg.attributs['viewBox']).toBe('1 2 3 4')
  })

  it('met en avant une barre en posant deux classes, sans rien reconstruire', () => {
    const { svg, pilote } = construire()
    const avant = svg.parClasse('barre').length

    pilote.selectionner(17)
    expect(svg.classes).toContain('selection')
    expect(svg.barreDe(17)!.classes).toContain('sel')
    expect(svg.barreDe(1)!.classes).not.toContain('sel')

    pilote.selectionner(null)
    expect(svg.classes).not.toContain('selection')
    expect(svg.barreDe(17)!.classes).not.toContain('sel')
    expect(svg.parClasse('barre')).toHaveLength(avant)
  })

  it('se détruit sans laisser de trace', () => {
    const { svg, pilote } = construire()
    pilote.selectionner(17)
    pilote.detruire()
    expect(svg.enfants).toHaveLength(0)
    expect(svg.attributs).not.toHaveProperty('viewBox')
    expect(svg.classes).not.toContain('selection')
  })

  it('rend null sans bande — l’écran affiche alors son état vide', () => {
    const svg = new ElementFactice('svg')
    const pilote = construireCanevasChronologie(svg as unknown as SVGSVGElement, EXEMPLE_VIDE, {
      orientation: 'lignes',
      montrerIncertitude: true,
      anneeCourante: ANNEE_COURANTE,
    })
    expect(pilote).toBeNull()
  })
})
