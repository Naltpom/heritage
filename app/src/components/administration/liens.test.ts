import { describe, expect, it } from 'vitest'
import type { LienDemande } from '@/lib/api'
import { lienLisible } from './liens'

/**
 * Ce que ces tests protègent : **une union sans date reste une union, et une
 * rupture sans date reste une rupture.**
 *
 * C'est la règle du § 6.4 du dossier — `marriage`, `divorce` et `divorced` sont
 * toutes facultatives. L'erreur naturelle est d'écrire « s'il y a une date,
 * alors il y a eu mariage », ce qui efface de l'écran toutes les unions dont on
 * ignore la date : sur un arbre ancien, c'est la majorité.
 */

const BASE: LienDemande = {
  seq: 1,
  kind: 'conjoint_de',
  subjectDraftRef: '#p1',
  otherPersonId: 55,
  otherLabel: 'Jargon Sarriette',
}

describe('la nature du lien', () => {
  /**
   * ⚠ Ce test affirmait `parent_de` → « Enfant de ». Il **verrouillait le
   * défaut** : la table était inversée, le test l'était aussi, et les deux se
   * confirmaient mutuellement. C'est pour cela qu'il n'a rien signalé pendant
   * que l'écran d'administration montrait l'arbre à l'envers.
   *
   * La règle, désormais écrite dans le test plutôt que sous-entendue : **une
   * nature se lit du sujet vers l'autre.**
   */
  it('se lit du sujet vers l’autre', () => {
    // Sujet enfant, autre parent.
    expect(lienLisible({ ...BASE, kind: 'enfant_de' }).nature).toBe('Enfant de')
    // Sujet parent, autre enfant. C'est celui-ci qui était inversé.
    expect(lienLisible({ ...BASE, kind: 'parent_de' }).nature).toBe('Parent de')
    // Symétrique : l'inversion ne s'y verrait pas, et c'est pourquoi les deux
    // filiations sont testées explicitement et non « une nature au hasard ».
    expect(lienLisible(BASE).nature).toBe('Conjoint·e de')
  })

  it('couvre les trois natures du modèle, et seulement elles', () => {
    // `LinkKind` en compte trois « ni plus ni moins » (§ 9.3 du modèle). La
    // table est un `Record<NatureLien, string>` : une clé manquante ou en trop
    // ne compile plus. Ce test affirme la taille de l'ensemble parcouru, sinon
    // il resterait vert en ne vérifiant plus rien.
    const naturesConnues = ['enfant_de', 'parent_de', 'conjoint_de'] as const
    expect(naturesConnues).toHaveLength(3)
    for (const kind of naturesConnues) {
      const libelle = lienLisible({ ...BASE, kind }).nature
      expect(libelle).not.toBe(kind)
      expect(libelle.length).toBeGreaterThan(0)
    }
  })

  it('retombe sur la valeur brute si le serveur en envoie une inconnue', () => {
    // Le cast est la démonstration : le type **interdit** désormais d'écrire une
    // quatrième nature — c'est ce qui a fait tomber `frere_soeur_de`, qui
    // n'existait nulle part ailleurs. Mais rien n'empêche un serveur plus récent
    // d'en envoyer une, et l'écran doit alors l'afficher brute plutôt que vide :
    // un libellé blanc sur l'écran de décision serait pire que l'énumération.
    const inconnue = { ...BASE, kind: 'temoin_de' as LienDemande['kind'] }
    expect(lienLisible(inconnue).nature).toBe('temoin_de')
  })
})

describe('les dates d’union et de rupture', () => {
  it('affiche la date de mariage que le serveur a formatée', () => {
    const lien = lienLisible({
      ...BASE,
      marriage: { texte: 'le 23 septembre 1927', raw: '23 SEP 1927' } as never,
    })

    expect(lien.union).toBe('mariés le 23 septembre 1927')
  })

  it('garde l’union quand la date manque', () => {
    // « Une union sans date reste une union » : le lien reste affiché, seule la
    // ligne de date disparaît.
    const lien = lienLisible(BASE)

    expect(lien.union).toBeNull()
    expect(lien.autre).toBe('Jargon Sarriette')
    expect(lien.nature).toBe('Conjoint·e de')
  })

  it('affiche la rupture même sans date', () => {
    const lien = lienLisible({ ...BASE, divorced: true })

    expect(lien.rupture).toMatch(/séparés/i)
    expect(lien.rupture).toMatch(/date inconnue/i)
  })

  it('affiche la rupture avec sa date quand elle existe', () => {
    const lien = lienLisible({
      ...BASE,
      divorced: true,
      divorce: { texte: 'en 1943' } as never,
    })

    expect(lien.rupture).toBe('séparés en 1943')
  })

  it('n’affirme pas une rupture que la charge ne déclare pas', () => {
    const lien = lienLisible({ ...BASE, divorce: { texte: 'en 1943' } as never })

    expect(lien.rupture).not.toMatch(/^séparés/)
    expect(lien.rupture).toContain('1943')
  })

  it('ne dit rien quand il n’y a ni rupture ni date', () => {
    expect(lienLisible(BASE).rupture).toBeNull()
  })

  it('ne recompose jamais une date depuis ses bornes', () => {
    const lien = lienLisible({
      ...BASE,
      marriage: { qualifier: 'entre', min: '1927-01-01', max: '1927-12-31' } as never,
    })

    expect(lien.union).toBeNull()
  })
})

describe('la personne à l’autre bout', () => {
  it('est nommée telle que le serveur la nomme', () => {
    expect(lienLisible(BASE).autre).toBe('Jargon Sarriette')
  })

  it('se dit en mots quand la charge ne la nomme pas', () => {
    // Surtout pas l'identifiant : un numéro ne veut rien dire pour qui décide,
    // et l'afficher mettrait un identifiant de personne sous ses yeux.
    const lien = lienLisible({ ...BASE, otherLabel: null })

    expect(lien.autre).not.toContain('55')
    expect(lien.autre).toMatch(/ne nomme pas/i)
  })
})
