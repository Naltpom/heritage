import { describe, expect, it } from 'vitest'
import { delaiAvantNouvelEssai, destinationApresConnexion, suiteAcceptable } from './connexion'

/**
 * Ce que ces tests protègent, sur la porte la plus sensible du produit :
 *
 *  1. **le `?suite=` ne fait pas sortir du site.** Il vient de l'URL, donc de
 *     n'importe qui, et il est suivi à l'instant précis où quelqu'un vient de
 *     taper son mot de passe ;
 *  2. **le blocage après cinq tentatives se comprend.** « Nouvel essai possible
 *     dans 847 secondes » est exact et inutilisable.
 */

describe('le ?suite= est une destination interne, ou rien', () => {
  it('accepte un chemin de ce site', () => {
    expect(suiteAcceptable('/arbre')).toBe(true)
    expect(suiteAcceptable('/personnes/17')).toBe(true)
    expect(suiteAcceptable('/demandes/14?onglet=diff')).toBe(true)
    expect(suiteAcceptable('/chronologie#1901')).toBe(true)
  })

  it('refuse une URL absolue', () => {
    expect(suiteAcceptable('https://ailleurs.example/')).toBe(false)
    expect(suiteAcceptable('http://ailleurs.example/')).toBe(false)
    expect(suiteAcceptable('javascript:alert(1)')).toBe(false)
  })

  it('refuse une URL protocole-relatif, qui commence pourtant par une barre', () => {
    // `//ailleurs.example/x` passe un test naïf « commence par / » et sort du
    // site : c'est le piège classique de ce genre de garde.
    expect(suiteAcceptable('//ailleurs.example/x')).toBe(false)
  })

  it('refuse l’antislash, que le navigateur normaliserait en barre oblique', () => {
    // `/\ailleurs.example` redevient `//ailleurs.example` une fois normalisé.
    expect(suiteAcceptable('/\\ailleurs.example')).toBe(false)
    expect(suiteAcceptable('\\\\ailleurs.example')).toBe(false)
  })

  it('refuse un retour vers la connexion, qui ferait une boucle', () => {
    expect(suiteAcceptable('/connexion')).toBe(false)
    expect(suiteAcceptable('/connexion?suite=/arbre')).toBe(false)
  })

  it('ne refuse pas un chemin qui commence seulement par les mêmes lettres', () => {
    expect(suiteAcceptable('/connexions-familiales')).toBe(true)
  })

  it('refuse ce qui n’est pas une chaîne — `?suite=a&suite=b` donne un tableau', () => {
    expect(suiteAcceptable(undefined)).toBe(false)
    expect(suiteAcceptable(null)).toBe(false)
    expect(suiteAcceptable(['/arbre', '/x'])).toBe(false)
    expect(suiteAcceptable('')).toBe(false)
  })
})

describe('où l’on atterrit après la connexion', () => {
  it('revient à la destination demandée quand elle est recevable', () => {
    expect(destinationApresConnexion('/personnes/17', 'membre')).toBe('/personnes/17')
  })

  it('retombe sur la page d’accueil du régime quand elle ne l’est pas', () => {
    expect(destinationApresConnexion('https://ailleurs.example/', 'membre')).toEqual({ name: 'accueil' })
    expect(destinationApresConnexion(undefined, 'administrateur')).toEqual({ name: 'accueil' })
  })

  it('laisse la garde trancher pour un compte en attente de validation', () => {
    // On ne recalcule pas le régime ici : `/demandes/14` est rendu tel quel, et
    // c'est la garde globale qui emmènera ce compte vers l'écran d'état — pas
    // vers la connexion, où il conclurait que sa session est tombée.
    expect(destinationApresConnexion('/demandes/14', 'en_attente')).toBe('/demandes/14')
    // Sans destination, il atterrit sur l'écran d'attente : l'accueil lit
    // l'arbre, qui lui est fermé (ADR 0003, révision du 01/10/2026).
    expect(destinationApresConnexion(undefined, 'en_attente')).toEqual({ name: 'inscription.etat' })
  })
})

describe('dire quand réessayer', () => {
  it('traduit les secondes du Retry-After en délai lisible', () => {
    expect(delaiAvantNouvelEssai(847)).toBe('dans 15 minutes')
    expect(delaiAvantNouvelEssai(900)).toBe('dans 15 minutes')
    expect(delaiAvantNouvelEssai(120)).toBe('dans 2 minutes')
  })

  it('arrondit vers le haut, pour ne pas faire revenir trop tôt', () => {
    // 61 s arrondi à « une minute » ferait revenir à 60 s, pour se faire
    // refuser une fois de plus.
    expect(delaiAvantNouvelEssai(61)).toBe('dans 2 minutes')
    expect(delaiAvantNouvelEssai(60)).toBe('dans une minute')
  })

  it('accorde le singulier', () => {
    expect(delaiAvantNouvelEssai(45)).toBe("dans moins d'une minute")
    expect(delaiAvantNouvelEssai(3600)).toBe('dans une heure')
    expect(delaiAvantNouvelEssai(7200)).toBe('dans 2 heures')
  })

  it('reste vague plutôt que d’inventer un chiffre', () => {
    // Le serveur n'a pas envoyé de `Retry-After` : on ne le devine pas.
    expect(delaiAvantNouvelEssai(null)).toBe('dans quelques minutes')
    expect(delaiAvantNouvelEssai(0)).toBe('dans quelques minutes')
    expect(delaiAvantNouvelEssai(Number.NaN)).toBe('dans quelques minutes')
  })
})
