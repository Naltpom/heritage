import { describe, expect, it } from 'vitest'
import type { NoeudNonPlace } from '@/lib/api'
import {
  avertissementDuCalque,
  calqueEstComplet,
  NOMMES_AU_PLUS,
  ponctuationApres,
} from './calque'

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  UN CALQUE QUI NE PEUT PAS TOUT MONTRER DOIT LE DIRE.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Le cas mesuré : cinq demandes portant vingt-deux personnes, **cinq nœuds
 * placés et dix-sept absents**, et un bandeau qui annonçait « votre arbre
 * affiche 5 demandes en attente de validation ». C'était vrai, et trompeur.
 *
 * Ces tests ne portent donc pas sur la mise en forme : ils portent sur le fait
 * qu'un écart **existe dans la charge** et **sorte à l'écran**. Un module muet
 * quand il manque dix-sept fiches est le défaut lui-même.
 */

function absent(nom: string, demandeId = 12, raison = 'enchainement'): NoeudNonPlace {
  return {
    noeudId: `#${demandeId}/p${nom.length}`,
    demandeId,
    brouillonRef: `p${nom.length}`,
    displayName: nom,
    raison,
  }
}

describe('l’avertissement du calque', () => {
  it('ne dit rien quand tout a été placé', () => {
    // Un bandeau qui s'affiche toujours, vide la plupart du temps, cesse d'être
    // lu — et c'est la lecture qui est tout l'objet.
    expect(avertissementDuCalque([])).toBeNull()
  })

  it('dit combien de fiches manquent, et les nomme', () => {
    const avertissement = avertissementDuCalque([
      absent('Girolle Chanterelle'),
      absent('Morille Chanterelle'),
    ])

    expect(avertissement).not.toBeNull()
    expect(avertissement?.phrase).toContain('2')
    expect(avertissement?.nommes.map((n) => n.displayName)).toStrictEqual([
      'Girolle Chanterelle',
      'Morille Chanterelle',
    ])
    expect(avertissement?.enPlus).toBe(0)
  })

  it('accorde le singulier', () => {
    const avertissement = avertissementDuCalque([absent('Girolle Chanterelle')])

    expect(avertissement?.phrase).toMatch(/^Une fiche proposée ne peut pas/)
    expect(avertissement?.phrase).not.toContain('1 fiches')
  })

  /**
   * **Le cas réel, à l'échelle où il s'est produit.** Dix-sept fiches nommées
   * feraient un bandeau plus haut que l'arbre ; zéro laisserait l'utilisateur
   * sans geste. Trois noms et un compte donnent les deux.
   */
  it('nomme les trois premières et compte le reste sans en perdre une', () => {
    const absents = Array.from({ length: 17 }, (_, rang) => absent(`Fiche numéro ${rang}`))

    const avertissement = avertissementDuCalque(absents)

    expect(avertissement?.nommes).toHaveLength(NOMMES_AU_PLUS)
    expect(avertissement?.enPlus).toBe(14)
    // L'assertion qui compte : les nommées **plus** le reste valent l'ensemble
    // reçu. Un module qui tronquerait sans compter reproduirait exactement le
    // défaut qu'il est censé signaler.
    expect((avertissement?.nommes.length ?? 0) + (avertissement?.enPlus ?? 0)).toBe(absents.length)
  })

  it('donne de quoi ouvrir chaque fiche nommée', () => {
    // Un bandeau qui annonce un manque sans offrir le moindre geste transforme
    // une information en frustration. `demandeId` et `brouillonRef` sont là pour
    // que l'écran fasse un lien — et `brouillonRef` est **sans dièse**, parce
    // que `#` est le séparateur de fragment d'une URL.
    const avertissement = avertissementDuCalque([absent('Girolle Chanterelle', 7)])

    expect(avertissement?.nommes[0]?.demandeId).toBe(7)
    expect(avertissement?.nommes[0]?.brouillonRef).not.toContain('#')
  })
})

describe('la ponctuation de l’énumération', () => {
  /**
   * **Le point final n'est pas une coquetterie.** Sans lui, la dernière fiche
   * nommée se colle à la phrase qui suit — « Cèpe Chanterelle Elles se
   * rattachent à… » —, et le cas ne se voit **que** lorsque les absences
   * tiennent toutes dans les noms, c'est-à-dire le cas le plus fréquent.
   */
  it('sépare les noms par une virgule et ferme la liste par un point', () => {
    const avertissement = avertissementDuCalque([absent('Morille'), absent('Cèpe')])
    if (avertissement === null) throw new Error('un avertissement était attendu')

    expect(ponctuationApres(avertissement, 0)).toBe(',')
    expect(ponctuationApres(avertissement, 1)).toBe('.')
  })

  it('n’écrit rien après le dernier nom quand la phrase continue par « et N autres »', () => {
    const avertissement = avertissementDuCalque(
      Array.from({ length: 17 }, (_, rang) => absent(`Fiche ${rang}`)),
    )
    if (avertissement === null) throw new Error('un avertissement était attendu')

    // Une virgule avant le « et » serait fautive.
    expect(ponctuationApres(avertissement, NOMMES_AU_PLUS - 1)).toBe('')
    expect(avertissement.enPlus).toBeGreaterThan(0)
  })

  it('ferme même une liste d’un seul nom', () => {
    const avertissement = avertissementDuCalque([absent('Morille')])
    if (avertissement === null) throw new Error('un avertissement était attendu')

    expect(ponctuationApres(avertissement, 0)).toBe('.')
  })
})

describe('l’écart entre ce qui est proposé et ce qui est placé', () => {
  it('reconnaît un calque complet', () => {
    expect(calqueEstComplet({ proposes: 4, places: 4 })).toBe(true)
    expect(calqueEstComplet({ proposes: 0, places: 0 })).toBe(true)
  })

  /**
   * Le chiffre exact du cas mesuré. Ce test existe pour que le rapport 5/22 soit
   * écrit quelque part dans la suite : c'est lui qui a été affiché comme
   * « complet », et c'est lui qui doit désormais se lire comme incomplet.
   */
  it('reconnaît un calque qui a écarté la majeure partie de ce qu’il a reçu', () => {
    expect(calqueEstComplet({ proposes: 22, places: 5 })).toBe(false)
  })
})
