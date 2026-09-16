import { describe, expect, it } from 'vitest'
import { ErreurApi } from '@/lib/api'
import { conflitDeDemande, selonConflit, valeurLisible, type ConflitDeDemande } from './conflit'

/**
 * Ce que ces tests protègent : **les deux `409` ne peuvent pas être traitées
 * pareil**, ni par accident à l'exécution, ni par négligence à l'écriture.
 *
 * `demande_caduque` et `verrou_concurrent` portent le même code HTTP et la même
 * enveloppe, et donnent des conseils **opposés**. Rejouer une caduque n'est pas
 * un affichage maladroit : c'est une double écriture. La distinction est donc
 * portée par le typage autant que par le code — voir le test « le typage refuse
 * la branche unique », qui échoue à la **compilation** si elle s'efface.
 */

function erreur(code: string, details: Record<string, unknown> | null): ErreurApi {
  return new ErreurApi(409, code, `message de ${code}`, null, details)
}

describe('verrou_concurrent — rien n’a été écrit', () => {
  it('propose de réessayer', () => {
    const conflit = conflitDeDemande(
      erreur('verrou_concurrent', { demandeId: 14, statut: 'en_attente', rejouable: true }),
    )

    expect(conflit?.sorte).toBe('rejouable')
    expect(conflit?.conduite).toBe('reessayer')
    expect(conflit?.libelleAction).toBe('Réessayer')
  })
})

describe('demande_caduque — le serveur a écrit', () => {
  const details = {
    demandeId: 14,
    statut: 'caduque',
    rejouable: false,
    champs: [
      {
        field: 'birth',
        propose: { raw: 'BET 1901 AND 1909', qualifier: 'entre', min: '1901-01-01', max: '1909-12-31', place: null },
        valeurALaSoumission: { raw: '1987', qualifier: 'annee', min: '1987-01-01', max: '1987-12-31', place: null },
        valeurCourante: { raw: 'ABT 1904', qualifier: 'environ', min: '1904-01-01', max: '1904-12-31', place: null },
        modifieePar: { demandeId: 16, auteur: 'Natron Romarin', le: '2026-09-15T08:12:00+02:00' },
      },
    ],
  }

  it('ne propose jamais de réessayer', () => {
    // « Le front ne doit pas proposer "réessayer" : il doit recharger la
    // demande, qui n'est plus dans la file. » Rejouer écrirait deux fois.
    const conflit = conflitDeDemande(erreur('demande_caduque', details))

    expect(conflit?.sorte).toBe('caduque')
    expect(conflit?.conduite).toBe('refaire')
    expect(conflit?.libelleAction).not.toBe('Réessayer')
    expect(conflit?.libelleAction).toBe('Refaire cette demande')
  })

  it('rapporte les trois valeurs de chaque champ qui a bougé', () => {
    // Sans la valeur intermédiaire, l'auteur ne voit pas *ce qui a bougé* : il
    // voit deux valeurs qui diffèrent, ce qui ressemble à un refus.
    const conflit = conflitDeDemande(erreur('demande_caduque', details))
    if (conflit?.sorte !== 'caduque') throw new Error('branche inattendue')

    expect(conflit.champs).toHaveLength(1)
    expect(conflit.champs[0]!.etiquette).toBe('Naissance')
    expect(conflit.champs[0]!.modifieePar?.auteur).toBe('Natron Romarin')
    expect(conflit.demandeId).toBe(14)
  })

  it('traite cible_disparue comme une caducité, pas comme un refus', () => {
    const conflit = conflitDeDemande(
      erreur('cible_disparue', {
        demandeId: 14,
        statut: 'caduque',
        rejouable: false,
        cible: { kind: 'personne', id: 55, label: 'Jargon Sarriette' },
      }),
    )
    if (conflit?.sorte !== 'caduque') throw new Error('branche inattendue')

    expect(conflit.cible).toEqual({ kind: 'personne', id: 55, label: 'Jargon Sarriette' })
    expect(conflit.champs).toEqual([])
  })
})

describe('le booléen l’emporte sur la table des codes', () => {
  it('lit details.rejouable en premier', () => {
    // Le contrat pose le booléen « pour que le front n'ait pas à connaître la
    // liste des codes » (§ 15.4). La table n'est qu'un repli.
    const conflit = conflitDeDemande(erreur('verrou_concurrent', { demandeId: 1, statut: 'caduque', rejouable: false }))

    expect(conflit?.sorte).toBe('caduque')
  })

  it('retombe sur le code quand le booléen manque', () => {
    expect(conflitDeDemande(erreur('verrou_concurrent', null))?.sorte).toBe('rejouable')
    expect(conflitDeDemande(erreur('demande_caduque', null))?.sorte).toBe('caduque')
  })
})

describe('ce que ce module refuse d’interpréter', () => {
  it('ignore les 409 d’une autre nature', () => {
    // `demande_deja_decidee` et `vue_comme_membre_active` appellent « recharger »,
    // et c'est le socle qui le dit. Inventer une conduite ici les rangerait, par
    // défaut fermé, du côté de la caducité — ce qu'elles ne sont pas.
    expect(conflitDeDemande(erreur('demande_deja_decidee', { statut: 'validee' }))).toBeNull()
    expect(conflitDeDemande(erreur('vue_comme_membre_active', { memberId: 4 }))).toBeNull()
  })

  it('ignore ce qui n’est pas une erreur d’API', () => {
    expect(conflitDeDemande(new Error('réseau'))).toBeNull()
    expect(conflitDeDemande(null)).toBeNull()
    expect(conflitDeDemande(new ErreurApi(422, 'donnees_invalides', 'x'))).toBeNull()
  })
})

describe('le typage refuse la branche unique', () => {
  it('interdit de lire les champs d’une caducité sans avoir discriminé', () => {
    const conflit: ConflitDeDemande = {
      sorte: 'rejouable',
      code: 'verrou_concurrent',
      message: 'x',
      conduite: 'reessayer',
      libelleAction: 'Réessayer',
    }

    // C'est **la** garde de ce module, et elle agit à la compilation. Si les
    // deux branches redevenaient interchangeables — un `champs?:` ajouté « pour
    // simplifier », un type élargi en `Record<string, unknown>` —, cette ligne
    // cesserait d'être une erreur et `vue-tsc` échouerait sur le
    // `@ts-expect-error` inutilisé. Le build casse **avant** que quiconque
    // rejoue une demande morte.
    // @ts-expect-error — `champs` n'existe que sur la branche caduque.
    expect(conflit.champs).toBeUndefined()

    // Même chose pour la cible, et pour l'identifiant de la demande morte.
    // @ts-expect-error — `cible` n'existe que sur la branche caduque.
    expect(conflit.cible).toBeUndefined()
  })

  it('impose deux traitements distincts', () => {
    const rendu = (conflit: ConflitDeDemande): string =>
      selonConflit(conflit, {
        rejouable: (c) => `réessayer (${c.code})`,
        // La branche caduque est la seule à recevoir de quoi expliquer.
        caduque: (c) => `refaire — ${c.champs.length} champ(s) ont bougé`,
      })

    expect(rendu({ sorte: 'rejouable', code: 'verrou_concurrent', message: '', conduite: 'reessayer', libelleAction: 'Réessayer' })).toMatch(/réessayer/)
    expect(
      rendu({
        sorte: 'caduque',
        code: 'demande_caduque',
        message: '',
        conduite: 'refaire',
        libelleAction: 'Refaire cette demande',
        demandeId: 14,
        champs: [],
        cible: null,
      }),
    ).toMatch(/refaire/)
  })
})

describe('l’affichage d’une valeur qui a bougé', () => {
  it('rend une fourchette comme une fourchette', () => {
    // Ici aussi : jamais un point là où la base a un intervalle.
    expect(
      valeurLisible({
        raw: 'BET 1901 AND 1909',
        qualifier: 'entre',
        min: '1901-01-01',
        max: '1909-12-31',
        place: null,
        texte: 'entre 1901 et 1909',
      }),
    ).toBe('entre 1901 et 1909')

    expect(
      valeurLisible({ raw: null, qualifier: 'entre', min: '1901-01-01', max: '1909-12-31', place: null }),
    ).toBe('1901-01-01 → 1909-12-31')
  })

  it('rend les scalaires et l’absence', () => {
    expect(valeurLisible('Sureau')).toBe('Sureau')
    expect(valeurLisible(true)).toBe('oui')
    expect(valeurLisible(null)).toBe('—')
    expect(valeurLisible('')).toBe('—')
  })
})

describe('la charge réellement rendue par l’API', () => {
  /**
   * **Capturée sur l'API qui tourne**, et non imaginée d'après le contrat.
   *
   * Le parcours : deux demandes déposées sur le même champ depuis le même
   * cliché, la seconde validée, puis la première — qui rend ce `409`. C'est le
   * cas où confondre les deux `409` écrit deux fois, et le seul moyen d'être sûr
   * de la forme est de l'avoir vue.
   *
   * Deux écarts avec l'exemple du § 6.8, tous deux inoffensifs et tous deux
   * fixés ici parce qu'un parseur qui ne les supporte pas casse en production
   * sans casser en test :
   *
   * 1. **`modifieePar` est absent.** Le contrat le montre, le serveur ne
   *    l'envoie pas quand il ne sait pas qui a écrit. La lecture le tolère et
   *    l'écran ne rend cette ligne que si l'auteur est nommé.
   * 2. **Les valeurs portent `texte`** — six clés, pas cinq. C'est normal en
   *    *sortie* : ce sont des valeurs à afficher, pas des valeurs d'op. C'est
   *    justement ce `texte` que `valeurLisible` préfère, ce qui donne
   *    « entre 1810 et 1815 » plutôt qu'une paire de bornes ISO.
   */
  const CAPTURE = {
    code: 'demande_caduque',
    message: 'La demande est devenue caduque : la fiche a changé depuis son dépôt.',
    details: {
      demandeId: 1,
      statut: 'caduque',
      rejouable: false,
      champs: [
        {
          field: 'birth',
          propose: {
            raw: 'BET 1810 AND 1815',
            qualifier: 'entre',
            min: '1810-01-01',
            max: '1815-12-31',
            place: null,
            texte: 'entre 1810 et 1815',
          },
          valeurALaSoumission: {
            raw: '1813',
            qualifier: 'annee',
            min: '1813-01-01',
            max: '1813-12-31',
            place: null,
            texte: '1813',
          },
          valeurCourante: {
            raw: 'ABT 1812',
            qualifier: 'environ',
            min: '1812-01-01',
            max: '1812-12-31',
            place: null,
            texte: 'vers 1812',
          },
        },
      ],
    },
  }

  it('se range du côté « refaire », jamais « réessayer »', () => {
    const conflit = conflitDeDemande(new ErreurApi(409, CAPTURE.code, CAPTURE.message, null, CAPTURE.details))
    if (conflit?.sorte !== 'caduque') throw new Error('branche inattendue')

    expect(conflit.conduite).toBe('refaire')
    expect(conflit.demandeId).toBe(1)
  })

  it('survit à l’absence de modifieePar', () => {
    const conflit = conflitDeDemande(new ErreurApi(409, CAPTURE.code, CAPTURE.message, null, CAPTURE.details))
    if (conflit?.sorte !== 'caduque') throw new Error('branche inattendue')

    expect(conflit.champs).toHaveLength(1)
    expect(conflit.champs[0]!.modifieePar).toBeNull()
    expect(conflit.champs[0]!.etiquette).toBe('Naissance')
  })

  it('rend les trois valeurs en prose, sans aplatir la fourchette', () => {
    const conflit = conflitDeDemande(new ErreurApi(409, CAPTURE.code, CAPTURE.message, null, CAPTURE.details))
    if (conflit?.sorte !== 'caduque') throw new Error('branche inattendue')
    const champ = conflit.champs[0]!

    expect(valeurLisible(champ.propose)).toBe('entre 1810 et 1815')
    expect(valeurLisible(champ.valeurALaSoumission)).toBe('1813')
    expect(valeurLisible(champ.valeurCourante)).toBe('vers 1812')
  })
})
