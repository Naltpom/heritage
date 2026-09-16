import { createMemoryHistory, createRouter, type RouteRecordName } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement, qui lit
// `window.location` : c'est `@/router/routes` qu'on importe, la table seule.
import { routes } from '@/router/routes'
import { resoudreAcces, type NiveauRequis } from '@/router/acces'

/**
 * Ce que ces tests protègent : **les quatre portes d'entrée s'ouvrent sur le bon
 * écran**, y compris dans les deux cas qui se cassent en silence.
 *
 *  1. Le lien d'invitation arrive avec le jeton **dans le chemin** (§ 3.2) ; le
 *     même écran doit aussi accepter la forme `?jeton=…`. Un lien qui tomberait
 *     sur « page introuvable » ne produirait aucune erreur visible ailleurs — le
 *     candidat conclurait simplement que l'invitation ne marche pas.
 *  2. `/inscription/rattachement` ne doit pas être lu comme un **jeton** nommé
 *     « rattachement ». `vue-router` classe le segment statique au-dessus du
 *     paramètre, mais c'est une règle implicite : si elle change, ou si
 *     quelqu'un réordonne la table, plus rien ne le signale.
 *
 * Et la troisième, qui est une décision de produit plutôt qu'une mécanique :
 * **un compte en attente de validation arrive sur l'écran d'état, pas sur la
 * connexion.** Le renvoyer à la connexion lui ferait croire que sa session est
 * tombée, alors qu'il lui manque un rattachement.
 */

function routeur() {
  // Historique en mémoire : `createWebHistory()` lit `window.location`, absent
  // de l'environnement de test. La table de routes, elle, est la vraie.
  return createRouter({ history: createMemoryHistory(), routes })
}

function nomDe(chemin: string): RouteRecordName | null | undefined {
  return routeur().resolve(chemin).name
}

const JETON = 'kJ8s-2Qb1nR7xTmA0vL5wYcE9hP3dZfG6uIoN4jXqSs'

describe('le lien d’invitation, dans ses deux formes', () => {
  it('ouvre l’écran d’invitation avec le jeton dans le chemin', () => {
    const resolue = routeur().resolve(`/inscription/${JETON}`)

    expect(resolue.name).toBe('inscription.jeton')
    expect(resolue.params['jeton']).toBe(JETON)
  })

  it('ouvre le même écran avec le jeton en chaîne de requête', () => {
    const resolue = routeur().resolve(`/inscription?jeton=${JETON}`)

    expect(resolue.name).toBe('inscription.jeton')
    expect(resolue.query['jeton']).toBe(JETON)
  })

  it('ouvre le même écran sans jeton du tout', () => {
    // La vue dira « ce lien n'est pas valide » : c'est à elle de le faire, pas
    // au routeur, qui répondrait « page introuvable » — un écran différent pour
    // une cause que le candidat ne peut pas distinguer.
    expect(nomDe('/inscription')).toBe('inscription.jeton')
  })

  it('n’altère pas un jeton qui porte des tirets et des soulignés', () => {
    // 43 caractères de `[A-Za-z0-9_-]` (§ 3.2). Un tiret bas ne doit pas être
    // avalé par un encodage de chemin.
    const avecSoulignes = 'a_b-c_d-e_f-g_h-i_j-k_l-m_n-o_p-q_r-s_t-u_v'
    expect(routeur().resolve(`/inscription/${avecSoulignes}`).params['jeton']).toBe(avecSoulignes)
  })
})

describe('les étapes ne sont pas confondues avec un jeton', () => {
  it.each([
    ['/inscription/rattachement', 'inscription.rattachement'],
    ['/inscription/compte', 'inscription.compte'],
    ['/inscription/etat', 'inscription.etat'],
  ])('%s ouvre %s', (chemin, attendu) => {
    expect(nomDe(chemin)).toBe(attendu)
  })

  it('l’écran de connexion reste intact', () => {
    expect(nomDe('/connexion')).toBe('connexion')
  })
})

describe('un compte en attente arrive sur l’écran d’état', () => {
  /** Le `meta.acces` réellement déclaré, lu dans la table plutôt que recopié. */
  function niveauDe(nom: string): NiveauRequis {
    const trouvee = routeur()
      .getRoutes()
      .find((route) => route.name === nom)
    if (trouvee === undefined) throw new Error(`Route absente de la table : ${nom}`)
    return trouvee.meta.acces
  }

  it('est renvoyé vers `inscription.etat`, et non vers la connexion', () => {
    const decision = resoudreAcces('membre', 'en_attente', '/demandes/14')

    expect(decision.autorise).toBe(false)
    if (!decision.autorise) {
      expect(decision.redirection).toEqual({ name: 'inscription.etat' })
      expect(decision.redirection).not.toEqual(
        expect.objectContaining({ name: 'connexion' }),
      )
    }
  })

  it('et cet écran l’accepte — sans quoi la redirection boucle', () => {
    // C'est l'assertion qui compte : une redirection vers un écran qui refuse à
    // son tour produit une boucle, et l'écran d'état est justement la seule
    // route d'inscription qui exige une **session** plutôt que l'anonymat.
    expect(niveauDe('inscription.etat')).toBe('attente')
    expect(resoudreAcces(niveauDe('inscription.etat'), 'en_attente', '/inscription/etat').autorise).toBe(
      true,
    )
  })

  it('tandis que les trois écrans d’inscription publics lui sont fermés', () => {
    // Il a déjà un compte : le renvoyer dans le parcours d'inscription le ferait
    // recommencer une inscription qu'il a terminée.
    for (const nom of ['inscription.jeton', 'inscription.rattachement', 'inscription.compte']) {
      expect(niveauDe(nom)).toBe('anonyme')
      expect(resoudreAcces('anonyme', 'en_attente', '/inscription').autorise).toBe(false)
    }
  })
})
