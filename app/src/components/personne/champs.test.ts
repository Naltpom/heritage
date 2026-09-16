import { describe, expect, it } from 'vitest'
import type { BlocDate, FichePersonne, FichePersonneComplete, FichePersonneRestreinte } from '@/lib/api'
import {
  calqueDuChamp,
  construireLignes,
  memeValeur,
  regimeDocumentaire,
  rendreDate,
  type LigneDeFiche,
} from './champs'

/**
 * Ce que ces tests protègent — trois règles, et chacune s'efface d'une façon
 * qui ne se voit pas à la relecture.
 *
 *  1. **« Absent » n'est pas « `null` ».** Un `??` mal placé transforme
 *     *« vous n'avez pas le droit de savoir »* en *« on ne sait pas »*, et la
 *     règle de confidentialité du § 1.3 disparaît en affichant un tiret. Les
 *     tests partent donc des **charges utiles du contrat**, à la clé près : la
 *     fiche réduite du § 11.1 en a **sept**, et l'écran doit s'en contenter.
 *  2. **Le calque est à côté de la valeur validée, jamais à la place** (§ 13.2).
 *     Et `actuel` peut différer de `fiche[champ]` : c'est ce qui annonce une
 *     caducité. Les fusionner ferait disparaître le signal.
 *  3. **Une fourchette de dates ne s'aplatit pas** (§ 14). Cas de contrôle du
 *     projet : Marne Dolomite Sureau, `BET 1901 AND 1909`. Sa fourchette a
 *     survécu au GEDCOM, à l'import, à PostgreSQL et au moteur de placement.
 *
 * Aucun composant n'est monté : ces trois règles se décident sans DOM, et un
 * test qui les vérifie sur un rendu vérifie surtout le rendu.
 */

/* ------------------------------------------------------------------ */
/* Les charges utiles du contrat, recopiées                            */
/* ------------------------------------------------------------------ */

/** § 5.3 — `BET 1901 AND 1909`, la fourchette du cas de contrôle. */
const NAISSANCE_MARIE: BlocDate = {
  raw: 'BET 1901 AND 1909',
  qualifier: 'entre',
  min: '1901-01-01',
  max: '1909-12-31',
  place: 'Saule, Ombrage, Sylvanie',
  texte: 'entre 1901 et 1909',
}

const DECES_MARIE: BlocDate = {
  raw: '1987',
  qualifier: 'annee',
  min: '1987-01-01',
  max: '1987-12-31',
  place: 'Charmille, Ombrage, Sylvanie',
  texte: '1987',
}

/** § 5.3 — la fiche complète de Marne Dolomite Sureau, `tree_person.id = 17`. */
function ficheComplete(surcharge: Partial<FichePersonneComplete> = {}): FichePersonneComplete {
  return {
    visibilite: 'complete',
    id: 17,
    gedcomXref: '@I500017@',
    displayName: 'Marne Dolomite Sureau',
    givenNames: 'Marne Dolomite',
    surname: 'Sureau',
    marriedName: 'Sarriette',
    nickname: null,
    sex: 'feminin',
    deceased: true,
    vivant: false,
    birth: NAISSANCE_MARIE,
    death: DECES_MARIE,
    ligneDates: '1901-1909 – 1987',
    branch: 'pm',
    generation: -3,
    bestQuay: 2,
    citationCount: 6,
    forcePrivacy: false,
    privacyNote: null,
    version: 8,
    parente: 'arrière-grand-mère',
    portrait: null,
    parents: [],
    fratrie: [],
    unions: [],
    faits: [],
    citations: [],
    notes: [],
    medias: [],
    enAttente: {},
    demandesEnAttente: [],
    ...surcharge,
  }
}

/**
 * § 11.1 — **la même route, le même instant, un autre lecteur.** Pyroxène
 * Tilleul, vivant, hors du cercle du lecteur B. `200`, **sept clés**, « pas une
 * clé de plus, et surtout aucune clé à `null` ».
 */
const FICHE_RESTREINTE: FichePersonneRestreinte = {
  id: 202,
  displayName: 'Pyroxène Tilleul',
  sex: 'masculin',
  generation: -2,
  branch: 'mm',
  vivant: true,
  visibilite: 'restreinte',
}

function ligne(lignes: LigneDeFiche[], cle: string): LigneDeFiche | undefined {
  return lignes.find((candidate) => candidate.cle === cle)
}

/* ------------------------------------------------------------------ */
/* § 1.3 — absent contre null                                          */
/* ------------------------------------------------------------------ */

describe('§ 1.3 — un champ absent n’a pas de ligne, un champ à null en a une', () => {
  it('la fiche réduite ne rend que les trois champs que ses sept clés portent', () => {
    const lignes = construireLignes(FICHE_RESTREINTE)

    // `sex`, `branch`, `generation` sont dans la liste blanche du § 11.4 ; les
    // autres descripteurs de la fiche n'ont aucune clé correspondante.
    expect(lignes.map((l) => l.cle)).toEqual(['sex', 'branch', 'generation'])
  })

  it('n’invente aucune ligne vide pour un champ interdit', () => {
    const lignes = construireLignes(FICHE_RESTREINTE)

    // C'est **la** régression à empêcher : une ligne « Naissance — » sur une
    // personne qu'on n'a pas le droit de lire dirait « on ne sait pas » là où la
    // vérité est « tu n'as pas le droit de savoir ».
    for (const interdit of ['birth', 'death', 'surname', 'givenNames', 'parente', 'gedcomXref']) {
      expect(ligne(lignes, interdit)).toBeUndefined()
    }
  })

  it('un champ présent à null garde sa ligne, avec une valeur vide et un mot français', () => {
    const lignes = construireLignes(ficheComplete({ nickname: null, marriedName: null }))

    const surnom = ligne(lignes, 'nickname')
    expect(surnom).toBeDefined()
    expect(surnom?.etat).toBe('validee')
    expect(surnom?.etat === 'validee' && surnom.valeur.vide).toBe(true)
    // Ni « — », ni « null », ni une case vide : un mot qui dit *inconnu*.
    expect(surnom?.etat === 'validee' && surnom.valeur.principal).toBe('aucun')
  })

  it('une date à null n’efface pas la ligne — la lacune se voit', () => {
    const lignes = construireLignes(ficheComplete({ death: null }))

    const deces = ligne(lignes, 'death')
    expect(deces?.etat === 'validee' && deces.valeur.vide).toBe(true)
    expect(deces?.etat === 'validee' && deces.valeur.principal).toBe('inconnu')
  })

  it('une clé que la charge utile n’envoie pas saute, même si le type la déclare', () => {
    // Le typeur décrit le **contrat** ; la charge utile décrit ce qui est
    // *arrivé*. Les deux coïncident presque toujours — et c'est précisément
    // pourquoi il faut interroger la seconde : le jour où elles divergent, c'est
    // une règle de confidentialité qui est en jeu.
    const amputee = ficheComplete()
    delete (amputee as unknown as Record<string, unknown>)['birth']

    const lignes = construireLignes(amputee)
    expect(ligne(lignes, 'birth')).toBeUndefined()
    expect(ligne(lignes, 'death')).toBeDefined()
  })

  it('le calque d’une fiche réduite se lit sans exploser — la clé n’existe pas', () => {
    // § 13.3 : « une personne vivante hors cercle n'a pas de clé `enAttente` du
    // tout : la clé fait partie de ce qui n'est pas envoyé ».
    expect(calqueDuChamp(FICHE_RESTREINTE, 'surname')).toBeUndefined()

    const lignes = construireLignes(FICHE_RESTREINTE)
    // **La taille d'abord.** `every()` sur un tableau vide rend `true` : sans
    // cette ligne, le jour où `construireLignes()` ne rendrait plus rien du
    // tout, l'assertion suivante resterait verte en ne vérifiant plus rien.
    expect(lignes).toHaveLength(3)
    expect(lignes.every((l) => l.etat === 'validee')).toBe(true)
  })
})

/* ------------------------------------------------------------------ */
/* § 14 — les fourchettes de dates                                     */
/* ------------------------------------------------------------------ */

describe('§ 14 — « entre 1901 et 1909 » s’affiche tel quel, jamais « 1901 »', () => {
  it('rend la fourchette de Marne Dolomite Sureau sans l’aplatir', () => {
    const lignes = construireLignes(ficheComplete())
    const naissance = ligne(lignes, 'birth')

    expect(naissance?.etat === 'validee' && naissance.valeur.principal).toBe('entre 1901 et 1909')
    expect(naissance?.etat === 'validee' && naissance.valeur.secondaire).toBe(
      'Saule, Ombrage, Sylvanie',
    )
  })

  it('ne compose jamais une date à partir de min et max', () => {
    // `texte` vide et `raw` absent : il ne reste que les bornes. Les lire
    // produirait « 1901 » — une certitude que la source ne porte pas. On rend
    // le mot d'absence, et le lieu s'il est connu.
    const sansLibelle: BlocDate = {
      raw: null,
      qualifier: 'entre',
      min: '1901-01-01',
      max: '1909-12-31',
      place: 'Saule, Ombrage, Sylvanie',
      texte: '',
    }

    const rendue = rendreDate(sansLibelle, 'inconnue')
    expect(rendue.principal).toBe('inconnue')
    expect(rendue.principal).not.toContain('1901')
    expect(rendue.vide).toBe(true)
    expect(rendue.secondaire).toBe('Saule, Ombrage, Sylvanie')
  })

  it('retombe sur la chaîne GEDCOM verbatim plutôt que sur les bornes', () => {
    const brut: BlocDate = {
      raw: 'BET 1901 AND 1909',
      qualifier: 'entre',
      min: '1901-01-01',
      max: '1909-12-31',
      place: null,
      texte: '',
    }

    expect(rendreDate(brut, 'inconnue').principal).toBe('BET 1901 AND 1909')
  })

  it('« 1930 » n’est pas une fourchette même si min et max diffèrent', () => {
    // § 14.3, piège n° 1 : `annee` donne déjà `1930-01-01` / `1930-12-31`. Un
    // client qui déduirait « min ≠ max donc fourchette » classerait les trois
    // quarts de l'arbre en incertain. On affiche ce que le serveur a écrit.
    const annee: BlocDate = {
      raw: '1930',
      qualifier: 'annee',
      min: '1930-01-01',
      max: '1930-12-31',
      place: null,
      texte: '1930',
    }

    expect(rendreDate(annee, 'inconnue').principal).toBe('1930')
  })
})

/* ------------------------------------------------------------------ */
/* § 13.2 — le calque « en attente »                                   */
/* ------------------------------------------------------------------ */

describe('§ 13.2 — le calque est à côté de la valeur validée, jamais à la place', () => {
  it('sans calque, la fiche rend ses valeurs validées', () => {
    const lignes = construireLignes(ficheComplete({ enAttente: {} }))
    // Même précaution : la taille avant le prédicat, sinon l'assertion devient
    // vraie par vacuité le jour où la liste se vide.
    expect(lignes).toHaveLength(11)
    expect(lignes.every((l) => l.etat === 'validee')).toBe(true)
  })

  it('avec un calque, propose et valeur validée sont toutes deux disponibles', () => {
    const lignes = construireLignes(
      ficheComplete({
        surname: 'Bourrache',
        enAttente: {
          surname: {
            propose: 'BOURRACHE',
            actuel: 'Bourrache',
            demandeId: 14,
            auteur: 'Opale Romarin',
            depuis: '2026-09-14T11:02:00+02:00',
            autresDemandes: [],
          },
        },
      }),
    )

    const nom = ligne(lignes, 'surname')
    expect(nom?.etat).toBe('en_attente')
    if (nom?.etat !== 'en_attente') throw new Error('la ligne devait porter le calque')

    expect(nom.propose.principal).toBe('BOURRACHE')
    // La valeur validée **reste** : le calque s'ajoute, il ne se substitue pas.
    expect(nom.validee.principal).toBe('Bourrache')
    expect(nom.actuel.principal).toBe('Bourrache')
    expect(nom.entree.demandeId).toBe(14)
    expect(nom.entree.auteur).toBe('Opale Romarin')
  })

  it('ne signale aucune caducité quand actuel et la valeur validée coïncident', () => {
    const lignes = construireLignes(
      ficheComplete({
        surname: 'Bourrache',
        enAttente: {
          surname: {
            propose: 'BOURRACHE',
            actuel: 'Bourrache',
            demandeId: 14,
            auteur: 'Opale Romarin',
            depuis: '2026-09-14T11:02:00+02:00',
            autresDemandes: [],
          },
        },
      }),
    )

    const nom = ligne(lignes, 'surname')
    expect(nom?.etat === 'en_attente' && nom.caduciteAnnoncee).toBe(false)
  })

  it('signale la caducité à venir quand la fiche a bougé depuis la soumission', () => {
    // § 13.2, conséquence n° 2 : « `actuel` vaut `old_value` de l'op, la valeur
    // **au moment de la soumission**. Si la fiche a changé depuis, `actuel` et
    // `fiche[champ]` diffèrent — et cette différence est précisément ce qui
    // annonce une caducité à venir. Le front ne doit pas supposer qu'ils sont
    // égaux. »
    const lignes = construireLignes(
      ficheComplete({
        surname: 'Bourrache-Sureau',
        enAttente: {
          surname: {
            propose: 'BOURRACHE',
            actuel: 'Bourrache',
            demandeId: 14,
            auteur: 'Opale Romarin',
            depuis: '2026-09-14T11:02:00+02:00',
            autresDemandes: [17],
          },
        },
      }),
    )

    const nom = ligne(lignes, 'surname')
    if (nom?.etat !== 'en_attente') throw new Error('la ligne devait porter le calque')

    expect(nom.caduciteAnnoncee).toBe(true)
    expect(nom.validee.principal).toBe('Bourrache-Sureau')
    expect(nom.actuel.principal).toBe('Bourrache')
    expect(nom.entree.autresDemandes).toEqual([17])
  })

  it('porte un bloc date entier dans le calque, sans aplatir la fourchette', () => {
    // § 13.2 : « pour un bloc date, `propose` et `actuel` portent le bloc
    // **entier**, avec sa clé `texte` rendue ».
    const lignes = construireLignes(
      ficheComplete({
        birth: DECES_MARIE,
        enAttente: {
          birth: {
            propose: NAISSANCE_MARIE,
            actuel: DECES_MARIE,
            demandeId: 13,
            auteur: 'Tuf Romarin',
            depuis: '2026-09-14T15:40:00+02:00',
            autresDemandes: [17],
          },
        },
      }),
    )

    const naissance = ligne(lignes, 'birth')
    if (naissance?.etat !== 'en_attente') throw new Error('la ligne devait porter le calque')

    expect(naissance.propose.principal).toBe('entre 1901 et 1909')
    expect(naissance.validee.principal).toBe('1987')
    expect(naissance.caduciteAnnoncee).toBe(false)
  })
})

/* ------------------------------------------------------------------ */
/* Comparaison de valeurs                                              */
/* ------------------------------------------------------------------ */

describe('memeValeur — sur les cinq clés d’op, jamais sur `texte`', () => {
  it('ignore `texte`, qui est dérivé du reste', () => {
    const a: BlocDate = { ...NAISSANCE_MARIE }
    const b: BlocDate = { ...NAISSANCE_MARIE, texte: 'entre 1901 et 1909 (environ)' }
    expect(memeValeur(a, b)).toBe(true)
  })

  it('distingue deux blocs qui ne diffèrent que par le lieu', () => {
    // Deux dates qui rendent le même libellé et ne désignent pas le même
    // endroit : `texte` ne porte pas le lieu, la comparaison si.
    const ailleurs: BlocDate = { ...NAISSANCE_MARIE, place: 'Charmille, Ombrage, Sylvanie' }
    expect(memeValeur(NAISSANCE_MARIE, ailleurs)).toBe(false)
  })

  it('traite null et une chaîne vide comme différents', () => {
    expect(memeValeur(null, '')).toBe(false)
    expect(memeValeur(null, null)).toBe(true)
  })
})

/* ------------------------------------------------------------------ */
/* Le bandeau documentaire                                             */
/* ------------------------------------------------------------------ */

describe('regimeDocumentaire — « vérifié » veut dire appuyé sur un acte', () => {
  it('ne juge rien sur une fiche réduite : les clés n’y sont pas', () => {
    expect(regimeDocumentaire(FICHE_RESTREINTE)).toBeNull()
  })

  it('distingue « aucune source » de « sourcé mais non vérifié »', () => {
    expect(regimeDocumentaire(ficheComplete({ citationCount: 0, bestQuay: null }))?.ton).toBe(
      'mauvais',
    )
    // `QUAY 1` = index ou déduction : une source, mais pas un acte. 242 des
    // citations de l'arbre sont à 0 et 64 à 1 — la nuance porte sur la moitié
    // du corpus.
    const recopie = regimeDocumentaire(ficheComplete({ citationCount: 3, bestQuay: 1 }))
    expect(recopie?.ton).toBe('mauvais')
    expect(recopie?.texte).toContain('Non vérifié')

    expect(regimeDocumentaire(ficheComplete({ citationCount: 6, bestQuay: 2 }))?.ton).toBe('bon')
  })
})

/* ------------------------------------------------------------------ */
/* Non-régression de typage                                            */
/* ------------------------------------------------------------------ */

describe('le typage garde la règle autant que les tests', () => {
  it('oblige à discriminer sur `visibilite` avant de lire une date', () => {
    // Le passage par une fonction n'est pas un détour : c'est ce qu'est un appel
    // d'API. Affecter directement la constante laisserait le typeur restreindre
    // l'union depuis l'initialiseur, et le test ne vérifierait plus rien de ce
    // qu'un écran rencontre — un écran, lui, reçoit l'union entière.
    const recevoir = (): FichePersonne => FICHE_RESTREINTE
    const fiche = recevoir()

    // @ts-expect-error — `birth` n'existe pas sur une fiche réduite. Si cette
    // ligne se met à compiler, le type s'est relâché et la règle n° 1 finira
    // par être violée par accident, sans qu'aucun test ne la voie tomber.
    expect(fiche.birth).toBeUndefined()

    // Après discrimination, en revanche, la lecture est légitime.
    expect(fiche.visibilite === 'complete' ? fiche.birth : null).toBeNull()
  })
})
