/**
 * « Rechercher une personne » — **une seule** mécanique pour tous les champs qui
 * interrogent l'API : l'en-tête (`RechercheEntete`), la barre du bas des deux
 * arbres sur téléphone (`components/arbre/ChampRechercheArbre`), la page de tous
 * les résultats (`RechercheView`) et le sélecteur des formulaires
 * (`proposition/SelecteurPersonne`).
 *
 * **Par l'API, jamais par un index local** (`rechercherPersonnes`) : le serveur
 * exclut les vivants hors cercle, un index bâti sur les nœuds les ferait
 * réapparaître (§ 5.5, ADR 0003).
 *
 * Elle tenait à quatre endroits, à quatre cadences, et trois d'entre eux
 * **avalaient** les erreurs : une coupure de réseau s'affichait « Personne ne
 * correspond. » — un mensonge, et le même texte s'affichait avant même la
 * première réponse. D'où cinq états distincts, que l'écran affiche tels quels :
 *
 * | `etat`      | Quand                                        | Ce qu'on montre               |
 * |-------------|----------------------------------------------|-------------------------------|
 * | `inactif`   | moins de deux caractères                     | rien                          |
 * | `chargement`| la frappe n'est pas finie, ou la réponse tarde | « Recherche… »              |
 * | `erreur`    | la dernière requête a échoué                 | le message + « Réessayer »    |
 * | `vide`      | le serveur a répondu : personne              | « Personne ne correspond. »   |
 * | `resultats` | le serveur a répondu : au moins une personne | la liste                      |
 *
 * Pendant un `chargement`, les résultats de la saisie précédente restent en
 * place (l'arbre les garde en évidence) : rien ne clignote à chaque frappe. Une
 * panne, elle, les retire : ils ne répondent pas à la saisie affichée.
 *
 * **Une requête dépassée est annulée** (`AbortController`), et son annulation
 * n'est pas une erreur : ni l'`AbortError` du navigateur, ni le
 * `client_requete_annulee` du client ne s'affichent. Une réponse arrivée après
 * une saisie plus récente est ignorée (jeton de séquence), comme dans
 * `useAppelApi`.
 */
import { computed, getCurrentScope, onScopeDispose, ref, shallowRef, watch, type ComputedRef, type Ref, type ShallowRef } from 'vue'
import { rechercherPersonnes, type ReponseDeRecherche, type ResultatDeRecherche } from '@/lib/api'
// Le module des erreurs directement, et non `@/lib/api` : `CODE_ANNULE` n'en est
// pas exporté, et un test qui simule `@/lib/api` en entier n'y met pas ces outils.
import { analyserErreur, CODE_ANNULE, estErreurApi } from '@/lib/api/erreurs'

/** Le plancher du serveur : en dessous, il répond `422` (§ 5.5). */
export const LONGUEUR_MINIMALE = 2

/** Le plafond du serveur (§ 5.5, `NormaliseurDeRequete::LIMITE_MAXIMALE`) : au-delà, il rabote. */
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

export interface OptionsRecherche<R extends ReponseDeRecherche> {
  /** Le nombre de résultats demandés, relu à chaque requête (l'en-tête en change). */
  limite: () => number
  /** Le délai après la dernière frappe, en millisecondes. */
  delai?: number
  /** Appelé à chaque réponse — une panne compte pour une liste vide —, et quand
   *  la saisie redescend sous deux caractères, avec la saisie concernée. */
  surResultats?: (texte: string, liste: ResultatDeRecherche[]) => void
  /**
   * Ce qu'on interroge. Par défaut `rechercherPersonnes` ; le sélecteur des
   * formulaires y joint les fiches proposées, dans la même requête annulable.
   */
  chercher?: (q: string, limite: number, signal: AbortSignal) => Promise<R>
}

export interface RecherchePersonnes<R extends ReponseDeRecherche = ReponseDeRecherche> {
  /** La saisie, à lier au champ (`v-model`). */
  texte: Ref<string>
  etat: ComputedRef<EtatRecherche>
  resultats: Readonly<ShallowRef<ResultatDeRecherche[]>>
  /** Le plafond demandé a été atteint : il y a sans doute d'autres personnes. */
  tronque: Readonly<Ref<boolean>>
  /** La dernière réponse entière (ce que `chercher` y a joint), `null` sans réponse valable. */
  reponse: Readonly<ShallowRef<R | null>>
  /** L'erreur brute, pour `<MessageErreur>`. */
  erreur: Readonly<ShallowRef<unknown>>
  /** Le message de l'erreur, tel que `analyserErreur` le rend. */
  message: ComputedRef<string>
  /** Relance tout de suite la dernière saisie (le bouton « Réessayer »). */
  reessayer: () => void
  /** Vide la saisie et les résultats, annule ce qui est en cours. */
  vider: () => void
}

export function useRecherchePersonnes<R extends ReponseDeRecherche = ReponseDeRecherche>(
  options: OptionsRecherche<R>,
): RecherchePersonnes<R> {
  // Sans `chercher`, `R` est `ReponseDeRecherche` : la conversion ne ment pas.
  const chercher = options.chercher ?? ((q, limite, signal) => rechercherPersonnes(q, limite, signal) as Promise<R>)

  const texte = ref('')
  const reponse: ShallowRef<R | null> = shallowRef(null)
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

  function oublier(): void {
    reponse.value = null
    resultats.value = []
    tronque.value = false
  }

  async function lancer(saisie: string): Promise<void> {
    annuler()
    const cette = sequence
    const c = new AbortController()
    controleur = c
    enCours.value = true
    try {
      const r = await chercher(saisie, options.limite(), c.signal)
      if (cette !== sequence) return
      reponse.value = r
      resultats.value = r.elements
      tronque.value = r.tronque
      erreur.value = null
      reponduA.value = saisie
      options.surResultats?.(saisie, r.elements)
    } catch (cause) {
      if (cette !== sequence || estAnnulation(cause)) return
      oublier()
      erreur.value = cause
      reponduA.value = saisie
      options.surResultats?.(saisie, [])
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
    erreur.value = null
    const saisie = q.trim()
    if (saisie.length < LONGUEUR_MINIMALE) {
      oublier()
      reponduA.value = null
      options.surResultats?.(saisie, [])
      return
    }
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
    oublier()
    erreur.value = null
    reponduA.value = null
  }

  if (getCurrentScope()) onScopeDispose(annuler)

  return { texte, etat, resultats, tronque, reponse, erreur, message, reessayer, vider }
}

/**
 * Le curseur d'une liste de résultats parcourue au clavier : `↑` et `↓` font le
 * tour. Pur, partagé par les champs.
 */
export function curseurSuivant(curseur: number, pas: number, nombre: number): number {
  if (nombre <= 0) return 0
  return (((curseur + pas) % nombre) + nombre) % nombre
}
