import { describe, expect, it } from 'vitest'
import { creerChronometre, enTexte, ouvertIci, premierChargement } from './chronometre'

describe('le chronomètre du chargement', () => {
  it('mesure chaque étape et cumule une étape répétée', async () => {
    let t = 0
    const c = creerChronometre(() => t)
    c.mesure('a', () => (t += 10))
    await c.mesureAsync('b', async () => {
      t += 5
    })
    c.mesure('a', () => (t += 2))
    expect(c.etapes()).toEqual([
      { etape: 'a', ms: 12 },
      { etape: 'b', ms: 5 },
    ])
  })

  it('compte une étape qui échoue, et laisse passer l’erreur', () => {
    let t = 0
    const c = creerChronometre(() => t)
    expect(() =>
      c.mesure('a', () => {
        t += 3
        throw new Error('panne')
      }),
    ).toThrow('panne')
    expect(c.etapes()).toEqual([{ etape: 'a', ms: 3 }])
  })

  it('ne dit « premier chargement » qu’une fois', () => {
    expect(premierChargement()).toBe(true)
    expect(premierChargement()).toBe(false)
  })

  it('ne compte depuis l’ouverture que si la page a été ouverte sur l’arbre', () => {
    expect(ouvertIci('https://heritage.test/arbre', '/arbre')).toBe(true)
    // Ouverte sur la connexion, puis l'accueil, puis un clic sur l'arbre.
    expect(ouvertIci('https://heritage.test/', '/arbre')).toBe(false)
    expect(ouvertIci('https://heritage.test/home', '/arbre')).toBe(false)
    expect(ouvertIci('https://heritage.test/arbre?personne=12', '/arbre')).toBe(true)
    expect(ouvertIci(null, '/arbre')).toBe(true)
  })

  it('écrit un relevé lisible, réseau et serveur compris', () => {
    const texte = enTexte({
      depuisLaNavigation: 7012.4,
      etapes: [{ etape: 'requête + lecture JSON', ms: 1500.2 }],
      reseau: {
        attente: 1200,
        telechargement: 250,
        transfertKo: 280.4,
        decodeKo: 1900,
        serveur: [
          { etape: 'placement', ms: 12, note: 'cache=hit' },
          { etape: 'memoire', ms: 0, note: '96Mo' },
        ],
      },
      personnes: 3000,
      noeuds: 4000,
    })
    expect(texte).toBe(
      [
        'Arbre : 3000 personnes, 4000 nœuds',
        'Depuis le F5 : 7012 ms',
        '  requête + lecture JSON : 1500 ms',
        'Réseau : attente 1200 ms, téléchargement 250 ms, 280 Ko transférés (1900 Ko décodés)',
        'Serveur :',
        '  placement : 12 ms cache=hit',
        '  memoire : 96Mo',
      ].join('\n'),
    )
  })
})
