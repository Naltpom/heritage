import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  LONGUEUR_MINIMALE,
  erreurCourriel,
  erreurMotDePasse,
  longueurVisible,
  motDePasseAcceptable,
  peutSoumettreCompte,
} from './reglesMotDePasse'

/**
 * Ce que ces tests protègent : **un mot de passe trop court est refusé avant
 * l'envoi, et le refus dit ce qu'il manque.**
 *
 * Le serveur reste la règle qui fait foi — `422` sur `champs.password` (§ 4.3).
 * La garde d'ici n'est là que pour répondre pendant la saisie plutôt qu'après un
 * aller-retour, et pour ne pas soumettre un formulaire dont on sait déjà qu'il
 * sera refusé.
 *
 * La contrainte qui compte pour le produit : le destinataire est un membre d'une
 * famille devant un formulaire, pas un utilisateur d'outil. « Mot de passe
 * invalide » se subit ; « encore quatre caractères » se corrige.
 */

describe('la longueur minimale', () => {
  it('vaut douze, comme au § 6.1 du dossier', () => {
    expect(LONGUEUR_MINIMALE).toBe(12)
  })

  it('refuse onze caractères et accepte douze', () => {
    expect(motDePasseAcceptable('a'.repeat(11))).toBe(false)
    expect(motDePasseAcceptable('a'.repeat(12))).toBe(true)
    expect(motDePasseAcceptable('a'.repeat(40))).toBe(true)
  })
})

describe('le message de refus', () => {
  it('dit combien de caractères il manque', () => {
    expect(erreurMotDePasse('a'.repeat(8))).toContain('4')
    expect(erreurMotDePasse('a'.repeat(8))).toContain('12')
  })

  it('accorde le singulier quand il n’en manque qu’un', () => {
    const message = erreurMotDePasse('a'.repeat(11)) ?? ''
    expect(message).toMatch(/encore un caractère/i)
    expect(message).not.toMatch(/caractères\s*:/i)
  })

  it('invite à choisir plutôt que de reprocher un champ vide', () => {
    expect(erreurMotDePasse('')).toBe('Choisissez un mot de passe.')
  })

  it('ne dit rien quand le mot de passe convient', () => {
    expect(erreurMotDePasse('une phrase de passe tranquille')).toBeNull()
  })

  it('n’impose aucune règle que le serveur n’applique pas', () => {
    // Pas de majuscule obligatoire, pas de chiffre, pas de caractère spécial :
    // le contrat ne demande qu'une longueur. Ajouter des règles ici ferait
    // refuser côté front ce que le serveur accepte, sans que personne puisse le
    // diagnostiquer depuis l'écran.
    expect(motDePasseAcceptable('tellement simple')).toBe(true)
    expect(motDePasseAcceptable('123456789012')).toBe(true)
  })
})

describe('le comptage des caractères', () => {
  it('compte les points de code, pas les unités UTF-16', () => {
    // « 👋 » compte pour un caractère aux yeux du candidat et du validateur
    // Symfony, mais `"👋".length` vaut 2 en JavaScript. Compter en unités
    // UTF-16 rendrait la barrière du front plus stricte que la vraie règle.
    expect(longueurVisible('👋')).toBe(1)
    expect(longueurVisible('été')).toBe(3)

    const douzeAvecEmoji = '👋'.repeat(12)
    expect(longueurVisible(douzeAvecEmoji)).toBe(12)
    expect(motDePasseAcceptable(douzeAvecEmoji)).toBe(true)
  })

  it('compte les espaces : une phrase de passe est un mot de passe', () => {
    expect(motDePasseAcceptable('deux mots ici')).toBe(true)
  })
})

describe('le refus a lieu avant l’envoi', () => {
  const SOURCE = readFileSync(
    fileURLToPath(new URL('../../views/InscriptionCompteView.vue', import.meta.url)),
    'utf8',
  )

  it('la garde bloque le formulaire tant que le mot de passe est court', () => {
    expect(peutSoumettreCompte('cousin.saule@example.net', 'court')).toBe(false)
    expect(peutSoumettreCompte('cousin.saule@example.net', 'a'.repeat(11))).toBe(false)
    expect(peutSoumettreCompte('cousin.saule@example.net', 'a'.repeat(12))).toBe(true)
  })

  it('et elle bloque aussi un courriel manifestement faux', () => {
    expect(peutSoumettreCompte('', 'a'.repeat(12))).toBe(false)
    expect(peutSoumettreCompte('cousin.saule', 'a'.repeat(12))).toBe(false)
  })

  it('l’écran l’applique avant d’appeler l’API, et non après', () => {
    // Écrit en ordre de lecture du fichier : c'est la seule façon de vérifier
    // « avant l'envoi » sans monter un navigateur. Si l'appel remontait au-dessus
    // de la garde, le mot de passe court partirait sur le réseau — et la réponse
    // utile arriverait un aller-retour plus tard.
    const garde = SOURCE.indexOf('peutSoumettreCompte(email.value, motDePasse.value)')
    const appel = SOURCE.indexOf('await creerCompte(')

    expect(garde).toBeGreaterThan(-1)
    expect(appel).toBeGreaterThan(-1)
    expect(garde).toBeLessThan(appel)
  })
})

describe('le courriel', () => {
  it('attrape la faute de frappe évidente', () => {
    expect(erreurCourriel('')).toBe('Indiquez votre adresse de courriel.')
    expect(erreurCourriel('cousin.saule')).not.toBeNull()
    expect(erreurCourriel('cousin @example.net')).not.toBeNull()
  })

  it('n’invente pas de règle au-delà', () => {
    // Toute expression plus ambitieuse finit par refuser l'adresse, valide, d'un
    // cousin. La vérification sérieuse est celle du serveur.
    expect(erreurCourriel('cousin.saule@example.net')).toBeNull()
    expect(erreurCourriel('c+arbre@sous.domaine.example')).toBeNull()
    expect(erreurCourriel("o'connor@example.net")).toBeNull()
  })
})
