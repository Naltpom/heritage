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
  subjectLabel: 'Tourmaline Muguet (proposée)',
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

    // Une fourchette atteste le mariage (ADR 0020) ; sans `texte`, sa date ne se
    // dit pas — surtout pas « 1927 », qui l'aplatirait.
    expect(lien.union).toBe('mariés, date inconnue')
    expect(lien.union).not.toContain('1927')
  })
})

/**
 * La nature de l'union (ADR 0020) : « mariés » même sans date, « union libre », et
 * la rupture qui se dit « divorcés » après un mariage, « séparés » sinon.
 */
describe('la nature de l’union', () => {
  it('dit un mariage sans date, et une union libre', () => {
    expect(lienLisible({ ...BASE, married: true }).union).toBe('mariés, date inconnue')
    expect(lienLisible({ ...BASE, married: false }).union).toBe('union libre')
    expect(lienLisible({ ...BASE, married: null }).union).toBeNull()
  })

  it('lit le mariage dans sa date, même sous un lien d’avant la nature', () => {
    const lien = lienLisible({ ...BASE, married: null, marriage: { qualifier: 'annee', texte: '1934', raw: '1934' } as never })

    expect(lien.union).toBe('mariés en 1934')
  })

  it('dit « divorcés » après un mariage, « séparés » sinon', () => {
    expect(lienLisible({ ...BASE, married: true, divorced: true }).rupture).toBe('divorcés, date inconnue')
    expect(lienLisible({ ...BASE, married: false, divorced: true }).rupture).toBe('séparés, date inconnue')
    expect(lienLisible({ ...BASE, married: true, divorced: true, divorce: { qualifier: 'annee', texte: '1942' } as never }).rupture).toBe(
      'divorcés en 1942',
    )
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

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  LE LIEN DIT DE QUI IL PARLE.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Mesuré sur un cas réel : une branche de vingt-deux personnes déposée en cinq
 * demandes, validée sans que sa parenté ait pu se lire. Une demande qui ne
 * propose qu'une personne n'a pas besoin de nommer le sujet de ses liens — c'est
 * elle, forcément. Une branche en a une trentaine, et « Enfant de X » répété
 * trente fois ne dit jamais qui est l'enfant.
 */
describe('le sujet du lien', () => {
  it('est nommé, pour qu’une liste de liens se lise sans compter les lignes', () => {
    expect(lienLisible(BASE).sujet).toBe('Tourmaline Muguet (proposée)')
  })

  it('ne rend jamais la référence provisoire du brouillon', () => {
    // C'est le défaut exact : `subjectLabel` valait « Personne proposée #p1 »,
    // et il fallait retrouver `#p1` à la main dans la liste des brouillons.
    const lien = lienLisible(BASE)

    expect(lien.sujet).not.toContain('#p1')
    expect(lien.sujet).not.toMatch(/Personne proposée #/)
  })

  it('se dit en mots quand la charge ne le nomme pas', () => {
    const lien = lienLisible({ ...BASE, subjectLabel: null })

    expect(lien.sujet).toMatch(/ne nomme pas/i)
  })
})

describe('le couple existant désigné par une filiation', () => {
  /**
   * Une filiation désigne **soit** un couple existant, **soit** un ou deux
   * parents — jamais les deux (`ck_prp_link_cible`). Le premier cas n'a pas
   * d'`otherLabel`, et l'écran affichait donc « une personne que cette charge ne
   * nomme pas » alors que la base porte le nom des deux époux.
   */
  it('est nommé par son libellé, pas par son numéro', () => {
    const lien = lienLisible({
      seq: 4,
      kind: 'enfant_de',
      subjectDraftRef: '#p3',
      subjectLabel: 'Cèpe Chanterelle (proposée)',
      familyId: 28,
      familyLabel: 'Mica Chanterelle × Ocre Chanterelle',
    })

    expect(lien.autre).toBe('Mica Chanterelle × Ocre Chanterelle')
    expect(lien.autre).not.toContain('28')
  })

  it('reste muet plutôt que d’afficher l’identifiant quand le libellé manque', () => {
    const lien = lienLisible({
      seq: 4,
      kind: 'enfant_de',
      subjectDraftRef: '#p3',
      subjectLabel: 'Cèpe Chanterelle (proposée)',
      familyId: 28,
    })

    expect(lien.autre).not.toContain('28')
    expect(lien.autre).toMatch(/ne nomme pas/i)
  })
})

describe('le second parent d’une filiation', () => {
  it('est rendu quand le demandeur en a désigné deux', () => {
    const lien = lienLisible({
      ...BASE,
      kind: 'enfant_de',
      other2DraftRef: '#p1',
      other2Label: 'Girolle Chanterelle (proposée)',
    })

    expect(lien.secondParent).toBe('Girolle Chanterelle (proposée)')
  })

  it('vaut null quand un seul parent est nommé — le cas courant, pas un manque', () => {
    expect(lienLisible({ ...BASE, kind: 'enfant_de' }).secondParent).toBeNull()
  })
})

describe('la note du lien', () => {
  /**
   * `prp_draft_link.note` est stockée depuis toujours et n'était **rendue nulle
   * part** : la justification écrite lien par lien — « déduit du registre
   * paroissial, pas d'acte retrouvé » — ne quittait pas la base, et
   * l'administrateur décidait sur le seul message global de la demande.
   */
  it('est rendue telle quelle', () => {
    const lien = lienLisible({ ...BASE, note: 'Déduit du registre, pas d’acte retrouvé.' })

    expect(lien.note).toBe('Déduit du registre, pas d’acte retrouvé.')
  })

  it('ne transforme pas une chaîne vide en note', () => {
    expect(lienLisible({ ...BASE, note: '' }).note).toBeNull()
  })
})

describe('ce que la validation fera d’une filiation', () => {
  /**
   * Le défaut du 01/10/2026 : « Véronique, enfant de Jean-Marie » a ouvert une
   * famille à un parent à côté du couple Jean-Marie × Hélène, sans que l'écran
   * de décision en dise rien. Le serveur annonce désormais la destination ;
   * l'écran doit la montrer quand elle ne se lit pas dans le lien.
   */
  const ENFANT: LienDemande = {
    ...BASE,
    kind: 'enfant_de',
    subjectLabel: 'Véronique Lebrun (proposée)',
    otherLabel: 'Jean-Marie Lebrun',
  }

  it('montre la phrase du serveur et les familles quittées quand il y a à signaler', () => {
    const effet = lienLisible({
      ...ENFANT,
      filiationPrevue: {
        motif: 'union_unique',
        familleId: 183,
        libelle: 'Jean-Marie Lebrun × Hélène Lebrun',
        phrase:
          'Sera rattaché·e au couple « Jean-Marie Lebrun × Hélène Lebrun », seule union connue de Jean-Marie Lebrun.',
        aSignaler: true,
        quittees: [
          { familleId: 184, libelle: 'Jean-Marie Lebrun', supprimee: true },
          { familleId: 189, libelle: 'Hélène Lebrun', supprimee: false },
        ],
        incompatibilite: null,
      },
    }).effet

    expect(effet?.phrase).toContain('seule union connue')
    expect(effet?.quittees).toEqual([
      'Quitte « Jean-Marie Lebrun », qui sera supprimée (vide).',
      'Quitte « Hélène Lebrun », conservée : elle porte encore des données.',
    ])
    expect(effet?.refus).toBeNull()
  })

  it('se tait quand la filiation va là où le lien se lit', () => {
    const lien = lienLisible({
      ...ENFANT,
      filiationPrevue: {
        motif: 'couple',
        familleId: 183,
        libelle: 'Jean-Marie Lebrun × Hélène Lebrun',
        phrase: 'Rattaché·e au couple « Jean-Marie Lebrun × Hélène Lebrun ».',
        aSignaler: false,
        quittees: [],
        incompatibilite: null,
      },
    })

    expect(lien.effet).toBeNull()
    expect(lienLisible(ENFANT).effet).toBeNull()
  })

  it('annonce le refus de la règle 4', () => {
    const effet = lienLisible({
      ...ENFANT,
      filiationPrevue: {
        motif: 'couple',
        familleId: null,
        libelle: 'Jean-Marie Lebrun × Hélène Lebrun',
        phrase: 'Rattaché·e au couple « Jean-Marie Lebrun × Hélène Lebrun ».',
        aSignaler: true,
        quittees: [],
        incompatibilite: 'Véronique Lebrun est déjà enfant de « Jean-Marie Lebrun × Odile Marchal ».',
      },
    }).effet

    expect(effet?.refus).toContain('Odile Marchal')
  })
})
