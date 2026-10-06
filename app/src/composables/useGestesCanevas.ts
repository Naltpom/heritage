/**
 * La couche DOM des gestes du canevas : elle traduit des événements en
 * `Contact`, applique les `Consigne` que rend le réducteur, et ne décide rien.
 *
 * Tout ce qui se décide est dans `components/arbre/gestes.ts`, qui est pur et
 * testé. Ici il ne reste que ce qu'un test sans navigateur ne pourrait pas
 * tenir : la capture de pointeur, l'écoute de la molette, et deux classes CSS.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Des accesseurs, pas des `ref`.** `vb` doit rester une variable ordinaire
 * dans le composant appelant : elle change à chaque image d'un déplacement, et
 * la faire passer par le système réactif de Vue déclencherait un cycle de rendu
 * par mouvement de doigt, pour écrire un attribut que l'on sait écrire
 * soi-même (ADR 0007, et l'en-tête de `CanevasChronologie`).
 *
 * **La capture se fait par identifiant, et symétriquement.** Un élément peut
 * tenir plusieurs pointeurs à la fois ; capturer le second doigt et ne relâcher
 * que le premier laisse une capture pendante, et le canevas garde alors la main
 * sur un pointeur que l'utilisateur croit avoir rendu.
 * ────────────────────────────────────────────────────────────────────
 */
import { onBeforeUnmount, onMounted, type Ref } from 'vue'
import {
  aBouge,
  bouger,
  creerEtatGestes,
  lever,
  poser,
  type Consigne,
} from '@/components/arbre/gestes'
import { deplacer, pincer, zoomer, type Boite, type CadreEcran } from '@/components/arbre/geometrie'

export interface AttachesGestes {
  /** Le bloc qui porte les gestionnaires — c'est lui qui reçoit la capture. */
  cadre: Ref<HTMLElement | null>
  /** Le `<svg>`, pour la classe `deplacement` seulement. */
  svg: Ref<SVGSVGElement | null>
  /** Le canevas est-il en état de recevoir un geste ? (un pilote est monté) */
  actif: () => boolean
  lireVb: () => Boite
  ecrireVb: (boite: Boite) => void
  /** La boîte rendue par l'API, qui fixe les butées. Jamais recalculée ici. */
  boiteInitiale: () => Boite
  mesurerCadre: () => CadreEcran
  /** Appelé après chaque changement de `viewBox`. */
  appliquer: () => void
}

export interface GestesCanevas {
  surPointeurBas: (ev: PointerEvent) => void
  surPointeurDeplace: (ev: PointerEvent) => void
  surPointeurFin: (ev: PointerEvent) => void
  /** Lu par le `surClic` de chaque canevas : un déplacement se termine par un
   *  `click`, et sans ce garde-fou déplacer l'arbre ouvrirait la fiche qui se
   *  trouve sous le doigt à l'arrivée. */
  aBouge: () => boolean
}

export function useGestesCanevas(attaches: AttachesGestes): GestesCanevas {
  const etat = creerEtatGestes()

  function surMolette(ev: WheelEvent): void {
    if (!attaches.actif()) return
    ev.preventDefault()
    attaches.ecrireVb(
      zoomer(
        attaches.lireVb(),
        attaches.boiteInitiale(),
        attaches.mesurerCadre(),
        ev.clientX,
        ev.clientY,
        ev.deltaY,
      ),
    )
    attaches.appliquer()
  }

  function appliquerConsigne(consigne: Consigne): void {
    if (consigne.demarre) {
      attaches.cadre.value?.classList.add('deplacement')
      attaches.svg.value?.classList.add('deplacement')
    }
    if (consigne.termine) {
      attaches.cadre.value?.classList.remove('deplacement')
      attaches.svg.value?.classList.remove('deplacement')
    }

    for (const id of consigne.capturer) {
      try {
        attaches.cadre.value?.setPointerCapture(id)
      } catch {
        // Capture refusée (pointeur déjà relâché) : le geste fonctionne quand
        // même, il s'arrête simplement en sortant du cadre.
      }
    }
    for (const id of consigne.relacher) {
      try {
        attaches.cadre.value?.releasePointerCapture(id)
      } catch {
        /* déjà relâché */
      }
    }

    const cadre = attaches.mesurerCadre()

    if (consigne.deplacement !== null) {
      const { dx, dy } = consigne.deplacement
      attaches.ecrireVb(deplacer(attaches.lireVb(), cadre, dx, dy))
      attaches.appliquer()
    }

    if (consigne.pincement !== null) {
      const { avant, apres } = consigne.pincement
      attaches.ecrireVb(
        pincer(attaches.lireVb(), attaches.boiteInitiale(), cadre, avant, apres),
      )
      attaches.appliquer()
    }
  }

  function surPointeurBas(ev: PointerEvent): void {
    appliquerConsigne(poser(etat, { id: ev.pointerId, x: ev.clientX, y: ev.clientY }))
  }

  function surPointeurDeplace(ev: PointerEvent): void {
    if (!attaches.actif()) return
    appliquerConsigne(bouger(etat, { id: ev.pointerId, x: ev.clientX, y: ev.clientY }))
  }

  /** Sert `pointerup` **et** `pointercancel` : une annulation est le retrait de
   *  ce pointeur-là, pas l'abandon du geste. Une paume rejetée pendant un
   *  pincement doit dégrader vers un déplacement à un doigt. */
  function surPointeurFin(ev: PointerEvent): void {
    appliquerConsigne(lever(etat, ev.pointerId))
  }

  onMounted(() => {
    // Enregistré à la main, et non par `@wheel.prevent` : il faut
    // `passive: false` pour avoir le droit d'empêcher le défilement de la page.
    attaches.cadre.value?.addEventListener('wheel', surMolette, { passive: false })
  })

  onBeforeUnmount(() => {
    attaches.cadre.value?.removeEventListener('wheel', surMolette)
  })

  return { surPointeurBas, surPointeurDeplace, surPointeurFin, aBouge: () => aBouge(etat) }
}
