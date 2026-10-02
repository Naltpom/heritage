/**
 * La consigne parlée de « Décrivez votre famille » : **une fois par onglet**,
 * un refus du navigateur qui ne casse rien, et le texte exact de
 * l'enregistrement.
 *
 * Le stockage de session est passé en paramètre : ces cas n'ont besoin ni de
 * navigateur ni de `jsdom`, et ils éprouvent aussi le stockage qui **lève** —
 * navigation privée de certains Safari, stockage bloqué par une extension.
 */
import { describe, expect, it } from 'vitest'
import {
  CLE_CONSIGNE_JOUEE,
  TEXTE_CONSIGNE,
  URL_CONSIGNE,
  consigneDejaJouee,
  doitJouerAutomatiquement,
  estUnRefusSilencieux,
  progression,
  retenirConsigneJouee,
  type StockageDeSession,
} from './consigneVocale'

function stockageEnMemoire(): StockageDeSession & { valeurs: Map<string, string> } {
  const valeurs = new Map<string, string>()
  return {
    valeurs,
    getItem: (cle) => valeurs.get(cle) ?? null,
    setItem: (cle, valeur) => void valeurs.set(cle, valeur),
  }
}

const STOCKAGE_QUI_LEVE: StockageDeSession = {
  getItem: () => {
    throw new DOMException('Accès refusé', 'SecurityError')
  },
  setItem: () => {
    throw new DOMException('Quota dépassé', 'QuotaExceededError')
  },
}

describe('« déjà jouée »', () => {
  it('joue la première fois, puis plus jamais dans l’onglet', () => {
    const stockage = stockageEnMemoire()
    expect(doitJouerAutomatiquement(true, stockage)).toBe(true)

    retenirConsigneJouee(stockage)

    expect(consigneDejaJouee(stockage)).toBe(true)
    expect(doitJouerAutomatiquement(true, stockage)).toBe(false)
  })

  it('ne joue jamais seule si on ne le lui demande pas', () => {
    expect(doitJouerAutomatiquement(false, stockageEnMemoire())).toBe(false)
  })

  it('ne retient qu’un booléen — rien qui parle d’une personne', () => {
    const stockage = stockageEnMemoire()
    retenirConsigneJouee(stockage)
    expect([...stockage.valeurs.entries()]).toEqual([[CLE_CONSIGNE_JOUEE, '1']])
  })

  it('un stockage qui lève ne casse rien : la consigne se rejoue, simplement', () => {
    expect(() => retenirConsigneJouee(STOCKAGE_QUI_LEVE)).not.toThrow()
    expect(consigneDejaJouee(STOCKAGE_QUI_LEVE)).toBe(false)
    expect(doitJouerAutomatiquement(true, STOCKAGE_QUI_LEVE)).toBe(true)
  })

  it('sans stockage du tout, pareil', () => {
    expect(consigneDejaJouee(null)).toBe(false)
    expect(() => retenirConsigneJouee(null)).not.toThrow()
  })
})

describe('les refus du navigateur', () => {
  it('taisent `NotAllowedError` et `AbortError` : le bouton suffit', () => {
    expect(estUnRefusSilencieux(new DOMException('geste requis', 'NotAllowedError'))).toBe(true)
    expect(estUnRefusSilencieux(new DOMException('coupée', 'AbortError'))).toBe(true)
  })

  it('ne confondent pas une autre panne avec un refus', () => {
    expect(estUnRefusSilencieux(new DOMException('format', 'NotSupportedError'))).toBe(false)
    expect(estUnRefusSilencieux(new Error('autre'))).toBe(false)
    expect(estUnRefusSilencieux(null)).toBe(false)
  })
})

describe('la barre de progression', () => {
  it('va de 0 à 1, bornée', () => {
    expect(progression(0, 25)).toBe(0)
    expect(progression(12.5, 25)).toBe(0.5)
    expect(progression(30, 25)).toBe(1)
  })

  it('reste à 0 tant que la durée n’est pas connue', () => {
    expect(progression(3, Number.NaN)).toBe(0)
    expect(progression(3, 0)).toBe(0)
    expect(progression(3, Number.POSITIVE_INFINITY)).toBe(0)
  })
})

describe('le texte de la consigne', () => {
  it('est celui de l’enregistrement, mot pour mot', () => {
    expect(TEXTE_CONSIGNE).toBe(
      'Nous n’avons pas trouvé de fiche qui corresponde exactement à ce que vous avez saisi. ' +
        'Ce n’est pas grave : racontez-nous simplement qui vous êtes. ' +
        'Votre date de naissance, puis vos parents, avec leurs dates. ' +
        'Si vous avez des frères et sœurs, un conjoint ou des enfants, donnez aussi leurs dates. ' +
        'Vous pouvez l’écrire ci-dessous, ou appuyer sur le micro pour le dire à voix haute, comme vous préférez.',
    )
  })

  it('ne dit rien de la cause du résultat vide — plafond, date, absence', () => {
    expect(TEXTE_CONSIGNE).not.toMatch(/tentative|limite|plafond|réessayez plus tard|décéd/i)
  })

  it('est servi par nginx sous /audio/, pas par le bundler', () => {
    expect(URL_CONSIGNE).toBe('/audio/aide-declaration.mp3')
  })
})
