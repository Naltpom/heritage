import { describe, expect, it } from 'vitest'
import type { ChargeDeSession } from '@/lib/api'
import {
  estRattache,
  regimeDeSession,
  resoudreAcces,
  routeParDefaut,
  type NiveauRequis,
  type RegimeAcces,
} from './acces'

/**
 * Ce que ces tests protègent : **les trois états d'accès, et non deux.**
 *
 * `en_attente` n'est ni un anonyme, ni un membre diminué. Il a une session, il
 * lit l'arbre des personnes décédées, et il n'a **pas de cercle de visibilité**.
 * Les deux erreurs que ce fichier interdit sont :
 *
 *  - le renvoyer à la connexion, alors qu'il est connecté ;
 *  - le laisser passer sur une route de contribution, alors que le serveur y
 *    répondra `403 regime_en_attente`.
 */

const UTILISATEUR_ACTIF = {
  id: 3,
  email: 'cobalt.romarin@example.net',
  displayName: 'Cobalt Romarin',
  status: 'actif' as const,
  totpEnabledAt: null,
  lastLoginAt: '2026-09-15T18:42:11+02:00',
}

/** Membre actif, avec un cercle. */
const SESSION_MEMBRE: ChargeDeSession = {
  utilisateur: { ...UTILISATEUR_ACTIF, roles: ['ROLE_USER'] },
  membre: {
    id: 7,
    status: 'actif',
    personId: 129,
    personLabel: 'Cobalt Romarin',
    matchKind: 'pre_lie',
    activatedAt: '2026-03-02T09:14:00+01:00',
  },
  cercle: { regime: 'cercle', generations: 3, taille: 34 },
  voirComme: null,
  demandesEnAttente: [12],
  portrait: null,
}

const SESSION_ADMIN: ChargeDeSession = {
  ...SESSION_MEMBRE,
  utilisateur: { ...UTILISATEUR_ACTIF, roles: ['ROLE_USER', 'ROLE_ADMIN'] },
}

/**
 * Compte en attente de validation. Noter les trois différences du § 2.2 :
 * `cercle.regime` vaut `"aucun"` (et non `"cercle"` avec une taille nulle),
 * `membre.personId` et `membre.personLabel` sont **absents**, et `displayName`
 * retombe sur le courriel.
 */
const SESSION_EN_ATTENTE: ChargeDeSession = {
  utilisateur: {
    id: 9,
    email: 'cousin.saule@example.net',
    displayName: 'cousin.saule@example.net',
    roles: ['ROLE_USER'],
    status: 'en_attente',
    totpEnabledAt: null,
    lastLoginAt: '2026-09-15T20:01:44+02:00',
  },
  membre: { id: 11, status: 'en_attente', matchKind: 'recherche_confirmee', activatedAt: null },
  cercle: { regime: 'aucun' },
  voirComme: null,
  demandesEnAttente: [],
  portrait: null,
}

describe('régime déduit de la charge de session', () => {
  it('sans session : anonyme', () => {
    expect(regimeDeSession(null)).toBe('anonyme')
  })

  it('`cercle.regime = "aucun"` : régime en attente, et non membre', () => {
    expect(regimeDeSession(SESSION_EN_ATTENTE)).toBe('en_attente')
  })

  it('membre actif avec cercle : membre', () => {
    expect(regimeDeSession(SESSION_MEMBRE)).toBe('membre')
  })

  it('ROLE_ADMIN : administrateur', () => {
    expect(regimeDeSession(SESSION_ADMIN)).toBe('administrateur')
  })

  it('branche sur `regime`, jamais sur une taille de cercle', () => {
    // Le contrat l'écrit : « Le front branche sur `regime`. Il ne teste jamais
    // `taille === 0` : les deux champs sont absents du régime `aucun`, et les
    // lire produirait `undefined`, pas `0` » (§ 12).
    expect(SESSION_EN_ATTENTE.cercle.regime).toBe('aucun')
    expect('taille' in SESSION_EN_ATTENTE.cercle).toBe(false)
    expect('generations' in SESSION_EN_ATTENTE.cercle).toBe(false)
  })

  it('en cas de signaux contradictoires, retient le régime le plus fermé', () => {
    // Défaut fermé : un compte que le serveur n'a pas activé n'obtient pas
    // l'arbre des vivants parce qu'un champ a divergé.
    const incoherente: ChargeDeSession = {
      ...SESSION_MEMBRE,
      utilisateur: { ...SESSION_MEMBRE.utilisateur, status: 'en_attente' },
    }
    expect(regimeDeSession(incoherente)).toBe('en_attente')
  })

  it('« rattaché » exclut le régime en attente', () => {
    expect(estRattache('en_attente')).toBe(false)
    expect(estRattache('membre')).toBe(true)
    expect(estRattache('administrateur')).toBe(true)
  })
})

describe('gardes de route — consultation (niveau « attente »)', () => {
  it('ouvre l’arbre au compte en attente : c’est la décision du commanditaire', () => {
    // « L'attente peut durer plusieurs jours, et un écran vide pousse à
    // l'abandon ; l'arbre ancien donne du sens à l'attente sans rien exposer de
    // sensible » (§ 12.2). Le filtrage des vivants est fait par le serveur.
    expect(resoudreAcces('attente', 'en_attente', '/arbre').autorise).toBe(true)
  })

  it('ouvre aussi au membre et à l’administrateur', () => {
    expect(resoudreAcces('attente', 'membre', '/arbre').autorise).toBe(true)
    expect(resoudreAcces('attente', 'administrateur', '/arbre').autorise).toBe(true)
  })

  it('renvoie l’anonyme à la connexion, en mémorisant la destination', () => {
    const decision = resoudreAcces('attente', 'anonyme', '/personnes/17')
    expect(decision).toEqual({
      autorise: false,
      motif: 'session_absente',
      redirection: { name: 'connexion', query: { suite: '/personnes/17' } },
    })
  })
})

describe('gardes de route — contribution (niveau « membre »)', () => {
  it('refuse le compte en attente sans le déconnecter', () => {
    // Le renvoyer à la connexion lui ferait croire que sa session est tombée.
    // Il est connecté ; ce qui lui manque est le rattachement.
    const decision = resoudreAcces('membre', 'en_attente', '/demandes/14')
    expect(decision).toEqual({
      autorise: false,
      motif: 'rattachement_non_valide',
      redirection: { name: 'inscription.etat' },
    })
  })

  it('laisse passer le membre et l’administrateur', () => {
    expect(resoudreAcces('membre', 'membre', '/demandes/14').autorise).toBe(true)
    expect(resoudreAcces('membre', 'administrateur', '/demandes/14').autorise).toBe(true)
  })

  it('renvoie l’anonyme à la connexion', () => {
    const decision = resoudreAcces('membre', 'anonyme', '/demandes/14')
    expect(decision.autorise).toBe(false)
    if (!decision.autorise) expect(decision.motif).toBe('session_absente')
  })
})

describe('gardes de route — administration', () => {
  it('n’ouvre qu’à l’administrateur', () => {
    expect(resoudreAcces('administration', 'administrateur', '/administration').autorise).toBe(true)
  })

  it('renvoie le membre vers un refus explicite, pas vers la connexion', () => {
    const decision = resoudreAcces('administration', 'membre', '/administration')
    expect(decision).toEqual({
      autorise: false,
      motif: 'administration_requise',
      redirection: { name: 'acces-refuse' },
    })
  })

  it('renvoie le compte en attente vers l’écran d’attente, qui lui explique', () => {
    const decision = resoudreAcces('administration', 'en_attente', '/administration')
    expect(decision.autorise).toBe(false)
    if (!decision.autorise) expect(decision.redirection).toEqual({ name: 'inscription.etat' })
  })
})

describe('gardes de route — écrans d’entrée (niveau « anonyme »)', () => {
  it('laisse passer l’anonyme', () => {
    expect(resoudreAcces('anonyme', 'anonyme', '/connexion').autorise).toBe(true)
  })

  it('détourne tout lecteur connecté, quel que soit son régime', () => {
    for (const regime of ['en_attente', 'membre', 'administrateur'] as const) {
      const decision = resoudreAcces('anonyme', regime, '/connexion')
      expect(decision.autorise).toBe(false)
      if (!decision.autorise) {
        expect(decision.motif).toBe('deja_connecte')
        expect(decision.redirection).toEqual({ name: 'accueil' })
      }
    }
  })
})

describe('routes publiques et atterrissage', () => {
  it('une route publique est ouverte dans les quatre régimes', () => {
    for (const regime of ['anonyme', 'en_attente', 'membre', 'administrateur'] as const) {
      expect(resoudreAcces('public', regime, '/introuvable').autorise).toBe(true)
    }
  })

  it('l’anonyme atterrit sur la connexion, tout lecteur connecté sur l’accueil', () => {
    expect(routeParDefaut('anonyme')).toEqual({ name: 'connexion' })
    expect(routeParDefaut('en_attente')).toEqual({ name: 'accueil' })
    expect(routeParDefaut('membre')).toEqual({ name: 'accueil' })
    expect(routeParDefaut('administrateur')).toEqual({ name: 'accueil' })
  })

  it('aucune combinaison niveau × régime ne reste indécise', () => {
    // Garde-fou : si l'on ajoute un niveau ou un régime sans traiter son cas,
    // ce test le fait remarquer plutôt que de laisser passer un `undefined`.
    const niveaux: NiveauRequis[] = ['public', 'anonyme', 'attente', 'membre', 'administration']
    const regimes: RegimeAcces[] = ['anonyme', 'en_attente', 'membre', 'administrateur']
    for (const niveau of niveaux) {
      for (const regime of regimes) {
        const decision = resoudreAcces(niveau, regime, '/x')
        expect(typeof decision.autorise).toBe('boolean')
        if (!decision.autorise) expect(decision.redirection).toBeTruthy()
      }
    }
  })
})
