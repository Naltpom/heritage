/**
 * La recherche, vue de l'arbre : les personnes trouvées sont **mises en
 * évidence sur le canevas**, la vue se cadre sur elles, le curseur les parcourt
 * en volant de l'une à l'autre, et le choix final pose la sélection.
 *
 * Deux sources, selon la largeur :
 *  - **grand écran** : le champ est celui de l'en-tête (`RechercheEntete`) ;
 *    l'arbre s'abonne à ses résultats et à son curseur (`rechercheGlobale`) ;
 *  - **téléphone** : le champ est en bas de l'arbre (`ChampRechercheArbre`), et
 *    la recherche est la sienne — la même mécanique (`useRecherchePersonnes`),
 *    jusqu'à 50 personnes, le plafond de l'API.
 *
 * Par l'API, jamais par un index local : le serveur exclut les vivants hors cercle.
 */
import { computed, ref, watch, type Ref } from 'vue'
import type { ResultatDeRecherche } from '@/lib/api'
import { useRechercheGlobale } from '@/composables/rechercheGlobale'
import { LIMITE_MAXIMALE, LONGUEUR_MINIMALE, curseurSuivant, useRecherchePersonnes } from '@/composables/useRecherchePersonnes'
import type { DispositionHistoire, FicheHistoire } from './lignees'
import type { CadrageArbre } from './useCadrage'

export interface DependancesRechercheArbre {
  disposition: Ref<DispositionHistoire | null>
  estMobile: Ref<boolean>
  cadrage: CadrageArbre
  /** La largeur utile de l'écran pour cadrer plusieurs cibles. */
  largeurUtile: () => number
  /** Le choix final : sélectionner la personne et voler vers elle. */
  aller: (r: ResultatDeRecherche) => void
}

/**
 * Le cadrage qui montre toutes les cibles : l'échelle (au plus 1) et le centre.
 * Pur : une seule cible se traite à part (on vole vers elle).
 */
export function cadrageDesCibles(fiches: readonly FicheHistoire[], largeurUtile: number): { s: number; x: number; y: number } | null {
  if (!fiches.length) return null
  const x0 = Math.min(...fiches.map((f) => f.x))
  const x1 = Math.max(...fiches.map((f) => f.x + f.w))
  const y0 = Math.min(...fiches.map((f) => f.y))
  const y1 = Math.max(...fiches.map((f) => f.y + f.h))
  return { s: Math.min(1, Math.max(100, largeurUtile) / Math.max(1, x1 - x0)), x: (x0 + x1) / 2, y: (y0 + y1) / 2 }
}

export function useRechercheArbre(d: DependancesRechercheArbre) {
  const curseur = ref(0)
  /** La liste du téléphone est-elle dépliée ? */
  const listeOuverte = ref(false)

  /** La recherche du téléphone. Sur grand écran, son champ n'est pas rendu. */
  const mobile = useRecherchePersonnes({
    limite: () => LIMITE_MAXIMALE,
    delai: 120,
    surResultats(texte) {
      if (!d.estMobile.value || texte.length < LONGUEUR_MINIMALE) return
      curseur.value = 0
      listeOuverte.value = true
      cadrerCibles()
    },
  })

  const globale = useRechercheGlobale()

  /** Les résultats qui allument le canevas : ceux de la recherche active. */
  const resultats = computed<readonly ResultatDeRecherche[]>(() =>
    d.estMobile.value ? mobile.resultats.value : globale.resultats.value,
  )
  const requete = computed(() => (d.estMobile.value ? mobile.texte.value : globale.requete.value))

  /** Les fiches trouvées et présentes dans la disposition. */
  const cibles = computed<FicheHistoire[]>(() => {
    const disp = d.disposition.value
    if (!disp || requete.value.trim().length < LONGUEUR_MINIMALE) return []
    return resultats.value.map((r) => disp.parPersonId.get(r.personId)).filter((f): f is FicheHistoire => f !== undefined)
  })
  const idsCibles = computed(() => new Set(cibles.value.map((f) => f.id)))
  /** La personne du curseur, plus marquée que les autres. */
  const personneCourante = computed(() => resultats.value[curseur.value]?.personId ?? null)

  /** Cadre la vue sur toutes les cibles ; une seule : on vole vers elle. */
  function cadrerCibles(): void {
    const fs = cibles.value
    if (!fs.length) return
    const s = d.cadrage.lire().s
    if (fs.length === 1) {
      const f = fs[0]!
      d.cadrage.voler(Math.max(s, 0.9), f.cx, f.y + f.h / 2)
      return
    }
    const c = cadrageDesCibles(fs, d.largeurUtile())!
    d.cadrage.voler(c.s, c.x, c.y)
  }

  function surCurseur(i: number): void {
    const n = resultats.value.length
    if (!n) return
    curseur.value = curseurSuivant(i, 0, n)
    const f = d.disposition.value?.parPersonId.get(resultats.value[curseur.value]!.personId)
    if (f) d.cadrage.voler(Math.max(d.cadrage.lire().s, 0.9), f.cx, f.y + f.h / 2)
    document.getElementById(`resultat-histoire-${curseur.value}`)?.scrollIntoView({ block: 'nearest' })
  }

  /* Grand écran : l'arbre suit la recherche de l'en-tête. */
  watch(
    () => globale.resultats.value,
    (liste) => {
      if (d.estMobile.value) return
      curseur.value = 0
      if (liste.length) cadrerCibles()
    },
  )
  watch(
    () => globale.curseur.value,
    (i) => {
      if (!d.estMobile.value && resultats.value.length) surCurseur(i)
    },
  )

  /** Le choix d'un résultat (liste du téléphone, ou en-tête par `abonnerChoix`). */
  function allerA(r: ResultatDeRecherche): void {
    mobile.vider()
    listeOuverte.value = false
    d.aller(r)
  }

  /** Le clavier du champ du téléphone : `↑` `↓` parcourent, Entrée choisit, Échap ferme. */
  function surTouche(e: KeyboardEvent): boolean {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      listeOuverte.value = true
      surCurseur(curseur.value + (e.key === 'ArrowDown' ? 1 : -1))
    } else if (e.key === 'Enter') {
      if (mobile.etat.value === 'erreur') {
        mobile.reessayer()
        return false
      }
      const r = mobile.etat.value === 'resultats' ? mobile.resultats.value[curseur.value] : undefined
      if (r) {
        allerA(r)
        return true
      }
    } else if (e.key === 'Escape') {
      mobile.vider()
      listeOuverte.value = false
      return true
    }
    return false
  }

  return {
    mobile,
    curseur,
    listeOuverte,
    cibles,
    idsCibles,
    personneCourante,
    allerA,
    surTouche,
  }
}
