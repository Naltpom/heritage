import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est la table seule qu'on importe.
import { routes } from '@/router/routes'
import { resoudreAcces, type NiveauRequis } from '@/router/acces'

/**
 * Ce que ces tests protègent : **le chemin existe pour de bon.**
 *
 * `/deconnexion` a rendu « Page introuvable » pendant des heures sans que
 * personne le voie, parce que le bouton, lui, marchait : il poussait vers un nom
 * de route sans jamais passer par une URL. Le chemin n'était nulle part, et
 * c'est pourtant celui qu'on tape et qu'on met en favori.
 *
 * `/mes-demandes` est exactement dans ce cas. C'est un écran qu'on ouvre depuis
 * une notification, depuis un favori, ou depuis un lien collé dans une
 * conversation familiale — et l'entrée de menu qui y mène ne prouverait rien de
 * tout cela.
 *
 * Il protège aussi **le nom** : `demande.detail` est cité par le contrat parce
 * que les notifications portent `linkRoute` plutôt qu'une URL. « On ajoute, on
 * ne renomme pas » — un nom qui disparaît rend muettes les notifications déjà
 * écrites en base.
 */

function routeur() {
  return createRouter({ history: createMemoryHistory(), routes })
}

function niveauDe(nom: string): NiveauRequis | undefined {
  const trouvee = routes.find((route) => route.name === nom)
  return trouvee?.meta?.['acces'] as NiveauRequis | undefined
}

describe('la liste de ses propres demandes a un chemin', () => {
  it('résout `/mes-demandes` sur son écran', () => {
    const resolue = routeur().resolve('/mes-demandes')

    expect(resolue.name).toBe('demandes.miennes')
  })

  it('n’est pas avalée par la route attrape-tout', () => {
    // Le repli `/:chemin(.*)*` rend « Page introuvable » et ne se plaint
    // jamais : une route mal déclarée s'y perd en silence. C'est précisément
    // ainsi que `/deconnexion` a disparu.
    expect(routeur().resolve('/mes-demandes').name).not.toBe('introuvable')
  })

  it('ne se confond pas avec le détail d’une demande', () => {
    // `/demandes/:id(\d+)` exige un entier : les deux chemins ne se recouvrent
    // pas, et l'un ne doit pas manger l'autre.
    expect(routeur().resolve('/demandes/6').name).toBe('demande.detail')
    expect(routeur().resolve('/mes-demandes').name).not.toBe('demande.detail')
  })

  it('porte un titre de page, comme toute route de la table', () => {
    const trouvee = routes.find((route) => route.name === 'demandes.miennes')

    expect(trouvee?.meta?.['titre']).toBe('Mes demandes')
  })
})

describe('qui peut l’ouvrir', () => {
  it('exige un rattachement validé', () => {
    // Un compte en attente n'a, par construction, aucune demande : l'API lui
    // refuse le dépôt (`403 regime_en_attente`). Lui ouvrir une liste toujours
    // vide serait lui promettre un écran qui ne dira jamais rien.
    expect(niveauDe('demandes.miennes')).toBe('membre')
  })

  it('emmène un compte en attente vers l’écran qui explique l’attente', () => {
    // Et non vers la connexion, où il croirait que sa session est tombée.
    const decision = resoudreAcces('membre', 'en_attente', '/mes-demandes')

    expect(decision.autorise).toBe(false)
    if (decision.autorise) return
    expect(decision.motif).toBe('rattachement_non_valide')
  })

  it('renvoie un visiteur sans session à la connexion, en gardant sa destination', () => {
    const decision = resoudreAcces('membre', 'anonyme', '/mes-demandes')

    expect(decision.autorise).toBe(false)
    if (decision.autorise) return
    expect(decision.redirection).toEqual({
      name: 'connexion',
      query: { suite: '/mes-demandes' },
    })
  })

  it('laisse passer un membre et un administrateur', () => {
    expect(resoudreAcces('membre', 'membre', '/mes-demandes').autorise).toBe(true)
    expect(resoudreAcces('membre', 'administrateur', '/mes-demandes').autorise).toBe(true)
  })
})

describe('les noms de route que l’écran fabrique', () => {
  /**
   * `lienDeReprise()` et les liens du gabarit désignent des routes **par leur
   * nom**. Un nom absent de la table ne lève pas : `vue-router` rend un lien
   * vers `/` et personne ne s'en aperçoit avant de cliquer.
   */
  it.each(['demande.detail', 'proposition.creation', 'proposition.modification', 'arbre'])(
    '« %s » existe dans la table',
    (nom) => {
      expect(routeur().hasRoute(nom)).toBe(true)
    },
  )

  it('résout la reprise d’une création avec son paramètre', () => {
    const resolue = routeur().resolve({ name: 'proposition.creation', query: { refaire: '8' } })

    expect(resolue.fullPath).toBe('/personnes/proposer?refaire=8')
  })

  it('résout la reprise d’une modification sur la fiche visée', () => {
    const resolue = routeur().resolve({
      name: 'proposition.modification',
      params: { id: 483 },
      query: { refaire: '6' },
    })

    expect(resolue.fullPath).toBe('/personnes/483/proposer?refaire=6')
  })
})
