/**
 * **Quelle fiche montrer à côté d'une demande**, et la lire.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUE ÇA RÉPARE.** `/demandes/{id}` montrait la demande et rien d'autre.
 * « Corriger la naissance en 1873 » se juge contre la fiche — ses sources, sa
 * famille, ses autres dates —, et il fallait l'ouvrir dans un autre onglet,
 * puis revenir. On regardait donc rarement, ce qui est la pire façon de décider
 * comme de relire ce qu'on a soi-même proposé.
 *
 * **Quelle personne, selon la demande.** Une seule règle, dans cet ordre :
 *
 * 1. **la cible** quand c'est une personne (`targetKind: personne`) — une
 *    modification, une suppression, un document, une note, une citation
 *    ajoutée, et un ajout de lien, dont le serveur pose la cible sur la
 *    personne d'où le contributeur travaillait (`ConstructeurDeCreation`) ;
 * 2. **un époux** quand c'est une famille — la fiche d'une famille n'a pas de
 *    page à elle ; celle du mari, à défaut de la femme, porte le couple ;
 * 3. **la personne créée**, pour une création déjà validée : le brouillon porte
 *    alors `resolvedPersonId`, et c'est sa vraie fiche qu'on veut relire ;
 * 4. **le parent ou le conjoint lié**, pour une création encore en attente :
 *    la personne proposée n'a pas de fiche, mais celle à qui on la rattache en
 *    a une, et c'est là que se voit le doublon éventuel ;
 * 5. sinon, **rien** — et le bouton ne s'affiche pas. C'est le cas d'une
 *    modification de citation : sa cible est la citation, et aucune route de
 *    lecture ne dit à qui elle appartient sans passer par le serveur.
 *
 * **Le cercle s'applique intégralement.** On lit la même route que la page de
 * la personne, `GET /api/arbre/personnes/{id}` : une personne vivante hors
 * cercle rend une fiche **réduite**, un `200` et non une erreur. Cet écran
 * l'affiche telle quelle (`FicheRestreinte`), il ne teste aucun rôle.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import { lireFicheFamille, lireFichePersonne, type Demande, type FichePersonne } from '@/lib/api'

/** Pourquoi cette fiche-là : la phrase au-dessus de la fiche en dépend. */
export type RaisonDeLaFiche = 'cible' | 'famille' | 'creee' | 'liee'

export type FicheVisee =
  | { personId: number; raison: Exclude<RaisonDeLaFiche, 'famille'> }
  | { familleId: number; raison: 'famille' }

/** Le premier identifiant de personne **existante** d'un lien : l'autre
 *  extrémité d'abord — pour une création, c'est le parent ou le conjoint. */
function personneDuLien(lien: Demande['liens'][number]): number | null {
  return lien.otherPersonId ?? lien.other2PersonId ?? lien.subjectPersonId ?? null
}

export function ficheVisee(demande: Demande): FicheVisee | null {
  const { targetKind, targetId } = demande

  if (targetKind === 'personne' && targetId !== null) return { personId: targetId, raison: 'cible' }
  if (targetKind === 'famille' && targetId !== null) return { familleId: targetId, raison: 'famille' }

  const creee = demande.brouillons.find((brouillon) => brouillon.resolvedPersonId !== null)
  if (creee?.resolvedPersonId != null) return { personId: creee.resolvedPersonId, raison: 'creee' }

  for (const lien of demande.liens) {
    const personne = personneDuLien(lien)
    if (personne !== null) return { personId: personne, raison: 'liee' }
  }

  const op = demande.ops.find((candidat) => candidat.subjectKind === 'personne' && candidat.subjectId !== null)
  if (op?.subjectId != null) return { personId: op.subjectId, raison: 'cible' }

  return null
}

/** La clé qui dit « c'est la même fiche » : on ne relit pas ce qu'on a déjà. */
export function cleDeFicheVisee(visee: FicheVisee | null): string {
  if (visee === null) return ''
  return 'familleId' in visee ? `famille:${visee.familleId}` : `personne:${visee.personId}`
}

/** La ligne au-dessus de la fiche : de qui il s'agit, et pourquoi elle. */
export function legendeDeLaFiche(raison: RaisonDeLaFiche): string {
  switch (raison) {
    case 'cible':
      return 'La fiche visée par la demande'
    case 'famille':
      return 'Un époux de la famille visée'
    case 'creee':
      return 'La personne créée par cette demande'
    case 'liee':
      return 'La personne à qui la proposition se rattache'
  }
}

/**
 * Lit la fiche. Une famille passe par sa fiche pour trouver un époux : le mari,
 * à défaut la femme. Une famille sans époux connu n'a rien à montrer — c'est
 * une erreur lisible, pas un écran vide.
 */
export async function lireFicheVisee(visee: FicheVisee, signal?: AbortSignal): Promise<FichePersonne> {
  if ('personId' in visee) return lireFichePersonne(visee.personId, signal)

  const famille = await lireFicheFamille(visee.familleId, signal)
  const epoux = famille.husband ?? famille.wife
  if (epoux === null) throw new Error('Cette famille n’a aucun époux connu : il n’y a pas de fiche à montrer.')
  return lireFichePersonne(epoux.personId, signal)
}

export interface FicheDeLaDemande {
  visee: ComputedRef<FicheVisee | null>
  ouverte: Ref<boolean>
  fiche: Ref<FichePersonne | null>
  chargement: Ref<boolean>
  erreur: Ref<unknown>
  basculer: () => void
  fermer: () => void
  charger: () => Promise<void>
}

/**
 * L'état du panneau de fiche, **tenu une fois** pour les deux présentations.
 *
 * Le téléphone l'ouvre en modale, le bureau en colonne : les deux sont dans le
 * gabarit, l'une `md:hidden`, l'autre `max-md:hidden`. La lecture, elle, n'a
 * lieu qu'ici — une seule requête, quelle que soit la largeur, et une fenêtre
 * qu'on élargit passe de la modale au partage sans rien relire.
 *
 * On ne lit qu'à l'ouverture : la plupart des demandes se décident sans, et la
 * fiche d'une personne n'est pas une lecture gratuite. On garde l'état ouvert
 * d'une demande à la suivante (« Valider & suivant ») : qui travaille avec la
 * fiche à côté la veut encore à côté.
 */
export function useFicheDeLaDemande(demande: Ref<Demande | null>): FicheDeLaDemande {
  const visee = computed(() => (demande.value === null ? null : ficheVisee(demande.value)))
  const ouverte = ref(false)
  const fiche = ref<FichePersonne | null>(null)
  const chargement = ref(false)
  const erreur = ref<unknown>(null)
  let lue = ''
  let abandon: AbortController | null = null

  async function charger(): Promise<void> {
    const cible = visee.value
    abandon?.abort()
    erreur.value = null
    if (cible === null) {
      fiche.value = null
      lue = ''
      return
    }

    const controleur = new AbortController()
    abandon = controleur
    chargement.value = true
    fiche.value = null
    try {
      const resultat = await lireFicheVisee(cible, controleur.signal)
      if (!controleur.signal.aborted) {
        fiche.value = resultat
        lue = cleDeFicheVisee(cible)
      }
    } catch (cause) {
      if (!controleur.signal.aborted) erreur.value = cause
    } finally {
      if (!controleur.signal.aborted) chargement.value = false
    }
  }

  watch(
    [ouverte, () => cleDeFicheVisee(visee.value)],
    ([estOuverte, cle]) => {
      if (estOuverte && cle !== lue) void charger()
    },
  )

  function basculer(): void {
    ouverte.value = !ouverte.value
  }

  function fermer(): void {
    ouverte.value = false
  }

  return { visee, ouverte, fiche, chargement, erreur, basculer, fermer, charger }
}
