import { describe, expect, it } from 'vitest'
import { adresseDeConnexionGoogle, messageDuRetourGoogle } from './google'

describe('la connexion avec Google', () => {
  it('ne renvoie qu’à un chemin interne', () => {
    expect(adresseDeConnexionGoogle('/arbre')).toBe('/api/connexion/google/debut?suite=%2Farbre')
    expect(adresseDeConnexionGoogle('//evil.example')).toBe('/api/connexion/google/debut?suite=%2F')
    expect(adresseDeConnexionGoogle('https://evil.example')).toBe('/api/connexion/google/debut?suite=%2F')
    expect(adresseDeConnexionGoogle(undefined)).toBe('/api/connexion/google/debut?suite=%2F')
  })

  it('dit pourquoi un retour de Google n’a pas abouti', () => {
    expect(messageDuRetourGoogle('inconnu')).toContain('Aucun compte')
    expect(messageDuRetourGoogle('echec')).toContain('Réessayez')
    expect(messageDuRetourGoogle(undefined)).toBeNull()
  })
})
