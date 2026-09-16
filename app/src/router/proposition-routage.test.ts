import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est la table seule qu'on importe.
import { routes } from '@/router/routes'
import { resoudreAcces, type NiveauRequis } from '@/router/acces'

/**
 * Ce que ces tests protègent : **déposer est réservé aux membres**, et les deux
 * formulaires s'ouvrent bien là où on les attend.
 *
 * Deux choses se cassent en silence ici :
 *
 * 1. `/personnes/proposer` ne doit pas être avalé par `/personnes/:id`. Le
 *    paramètre est contraint à `\d+`, et `vue-router` classe de toute façon le
 *    segment statique au-dessus — mais c'est une règle implicite, et personne ne
 *    la signale si elle change. Le formulaire de création tomberait alors sur la
 *    fiche d'une personne, avec un identifiant « proposer ».
 * 2. Un compte **en attente de validation** ne dépose pas. « Le défaut fermé
 *    vaut aussi en écriture » : l'API lui rendrait `403 regime_en_attente`, et
 *    un formulaire qu'on remplit pour s'entendre refuser à l'envoi est pire
 *    qu'un formulaire qu'on ne peut pas ouvrir. La garde l'emmène vers l'écran
 *    qui explique l'attente — **pas** vers la connexion, où il croirait que sa
 *    session est tombée.
 */

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

function niveauDe(nom: string): NiveauRequis | undefined {
  const trouvee = routes.find((route) => route.name === nom)
  return trouvee?.meta?.['acces'] as NiveauRequis | undefined
}

describe('les deux formulaires ont leur route', () => {
  it('ouvre la modification sur l’identifiant de la personne visée', () => {
    const resolue = routeur().resolve('/personnes/17/proposer')

    expect(resolue.name).toBe('proposition.modification')
    expect(resolue.params['id']).toBe('17')
  })

  it('ouvre la création sans identifiant du tout', () => {
    // La personne proposée n'a pas d'identifiant tant qu'elle n'est pas
    // validée : elle vit sous une référence provisoire propre à la demande.
    const resolue = routeur().resolve('/personnes/proposer')

    expect(resolue.name).toBe('proposition.creation')
    expect(resolue.params['id']).toBeUndefined()
  })

  it('ne confond pas « proposer » avec un identifiant de personne', () => {
    expect(routeur().resolve('/personnes/17').name).toBe('personne.detail')
    expect(routeur().resolve('/personnes/proposer').name).not.toBe('personne.detail')
  })

  it('conserve le paramètre de reprise', () => {
    // `?refaire=<id>` est ce qui transforme une caducité en contribution
    // reprise plutôt qu'en contribution perdue (§ 6.10).
    expect(routeur().resolve('/personnes/17/proposer?refaire=14').query['refaire']).toBe('14')
    expect(routeur().resolve('/personnes/proposer?refaire=12').query['refaire']).toBe('12')
  })
})

describe('proposer est réservé aux membres', () => {
  it.each(['proposition.modification', 'proposition.creation'])(
    '%s exige un rattachement validé',
    (nom) => {
      expect(niveauDe(nom)).toBe('membre')
    },
  )

  it('renvoie un compte en attente vers l’écran d’attente, pas vers la connexion', () => {
    const decision = resoudreAcces('membre', 'en_attente', '/personnes/proposer')

    expect(decision.autorise).toBe(false)
    if (decision.autorise) return
    expect(decision.motif).toBe('rattachement_non_valide')
    expect(decision.redirection).toEqual({ name: 'inscription.etat' })
  })

  it('renvoie un visiteur anonyme vers la connexion, en gardant sa destination', () => {
    // Un lien de notification collé dans un navigateur vierge est le cas
    // nominal ici, pas un cas limite.
    const decision = resoudreAcces('membre', 'anonyme', '/personnes/17/proposer?refaire=14')

    expect(decision.autorise).toBe(false)
    if (decision.autorise) return
    expect(decision.redirection).toEqual({
      name: 'connexion',
      query: { suite: '/personnes/17/proposer?refaire=14' },
    })
  })

  it.each(['membre', 'administrateur'] as const)('laisse passer un %s', (regime) => {
    expect(resoudreAcces('membre', regime, '/personnes/proposer').autorise).toBe(true)
  })
})

describe('le suivi de la demande reste la destination après dépôt', () => {
  it('garde le nom de route que le contrat cite', () => {
    // Les notifications portent `linkRoute: "demande.detail"` : renommer la
    // route rendrait muettes celles déjà écrites en base. On ajoute, on ne
    // renomme pas — et c'est là que l'auteur lira le motif d'un refus.
    expect(routeur().resolve({ name: 'demande.detail', params: { id: 12 } }).path).toBe('/demandes/12')
  })
})
