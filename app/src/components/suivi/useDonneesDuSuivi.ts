/**
 * Les données du suivi en direct, et la boucle qui les tient à jour — partagées
 * par le tableau de suivi (`SuiviView.vue`) et sa maquette 3D (`MondeView.vue`).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Les mêmes lectures, une seule fois écrites.** Deux écrans qui interrogent
 * l'API chacun à sa façon finissent par ne plus dire la même chose ; celui-ci
 * est le seul endroit qui décide quoi lire et à quelle cadence.
 *
 * - la sonde `/api/sante` à chaque tour (sa latence est une mesure en soi) ;
 * - le journal, la file « À traiter », les comptes et les invitations au plus
 *   toutes les `CADENCE_JOURNAL_MS` ;
 * - le chantier, seulement quand l'écran le demande (`avecChantier`), au plus
 *   une fois par minute.
 *
 * La boucle se suspend quand l'onglet du navigateur est caché et repart à son
 * retour ; elle s'arrête au démontage. Rien ne démarre hors navigateur : sous
 * le rendu serveur des tests, `onMounted` ne s'exécute pas.
 * ────────────────────────────────────────────────────────────────────
 */

import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  chargerChantier,
  compterUtilisateurs,
  lireJournal,
  listerDemandesAdministration,
  listerDemandesRgpd,
  listerInvitations,
  listerRattachements,
  sonderSante,
  type Chantier,
  type CompteARattacher,
  type CompteursUtilisateurs,
  type Demande,
  type DemandeRgpdAdministration,
  type LigneDuJournal,
  type MesureDeSante,
} from '@/lib/api'
import { CADENCE_CHANTIER_MS, CADENCE_JOURNAL_MS, MESURES_GARDEES, depuis, etatGlobal, fraicheur } from './suivi'

export interface OptionsDuSuivi {
  /** Le délai avant le tour suivant, relu à chaque tour (il dépend de l'onglet). */
  cadence: () => number
  /** Lire aussi le chantier à ce tour-ci ? */
  avecChantier?: () => boolean
  /** Ce que l'écran ajoute à chaque tour (l'historique du suivi, par exemple). */
  enPlus?: (force: boolean) => Promise<void> | void
}

export function useDonneesDuSuivi(options: OptionsDuSuivi) {
  const maintenant = ref(Date.now())
  const mesures = ref<MesureDeSante[]>([])
  const derniereSante = computed(() => mesures.value.at(-1) ?? null)
  /** La dernière fois que le serveur a répondu, base comprise. */
  const derniereReussite = ref<number | null>(null)

  const journal = ref<LigneDuJournal[]>([])
  const journalTotal = ref<number | null>(null)
  const echecs = ref<LigneDuJournal[]>([])
  const appels = ref<LigneDuJournal[]>([])
  const appelsTotal = ref<number | null>(null)

  const demandes = ref<Demande[]>([])
  const demandesTotal = ref<number | null>(null)
  const rattachements = ref<CompteARattacher[] | null>(null)
  const rgpd = ref<DemandeRgpdAdministration[] | null>(null)
  const comptes = ref<CompteursUtilisateurs | null>(null)
  const invitationsActives = ref<number | null>(null)
  const chantier = ref<Chantier | null>(null)

  /** Une erreur par source : une lecture qui échoue n'éteint pas les autres. */
  const erreurs = ref<Record<string, unknown>>({})

  let dernierJournal = 0
  let dernierChantier = 0

  function noter(source: string, r: PromiseSettledResult<unknown>): boolean {
    const suivantes = { ...erreurs.value }
    if (r.status === 'rejected') suivantes[source] = r.reason
    else delete suivantes[source]
    erreurs.value = suivantes
    return r.status === 'fulfilled'
  }

  async function lireLeJournal(): Promise<void> {
    const [recents, rates, ia, file, ratt, demRgpd, cpt, inv] = await Promise.allSettled([
      lireJournal({ parPage: 100 }),
      lireJournal({ action: 'connexion_echouee', parPage: 100 }),
      lireJournal({ action: 'appel_modele_langage', parPage: 100 }),
      listerDemandesAdministration({ status: 'en_attente', tri: 'anciennes', parPage: 8 }),
      listerRattachements(),
      listerDemandesRgpd(),
      compterUtilisateurs(),
      listerInvitations({ status: 'active', parPage: 1 }),
    ])
    if (noter('journal', recents) && recents.status === 'fulfilled') {
      journal.value = recents.value.elements
      journalTotal.value = recents.value.total
    }
    if (noter('echecs', rates) && rates.status === 'fulfilled') echecs.value = rates.value.elements
    if (noter('ia', ia) && ia.status === 'fulfilled') {
      appels.value = ia.value.elements
      appelsTotal.value = ia.value.total
    }
    if (noter('file', file) && file.status === 'fulfilled') {
      demandes.value = file.value.elements
      demandesTotal.value = file.value.total
    }
    // Sous une vue empruntée, rattachements et RGPD rendent 409 : ils comptent
    // alors pour « — », comme dans « À traiter » qui ne les montre pas.
    rattachements.value = ratt.status === 'fulfilled' ? ratt.value.elements : null
    rgpd.value = demRgpd.status === 'fulfilled' ? demRgpd.value.elements.filter((d) => d.decision === null) : null
    if (noter('comptes', cpt) && cpt.status === 'fulfilled') comptes.value = cpt.value
    if (noter('invitations', inv) && inv.status === 'fulfilled') invitationsActives.value = inv.value.total
  }

  async function lireLeChantier(): Promise<void> {
    const [r] = await Promise.allSettled([chargerChantier('tout')])
    if (noter('chantier', r) && r.status === 'fulfilled') chantier.value = r.value
  }

  async function rafraichir(force = false): Promise<void> {
    const t = Date.now()
    const taches: Promise<void>[] = [
      sonderSante().then((m) => {
        mesures.value = [...mesures.value, m].slice(-MESURES_GARDEES)
        if (m.ok) derniereReussite.value = m.le
      }),
    ]
    if (force || t - dernierJournal >= CADENCE_JOURNAL_MS) {
      dernierJournal = t
      taches.push(lireLeJournal())
    }
    if (options.avecChantier?.() && (force || t - dernierChantier >= CADENCE_CHANTIER_MS)) {
      dernierChantier = t
      taches.push(lireLeChantier())
    }
    const enPlus = options.enPlus?.(force)
    if (enPlus) taches.push(enPlus)
    await Promise.allSettled(taches)
    maintenant.value = Date.now()
  }

  /* ───── La boucle : planifiée, suspendue quand l'onglet est caché ───── */

  let minuterie: ReturnType<typeof setTimeout> | undefined
  let actif = false

  function planifier(): void {
    clearTimeout(minuterie)
    if (!actif || document.hidden) return
    minuterie = setTimeout(() => void relancer(), options.cadence())
  }

  /** Un tour tout de suite, puis la boucle reprend sa cadence. */
  async function relancer(force = false): Promise<void> {
    clearTimeout(minuterie)
    await rafraichir(force)
    planifier()
  }

  function surVisibilite(): void {
    if (document.hidden) clearTimeout(minuterie)
    else void relancer()
  }

  /** L'horloge de « il y a 12 s » : elle tourne même quand la sonde attend. */
  let horloge: ReturnType<typeof setInterval> | undefined

  onMounted(() => {
    actif = true
    document.addEventListener('visibilitychange', surVisibilite)
    horloge = setInterval(() => (maintenant.value = Date.now()), 1000)
    void relancer(true)
  })

  onBeforeUnmount(() => {
    actif = false
    clearTimeout(minuterie)
    clearInterval(horloge)
    document.removeEventListener('visibilitychange', surVisibilite)
  })

  /* ───── Ce que les deux écrans affichent pareil ───── */

  const echecs24h = computed(() => depuis(echecs.value, 24, maintenant.value).length)
  const appels24h = computed(() => depuis(appels.value, 24, maintenant.value).length)

  const aTraiter = computed<number | null>(() => {
    if (demandesTotal.value === null) return null
    return demandesTotal.value + (rattachements.value?.length ?? 0) + (rgpd.value?.length ?? 0)
  })

  const etat = computed(() =>
    etatGlobal({ sante: derniereSante.value, aTraiter: aTraiter.value, connexionsEchouees: echecs24h.value }),
  )

  const enLigne = computed(() => derniereSante.value !== null && derniereSante.value.ok)
  const texteFraicheur = computed(() => fraicheur(derniereReussite.value, maintenant.value))

  const latences = computed(() => mesures.value.map((m) => (m.ok ? m.latenceMs : 0)))
  const disponibilite = computed(() => {
    if (mesures.value.length === 0) return null
    return Math.round((100 * mesures.value.filter((m) => m.ok).length) / mesures.value.length)
  })
  const latenceMediane = computed(() => {
    const ok = mesures.value
      .filter((m) => m.ok)
      .map((m) => m.latenceMs)
      .sort((a, b) => a - b)
    return ok.length === 0 ? null : ok[Math.floor(ok.length / 2)]!
  })

  const erreurPrincipale = computed(() => erreurs.value.journal ?? erreurs.value.file ?? null)

  return {
    maintenant,
    mesures,
    derniereSante,
    derniereReussite,
    journal,
    journalTotal,
    echecs,
    appels,
    appelsTotal,
    demandes,
    demandesTotal,
    rattachements,
    rgpd,
    comptes,
    invitationsActives,
    chantier,
    erreurs,
    relancer,
    echecs24h,
    appels24h,
    aTraiter,
    etat,
    enLigne,
    texteFraicheur,
    latences,
    disponibilite,
    latenceMediane,
    erreurPrincipale,
  }
}
