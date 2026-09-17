/**
 * Les deux tiroirs du mobile.
 *
 * Aucun DOM ici : la liaison au navigateur passe par `surveillerLargeur()`, à
 * qui l'on tend une `MediaQueryList` écrite à la main. C'est le même procédé
 * que `poserFenetre()` dans `cadre.test.ts` — l'environnement de vitest est
 * `node`, et ce produit n'embarque pas jsdom.
 */
import { describe, expect, it, vi } from 'vitest'
import {
  REQUETE_MOBILE,
  basculer,
  creerEtatTiroirs,
  declarerOutils,
  fermer,
  outilsDisponibles,
  retirerOutils,
  surveillerLargeur,
  type ListeMedia,
} from './tiroirs'

describe('un seul tiroir à la fois', () => {
  it('ouvre le menu et ferme les outils du même geste', () => {
    const etat = creerEtatTiroirs()
    basculer(etat, 'outils')

    expect(basculer(etat, 'menu')).toBe('menu')
  })

  it('referme le tiroir déjà ouvert quand on rappuie sur son bouton', () => {
    const etat = creerEtatTiroirs()
    basculer(etat, 'menu')

    expect(basculer(etat, 'menu')).toBe('aucun')
  })

  it('ne sait pas représenter deux tiroirs ouverts', () => {
    /*
     * C'est la propriété, et elle est structurelle : l'état est UNE valeur
     * parmi trois. Avec deux booléens, `{menu: true, outils: true}` serait
     * représentable — les deux panneaux se recouvrant, l'un par la gauche et
     * l'autre par la droite — et il faudrait se souvenir de fermer l'autre à
     * chaque ouverture, partout.
     */
    const etat = creerEtatTiroirs()
    const vus = new Set<string>()
    for (const cible of ['menu', 'outils', 'menu', 'menu', 'outils'] as const) {
      vus.add(basculer(etat, cible))
    }

    expect([...vus].every((v) => v === 'aucun' || v === 'menu' || v === 'outils')).toBe(true)
  })

  it('se ferme sans qu’on ait à dire lequel était ouvert', () => {
    const etat = creerEtatTiroirs()
    basculer(etat, 'outils')

    expect(fermer(etat)).toBe('aucun')
  })
})

describe('la déclaration d’une barre d’outils', () => {
  it('n’annonce le bouton des outils qu’une fois une barre déclarée', () => {
    const etat = creerEtatTiroirs()
    expect(outilsDisponibles(etat)).toBe(false)

    declarerOutils(etat)

    expect(outilsDisponibles(etat)).toBe(true)
  })

  it('le retire quand l’écran qui portait la barre est démonté', () => {
    const etat = creerEtatTiroirs()
    declarerOutils(etat)
    retirerOutils(etat)

    expect(outilsDisponibles(etat)).toBe(false)
  })

  it('survit au recouvrement de deux écrans pendant un changement de route', () => {
    /*
     * LE DÉFAUT QU'UN BOOLÉEN AURAIT. Vue monte le nouvel écran AVANT de
     * démonter l'ancien. Avec un booléen, le démontage de l'ancien remettrait
     * `false` juste après que le nouveau a mis `true`, et le bouton des outils
     * disparaîtrait en changeant de page — sur une page qui a pourtant une
     * barre. Un compteur passe par 2 et redescend à 1.
     */
    const etat = creerEtatTiroirs()
    declarerOutils(etat) // l'arbre
    declarerOutils(etat) // la carte se monte
    retirerOutils(etat) // l'arbre se démonte

    expect(outilsDisponibles(etat)).toBe(true)
  })

  it('ne descend jamais sous zéro', () => {
    const etat = creerEtatTiroirs()
    retirerOutils(etat)
    retirerOutils(etat)
    declarerOutils(etat)

    expect(outilsDisponibles(etat)).toBe(true)
  })

  it('referme le tiroir d’outils quand la dernière barre s’en va', () => {
    /* Sinon il resterait ouvert alors que son bouton vient de disparaître :
       impossible à refermer autrement qu'en touchant le voile. */
    const etat = creerEtatTiroirs()
    declarerOutils(etat)
    basculer(etat, 'outils')

    retirerOutils(etat)

    expect(etat.ouvert).toBe('aucun')
  })

  it('laisse le menu ouvert quand la dernière barre s’en va', () => {
    const etat = creerEtatTiroirs()
    declarerOutils(etat)
    basculer(etat, 'menu')

    retirerOutils(etat)

    expect(etat.ouvert).toBe('menu')
  })
})

describe('le seuil, écrit une seule fois', () => {
  it('emploie la requête exacte que Tailwind produit pour « max-md »', () => {
    /*
     * Tailwind 4 compile `max-md:` en `@media (width < 48rem)`. Recopier le
     * seuil en pixels le ferait mentir dès qu'un lecteur agrandit la taille de
     * texte du navigateur : `48rem` ne vaut alors plus 768 px, le CSS bascule et
     * le JavaScript non. Les tiroirs resteraient ouverts sur un grand écran, ou
     * fermés sur un petit.
     */
    expect(REQUETE_MOBILE).toBe('(width < 48rem)')
  })

  it('s’abonne à la liste qu’on lui donne et s’en détache sans toucher au DOM', () => {
    const ajouts: unknown[] = []
    const retraits: unknown[] = []
    const liste: ListeMedia = {
      matches: true,
      addEventListener: (_t, e) => ajouts.push(e),
      removeEventListener: (_t, e) => retraits.push(e),
    }

    const detacher = surveillerLargeur(liste, () => {})
    expect(ajouts).toHaveLength(1)

    detacher()
    expect(retraits).toEqual(ajouts)
  })

  it('prévient que la fenêtre a dépassé le seuil', () => {
    /* Le CSS cache le tiroir au-delà du seuil ; il ne le REFERME pas. Sans ce
       fil, Échap resterait captée sur un tiroir que personne ne voit, et le
       tiroir reparaîtrait au prochain rétrécissement. */
    // Un tableau plutôt qu'une variable : TypeScript réduit une variable
    // affectée dans une lambda au type `never` au point d'appel.
    const ecouteurs: ((ev: { matches: boolean }) => void)[] = []
    const liste: ListeMedia = {
      matches: true,
      addEventListener: (_t, e) => {
        ecouteurs.push(e)
      },
      removeEventListener: () => {},
    }
    const vu = vi.fn()

    surveillerLargeur(liste, vu)
    for (const ecouteur of ecouteurs) ecouteur({ matches: false })

    expect(vu).toHaveBeenCalledWith(false)
  })
})
