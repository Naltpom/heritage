import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { oublierEtag, requeteRevalidee } from './revalidation'
import { ErreurApi } from './erreurs'

/**
 * Ce que ces tests protègent : la revalidation du graphe (§ 5.2).
 *
 * Aucun de ses pièges ne se voit à l'exécution — on reçoit simplement un graphe
 * périmé, ou on reconstruit 377 nœuds à chaque navigation sans que rien
 * n'échoue. D'où des tests sur l'en-tête réellement envoyé.
 */

const CLE = 'test.graphe'

interface AppelObserve {
  url: string
  ifNoneMatch: string | null
}

let appels: AppelObserve[] = []

/** Remplace `fetch` par une file de réponses, en notant ce qui a été envoyé. */
function poserReponses(reponses: Response[]): void {
  let index = 0
  vi.stubGlobal('fetch', (entree: RequestInfo | URL, init?: RequestInit) => {
    const entetes = new Headers(init?.headers)
    appels.push({ url: String(entree), ifNoneMatch: entetes.get('If-None-Match') })
    const reponse = reponses[Math.min(index, reponses.length - 1)]
    index += 1
    return Promise.resolve(reponse)
  })
}

function reponseJson(corps: unknown, statut: number, etag?: string): Response {
  const entetes = new Headers({ 'Content-Type': 'application/json; charset=utf-8' })
  if (etag !== undefined) entetes.set('ETag', etag)
  return new Response(JSON.stringify(corps), { status: statut, headers: entetes })
}

beforeEach(() => {
  appels = []
  oublierEtag()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('revalidation par ETag', () => {
  it("n'envoie pas d'`If-None-Match` au premier appel, et retient l'ETag rendu", async () => {
    poserReponses([reponseJson({ empreinte: '9f2c' }, 200, '"3f0a"')])

    const resultat = await requeteRevalidee<{ empreinte: string }>(CLE, '/arbre/graphe')

    expect(appels[0]?.ifNoneMatch).toBeNull()
    expect(appels[0]?.url).toBe('/api/arbre/graphe')
    expect(resultat).toEqual({
      statut: 'nouveau',
      donnees: { empreinte: '9f2c' },
      etag: '"3f0a"',
    })
  })

  it("renvoie l'ETag **tel quel, guillemets compris**", async () => {
    // § 5.2, étape 2. Le déshabiller de ses guillemets « pour faire propre »
    // produit un `If-None-Match` qui ne correspond à rien : 200 à chaque appel,
    // et reconstruction complète du canevas à chaque navigation.
    poserReponses([
      reponseJson({ v: 1 }, 200, '"3f0a"'),
      new Response(null, { status: 304 }),
    ])

    await requeteRevalidee(CLE, '/arbre/graphe')
    await requeteRevalidee(CLE, '/arbre/graphe')

    expect(appels[1]?.ifNoneMatch).toBe('"3f0a"')
  })

  it('304 : `inchange`, sans données — le canevas reste en place', async () => {
    // « Le client garde son SVG en place. Il ne le reconstruit pas, il ne le
    // vide pas. » L'union discriminée empêche l'appelant de lire des données
    // absentes ; ce test vérifie qu'il n'y en a effectivement aucune.
    poserReponses([reponseJson({ v: 1 }, 200, '"3f0a"'), new Response(null, { status: 304 })])

    await requeteRevalidee(CLE, '/arbre/graphe')
    const resultat = await requeteRevalidee(CLE, '/arbre/graphe')

    expect(resultat).toEqual({ statut: 'inchange' })
  })

  it('200 avec un nouvel ETag : `nouveau`, et le nouvel ETag remplace l’ancien', async () => {
    poserReponses([
      reponseJson({ v: 1 }, 200, '"3f0a"'),
      reponseJson({ v: 2 }, 200, '"88bd"'),
      new Response(null, { status: 304 }),
    ])

    await requeteRevalidee(CLE, '/arbre/graphe')
    const deuxieme = await requeteRevalidee<{ v: number }>(CLE, '/arbre/graphe')
    await requeteRevalidee(CLE, '/arbre/graphe')

    expect(deuxieme).toEqual({ statut: 'nouveau', donnees: { v: 2 }, etag: '"88bd"' })
    expect(appels[2]?.ifNoneMatch).toBe('"88bd"')
  })

  it('ne retient pas un ETag faible', async () => {
    // « Un `W/` autorise un intermédiaire à considérer deux représentations
    // assez équivalentes ; ici deux représentations qui diffèrent d'un champ
    // diffèrent d'une donnée personnelle » (§ 5.2). On préfère un 200 de trop.
    poserReponses([reponseJson({ v: 1 }, 200, 'W/"3f0a"'), reponseJson({ v: 1 }, 200, 'W/"3f0a"')])

    await requeteRevalidee(CLE, '/arbre/graphe')
    await requeteRevalidee(CLE, '/arbre/graphe')

    expect(appels[1]?.ifNoneMatch).toBeNull()
  })

  it('`oublierEtag()` force un 200 au prochain appel', async () => {
    // Appelé à tout changement de session : l'ETag incorpore les demandes en
    // attente du lecteur et, sous la bascule, le membre emprunté (§ 5.2, § 10.4).
    // Le garder ferait recevoir un 304, c'est-à-dire le graphe du lecteur précédent.
    poserReponses([reponseJson({ v: 1 }, 200, '"3f0a"'), reponseJson({ v: 1 }, 200, '"3f0a"')])

    await requeteRevalidee(CLE, '/arbre/graphe')
    oublierEtag(CLE)
    await requeteRevalidee(CLE, '/arbre/graphe')

    expect(appels[1]?.ifNoneMatch).toBeNull()
  })

  it('une erreur lève une `ErreurApi` et jette l’ETag mémorisé', async () => {
    poserReponses([
      reponseJson({ v: 1 }, 200, '"3f0a"'),
      reponseJson(
        { erreur: { code: 'authentification_requise', message: 'Authentification requise.', champs: null, details: null } },
        401,
      ),
      reponseJson({ v: 1 }, 200, '"88bd"'),
    ])

    await requeteRevalidee(CLE, '/arbre/graphe')
    await expect(requeteRevalidee(CLE, '/arbre/graphe')).rejects.toBeInstanceOf(ErreurApi)
    await requeteRevalidee(CLE, '/arbre/graphe')

    // Troisième appel sans `If-None-Match` : on ne revalide pas contre un état
    // qu'on n'a peut-être plus le droit de voir.
    expect(appels[2]?.ifNoneMatch).toBeNull()
  })
})
