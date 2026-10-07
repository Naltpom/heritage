/**
 * Les inscriptions **sans fiche** à rattacher (ADR 0013) — la logique de
 * l'ancien écran « À rattacher », extraite pour la file « À traiter » (handoff
 * « mode Administration », § 4.1).
 *
 * ── Rien d'automatique à l'ouverture (3 octobre 2026) ───────────────────────
 *
 * Le propriétaire veut relire, **corriger la transcription**, puis lancer la
 * recherche lui-même. Le chargement ne fait donc que **lire** :
 *
 *  - la liste (`GET /api/administration/rattachements`) ;
 *  - pour les comptes qui en ont une (`suggestionCalculeeLe`), la suggestion
 *    déjà calculée (`GET …/suggestion`, gratuit — `204` s'il n'y en a plus),
 *    l'une après l'autre ;
 *  - le quota global (`GET /api/administration/quotas`), pour dire ce qu'il
 *    reste à côté du bouton. S'il ne se lit pas, on n'en dit rien.
 *
 * Puis tout part d'un clic :
 *
 *  - « Transcrire le message vocal » (`POST …/transcription`, 10 à 60 s) ;
 *  - « Enregistrer » une transcription corrigée (`PATCH …/transcription` — la
 *    carte l'envoie elle-même, et l'on relit la suggestion, désormais périmée) ;
 *  - « Lancer la recherche » (`POST …/suggestion`, 20 à 60 s), **une à la
 *    fois** dans l'ordre des clics : voir `FileDAnalyses`. Le quota est global,
 *    40 par 24 heures pour tous les administrateurs.
 *
 * `arreter()` vide la file et coupe les appels en cours : l'écran l'appelle en
 * se démontant.
 *
 * ── Sous la bascule « voir comme » ──────────────────────────────────────────
 *
 * La liste rend `409 vue_comme_membre_active` : une description ne se filtre
 * pas par un cercle. `charger()` ne l'appelle donc pas du tout tant que
 * `suspendu` est vrai, et vide ce qu'il tenait.
 */
import { ref, type Ref } from 'vue'
import {
  lireSuggestionDeRattachement,
  listerQuotasGlobaux,
  listerRattachements,
  relancerSuggestionDeRattachement,
  transcrireMessageVocal,
  type CompteARattacher,
  type SuggestionDeRattachement,
  type TranscriptionARattacher,
} from '@/lib/api'
import {
  FileDAnalyses,
  compteDisparu,
  etatApresErreur,
  etatDeTranscription,
  quotaDesRattachements,
  transcriptionApresErreur,
  type EtatDAnalyse,
  type EtatDeTranscription,
  type QuotaRestant,
} from '@/components/administration/rattachements'

export function useRattachements(suspendu: Readonly<Ref<boolean>>) {
  const comptes = ref<CompteARattacher[]>([])
  /** L'état de la recherche de chaque compte, par `memberId`. */
  const analyses = ref<Record<number, EtatDAnalyse>>({})
  /** L'état de la transcription de chaque compte qui a un vocal, par `memberId`. */
  const transcriptions = ref<Record<number, EtatDeTranscription>>({})
  /** Ce qu'il reste du quota global ; `null` tant qu'il n'a pas été lu (ou s'il ne se lit pas). */
  const quota = ref<QuotaRestant | null>(null)
  const chargement = ref(false)
  const erreur = ref<unknown>(null)

  const file = new FileDAnalyses()
  /** Coupe les lectures et les transcriptions en vol quand on recharge ou qu'on quitte l'écran. */
  let sortie = new AbortController()

  function analyseDe(memberId: number): EtatDAnalyse {
    return analyses.value[memberId] ?? { etat: 'aucune' }
  }

  function poserAnalyse(memberId: number, etat: EtatDAnalyse): void {
    // Un compte retiré entre-temps ne revient pas par une réponse tardive.
    if (!comptes.value.some((compte) => compte.memberId === memberId)) return
    analyses.value = { ...analyses.value, [memberId]: etat }
  }

  function poserTranscription(memberId: number, etat: EtatDeTranscription): void {
    if (!comptes.value.some((compte) => compte.memberId === memberId)) return
    transcriptions.value = { ...transcriptions.value, [memberId]: etat }
  }

  function retirer(memberId: number): void {
    file.retirer(memberId)
    comptes.value = comptes.value.filter((compte) => compte.memberId !== memberId)
    const reste = { ...analyses.value }
    delete reste[memberId]
    analyses.value = reste
    const resteT = { ...transcriptions.value }
    delete resteT[memberId]
    transcriptions.value = resteT
  }

  /**
   * Relit le quota global — à l'ouverture, et après chaque appel au modèle. Un
   * échec de lecture efface l'indication plutôt que d'en garder une fausse.
   */
  async function lireLeQuota(): Promise<void> {
    try {
      quota.value = quotaDesRattachements((await listerQuotasGlobaux()).quotas)
    } catch {
      quota.value = null
    }
  }

  /**
   * Relit la suggestion **déjà calculée** d'un compte (`GET`, gratuit) : `null`
   * (`204`) la remet à « Lancer la recherche ». Jamais un calcul.
   */
  async function relireLaSuggestion(memberId: number, signal: AbortSignal): Promise<void> {
    try {
      const gardee = await lireSuggestionDeRattachement(memberId, signal)
      if (signal.aborted) return
      poserAnalyse(memberId, gardee === null ? { etat: 'aucune' } : { etat: 'prete', suggestion: gardee })
    } catch (cause) {
      if (signal.aborted) return
      if (compteDisparu(cause)) {
        retirer(memberId)
        return
      }
      poserAnalyse(memberId, etatApresErreur(cause))
    }
  }

  /**
   * « Transcrire le message vocal », « Réessayer », « Retranscrire ». Un appel
   * au modèle, au clic — pas en file : une transcription ne tient pas la file
   * des recherches. La suggestion déjà affichée est relue ensuite : elle a pu
   * devenir périmée.
   */
  async function transcrire(memberId: number): Promise<void> {
    if (suspendu.value || transcriptions.value[memberId]?.etat === 'en_cours') return
    const signal = sortie.signal
    poserTranscription(memberId, { etat: 'en_cours' })
    try {
      const transcription = await transcrireMessageVocal(memberId, signal)
      if (signal.aborted) return
      poserTranscription(memberId, etatDeTranscription(transcription))
    } catch (cause) {
      if (signal.aborted) return
      if (compteDisparu(cause)) {
        retirer(memberId)
        return
      }
      poserTranscription(memberId, transcriptionApresErreur(cause))
    } finally {
      if (!signal.aborted) void lireLeQuota()
    }
    if (analyses.value[memberId]?.etat === 'prete') await relireLaSuggestion(memberId, signal)
  }

  /**
   * La carte a enregistré une transcription corrigée. La suggestion affichée
   * est marquée périmée **tout de suite** — c'est ce que le serveur dira —,
   * puis relue pour en avoir le dernier mot (un retour au texte d'origine la
   * rend de nouveau valable).
   */
  function transcriptionCorrigee(memberId: number, transcription: TranscriptionARattacher): void {
    poserTranscription(memberId, etatDeTranscription(transcription))
    const analyse = analyses.value[memberId]
    if (analyse?.etat !== 'prete') return
    poserAnalyse(memberId, { etat: 'prete', suggestion: { ...analyse.suggestion, perimee: true } })
    void relireLaSuggestion(memberId, sortie.signal)
  }

  /**
   * « Lancer la recherche » / « Relancer la recherche » (`POST`, consomme le
   * quota), en file : une seule à la fois.
   */
  function lancerLaRecherche(memberId: number): void {
    if (suspendu.value) return
    const actuel = analyses.value[memberId]?.etat
    if (actuel === 'attente' || actuel === 'en_cours') return
    poserAnalyse(memberId, { etat: 'attente' })
    void file.ajouter(memberId, async (signal) => {
      poserAnalyse(memberId, { etat: 'en_cours' })
      let suggestion: SuggestionDeRattachement
      try {
        suggestion = await relancerSuggestionDeRattachement(memberId, signal)
      } catch (cause) {
        if (signal.aborted) return
        // Traité par un autre administrateur entre-temps : le compte s'en va.
        if (compteDisparu(cause)) {
          retirer(memberId)
          return
        }
        poserAnalyse(memberId, etatApresErreur(cause))
        return
      } finally {
        if (!signal.aborted) void lireLeQuota()
      }
      if (signal.aborted) return
      poserAnalyse(memberId, { etat: 'prete', suggestion })
    })
  }

  /**
   * Lit la liste, puis — sans attendre qu'elles reviennent — le quota et les
   * suggestions gardées. La promesse se résout dès que la liste est là :
   * l'écran peut l'afficher pendant que les suggestions arrivent.
   */
  async function charger(): Promise<void> {
    file.vider()
    sortie.abort()
    sortie = new AbortController()
    const signal = sortie.signal
    if (suspendu.value) {
      comptes.value = []
      analyses.value = {}
      transcriptions.value = {}
      return
    }
    chargement.value = true
    erreur.value = null
    try {
      comptes.value = (await listerRattachements()).elements
      if (signal.aborted) return
      // Rien ne se calcule ici : un compte sans suggestion gardée attend le clic.
      analyses.value = Object.fromEntries(
        comptes.value.map((compte) => [
          compte.memberId,
          compte.suggestionCalculeeLe === null ? { etat: 'aucune' } : { etat: 'lecture' },
        ]),
      )
      transcriptions.value = Object.fromEntries(
        comptes.value.flatMap((compte) =>
          compte.messageVocal === null ? [] : [[compte.memberId, etatDeTranscription(compte.messageVocal.transcription)]],
        ),
      )
    } catch (cause) {
      erreur.value = cause
      return
    } finally {
      chargement.value = false
    }
    void lireLeQuota()
    void relireLesSuggestionsGardees(signal)
  }

  /** Les suggestions gardées, l'une après l'autre : des lectures, mais chacune
   *  tient un processus PHP le temps de rafraîchir les fiches citées. */
  async function relireLesSuggestionsGardees(signal: AbortSignal): Promise<void> {
    for (const compte of comptes.value.filter((c) => c.suggestionCalculeeLe !== null)) {
      if (signal.aborted) return
      await relireLaSuggestion(compte.memberId, signal)
    }
  }

  function arreter(): void {
    file.vider()
    sortie.abort()
  }

  return {
    comptes,
    analyses,
    transcriptions,
    quota,
    chargement,
    erreur,
    analyseDe,
    retirer,
    transcrire,
    transcriptionCorrigee,
    lancerLaRecherche,
    charger,
    arreter,
  }
}
