import { describe, expect, it } from 'vitest'
import {
  analyserErreur,
  conduiteATenir,
  erreurDepuisCorps,
  estRejouable,
  ErreurApi,
  CODE_RESEAU,
  CODE_REPONSE_ILLISIBLE,
} from './erreurs'

/**
 * Ce que ces tests protègent.
 *
 * Le contrat n'a qu'une enveloppe d'erreur et une liste de codes stables, mais
 * deux de ces codes partagent le même code HTTP `409` **en donnant des conseils
 * opposés** (§ 15.4). Les confondre ferait boucler l'interface sur une demande
 * morte. C'est la raison d'être du module, et donc de ce fichier.
 */

/** Fabrique une enveloppe du § 15.1, `champs` et `details` présents à `null`. */
function enveloppe(
  code: string,
  message: string,
  extras: { champs?: unknown; details?: unknown } = {},
): unknown {
  return {
    erreur: {
      code,
      message,
      champs: extras.champs ?? null,
      details: extras.details ?? null,
    },
  }
}

describe("traduction d'une réponse d'erreur", () => {
  it("lit le code, le message et les champs de l'enveloppe du § 15.1", () => {
    const erreur = erreurDepuisCorps(
      422,
      enveloppe('donnees_invalides', 'Les données soumises sont invalides.', {
        champs: { nouveauMotDePasse: '12 caractères minimum.' },
      }),
    )

    expect(erreur).toBeInstanceOf(ErreurApi)
    expect(erreur.code).toBe('donnees_invalides')
    expect(erreur.statut).toBe(422)
    expect(erreur.champs).toEqual({ nouveauMotDePasse: '12 caractères minimum.' })

    const analyse = analyserErreur(erreur)
    // Le message du serveur fait foi : il est « affichable tel quel » (§ 15.1),
    // et c'est lui qui porte le contexte. Le front ne le réécrit pas.
    expect(analyse.message).toBe('Les données soumises sont invalides.')
    expect(analyse.conduite).toBe('corriger')
    // Rien à proposer : c'est la saisie qu'il faut corriger, pas l'appel à rejouer.
    expect(analyse.libelleAction).toBeNull()
    expect(analyse.champs['nouveauMotDePasse']).toBe('12 caractères minimum.')
  })

  it('ignore les entrées de `champs` qui ne sont pas des chaînes', () => {
    const erreur = erreurDepuisCorps(
      422,
      enveloppe('donnees_invalides', 'Invalide.', {
        champs: { email: 'Adresse invalide.', age: 42, imbrique: { a: 1 } },
      }),
    )
    expect(erreur.champs).toEqual({ email: 'Adresse invalide.' })
  })

  it("emballe une réponse qui n'a pas la forme du contrat, sans exploser", () => {
    // Le cas d'une 404 de routeur qui rendrait du HTML : « exactement le cas où
    // le front a le plus besoin d'un JSON » (§ 15.1). Il ne doit pas planter.
    const erreur = erreurDepuisCorps(404, { __texte: '<!doctype html><h1>Not Found</h1>' })
    expect(erreur.code).toBe(CODE_REPONSE_ILLISIBLE)
    expect(erreur.statut).toBe(404)
    expect(analyserErreur(erreur).message).toContain('404')
  })

  it('rend une analyse lisible pour ce qui n’est pas une ErreurApi', () => {
    // Un `catch` en TypeScript ne promet rien : un bogue du front ne doit pas
    // s'afficher comme une page vide.
    const analyse = analyserErreur(new TypeError('x.map is not a function'))
    expect(analyse.code).toBe(CODE_REPONSE_ILLISIBLE)
    expect(analyse.message).toBe('x.map is not a function')
  })

  it('donne un message propre quand le serveur n’a pas répondu', () => {
    const analyse = analyserErreur(new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu."))
    expect(analyse.message).toContain('connexion')
    expect(analyse.conduite).toBe('reessayer')
  })
})

describe('401 — la session perdue ne se confond pas avec un refus de connexion', () => {
  it('marque `sessionPerdue` sur `authentification_requise`', () => {
    const analyse = analyserErreur(
      erreurDepuisCorps(401, enveloppe('authentification_requise', 'Authentification requise.')),
    )
    expect(analyse.sessionPerdue).toBe(true)
    expect(analyse.conduite).toBe('se_connecter')
  })

  it("ne marque rien sur `identifiants_invalides`, qui est une réponse de l'écran de connexion", () => {
    // § 2.1 : une 401 traitée comme une session perdue déconnecterait le front
    // au premier mot de passe erroné. Le rattrapage branche sur le CODE.
    const analyse = analyserErreur(
      erreurDepuisCorps(401, enveloppe('identifiants_invalides', 'Identifiants invalides.')),
    )
    expect(analyse.sessionPerdue).toBe(false)
  })

  it('ne marque rien sur `compte_suspendu`', () => {
    const analyse = analyserErreur(
      erreurDepuisCorps(401, enveloppe('compte_suspendu', 'Ce compte est suspendu.')),
    )
    expect(analyse.sessionPerdue).toBe(false)
  })
})

describe('les deux 409 de la validation donnent des conseils opposés (§ 15.4)', () => {
  const caduque = erreurDepuisCorps(
    409,
    enveloppe('demande_caduque', 'La demande est devenue caduque : la fiche a changé depuis son dépôt.', {
      details: { demandeId: 14, statut: 'caduque', rejouable: false, champs: [] },
    }),
  )

  const verrou = erreurDepuisCorps(
    409,
    enveloppe(
      'verrou_concurrent',
      "Une autre écriture a touché cette fiche pendant la validation. Rien n'a été écrit.",
      { details: { demandeId: 14, statut: 'en_attente', rejouable: true } },
    ),
  )

  it('`verrou_concurrent` : rien n’a été écrit, on réessaie', () => {
    expect(estRejouable(verrou)).toBe(true)
    expect(conduiteATenir(verrou)).toBe('reessayer')
    expect(analyserErreur(verrou).libelleAction).toBe('Réessayer')
  })

  it('`demande_caduque` : la demande est morte, on la refait — jamais « Réessayer »', () => {
    expect(estRejouable(caduque)).toBe(false)
    expect(conduiteATenir(caduque)).toBe('refaire')
    expect(analyserErreur(caduque).libelleAction).toBe('Refaire cette demande')
  })

  it('les deux ne proposent jamais la même action', () => {
    // Le test qui compte : c'est la confusion des deux qui ferait boucler
    // l'administrateur sur une demande qui ne se validera jamais.
    expect(analyserErreur(caduque).libelleAction).not.toBe(analyserErreur(verrou).libelleAction)
    expect(analyserErreur(caduque).rejouable).not.toBe(analyserErreur(verrou).rejouable)
  })

  it('`cible_disparue` se comporte comme une caducité, pas comme un refus', () => {
    const disparue = erreurDepuisCorps(
      409,
      enveloppe('cible_disparue', 'La personne visée a disparu.', {
        details: {
          demandeId: 14,
          statut: 'caduque',
          rejouable: false,
          cible: { kind: 'personne', id: 17, label: '—' },
        },
      }),
    )
    expect(estRejouable(disparue)).toBe(false)
    expect(conduiteATenir(disparue)).toBe('refaire')
  })

  it('sans `details.rejouable`, la table des codes tranche dans le même sens', () => {
    // Le booléen est la source principale (§ 15.4), mais une réponse ancienne ou
    // tronquée ne doit pas transformer une demande morte en boucle de réessais.
    const caduqueNue = erreurDepuisCorps(409, enveloppe('demande_caduque', 'Caduque.'))
    const verrouNu = erreurDepuisCorps(409, enveloppe('verrou_concurrent', 'Verrou.'))
    expect(estRejouable(caduqueNue)).toBe(false)
    expect(estRejouable(verrouNu)).toBe(true)
  })

  it('`details.rejouable` prime sur la table des codes', () => {
    // Le contrat pose le booléen « pour que le front n'ait pas à connaître la
    // liste des codes » : il doit donc pouvoir contredire la table.
    const inattendu = erreurDepuisCorps(
      409,
      enveloppe('conflit', 'Conflit.', { details: { rejouable: true } }),
    )
    expect(estRejouable(inattendu)).toBe(true)
    expect(conduiteATenir(inattendu)).toBe('reessayer')
  })

  it("une 409 inconnue et sans booléen n'est pas déclarée rejouable", () => {
    const inconnue = erreurDepuisCorps(409, enveloppe('conflit_inedit', 'État incompatible.'))
    expect(estRejouable(inconnue)).toBe(false)
    expect(conduiteATenir(inconnue)).toBe('recharger')
  })
})

describe('autres conduites', () => {
  it('429 : on patiente, et on dit combien de temps quand le serveur le donne', () => {
    const analyse = analyserErreur(
      erreurDepuisCorps(
        429,
        enveloppe('trop_de_tentatives', 'Trop de tentatives.', { details: { retryAfter: 900 } }),
      ),
    )
    expect(analyse.conduite).toBe('patienter')
    expect(analyse.attenteSecondes).toBe(900)
    expect(analyse.libelleAction).toBeNull()
  })

  it('`vue_comme_membre_active` invite à recharger, pas à réessayer', () => {
    // § 10.4 : toute écriture est refusée tant que la bascule est active.
    // Réessayer à l'identique échouerait à l'identique.
    const analyse = analyserErreur(
      erreurDepuisCorps(
        409,
        enveloppe('vue_comme_membre_active', 'Bascule active.', {
          details: { memberId: 7, memberLabel: 'Cobalt Romarin' },
        }),
      ),
    )
    expect(analyse.conduite).toBe('recharger')
  })

  it('`regime_en_attente` ne propose rien : il n’y a rien à refaire', () => {
    // Le compte doit être activé par un administrateur ; aucun geste du lecteur
    // ne change cela (§ 12.2).
    const analyse = analyserErreur(
      erreurDepuisCorps(
        403,
        enveloppe(
          'regime_en_attente',
          'Votre rattachement est en cours de vérification. Vous pourrez proposer des modifications une fois votre compte activé.',
        ),
      ),
    )
    expect(analyse.conduite).toBe('aucune')
    expect(analyse.libelleAction).toBeNull()
    expect(analyse.message).toContain('vérification')
  })

  it('500 : le serveur ne dit rien de plus, on réessaie', () => {
    const analyse = analyserErreur(
      erreurDepuisCorps(500, enveloppe('erreur_interne', 'Une erreur interne est survenue.')),
    )
    expect(analyse.conduite).toBe('reessayer')
    expect(analyse.libelleAction).toBe('Réessayer')
  })
})

describe('les 409 de la complétion (ADR 0017)', () => {
  it('`demande_modifiee_entre_temps` : relire, jamais rejouer — même si rien n’a été écrit', () => {
    // Le serveur dit `rejouable: true` : rien n'est écrit. Mais rejouer le même
    // appel renverrait la même empreinte périmée — ou, sans elle, écraserait ce
    // que l'autre vient d'écrire. Le contrat : « le front relit la demande ».
    const analyse = analyserErreur(
      erreurDepuisCorps(
        409,
        enveloppe('demande_modifiee_entre_temps', 'La demande a changé depuis votre lecture.', {
          details: { demandeId: 12, empreinte: '41ab', rejouable: true },
        }),
      ),
    )
    expect(analyse.rejouable).toBe(true)
    expect(analyse.conduite).toBe('recharger')
    expect(analyse.libelleAction).toBe('Recharger')
  })

  it('`complements_en_attente` et `complement_a_fusionner` : relire, pour voir le geste qui fusionne', () => {
    const cas: [string, Record<string, unknown>][] = [
      ['complements_en_attente', { demandeId: 12, statut: 'en_attente', rejouable: true, complements: [21] }],
      [
        'complement_a_fusionner',
        { demandeId: 21, statut: 'en_attente', rejouable: false, origineDemandeId: 12, origineStatut: 'en_attente' },
      ],
    ]
    for (const [code, details] of cas) {
      const analyse = analyserErreur(erreurDepuisCorps(409, enveloppe(code, 'Fusionnez d’abord.', { details })))
      expect(analyse.conduite).toBe('recharger')
      expect(analyse.libelleAction).not.toBe('Réessayer')
    }
  })

  it('`proposition_plus_en_attente` ne propose rien : l’issue n’est pas dite, rien à relire', () => {
    const analyse = analyserErreur(
      erreurDepuisCorps(409, enveloppe('proposition_plus_en_attente', 'La proposition n’attend plus de validation.')),
    )
    expect(analyse.conduite).toBe('aucune')
    expect(analyse.libelleAction).toBeNull()
  })
})
