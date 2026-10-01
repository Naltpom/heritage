import { afterEach, describe, expect, it } from 'vitest'
import type { DeclarationDeFamille } from '@/lib/api'
import {
  DESCRIPTION_MAXIMALE,
  DESCRIPTION_MINIMALE,
  compteurDescription,
  declarationAcceptable,
  erreurDateDeNaissance,
  erreurDescription,
  erreursDeclaration,
  libelleSansFiche,
  longueurDescription,
  normaliserDeclaration,
} from './declaration'
import {
  choisirCandidat,
  fermerFlux,
  ouvrirFlux,
  retenirCandidats,
  retenirDeclaration,
  useFluxInscription,
} from './fluxInscription'

/**
 * « Je ne me trouve pas » : ce que ces tests tiennent.
 *
 * 1. **Les bornes du contrat** (60 à 4 000 caractères après `trim()`, date ferme
 *    entre 1900 et aujourd'hui) sont appliquées avant l'envoi, comptées en
 *    points de code comme le fait le serveur.
 * 2. **Le bouton ne dépend que du nombre de candidats** : un résultat vide donne
 *    le même libellé quelle qu'en soit la cause.
 * 3. **`choix` et `declaration` sont exclusifs** dans le parcours en mémoire, et
 *    tout s'efface à la fermeture — rien de ce texte sur des vivants ne survit.
 */

const AUJOURDHUI = new Date(2026, 9, 1) // 1ᵉʳ octobre 2026, heure locale

const DESCRIPTION_VALIDE =
  'Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin, née en 1962.'

const COMPLETE: DeclarationDeFamille = {
  surname: 'Dupont',
  givenNames: 'Léa Marie',
  birthDate: '1998-04-12',
  description: DESCRIPTION_VALIDE,
}

describe('la description', () => {
  it('se compte après trim, en points de code', () => {
    expect(longueurDescription('   abc   ')).toBe(3)
    // Un emoji vaut 2 en UTF-16, 1 pour le lecteur comme pour le serveur.
    expect(longueurDescription('👋é')).toBe(2)
  })

  it('refuse le vide et dit ce qu’il manque sous le minimum', () => {
    expect(erreurDescription('   ')).toMatch(/Décrivez votre famille/)
    expect(erreurDescription('x'.repeat(DESCRIPTION_MINIMALE - 1))).toMatch(/Encore un caractère/)
    expect(erreurDescription('x'.repeat(40))).toMatch(/Encore 20 caractères/)
  })

  it('accepte de 60 à 4 000 caractères, pas au-delà', () => {
    expect(erreurDescription('x'.repeat(DESCRIPTION_MINIMALE))).toBeNull()
    expect(erreurDescription('x'.repeat(DESCRIPTION_MAXIMALE))).toBeNull()
    expect(erreurDescription('x'.repeat(DESCRIPTION_MAXIMALE + 1))).not.toBeNull()
    // Les espaces de bord ne comptent pas pour atteindre le minimum.
    expect(erreurDescription(` ${'x'.repeat(DESCRIPTION_MINIMALE - 1)}   `)).not.toBeNull()
  })

  it('affiche un compteur tourné vers le minimum, puis vers le maximum', () => {
    expect(compteurDescription('x'.repeat(12))).toMatch(/^12 \/ 60 caractères au minimum$/)
    expect(compteurDescription('x'.repeat(180))).toMatch(/^180 \/ 4\s000 caractères$/u)
  })
})

describe('la date de naissance déclarée', () => {
  it('exige une date ferme et réelle', () => {
    expect(erreurDateDeNaissance('', AUJOURDHUI)).not.toBeNull()
    expect(erreurDateDeNaissance('12/04/1998', AUJOURDHUI)).not.toBeNull()
    expect(erreurDateDeNaissance('1998-04-31', AUJOURDHUI)).toMatch(/n’existe pas/)
    expect(erreurDateDeNaissance('2001-02-29', AUJOURDHUI)).toMatch(/n’existe pas/)
    expect(erreurDateDeNaissance('2000-02-29', AUJOURDHUI)).toBeNull()
  })

  it('borne à 1900 et à aujourd’hui', () => {
    expect(erreurDateDeNaissance('1899-12-31', AUJOURDHUI)).not.toBeNull()
    expect(erreurDateDeNaissance('1900-01-01', AUJOURDHUI)).toBeNull()
    expect(erreurDateDeNaissance('2026-10-01', AUJOURDHUI)).toBeNull()
    expect(erreurDateDeNaissance('2026-10-02', AUJOURDHUI)).toMatch(/futur/)
  })
})

describe('la déclaration entière', () => {
  it('accepte une déclaration complète', () => {
    expect(erreursDeclaration(COMPLETE, AUJOURDHUI)).toEqual({})
    expect(declarationAcceptable(COMPLETE, AUJOURDHUI)).toBe(true)
  })

  it('range chaque refus sous la clé du contrat', () => {
    const erreurs = erreursDeclaration(
      { surname: ' ', givenNames: 'x'.repeat(161), birthDate: '', description: 'court' },
      AUJOURDHUI,
    )
    expect(Object.keys(erreurs).sort()).toEqual(['birthDate', 'description', 'givenNames', 'surname'])
    expect(erreursDeclaration({ ...COMPLETE, surname: 'x'.repeat(121) }, AUJOURDHUI).surname).toBeDefined()
  })

  it('ne retire que les espaces de bord', () => {
    expect(
      normaliserDeclaration({
        surname: '  Dupont ',
        givenNames: ' Léa  Marie ',
        birthDate: '1998-04-12',
        description: `\n ${DESCRIPTION_VALIDE}\n`,
      }),
    ).toEqual({ ...COMPLETE, givenNames: 'Léa  Marie' })
  })
})

describe('le bouton « sans fiche »', () => {
  it('ne dépend que du nombre de candidats affichés', () => {
    expect(libelleSansFiche(0)).toBe('Je ne me trouve pas')
    expect(libelleSansFiche(1)).toBe('Aucune de ces personnes')
    expect(libelleSansFiche(3)).toBe('Aucune de ces personnes')
  })

  it('n’a qu’un paramètre : aucune cause ne peut y entrer', () => {
    expect(libelleSansFiche.length).toBe(1)
  })
})

describe('le parcours en mémoire', () => {
  afterEach(() => fermerFlux())

  function ouvrir(): void {
    ouvrirFlux('jeton-essai', {
      mode: 'recherche',
      expiresAt: '2026-10-08T00:00:00+02:00',
      candidatsMax: 3,
      champsDemandes: ['surname', 'givenNames', 'birthDate'],
    })
  }

  it('tient `choix` et `declaration` exclusifs', () => {
    ouvrir()
    const flux = useFluxInscription()

    choisirCandidat({ ref: 'c1', libelle: 'P. Romarin, 1980' })
    retenirDeclaration(COMPLETE)
    expect(flux.choix.value).toBeNull()
    expect(flux.declaration.value).toEqual(COMPLETE)

    choisirCandidat({ ref: 'c1', libelle: 'P. Romarin, 1980' })
    expect(flux.declaration.value).toBeNull()
  })

  it('garde la description à travers une nouvelle recherche', () => {
    ouvrir()
    retenirDeclaration(COMPLETE)
    retenirCandidats([])
    expect(useFluxInscription().declaration.value).toEqual(COMPLETE)
  })

  it('efface tout à la fermeture et à la réouverture', () => {
    ouvrir()
    retenirDeclaration(COMPLETE)
    fermerFlux()
    expect(useFluxInscription().declaration.value).toBeNull()

    ouvrir()
    retenirDeclaration(COMPLETE)
    ouvrir()
    expect(useFluxInscription().declaration.value).toBeNull()
  })
})
