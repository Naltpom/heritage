/**
 * La cadence à laquelle le cadrage est **publié** à Vue pendant un geste.
 *
 * Pendant un glisser, un pincement, la molette ou un vol, le cadrage change à
 * chaque image ; il est écrit dans le DOM directement (`viewBox`, une
 * transformation par couche — ADR 0007). Ce qui doit se recalculer par Vue — les
 * textes visibles, les anneaux, les noms de foyers — ne l'est qu'**au plus une
 * fois par `intervalle`**, et **toujours** à la fin du geste : `demander()` tient
 * la cadence, `maintenant()` publie sur-le-champ. Aucune demande n'est perdue —
 * la dernière est publiée à l'échéance.
 *
 * Pur : l'horloge est injectée, pour être testée sans attendre.
 */
export interface Horloge {
  maintenant: () => number
  programmer: (f: () => void, ms: number) => unknown
  annuler: (jeton: unknown) => void
}

const HORLOGE_DU_NAVIGATEUR: Horloge = {
  maintenant: () => performance.now(),
  programmer: (f, ms) => setTimeout(f, ms),
  annuler: (jeton) => clearTimeout(jeton as ReturnType<typeof setTimeout>),
}

export interface Cadence {
  /** Publie au plus une fois par intervalle ; la dernière demande part à l'échéance. */
  demander: () => void
  /** Publie tout de suite, et oublie l'échéance en attente. */
  maintenant: () => void
  /** Oublie l'échéance en attente, sans publier (démontage). */
  arreter: () => void
}

export function creerCadence(intervalle: number, publier: () => void, horloge: Horloge = HORLOGE_DU_NAVIGATEUR): Cadence {
  let derniere = -Infinity
  let jeton: unknown = null

  function arreter(): void {
    if (jeton !== null) horloge.annuler(jeton)
    jeton = null
  }

  function maintenant(): void {
    arreter()
    derniere = horloge.maintenant()
    publier()
  }

  function demander(): void {
    if (jeton !== null) return
    const reste = intervalle - (horloge.maintenant() - derniere)
    if (reste <= 0) {
      maintenant()
      return
    }
    jeton = horloge.programmer(() => {
      jeton = null
      maintenant()
    }, reste)
  }

  return { demander, maintenant, arreter }
}
