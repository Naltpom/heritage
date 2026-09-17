/**
 * Le réducteur de gestes.
 *
 * Aucun `PointerEvent` n'est construit ici, et c'est le but : ce fichier appelle
 * `poser` / `bouger` / `lever` avec des objets à trois nombres. La seule surface
 * non couverte est la ligne du composable qui traduit un événement en `Contact`,
 * qu'aucun test ne tiendrait utilement.
 *
 * Chaque cas nomme le défaut qu'il empêche. Trois d'entre eux sont des défauts
 * **réels du code d'origine**, révélés en passant de un à deux pointeurs.
 */
import { describe, expect, it } from 'vitest'
import { aBouge, bouger, creerEtatGestes, lever, poser } from './gestes'

describe('un doigt', () => {
  it('ne déplace rien tant que le doigt n’a pas bougé de plus de trois pixels', () => {
    /* Sans ce seuil, un clic un peu tremblant sur une fiche est compté comme un
       déplacement, et le panneau ne s’ouvre jamais. */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })

    const consigne = bouger(etat, { id: 1, x: 102, y: 101 })

    expect(consigne.deplacement).toBeNull()
    expect(consigne.demarre).toBe(false)
    expect(aBouge(etat)).toBe(false)
  })

  it('mesure les trois pixels depuis le point d’appui, jamais depuis le dernier mouvement', () => {
    /*
     * LE PIÈGE. Le code d’origine n’avance `px`/`py` qu’APRÈS le franchissement :
     * tant que le seuil n’est pas passé, la référence reste le point d’appui.
     * Avancer la référence à chaque image rendrait le seuil infranchissable — on
     * pourrait traverser l’écran par pas de deux pixels sans jamais déplacer.
     *
     * Les deux lectures se distinguent ici : depuis l’appui, l’écart vaut 4 ;
     * depuis le mouvement précédent, il vaudrait 2.
     */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    expect(bouger(etat, { id: 1, x: 102, y: 100 }).deplacement).toBeNull()

    const consigne = bouger(etat, { id: 1, x: 104, y: 100 })

    expect(consigne.demarre).toBe(true)
    expect(consigne.deplacement).toEqual({ dx: 4, dy: 0 })
  })

  it('déplace ensuite de l’écart exact, image par image', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    bouger(etat, { id: 1, x: 120, y: 100 })

    const consigne = bouger(etat, { id: 1, x: 125, y: 90 })

    expect(consigne.deplacement).toEqual({ dx: 5, dy: -10 })
    expect(consigne.demarre).toBe(false)
  })

  it('ignore un mouvement dont le pointeur n’a jamais été posé', () => {
    /* Forme forte du `if (!enfonce) return` d’origine : elle écarte aussi une
       seconde souris, et un pointeur relâché dont un événement traîne. */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })

    expect(bouger(etat, { id: 9, x: 400, y: 400 }).deplacement).toBeNull()
  })

  it('demande la capture du pointeur au moment où le seuil est franchi', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })

    expect(bouger(etat, { id: 1, x: 140, y: 100 }).capturer).toEqual([1])
    expect(bouger(etat, { id: 1, x: 160, y: 100 }).capturer).toEqual([])
  })
})

describe('deux doigts', () => {
  it('passe au pincement dès le second appui, sans attendre trois pixels', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })

    const consigne = poser(etat, { id: 2, x: 300, y: 100 })

    expect(consigne.demarre).toBe(true)
    expect(consigne.capturer).toEqual([1, 2])
  })

  it('étouffe le clic dès le second appui — un pincement ne sélectionne personne', () => {
    /*
     * DÉFAUT RÉEL DU CODE D’ORIGINE. Il remettait `bouge` à zéro à CHAQUE appui.
     * Avec un seul pointeur c’était juste ; avec deux, le second appui rouvrait
     * le clic que le premier venait d’étouffer, et un pincement se terminait en
     * ouvrant la fiche qui se trouvait sous les doigts.
     */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    bouger(etat, { id: 1, x: 140, y: 100 })
    expect(aBouge(etat)).toBe(true)

    poser(etat, { id: 2, x: 300, y: 100 })

    expect(aBouge(etat)).toBe(true)
  })

  it('étouffe le clic même si les deux doigts ne bougent pas d’un pixel', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })

    expect(aBouge(etat)).toBe(true)
  })

  it('rend l’écartement et le milieu des deux doigts', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })

    const consigne = bouger(etat, { id: 2, x: 500, y: 100 })

    expect(consigne.pincement).not.toBeNull()
    expect(consigne.pincement?.avant).toEqual({ x: 200, y: 100, d: 200 })
    expect(consigne.pincement?.apres).toEqual({ x: 300, y: 100, d: 400 })
  })

  it('ne rend aucun déplacement pendant un pincement : tout passe par la pince', () => {
    /* Le milieu se déplace AUSSI, et c’est `pincer()` qui compose les deux.
       Émettre les deux séparément appliquerait le glissement deux fois. */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })

    expect(bouger(etat, { id: 1, x: 120, y: 140 }).deplacement).toBeNull()
  })

  it('ne suit que les deux premiers doigts posés', () => {
    /* Une paume qui se pose pendant un pincement ne doit pas prendre la main sur
       le geste en cours. */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })
    poser(etat, { id: 3, x: 700, y: 700 })

    expect(bouger(etat, { id: 3, x: 750, y: 750 }).pincement).toBeNull()
    expect(bouger(etat, { id: 2, x: 400, y: 100 }).pincement).not.toBeNull()
  })
})

describe('le passage du pincement au déplacement', () => {
  it('repart du doigt restant, sans le saut du milieu vers le doigt', () => {
    /*
     * L’À-COUP CLASSIQUE. Au retrait d’un doigt, la référence du déplacement
     * était le MILIEU des deux d’avant. La première image suivante comparait
     * donc le doigt restant à ce milieu, et la vue sautait de l’écart entre les
     * deux — ici 90 px pour un doigt qui n’en a parcouru que 10.
     */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })
    lever(etat, 2)

    const consigne = bouger(etat, { id: 1, x: 110, y: 100 })

    expect(consigne.deplacement).toEqual({ dx: 10, dy: 0 })
  })

  it('n’impose pas un second seuil de trois pixels au doigt restant', () => {
    /* Le geste est déjà commencé : réarmer le seuil ferait « coller » la vue
       pendant trois pixels à chaque doigt levé. */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })
    lever(etat, 2)

    expect(bouger(etat, { id: 1, x: 101, y: 100 }).deplacement).toEqual({ dx: 1, dy: 0 })
  })

  it('garde le clic étouffé après le retrait du second doigt', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })
    lever(etat, 2)

    expect(aBouge(etat)).toBe(true)
  })
})

describe('la fin du geste', () => {
  it('ne rend « terminé » qu’au retrait du dernier doigt', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })

    expect(lever(etat, 1).termine).toBe(false)
    expect(lever(etat, 2).termine).toBe(true)
  })

  it('traite une annulation comme un retrait, doigt par doigt', () => {
    /* `pointercancel` d’origine effaçait tout l’état. Avec deux doigts, une
       paume rejetée doit dégrader vers un déplacement à un doigt, pas
       interrompre le geste. `lever()` sert les deux événements. */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })

    lever(etat, 2)

    expect(bouger(etat, { id: 1, x: 130, y: 100 }).deplacement).toEqual({ dx: 30, dy: 0 })
  })

  it('relâche exactement le pointeur qui se lève', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    poser(etat, { id: 2, x: 300, y: 100 })

    expect(lever(etat, 2).relacher).toEqual([2])
    expect(lever(etat, 1).relacher).toEqual([1])
  })

  it('ignore le retrait d’un pointeur qu’il ne suivait pas', () => {
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })

    expect(lever(etat, 7).relacher).toEqual([])
  })

  it('ne rouvre le clic qu’au prochain appui, jamais au relâchement', () => {
    /* Le `click` arrive APRÈS le `pointerup`, et c’est lui qui lit `bouge` pour
       savoir s’il doit s’abstenir. Le remettre à zéro en levant le doigt
       rouvrirait la fiche à la fin de chaque déplacement. */
    const etat = creerEtatGestes()
    poser(etat, { id: 1, x: 100, y: 100 })
    bouger(etat, { id: 1, x: 200, y: 100 })

    lever(etat, 1)
    expect(aBouge(etat)).toBe(true)

    poser(etat, { id: 1, x: 100, y: 100 })
    expect(aBouge(etat)).toBe(false)
  })
})
