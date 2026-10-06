/**
 * « Rechercher une personne » — **une seule** mécanique pour les trois champs :
 * l'en-tête (`RechercheEntete`), la barre du bas de l'arbre sur téléphone
 * (`histoire/ChampRechercheArbre`) et la page de tous les résultats
 * (`RechercheView`).
 *
 * **Par l'API, jamais par un index local** (`rechercherPersonnes`) : le serveur
 * exclut les vivants hors cercle, un index bâti sur les nœuds les ferait
 * réapparaître (§ 5.5, ADR 0003).
 *
 * Elle tenait à deux endroits, à deux cadences (140 et 120 ms), et les deux
 * **avalaient** les erreurs : une coupure de réseau s'affichait « Personne ne
 * correspond. » — un mensonge, et le même texte s'affichait avant même la
 * première réponse. D'où cinq états distincts, que l'écran affiche tels quels :
 *
 * | `etat`      | Quand                                        | Ce qu'on montre               |
 * |-------------|----------------------------------------------|-------------------------------|
 * | `inactif`   | moins de deux caractères                     | rien                          |
 * | `chargement`| une requête part ou attend sa réponse        | « Recherche… »                |
 * | `erreur`    | la dernière requête a échoué                 | le message + « Réessayer »    |
 * | `vide`      | le serveur a répondu : personne              | « Personne ne correspond. »   |
 * | `resultats` | le serveur a répondu : au moins une personne | la liste                      |
 *
 * Pendant un `chargement`, les résultats de la saisie précédente restent en
 * place (l'arbre les garde en évidence) : rien ne clignote à chaque frappe.
 *
 * **Une requête dépassée est annulée** (`AbortController`), et son annulation
 * n'est pas une erreur : ni l'`AbortError` du navigateur, ni le
 * `client_requete_annulee` du client ne s'affichent. Une réponse arrivée après
 * une saisie plus récente est ignorée (jeton de séquence), comme dans
 * `useAppelApi`.
 */
import { computed, getCurrentScope, onScopeDispose, ref, shallowRef, watch, type ComputedRef, type Ref } from 'vue'
import { analyserErreur, CODE_ANNULE, estErreurApi, rechercherPersonnes, type ResultatDeRecherche } from '@/lib/api'

/** Le plancher du serveur : en dessous, il répond `422` (§ 5.5). */
export const LONGUEUR_MINIMALE = 2

/** Le plafond du serveur (§ 5.5, `NormaliseurDeRequete::LIMITE_MAXIMALE`). */
export const LIMITE_MAXIMALE = 50

export type EtatRecherche = 'inactif' | 'chargement' | 'erreur' | 'vide' | 'resultats'

/** L'état affiché, déduit des faits — pur, pour être testé sans minuterie. */
export function etatDeRecherche(f: {
  texte: string
  enCours: boolean
  erreur: unknown
  /** Le serveur a-t-il déjà répondu à cette saisie ? */
  repondu: boolean
  nombre: number
}): EtatRecherche {
  if (f.texte.trim().length < LONGUEUR_MINIMALE) return 'inactif'
  if (f.enCours || (!f.repondu && f.erreur === null)) return 'chargement'
  if (f.erreur !== null) return 'erreur'
  return f.nombre > 0 ? 'resultats' : 'vide'
}

/** Une annulation n'est pas une panne : on ne l'affiche jamais. */
export function estAnnulation(cause: unknown): boolean {
  if (estErreurApi(cause)) return cause.code === CODE_ANNULE
  return typeof cause === 'object' && cause !== null && (cause as { name?: unknown }).name === 'AbortError'
}

export interface OptionsRecherche {
  /** Le nombre de résultats demandés, relu à chaque requête (l'en-tête en change). */
  limite: () => number
  /** Le délai après la dernière frappe, en millisecondes. */
  delai?: number
  /** Appelé à chaque réponse, avec la saisie qui l'a produite. */
  surResultats?: (texte: string, liste: ResultatDeRecherche[]) => void
}

export interface RecherchePersonnes {
  /** La saisie, à lier au champ (`v-model`). */
  texte: Ref<string>
  etat: ComputedRef<EtatRecherche>
  resultats: Ref<ResultatDeRecherche[]>
  /** Le plafond demandé a été atteint : il y a sans doute d'autres personnes. */
  tronque: Ref<boolean>
  /** L'erreur brute, pour `analyserErreur` ou `<MessageErreur>`. */
  erreur: Ref<unknown>
  /** Le message de l'erreur, tel que `messageDErreur` le rend. */
  message: ComputedRef<string>
  /** Relance tout de suite la dernière saisie (le bouton « Réessayer »). */
  reessayer: () => void
  /** Vide la saisie et les résultats, annule ce qui est en cours. */
  vider: () => void
}

export function useRecherchePersonnes(options: OptionsRecherche): RecherchePersonnes {
  const texte = ref('')
  const resultats = shallowRef<ResultatDeRecherche[]>([])
  const tronque = ref(false)
  const erreur = shallowRef<unknown>(null)
  const enCours = ref(false)
  /** La saisie à laquelle le serveur a répondu en dernier (succès ou échec). */
  const reponduA = ref<string | null>(null)

  let minuterie: ReturnType<typeof setTimeout> | null = null
  let controleur: AbortController | null = null
  let sequence = 0

  function annuler(): void {
    if (minuterie !== null) clearTimeout(minuterie)
    minuterie = null
    controleur?.abort()
    controleur = null
    sequence++
  }

  async function lancer(saisie: string): Promise<void> {
    annuler()
    const cette = sequence
    const c = new AbortController()
    controleur = c
    enCours.value = true
    try {
      const r = await rechercherPersonnes(saisie, options.limite(), c.signal)
      if (cette !== sequence) return
      resultats.value = r.elements
      tronque.value = r.tronque
      erreur.value = null
      reponduA.value = saisie
      options.surResultats?.(saisie, r.elements)
    } catch (cause) {
      if (cette !== sequence || estAnnulation(cause)) return
      erreur.value = cause
      reponduA.value = saisie
    } finally {
      if (cette === sequence) {
        enCours.value = false
        controleur = null
      }
    }
  }

  watch(texte, (q) => {
    annuler()
    enCours.value = false
    const saisie = q.trim()
    if (saisie.length < LONGUEUR_MINIMALE) {
      resultats.value = []
      tronque.value = false
      erreur.value = null
      reponduA.value = null
      options.surResultats?.(saisie, [])
      return
    }
    erreur.value = null
    minuterie = setTimeout(() => {
      minuterie = null
      void lancer(saisie)
    }, options.delai ?? 140)
  })

  const etat = computed(() =>
    etatDeRecherche({
      texte: texte.value,
      enCours: enCours.value,
      erreur: erreur.value,
      repondu: reponduA.value === texte.value.trim(),
      nombre: resultats.value.length,
    }),
  )

  const message = computed(() => (erreur.value === null ? '' : analyserErreur(erreur.value).message))

  function reessayer(): void {
    const saisie = texte.value.trim()
    if (saisie.length >= LONGUEUR_MINIMALE) void lancer(saisie)
  }

  function vider(): void {
    annuler()
    enCours.value = false
    texte.value = ''
    // Tout de suite, sans attendre l'observateur : le choix d'un résultat ferme la liste.
    resultats.value = []
    tronque.value = false
    erreur.value = null
    reponduA.value = null
  }

  if (getCurrentScope()) onScopeDispose(annuler)

  return { texte, etat, resultats, tronque, erreur, message, reessayer, vider }
}

/**
 * Le curseur d'une liste de résultats parcourue au clavier : `↑` et `↓` font le
 * tour. Pur, partagé par les deux champs.
 */
export function curseurSuivant(curseur: number, pas: number, nombre: number): number {
  if (nombre <= 0) return 0
  return (((curseur + pas) % nombre) + nombre) % nombre
}
