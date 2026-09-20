/**
 * **Ce que l'écran garde d'une charge à la suivante.**
 *
 * ────────────────────────────────────────────────────────────────────
 * La règle éprouvée ici tient en une phrase : **un changement de DONNÉES passe
 * par l'insertion locale, un changement de MISE EN PAGE non.** Elle décide de ce
 * que le lecteur voit bouger, et elle se casserait sans bruit : un arbre qui
 * refuse d'afficher la maquette demandée n'affiche pas un message d'erreur, il
 * affiche l'arbre d'avant — et l'on croit avoir cliqué à côté.
 *
 * Même forme que `arbre-revalidation.test.ts`, et pour la même raison : le
 * projet n'a ni `jsdom` ni `@vue/test-utils`, la décision est donc **exportée**
 * par `ArbreView.vue` dans un `<script>` ordinaire et éprouvée telle quelle.
 * Ce fichier ne prouve rien de la peinture ; il prouve la décision.
 *
 * Les noms sont inventés — aucune personne réelle n'entre dans le dépôt (ADR 0006).
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { ETAT_INITIAL, etatApresUneCharge } from './ArbreView.vue'

function personne(id: string, x: number, y: number, w = 100, branch: string | null = null): NoeudGraphe {
  return {
    type: 'personne',
    id,
    personId: null,
    displayName: id,
    etiquetteNom: id,
    sexe: 'inconnu',
    vivant: false,
    branch,
    x,
    y,
    w,
    h: 40,
  } as unknown as NoeudGraphe
}

const famille = (id: string, x: number, y: number): NoeudGraphe =>
  ({ type: 'famille', id, familyId: null, divorced: false, x, y, w: 22, h: 14 }) as NoeudGraphe

const fi = (de: string, vers: string): AreteGraphe =>
  ({ de, vers, genre: 'filiation' }) as AreteGraphe

function graphe(noeuds: NoeudGraphe[], aretes: AreteGraphe[] = []): Graphe {
  return {
    empreinte: 'x'.repeat(64),
    ego: 'p1',
    boite: { x: -1000, y: -1000, w: 4000, h: 2000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

/** Un arbre au large : le lecteur, un losange, et des fiches loin à droite. */
const depart = (): Graphe =>
  graphe(
    [
      personne('p1', 0, 0, 100, 'tr'),
      famille('f1', 1200, -40),
      personne('parent', 1200, -200),
      personne('loin1', 2400, 0),
      personne('loin2', 3000, 0),
    ],
    [],
  )

/** La même charge, replacée ailleurs par la disposition ET portant une fiche de plus. */
const apresUnAjout = (): Graphe =>
  graphe(
    [
      personne('p1', 0, 0, 100, 'tr'),
      famille('f1', 1200, -40),
      personne('parent', 1200, -200),
      // La disposition a tout recalculé : les deux lointaines ont bougé…
      personne('loin1', 1900, 0),
      personne('loin2', 2600, 0),
      // …et une fiche est arrivée, à une place que la disposition a choisie.
      personne('neuve', 1200, 0),
    ],
    [fi('f1', 'neuve')],
  )

const CLE = 'ressorts'
const abscisse = (g: Graphe | null, id: string): number => g!.noeuds.find((n) => n.id === id)!.x

describe('l’état de l’arbre d’une charge à la suivante', () => {
  it('affiche la mise en page de la disposition au PREMIER passage', () => {
    const etat = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)

    expect(etat.graphe!.noeuds.map((n) => n.x)).toEqual(depart().noeuds.map((n) => n.x))
    expect(etat.annonce).toBeNull()
    expect(etat.animer).toBe(true)
  })

  it('GARDE LES PLACES quand seules les DONNÉES changent', () => {
    const premier = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)
    const second = etatApresUneCharge(premier, apresUnAjout(), CLE)

    // Le témoin : la disposition, elle, les avait bel et bien déplacées.
    expect(abscisse(apresUnAjout(), 'loin1')).not.toBe(abscisse(depart(), 'loin1'))

    expect(abscisse(second.graphe, 'loin1')).toBe(abscisse(depart(), 'loin1'))
    expect(abscisse(second.graphe, 'loin2')).toBe(abscisse(depart(), 'loin2'))
    expect(second.graphe!.noeuds.some((n) => n.id === 'neuve')).toBe(true)
  })

  it('annonce l’arrivée, et dit que rien d’autre n’a bougé', () => {
    const premier = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)
    const second = etatApresUneCharge(premier, apresUnAjout(), CLE)

    expect(second.annonce).not.toBeNull()
    expect(second.annonce!.ton).toBe('arrivee')
    expect(second.annonce!.texte).toContain('1 nouvelle fiche')
  })

  it('NE REJOUE PAS LE DÉPLIAGE sur une insertion locale', () => {
    const premier = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)
    const second = etatApresUneCharge(premier, apresUnAjout(), CLE)

    expect(second.animer).toBe(false)
  })

  it('OUBLIE LES PLACES quand la MISE EN PAGE change — sinon la maquette demandée ne vient jamais', () => {
    const premier = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)
    const autre = etatApresUneCharge(premier, apresUnAjout(), 'blocs')

    expect(abscisse(autre.graphe, 'loin1')).toBe(abscisse(apresUnAjout(), 'loin1'))
    expect(autre.annonce).toBeNull()
    expect(autre.animer).toBe(true)
  })

  it('se tait quand la charge n’apporte rien de neuf', () => {
    const premier = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)
    const second = etatApresUneCharge(premier, depart(), CLE)

    expect(second.annonce).toBeNull()
  })

  it('traverse une charge absente sans rien garder', () => {
    const premier = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)
    const vide = etatApresUneCharge(premier, null, CLE)

    expect(vide.graphe).toBeNull()
    expect(vide.cle).toBeNull()
    expect(vide.annonce).toBeNull()
  })

  it('ne mute pas l’état qu’on lui donne', () => {
    const premier = etatApresUneCharge(ETAT_INITIAL, depart(), CLE)
    const copie = JSON.stringify(premier.graphe)

    etatApresUneCharge(premier, apresUnAjout(), CLE)

    expect(JSON.stringify(premier.graphe)).toBe(copie)
    expect(ETAT_INITIAL.graphe).toBeNull()
  })
})
