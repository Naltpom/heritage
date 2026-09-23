/**
 * La disposition : le rangement en pistes et les groupes de branche.
 *
 * C'est le calcul qui, faux, produit une chronologie **plausible** : deux vies
 * superposées sur la même piste se lisent comme une seule, et rien à l'écran ne
 * le dit. D'où l'invariant vérifié ici sur le jeu d'essai entier — aucune paire
 * de barres ne partage une piste **et** un intervalle de temps.
 */
import { describe, expect, it } from 'vitest'
import {
  DESCRIPTIONS_BRANCHE,
  LIBELLES_BRANCHE,
  ORDRE_BRANCHES,
  disposer,
  entetesDeGroupe,
  estVide,
  placerJalons,
  pointDeJalon,
  repartirEnPistes,
} from './disposition'
import {
  EPAISSEUR_BARRE,
  GOUTTIERE_BRANCHE,
  PAS_PISTE,
  UNITE_ANNEE,
  positionAnnee,
  type AxeTemps,
} from './geometrie'
import {
  EXEMPLE_BORNES_PARTIELLES,
  EXEMPLE_CHRONOLOGIE,
  EXEMPLE_VIDE,
} from './_exemple'

const ANNEE_COURANTE = 2037
const AXE: AxeTemps = { origine: 1657, finExclusive: 2057 }

describe('repartirEnPistes', () => {
  it('deux segments qui se chevauchent ne partagent pas une piste', () => {
    expect(
      repartirEnPistes(
        [
          { debut: 0, fin: 10 },
          { debut: 5, fin: 15 },
        ],
        0,
      ),
    ).toEqual([0, 1])
  })

  it('deux segments qui se suivent partagent la piste', () => {
    expect(
      repartirEnPistes(
        [
          { debut: 0, fin: 10 },
          { debut: 10, fin: 20 },
        ],
        0,
      ),
    ).toEqual([0, 0])
  })

  it('l’écart exigé sépare deux vies qui se touchent', () => {
    // Sans blanc, une vie qui commence l'année où une autre finit se lit comme
    // une seule barre continue.
    expect(
      repartirEnPistes(
        [
          { debut: 0, fin: 10 },
          { debut: 12, fin: 20 },
        ],
        5,
      ),
    ).toEqual([0, 1])
  })

  it('rend les pistes dans l’ordre d’entrée, quel que soit l’ordre des débuts', () => {
    expect(
      repartirEnPistes(
        [
          { debut: 30, fin: 40 },
          { debut: 0, fin: 10 },
          { debut: 5, fin: 20 },
        ],
        0,
      ),
      // Le plus ancien prend la piste 0 même s'il arrive en deuxième position.
    ).toEqual([0, 0, 1])
  })

  it('remplit le premier trou disponible, pas la dernière piste ouverte', () => {
    const pistes = repartirEnPistes(
      [
        { debut: 0, fin: 100 },
        { debut: 10, fin: 20 },
        { debut: 30, fin: 40 },
      ],
      0,
    )
    expect(pistes).toEqual([0, 1, 1])
  })

  it('sans segment, aucune piste', () => {
    expect(repartirEnPistes([], 0)).toEqual([])
  })
})

describe('disposer', () => {
  const disposition = disposer(EXEMPLE_CHRONOLOGIE, 'colonnes', ANNEE_COURANTE, true)

  it('le jeu d’essai a la taille qu’on croit', () => {
    // Les parcours de ce fichier n'ont de valeur que si l'ensemble parcouru
    // n'est pas vide : on l'affirme, plutôt que de rester vert sur rien.
    expect(EXEMPLE_CHRONOLOGIE.barres).toHaveLength(6)
    expect(EXEMPLE_CHRONOLOGIE.bandes).toHaveLength(16)
    expect(disposition?.barres).toHaveLength(6)
    expect(disposition?.groupes).toHaveLength(3)
    // NEUF : les quatre quarts du lecteur, le tronc, les quatre quarts de son conjoint.
    // La belle-famille n'en fait plus partie — qui entre par alliance prend le bloc de son
    // partenaire, et n'a donc pas de région à lui.
    //
    // **Dix régions et treize blocs, et les deux comptes sont justes.** Les quatre qui
    // manquent à l'appel sont les enveloppes `p`, `m`, `p'`, `m'` — des RÉUNIONS de deux
    // régions voisines, dérivées à la géométrie. Une valeur de `Branche` désigne une
    // partition, jamais une réunion. Voir `Branch::Cpp` et `CalculAscendance`.
    expect(ORDRE_BRANCHES).toHaveLength(9)
    // Et aucune n'est servie deux fois : c'est ce que l'ordre garantit aux colonnes.
    expect(new Set(ORDRE_BRANCHES).size).toBe(ORDRE_BRANCHES.length)
  })

  it('rend null sans bande — il n’y a pas d’axe, donc rien à placer', () => {
    expect(disposer(EXEMPLE_VIDE, 'lignes', ANNEE_COURANTE, true)).toBeNull()
  })

  it('ne laisse tomber aucune barre traçable, et compte celles qui ne le sont pas', () => {
    expect(disposition?.ignorees).toBe(0)

    const partielles = disposer(EXEMPLE_BORNES_PARTIELLES, 'lignes', ANNEE_COURANTE, true)
    // Six barres reçues, cinq posables : celle qui n'a aucune borne n'a nulle
    // part où aller. Elle est comptée, pas perdue — et ce compte ne s'affiche
    // nulle part (§ 9.1).
    expect(EXEMPLE_BORNES_PARTIELLES.barres).toHaveLength(6)
    expect(partielles?.barres).toHaveLength(5)
    expect(partielles?.ignorees).toBe(1)
    expect(partielles?.barres.some((b) => b.barre.personId === 999)).toBe(false)
  })

  it('range les bornes partielles sans les faire se recouvrir', () => {
    const partielles = disposer(EXEMPLE_BORNES_PARTIELLES, 'lignes', ANNEE_COURANTE, true)
    const posees = partielles!.barres
    expect(posees).toHaveLength(5)
    // Ciboule (1878 – <1931) chevauche Glycine († <1899) : deux pistes.
    expect(partielles?.groupes[0].pistes).toBe(2)
    const glycine = posees.find((p) => p.barre.personId === 88)
    expect(glycine?.piste).toBe(1)

    // **Aucune barre ne commence avant l'origine de l'axe.** C'est l'assertion
    // qui manquait : une borne `null` prise pour un zéro donnait un `long0` de
    // −13 152, une barre traversant tout le canevas, et aucune erreur nulle part.
    for (const p of posees) expect(p.trace.long0).toBeGreaterThanOrEqual(0)
  })

  it('suit l’ordre des branches de la charte, pas celui du filtre', () => {
    expect(disposition?.groupes.map((g) => g.branche)).toEqual(['pm', 'mm', 'tr'])
  })

  it('garde une branche qui porte une barre sans figurer dans le filtre', () => {
    // L'exemple du § 9.1 porte lui-même ce cas : `branches: ["pm", "mm"]` et une
    // barre de branche `tr`. Une barre sans groupe disparaîtrait du dessin sans
    // erreur ni trace — on préfère un groupe de plus.
    expect(EXEMPLE_CHRONOLOGIE.branches).not.toContain('tr')
    expect(disposition?.groupes.map((g) => g.branche)).toContain('tr')
  })

  it('ne perd aucune barre', () => {
    expect(disposition?.barres).toHaveLength(EXEMPLE_CHRONOLOGIE.barres.length)
    const places = new Set(disposition?.barres.map((b) => b.barre.personId))
    for (const barre of EXEMPLE_CHRONOLOGIE.barres) expect(places.has(barre.personId)).toBe(true)
  })

  it('empile les groupes en travers, séparés par leur gouttière', () => {
    const [pm, mm, tr] = disposition!.groupes
    // Trois vies qui se chevauchent chez les Bourrache ⇒ trois pistes.
    expect(pm.pistes).toBe(3)
    expect(pm.travers).toBe(0)
    expect(pm.epaisseur).toBe(3 * PAS_PISTE - (PAS_PISTE - EPAISSEUR_BARRE))

    expect(mm.pistes).toBe(1)
    expect(mm.travers).toBe(pm.epaisseur + GOUTTIERE_BRANCHE)
    expect(mm.epaisseur).toBe(EPAISSEUR_BARRE)

    expect(tr.travers).toBe(mm.travers + mm.epaisseur + GOUTTIERE_BRANCHE)
    // Pas de gouttière après le dernier groupe.
    expect(disposition!.epaisseur).toBe(tr.travers + tr.epaisseur)
  })

  it('aucune barre n’en recouvre une autre sur la même piste', () => {
    const barres = disposition!.barres
    for (let i = 0; i < barres.length; i++) {
      for (let j = i + 1; j < barres.length; j++) {
        if (barres[i].travers !== barres[j].travers) continue
        const a = barres[i].trace
        const b = barres[j].trace
        const seChevauchent = a.long0 < b.long1 && b.long0 < a.long1
        expect(seChevauchent).toBe(false)
      }
    }
  })

  it('l’axe va au moins jusqu’au bout de la dernière bande', () => {
    expect(disposition?.longueur).toBe(positionAnnee(AXE, 2057))
  })

  it('… et s’étend si une barre ouverte dépasse la dernière bande', () => {
    // On allonge le dessin plutôt que de couper la barre d'une personne
    // vivante ; on n'ajoute surtout pas de bande, elles viennent du serveur.
    const tard = disposer(EXEMPLE_CHRONOLOGIE, 'colonnes', 2100, true)
    expect(tard?.longueur).toBe(positionAnnee(AXE, 2101))
    expect(tard?.axe.finExclusive).toBe(2057)
  })

  it('place les barres à la hauteur de leur piste', () => {
    const sureau = disposition!.barres.find((b) => b.barre.personId === 17)
    expect(sureau?.travers).toBe(sureau!.piste * PAS_PISTE)
    expect(sureau?.trace.corps.y).toBe(sureau?.travers)
  })

  it('la disposition ne dépend pas de l’orientation, seule la projection change', () => {
    const lignes = disposer(EXEMPLE_CHRONOLOGIE, 'lignes', ANNEE_COURANTE, true)
    expect(lignes?.epaisseur).toBe(disposition?.epaisseur)
    expect(lignes?.longueur).toBe(disposition?.longueur)
    expect(lignes?.barres.map((b) => b.travers)).toEqual(
      disposition?.barres.map((b) => b.travers),
    )
  })

  it('éteindre l’incertitude ne change pas le rangement', () => {
    // La hachure est **dans** le corps de la barre : l'éteindre ne raccourcit
    // rien, donc ne redistribue rien. Une chronologie qui se réorganiserait à
    // chaque bascule d'affichage serait illisible.
    const sans = disposer(EXEMPLE_CHRONOLOGIE, 'colonnes', ANNEE_COURANTE, false)
    expect(sans?.barres.map((b) => b.travers)).toEqual(
      disposition?.barres.map((b) => b.travers),
    )
  })
})

describe('estVide', () => {
  it('reconnaît une réponse sans bande ni barre', () => {
    expect(estVide(EXEMPLE_VIDE)).toBe(true)
    expect(estVide(EXEMPLE_CHRONOLOGIE)).toBe(false)
  })

  it('des bandes sans aucune barre restent un écran vide', () => {
    expect(estVide({ ...EXEMPLE_CHRONOLOGIE, barres: [] })).toBe(true)
  })
})

describe('entetesDeGroupe', () => {
  const disposition = disposer(EXEMPLE_CHRONOLOGIE, 'lignes', ANNEE_COURANTE, true)!

  it('pose le nom de branche au-dessus de sa colonne, en mode lignes', () => {
    const entetes = entetesDeGroupe(disposition.groupes, 'lignes')
    expect(entetes[0].texte).toBe(LIBELLES_BRANCHE['pm'])
    expect(entetes[0].ancrage).toBe('middle')
    expect(entetes[0].x).toBe(disposition.groupes[0].epaisseur / 2)
  })

  it('… et à gauche de sa rangée, en mode colonnes', () => {
    const entetes = entetesDeGroupe(disposition.groupes, 'colonnes')
    expect(entetes[0].ancrage).toBe('end')
    expect(entetes[0].x).toBeLessThan(0)
  })

  it('les cinq branches ont un nom court et un nom long', () => {
    for (const branche of ORDRE_BRANCHES) {
      expect(LIBELLES_BRANCHE[branche].length).toBeGreaterThan(0)
      expect(DESCRIPTIONS_BRANCHE[branche]).toContain(LIBELLES_BRANCHE[branche])
    }
  })
})

describe('les faits datés prolongent la barre', () => {
  /** Le cas réel : un recensement de 1957 sur une personne dont le décès n'est
   *  pas daté. Sans prolongement, le jalon se pose **hors** de sa barre. */
  const vue = {
    ...EXEMPLE_BORNES_PARTIELLES,
    barres: [
      {
        personId: 53,
        displayName: 'Turquoise Marjolaine',
        branch: 'pm' as const,
        generation: -7,
        sex: 'feminin' as const,
        vivant: false,
        debut: { min: 1891, max: 1891, certain: false },
        fin: null,
        ouverte: false as const,
        ligneDates: 'n. 1891',
      },
    ],
    jalons: [
      { type: 'recensement', annee: 1957, libelle: 'Recensement', personId: 53 },
      { type: 'residence', annee: 1932, libelle: 'Résidence', personId: 53 },
    ],
  }

  it('la barre couvre le dernier fait connu, et le jalon tombe dessus', () => {
    const d = disposer(vue, 'lignes', ANNEE_COURANTE, true)!
    expect(d.barres).toHaveLength(1)
    const barre = d.barres[0]
    expect(barre.trace.long0).toBe(positionAnnee(d.axe, 1891))
    // 1957, le plus tardif des deux faits, et non 1892 — la fin de la seule
    // fourchette de naissance.
    expect(barre.trace.long1).toBe(positionAnnee(d.axe, 1958))

    for (const jalon of vue.jalons) {
      const p = pointDeJalon(d.axe, 'lignes', jalon.annee, barre.travers)
      expect(p.y).toBeGreaterThanOrEqual(barre.trace.long0)
      expect(p.y).toBeLessThanOrEqual(barre.trace.long1)
    }
  })

  it('… mais ne remonte pas avant une naissance connue', () => {
    // Un fait antérieur à une naissance connue est une donnée contradictoire :
    // on ne dessine pas une barre qui commence avant que la personne ne naisse.
    const avant = {
      ...vue,
      jalons: [{ type: 'autre', annee: 1800, libelle: 'Douteux', personId: 53 }],
    }
    const d = disposer(avant, 'lignes', ANNEE_COURANTE, true)!
    expect(d.barres[0].trace.long0).toBe(positionAnnee(d.axe, 1891))
  })

  it('… et remonte quand la naissance est inconnue', () => {
    // « Jade De le Nigelle », baptisé en 1660, décédé en 1721, naissance
    // inconnue : la barre part du baptême.
    const sansNaissance = {
      ...vue,
      barres: [{ ...vue.barres[0], debut: null, fin: { min: 1721, max: 1721, certain: true } }],
      jalons: [{ type: 'bapteme', annee: 1660, libelle: 'Baptême', personId: 53 }],
    }
    const d = disposer(sansNaissance, 'lignes', ANNEE_COURANTE, true)!
    expect(d.barres[0].trace.long0).toBe(positionAnnee(d.axe, 1660))
    expect(d.barres[0].trace.long1).toBe(positionAnnee(d.axe, 1722))
  })
})

describe('placerJalons — un jalon appartient toujours à sa piste', () => {
  const vue = {
    ...EXEMPLE_BORNES_PARTIELLES,
    barres: [
      {
        personId: 53,
        displayName: 'Turquoise Marjolaine',
        branch: 'pm' as const,
        generation: -7,
        sex: 'feminin' as const,
        vivant: false,
        debut: { min: 1891, max: 1891, certain: true },
        fin: null,
        ouverte: false as const,
        ligneDates: 'n. 29.7.1891',
      },
    ],
    jalons: [{ type: 'recensement', annee: 1957, libelle: 'Recensement', personId: 53 }],
  }

  it('sans trait quand le jalon tombe sur sa barre — le cas courant', () => {
    // La barre est prolongée par ses propres faits : le jalon tombe dessus, il
    // n'y a rien à rattacher. Sur la charge réelle, les 29 jalons sont dans ce
    // cas.
    const d = disposer(vue, 'lignes', ANNEE_COURANTE, true)!
    const places = placerJalons(vue, d, 'lignes')
    expect(places).toHaveLength(1)
    expect(places[0].attache).toBeNull()
  })

  it('avec un trait de rattachement quand la donnée se contredit', () => {
    // Un fait **antérieur à une naissance connue** : on ne prolonge pas la barre
    // en arrière — on ne dessine pas quelqu'un vivant avant de naître —, donc le
    // jalon tombe dehors. Sans trait, il serait attribuable à la barre voisine
    // de la même piste : une information fausse donnée pour un fait.
    const contradictoire = {
      ...vue,
      jalons: [{ type: 'autre', annee: 1800, libelle: 'Douteux', personId: 53 }],
    }
    const d = disposer(contradictoire, 'lignes', ANNEE_COURANTE, true)!
    const [place] = placerJalons(contradictoire, d, 'lignes')

    expect(place.attache).not.toBeNull()
    // Le trait relie la marque au bord de **sa** barre, sur sa piste.
    expect(place.attache!.x1).toBe(place.point.x)
    expect(place.attache!.x2).toBe(place.point.x)
    expect(place.attache!.y1).toBe(place.point.y)
    expect(place.attache!.y2).toBe(d.barres[0].trace.long0)
    // Et le jalon n'est pas écarté : la date est vraie, c'est la barre qui est
    // courte.
    expect(place.jalon.annee).toBe(1800)
  })

  it('le trait suit l’orientation, comme le reste de la géométrie', () => {
    const contradictoire = {
      ...vue,
      jalons: [{ type: 'autre', annee: 1800, libelle: 'Douteux', personId: 53 }],
    }
    const d = disposer(contradictoire, 'colonnes', ANNEE_COURANTE, true)!
    const [place] = placerJalons(contradictoire, d, 'colonnes')
    expect(place.attache!.y1).toBe(place.point.y)
    expect(place.attache!.y2).toBe(place.point.y)
    expect(place.attache!.x2).toBe(d.barres[0].trace.long0)
  })

  it('ignore un jalon dont la personne n’a pas de barre', () => {
    const orphelin = {
      ...vue,
      jalons: [{ type: 'autre', annee: 1900, libelle: 'Ailleurs', personId: 9999 }],
    }
    const d = disposer(orphelin, 'lignes', ANNEE_COURANTE, true)!
    expect(placerJalons(orphelin, d, 'lignes')).toHaveLength(0)
  })
})

describe('pointDeJalon', () => {
  it('pose le jalon au milieu de son année, sur l’épaisseur de la barre', () => {
    const point = pointDeJalon(AXE, 'colonnes', 1957, 18)
    expect(point.x).toBe(positionAnnee(AXE, 1957) + UNITE_ANNEE / 2)
    expect(point.y).toBe(18 + EPAISSEUR_BARRE / 2)
  })

  it('… dans les deux orientations', () => {
    const point = pointDeJalon(AXE, 'lignes', 1957, 18)
    expect(point.y).toBe(positionAnnee(AXE, 1957) + UNITE_ANNEE / 2)
    expect(point.x).toBe(18 + EPAISSEUR_BARRE / 2)
  })
})
