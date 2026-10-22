import { describe, expect, it } from 'vitest'
import { ArbreAffiche } from './donnees'
import { arbreFictif, coupleSeul, type ArbreDEssai } from './essai/arbreFictif'
import { installerLesPolicesDuDisque } from './essai/polices'
import { radians } from './eventail'
import { afficheEventail, arrondiDizaine, type AfficheProduite } from './fabriquer'
import { formatOriente, page, pixels, pngPossible } from './formats'
import { versSvg } from './svg'

// Dès le chargement : des `describe` produisent leur affiche à la collecte des cas.
installerLesPolicesDuDisque()

const DATE = new Date(2026, 9, 22) // le pied est daté : octobre 2026

function produire(essai: ArbreDEssai, options: { masque?: boolean; profondeur?: number } = {}): AfficheProduite {
  const A = new ArbreAffiche(essai.charge)
  if (options.masque) A.masquerVivants()
  return afficheEventail(A, essai.mari, essai.femme, { date: DATE, ...(options.profondeur !== undefined ? { profondeur: options.profondeur } : {}) })
}

const textes = (a: AfficheProduite): string[] => a.scene.textes.map((t) => t.texte)

describe('fidélité à l’affiche d’origine', () => {
  /*
   * Les valeurs attendues sont celles de `affiche_eventail()` (Python) sur **le même arbre
   * fictif** (`arbreFictif()`, graine 1789), avec la mention du pied d'Heritage. Mesuré le
   * 22/10/2026 : 960 des 964 éléments des deux SVG coïncident au millième de millimètre ; les
   * quatre autres sont les écarts voulus (une égalité tranchée au dernier bit, voir
   * `Eventail.texteTan`, et la naissance « 27 novembre » que l'original ne savait pas écrire).
   * Changer le générateur de l'arbre fictif oblige à refaire la mesure.
   */
  it('retrouve les dimensions, le rayon et le disque central de l’original', () => {
    const { scene, eventail } = produire(arbreFictif())
    expect(scene.largeur).toBeCloseTo(1325.0306549706954, 6)
    expect(scene.hauteur).toBeCloseTo(849.2500387877003, 6)
    expect(eventail.k).toBeCloseTo(1.966908035799861, 9)
    expect(eventail.rC).toBeCloseTo(152.02896429598331, 6)
    expect(eventail.rExt).toBeCloseTo(627.5153274853477, 6)
    expect(eventail.nbNiveaux).toBe(11)
  })

  it('retrouve la hauteur de l’original quand les dates des vivants sont masquées', () => {
    expect(produire(arbreFictif(), { masque: true }).scene.hauteur).toBeCloseTo(839.5096197499453, 6)
  })

  it('arrondit les repères d’années à la dizaine comme Python (au pair)', () => {
    expect([1744, 1745, 1746, 1755, 1750].map(arrondiDizaine)).toEqual([1740, 1740, 1750, 1760, 1750])
  })
})

describe('la géométrie de l’éventail', () => {
  const { eventail: E, scene } = produire(arbreFictif())

  it('ouvre le couple sur un demi-cercle, lui à gauche, elle à droite', () => {
    expect(E.h.a2).toBeCloseTo(180, 9)
    expect(E.f.a1).toBeCloseTo(0, 9)
    expect(E.h.a1).toBeCloseTo(E.f.a2, 9)
  })

  it('pose chaque place dans celle de son enfant, père à gauche, mère à droite, sans chevauchement', () => {
    for (const n of E.noeuds) {
      expect(n.a2 - n.a1, `${n.p.prenoms} : angle`).toBeGreaterThan(0)
      if (n.pere) expect(n.pere.a2).toBeCloseTo(n.a2, 9)
      if (n.mere) expect(n.mere.a1).toBeCloseTo(n.a1, 9)
      if (n.pere && n.mere) expect(n.pere.a1).toBeCloseTo(n.mere.a2, 9)
      for (const p of n.parents) {
        expect(p.a1).toBeGreaterThanOrEqual(n.a1 - 1e-9)
        expect(p.a2).toBeLessThanOrEqual(n.a2 + 1e-9)
      }
    }
  })

  it('donne à chaque place au moins l’angle que réclame son texte', () => {
    for (const n of E.noeuds) expect(n.a2 - n.a1, n.p.prenoms).toBeGreaterThanOrEqual(n.minimum - 1e-9)
  })

  it('écrit chaque texte tangentiel dans la longueur de son arc, marges comprises', () => {
    const arcs = scene.textes.filter((t) => t.type === 'texteSurArc')
    expect(arcs.length).toBeGreaterThan(50)
    for (const t of arcs) {
      if (t.type !== 'texteSurArc') continue
      const dispo = radians(t.a2 - t.a1) * t.r - 2 * E.R.margeTan
      expect(t.style.largeur(t.texte), t.texte).toBeLessThanOrEqual(dispo + 1e-6)
    }
  })

  it('écrit chaque texte rayonnant dans l’épaisseur de son anneau, quitte à le serrer', () => {
    const rayonnants = scene.textes.filter((t) => t.type === 'texte' && t.transform?.includes('rotate') && !t.transform.includes(' rotate(-90'))
    expect(rayonnants.length).toBeGreaterThan(50)
    const epaisseurMax = Math.max(...E.anneaux.filter((a) => a.orientation === 'rad').map((a) => a.r2 - a.r1 - 2 * E.R.margeRad))
    for (const t of rayonnants) {
      if (t.type !== 'texte') continue
      const largeur = t.compression ?? t.style.largeur(t.texte)
      expect(largeur, t.texte).toBeLessThanOrEqual(epaisseurMax + 1e-6)
    }
  })
})

describe('l’ajustement des corps', () => {
  /*
   * Les boîtes des descendants prennent la largeur de leur texte : rien à ajuster. C'est dans
   * l'éventail que les textes se battent pour la place — un anneau rayonnant a l'épaisseur du
   * 96ᵉ centile des noms, et les 4 % les plus longs doivent rétrécir (jusqu'à 84 % du corps),
   * puis se serrer.
   */
  it('réduit le corps, puis serre les lettres d’un nom trop long pour son anneau, sans jamais déborder', () => {
    const { scene, eventail: E } = produire(arbreFictif())
    const rayonnants = scene.textes.filter((t) => t.type === 'texte' && t.transform?.includes(' rotate('))
    const serres = rayonnants.filter((t) => t.type === 'texte' && t.compression)
    expect(serres.length, 'des noms serrés').toBeGreaterThan(0)
    for (const t of serres) {
      if (t.type !== 'texte') continue
      // Serré, il a d'abord été réduit au plancher : 84 % de son corps d'anneau.
      const corps = Math.min(...[7.2, 7.6, 8.2].map((c) => Math.abs(t.style.taille - c * 0.84)))
      expect(corps, t.texte).toBeLessThan(1e-9)
    }
    const reduits = scene.textes.filter((t) => t.type === 'texteSurArc' && ![12.5, 10, 8.8].some((c) => Math.abs(t.style.taille - c * E.R.croissanceMax) < 1e-9 || Math.abs(t.style.taille - c * E.R.croissanceMax * 0.86) < 1e-9))
    expect(reduits.length, 'des textes tangentiels réduits pour tenir dans leur arc').toBeGreaterThan(0)
  })
})

describe('les signes des unions', () => {
  const { scene } = produire(arbreFictif())
  const t = textes({ scene } as AfficheProduite)

  it('pose ∞ pour un mariage, & pour une union sans mariage, et barre le signe d’un divorce', () => {
    expect(t).toContain('∞')
    expect(t).toContain('&')
    expect(t).toContain('div.')
    expect(scene.elements.some((e) => e.type === 'ligne'), 'le trait oblique du divorce').toBe(true)
  })

  it('écrit « veuf » devant le remariage, et le rang des unions', () => {
    expect(t).toContain('veuf')
    expect(t).toContain('1')
    expect(t).toContain('2')
    expect(t, 'pas de « séparés » : un divorce suppose un mariage').not.toContain('sép.')
  })

  it('légende les signes comme l’original', () => {
    expect(t).toContain('&  union sans mariage connu     ∞2  deuxième union de la personne')
    expect(t).toContain('signe barré, div.  divorcés     veuf, veuve  remarié(e) après la mort du conjoint')
  })
})

describe('les vivants', () => {
  it('masque les dates des vivants et garde leurs noms ; montre-les sans le masque', () => {
    const essai = arbreFictif()
    const silex = essai.charge.personnes.find((p) => p.id === essai.reperes['petit1'])!
    expect(silex.visibilite === 'complete' && silex.vivant).toBe(true)
    const sans = textes(produire(essai))
    const avec = textes(produire(essai, { masque: true }))
    expect(sans.some((x) => x.includes('1936'))).toBe(true)
    expect(avec.some((x) => x.includes('1936'))).toBe(false)
    expect(avec).toContain('Silex')
    // Aucun vivant n'a plus la moindre ligne de dates.
    for (const p of essai.charge.personnes) {
      if (p.visibilite !== 'complete' || !p.vivant || !p.birth.min) continue
      const an = p.birth.min.slice(0, 4)
      expect(avec.some((x) => x.startsWith('°') && x.includes(an) && x.includes(p.givenNames)), p.givenNames).toBe(false)
    }
  })

  it('montre un vivant hors cercle sous le masque, sans rien de plus que ce que l’API a servi', () => {
    const essai = arbreFictif()
    const t = textes(produire(essai))
    expect(t).toContain('Personne vivante')
    // Son nom n'est même pas dans la charge : l'arbre fictif la sert comme l'API, sous le masque.
    expect(JSON.stringify(essai.charge)).not.toContain('Obsidienne')
    expect(t.join(' ')).not.toMatch(/Obsidienne|VALÉRIANE/)
  })

  it('écrit la naissance réduite d’un vivant (l’année) et celle dont seuls jour et mois sont partagés', () => {
    const t = textes(produire(arbreFictif()))
    expect(t).toContain('° 1949')
    expect(t).toContain('° 27 novembre')
  })
})

describe('le pied', () => {
  it('ne nomme personne : la mention est générique et datée', () => {
    const t = textes(produire(arbreFictif()))
    expect(t).toContain('Arbre établi avec Heritage — octobre 2026')
    expect(t.some((x) => x.startsWith('Arbre établi par'))).toBe(false)
  })

  it('compte les ancêtres, les générations et l’implexe', () => {
    const a = produire(arbreFictif())
    expect(a.chiffres.implexes).toBe(1)
    expect(textes(a)).toContain("1 couple d'ancêtres présent deux fois (implexe ◆)")
    expect(textes(a).some((x) => x.includes('◆1'))).toBe(true)
  })
})

describe('les cas limites', () => {
  it('dessine un couple sans ascendance ni descendance (l’original divisait par zéro)', () => {
    const a = produire(coupleSeul())
    expect(Number.isFinite(a.scene.largeur) && Number.isFinite(a.scene.hauteur)).toBe(true)
    expect(a.eventail.nbNiveaux).toBe(1)
    expect(a.scene.elements.filter((e) => e.type === 'rect' && e.trait !== undefined && e.rayon === 1.2)).toHaveLength(0)
    expect(versSvg(a.scene)).not.toMatch(/NaN|Infinity/)
    expect(textes(a)).toContain('? — lignée du père de Onyx')
  })

  it('limite l’ascendance au nombre de générations choisi', () => {
    const a = produire(arbreFictif(), { profondeur: 3 })
    expect(a.eventail.nbNiveaux).toBe(4)
    expect(a.scene.largeur).toBeLessThan(produire(arbreFictif()).scene.largeur)
  })
})

describe('le rendu SVG et les formats', () => {
  const { scene } = produire(arbreFictif())

  it('pose le dessin, agrandi et centré, dans un A0 à l’italienne', () => {
    const format = formatOriente('A0', scene.largeur, scene.hauteur)!
    expect(format).toEqual({ largeur: 1189, hauteur: 841 })
    const svg = versSvg(scene, { format })
    expect(svg).toContain('width="1189.00mm" height="841.00mm"')
    expect(svg).toMatch(/<g transform="translate\([\d.]+,[\d.]+\) scale\(0\.\d{6}\)">/)
  })

  it('embarque seulement les polices dont la scène se sert', () => {
    const polices = new Map([...scene.polices].map((c) => [c, 'AAAA']))
    polices.set('police-inutile', 'BBBB')
    const svg = versSvg(scene, { polices })
    expect(svg.match(/@font-face/g)).toHaveLength(scene.polices.size)
    expect(svg).not.toContain('BBBB')
  })

  it('échappe le texte (« & » des unions libres)', () => {
    expect(versSvg(scene)).toContain('>&amp;</text>')
  })

  it('compte les pixels du PNG et refuse ce qu’un navigateur ne saurait pas dessiner', () => {
    const p = page('220x127', scene.largeur, scene.hauteur)
    expect(p).toEqual({ largeur: 2200, hauteur: 1270 })
    expect(pixels(p, 150)).toEqual([12992, 7500])
    expect(pngPossible(p, 100)).toBe(true)
    expect(pngPossible(p, 300)).toBe(false)
    expect(page('naturelle', 100, 50)).toEqual({ largeur: 100, hauteur: 50 })
  })
})
