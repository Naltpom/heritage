<script setup lang="ts">
/**
 * **Suivi en direct** — `/administration/suivi`, administration seulement.
 *
 * Un écran plein cadre, sombre et lisible de loin (un second écran, un
 * téléphone posé), qui dit en un coup d'œil si l'application répond, ce qui
 * attend une décision et ce qui vient de se passer.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Lecture seule, et sans route nouvelle.** Tout vient de routes qui
 * existaient : la sonde `/api/sante`, le journal d'audit, les compteurs de
 * l'administration et le chantier (voir l'en-tête de `lib/api/suivi.ts`).
 * L'écran ne décide rien : chaque chiffre mène à l'écran où l'on décide.
 *
 * **Pas de fausse barre de progression.** L'API répond en synchrone : il n'y a
 * pas de tâche « en cours » à suivre. Ce qui en tient lieu, honnêtement : la
 * file « À traiter » (ce qui attend un humain) et le journal (ce qui vient
 * d'avoir lieu), appels au modèle de langage compris.
 *
 * **Cadences.** La sonde toutes les 2 s sur « En direct », 10 s ailleurs ; le
 * journal et les compteurs toutes les 10 s ; le chantier une fois par minute,
 * et seulement sur « Statistiques ». Tout s'arrête quand l'onglet du
 * navigateur est caché, et repart à son retour.
 *
 * `cadre: false` : ni en-tête applicatif ni barre du bas. L'écran a son propre
 * en-tête, et sa sortie (« Administration ») y est toujours visible.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ACTIONS_DU_JOURNAL, lireJournal, type LigneDuJournal } from '@/lib/api'
import Barres from '@/components/statistiques/Barres.vue'
import { moisLisible } from '@/components/statistiques/format'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PaginationListe from '@/components/base/PaginationListe.vue'
import CodeQr from '@/components/suivi/CodeQr.vue'
import { useDonneesDuSuivi } from '@/components/suivi/useDonneesDuSuivi'
import {
  CADENCE_AUTRES_MS,
  CADENCE_DIRECT_MS,
  CADENCE_JOURNAL_MS,
  activiteParJour,
  couleurDe,
  detailsDAppel,
  elementsEnAttente,
  familleDe,
  filtrerTexte,
  heureCourte,
  libelleAction,
  modelesAppeles,
  natureDAppel,
  pointsDeCourbe,
  repartition,
} from '@/components/suivi/suivi'

/* ───────────────── Onglets ───────────────── */

type Onglet = 'direct' | 'historique' | 'entites' | 'stats' | 'telephone'
const ONGLETS: { cle: Onglet; libelle: string }[] = [
  { cle: 'direct', libelle: 'En direct' },
  { cle: 'historique', libelle: 'Historique' },
  { cle: 'entites', libelle: 'Comptes et modèles' },
  { cle: 'stats', libelle: 'Statistiques' },
  { cle: 'telephone', libelle: 'Téléphone' },
]

const route = useRoute()
const router = useRouter()

function ongletDeLaRoute(): Onglet {
  const q = route.query.onglet
  return ONGLETS.some((o) => o.cle === q) ? (q as Onglet) : 'direct'
}
const onglet = ref<Onglet>(ongletDeLaRoute())

function choisir(o: Onglet): void {
  onglet.value = o
  void router.replace({ query: { ...route.query, onglet: o === 'direct' ? undefined : o } })
}

/** Flèches gauche/droite entre onglets (motif ARIA « tablist »). */
function clavierOnglets(e: KeyboardEvent): void {
  if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
  const i = ONGLETS.findIndex((o) => o.cle === onglet.value)
  const j = (i + (e.key === 'ArrowRight' ? 1 : ONGLETS.length - 1)) % ONGLETS.length
  choisir(ONGLETS[j]!.cle)
  document.getElementById(`onglet-${ONGLETS[j]!.cle}`)?.focus()
}

/* ───────────────── L'état chargé (partagé avec le Monde) ───────────────── */

const {
  maintenant,
  mesures,
  derniereSante,
  journal,
  journalTotal,
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
  appels24h,
  aTraiter,
  etat,
  enLigne,
  texteFraicheur,
  latences,
  disponibilite,
  latenceMediane,
  erreurPrincipale,
} = useDonneesDuSuivi({
  cadence: () => (onglet.value === 'direct' ? CADENCE_DIRECT_MS : CADENCE_AUTRES_MS),
  avecChantier: () => onglet.value === 'stats',
  // L'historique ne se rafraîchit seul que sur sa première page, détail fermé :
  // une ligne qui glisse sous le doigt pendant qu'on la lit est pire qu'un retard.
  enPlus: (force) => {
    if (onglet.value !== 'historique' || hPage.value !== 1 || detail.value !== null) return
    if (force || Date.now() - dernierHistorique >= CADENCE_JOURNAL_MS) return chargerHistorique(true)
  },
})

watch(onglet, () => void relancer())

/* ───────────────── En direct ───────────────── */

const maintenantDate = computed(() => new Date(maintenant.value))
const dernieres = computed(() => journal.value.slice(0, 10))

/** Depuis quand un élément attend : « 3 j », « 5 h », « 12 min ». */
function attente(iso: string | null): string {
  if (iso === null) return ''
  const s = (maintenant.value - new Date(iso).getTime()) / 1000
  if (s < 3600) return `${Math.max(1, Math.round(s / 60))} min`
  if (s < 86_400) return `${Math.round(s / 3600)} h`
  return `${Math.round(s / 86_400)} j`
}

const file = computed(() => elementsEnAttente(demandes.value, rattachements.value, rgpd.value).slice(0, 8))

/* ───────────────── Historique ───────────────── */

const hAction = ref('')
const hActeur = ref('')
const hType = ref('')
const hTexte = ref('')
const hPage = ref(1)
const H_PAR_PAGE = 50
const historique = ref<LigneDuJournal[]>([])
const hTotal = ref(0)
const hChargement = ref(false)
const hErreur = ref<unknown>(null)
const detail = ref<LigneDuJournal | null>(null)
let dernierHistorique = 0

/** Les acteurs et les types vus dans la fenêtre récente : de quoi remplir les listes. */
const acteursConnus = computed(() => {
  const m = new Map<number, string>()
  for (const l of journal.value) if (l.actorUserId !== null) m.set(l.actorUserId, l.actorEmail ?? `compte n° ${l.actorUserId}`)
  return [...m.entries()].sort((a, b) => a[1].localeCompare(b[1]))
})
const typesConnus = computed(() => [...new Set(journal.value.map((l) => l.subjectType))].sort())

async function chargerHistorique(silencieux = false): Promise<void> {
  dernierHistorique = Date.now()
  if (!silencieux) hChargement.value = true
  try {
    const r = await lireJournal({
      action: hAction.value,
      actorUserId: hActeur.value === '' ? undefined : Number(hActeur.value),
      subjectType: hType.value,
      page: hPage.value,
      parPage: H_PAR_PAGE,
    })
    historique.value = r.elements
    hTotal.value = r.total
    hErreur.value = null
  } catch (e) {
    hErreur.value = e
  } finally {
    hChargement.value = false
  }
}

const historiqueFiltre = computed(() => filtrerTexte(historique.value, hTexte.value))

watch([hAction, hActeur, hType], () => {
  hPage.value = 1
  void chargerHistorique()
})
watch(hPage, () => void chargerHistorique())
watch(onglet, (o) => {
  if (o === 'historique' && historique.value.length === 0) void chargerHistorique()
})

function fermerDetail(): void {
  detail.value = null
}
function surEchap(e: KeyboardEvent): void {
  if (e.key === 'Escape' && detail.value !== null) fermerDetail()
}
onMounted(() => document.addEventListener('keydown', surEchap))
onBeforeUnmount(() => document.removeEventListener('keydown', surEchap))

/* ───────────────── Statistiques ───────────────── */

const JOURS = 14
const parJour = computed(() => activiteParJour(journal.value, JOURS, maintenantDate.value))
const parFamille = computed(() => repartition(journal.value))
const maxFamille = computed(() => Math.max(1, ...parFamille.value.map((f) => f.n)))
/** La fenêtre sur laquelle portent les graphiques du journal — dite à l'écran. */
const fenetre = computed(() => {
  const plus = journal.value.at(-1)
  return plus ? heureCourte(plus.occurredAt, maintenantDate.value) : null
})

const demandesParMois = computed(() => {
  const mois = chantier.value?.activite.demandesParMois ?? []
  return {
    categories: mois.map((m) => moisLisible(m.mois)),
    series: [
      { nom: 'Proposées', valeurs: mois.map((m) => m.proposees), couleur: 'var(--h-accent)' },
      { nom: 'Validées', valeurs: mois.map((m) => m.validees), couleur: 'var(--h-ok)' },
    ],
  }
})

const FICHES: { cle: 'sansDateDeNaissance' | 'sansParents' | 'sansSource' | 'nonVerifiees'; libelle: string }[] = [
  { cle: 'sansDateDeNaissance', libelle: 'Sans date de naissance' },
  { cle: 'sansParents', libelle: 'Sans parents' },
  { cle: 'sansSource', libelle: 'Sans source' },
  { cle: 'nonVerifiees', libelle: 'Non vérifiées' },
]

/* ───────────────── Comptes et modèles ───────────────── */

const modeles = computed(() => modelesAppeles(appels.value))

/* ───────────────── Téléphone ───────────────── */

const adresse = ref('')
const adresseLocale = ref(false)
const copie = ref<'non' | 'oui' | 'refuse'>('non')
onMounted(() => {
  adresse.value = `${window.location.origin}${router.resolve({ name: 'administration.suivi' }).href}`
  adresseLocale.value = ['localhost', '127.0.0.1', '[::1]'].includes(window.location.hostname)
})

async function copier(): Promise<void> {
  try {
    await navigator.clipboard.writeText(adresse.value)
    copie.value = 'oui'
  } catch {
    // Presse-papiers refusé (contexte non sécurisé) : l'adresse est affichée en
    // entier et sélectionnable, c'est le chemin de repli.
    copie.value = 'refuse'
  }
}

/* ───────────────── Petits outils de gabarit ───────────────── */

const nombre = (n: number | null | undefined): string => (n === null || n === undefined ? '—' : n.toLocaleString('fr-FR'))
const ACTIONS_TRIEES = [...ACTIONS_DU_JOURNAL].sort((a, b) => libelleAction(a).localeCompare(libelleAction(b)))
</script>

<template>
  <div class="suivi min-h-dvh bg-fond text-texte" data-suivi>
    <!-- En-tête fixe : nom, état, connexion, compteurs clés. -->
    <header class="sticky top-0 z-20 border-b border-ligne bg-fond/95 backdrop-blur">
      <div class="mx-auto flex max-w-[1400px] flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3 md:px-6">
        <div class="flex min-w-0 items-center gap-3">
          <h1 class="text-lg font-bold tracking-[-.01em] md:text-xl">Heritage <span class="text-attenue">· Suivi</span></h1>
          <span class="pastille" :class="`pastille--${etat.niveau}`" role="status" data-etat-global :data-niveau="etat.niveau">
            <span class="pastille__point" aria-hidden="true" />
            {{ etat.libelle }}
          </span>
        </div>

        <p class="text-sm text-attenue" data-connexion>
          <span v-if="enLigne">Connecté · mis à jour {{ texteFraicheur }}</span>
          <span v-else-if="derniereSante === null">Connexion…</span>
          <span v-else class="text-alerte">Hors ligne · dernière réponse {{ texteFraicheur }} · nouvel essai automatique</span>
        </p>

        <dl class="ml-auto flex flex-wrap gap-x-5 gap-y-1 text-sm">
          <div class="compteur">
            <dt>À traiter</dt>
            <dd data-compteur="a-traiter">{{ nombre(aTraiter) }}</dd>
          </div>
          <div class="compteur">
            <dt>Comptes actifs</dt>
            <dd>{{ nombre(comptes?.actif) }}</dd>
          </div>
          <div class="compteur">
            <dt>Appels IA 24 h</dt>
            <dd>{{ nombre(appels24h) }}</dd>
          </div>
          <div class="compteur">
            <dt>Latence</dt>
            <dd>{{ derniereSante?.ok ? `${derniereSante.latenceMs} ms` : '—' }}</dd>
          </div>
        </dl>

        <div class="flex gap-2">
          <RouterLink
            :to="{ name: 'administration.monde' }"
            class="inline-flex min-h-10 items-center rounded-champ border border-ligne px-3 text-sm hover:border-accent"
            data-vers-monde
          >
            Monde 3D
          </RouterLink>
          <RouterLink
            :to="{ name: 'administration.a-traiter' }"
            class="inline-flex min-h-10 items-center rounded-champ border border-ligne px-3 text-sm hover:border-accent"
          >
            ‹ Administration
          </RouterLink>
        </div>
      </div>

      <nav class="mx-auto max-w-[1400px] overflow-x-auto px-4 md:px-6" aria-label="Sections du suivi">
        <div role="tablist" class="flex gap-1" @keydown="clavierOnglets">
          <button
            v-for="o in ONGLETS"
            :id="`onglet-${o.cle}`"
            :key="o.cle"
            type="button"
            role="tab"
            :aria-selected="onglet === o.cle"
            :aria-controls="`panneau-${o.cle}`"
            :tabindex="onglet === o.cle ? 0 : -1"
            class="onglet"
            @click="choisir(o.cle)"
          >
            {{ o.libelle }}
          </button>
        </div>
      </nav>
    </header>

    <main class="mx-auto max-w-[1400px] px-4 py-5 md:px-6">
      <MessageErreur
        v-if="erreurPrincipale !== null"
        :erreur="erreurPrincipale"
        titre="Une partie du suivi n’a pas pu être lue"
        class="mb-4"
        @action="relancer(true)"
      />

      <!-- ═════════════ En direct ═════════════ -->
      <section v-if="onglet === 'direct'" id="panneau-direct" role="tabpanel" aria-labelledby="onglet-direct" class="grille">
        <!-- Santé : la sonde, sa courbe, sa disponibilité. -->
        <article class="carte">
          <h2 class="carte__titre">Santé du service</h2>
          <div class="grid grid-cols-3 gap-3">
            <div class="jauge">
              <span class="jauge__libelle">Base de données</span>
              <span class="jauge__valeur" :class="{ 'text-alerte': derniereSante !== null && !derniereSante.ok }">
                {{ derniereSante === null ? '—' : derniereSante.ok ? 'OK' : 'KO' }}
              </span>
            </div>
            <div class="jauge">
              <span class="jauge__libelle">Latence médiane</span>
              <span class="jauge__valeur">{{ latenceMediane === null ? '—' : `${latenceMediane} ms` }}</span>
            </div>
            <div class="jauge">
              <span class="jauge__libelle">Disponibilité</span>
              <span class="jauge__valeur">{{ disponibilite === null ? '—' : `${disponibilite} %` }}</span>
            </div>
          </div>
          <svg
            v-if="latences.length > 1"
            class="mt-3 h-14 w-full"
            viewBox="0 0 300 56"
            preserveAspectRatio="none"
            role="img"
            :aria-label="`Latence des ${latences.length} dernières sondes`"
          >
            <polyline :points="pointsDeCourbe(latences, 300, 56)" fill="none" stroke="var(--h-accent)" stroke-width="2" vector-effect="non-scaling-stroke" />
          </svg>
          <p class="mt-2 text-xs text-attenue">
            Sonde <code>/api/sante</code> toutes les {{ CADENCE_DIRECT_MS / 1000 }} s, aller-retour du navigateur compris ·
            {{ mesures.length }} mesure{{ mesures.length > 1 ? 's' : '' }} gardée{{ mesures.length > 1 ? 's' : '' }}.
          </p>
        </article>

        <!-- La file : ce qui attend une décision humaine. -->
        <article class="carte">
          <h2 class="carte__titre">
            File d’attente
            <span class="text-attenue">· {{ nombre(aTraiter) }}</span>
          </h2>
          <dl class="mb-3 grid grid-cols-3 gap-3">
            <div class="jauge">
              <dt class="jauge__libelle">Demandes</dt>
              <dd class="jauge__valeur">{{ nombre(demandesTotal) }}</dd>
            </div>
            <div class="jauge">
              <dt class="jauge__libelle">Inscriptions</dt>
              <dd class="jauge__valeur">{{ rattachements === null ? '—' : rattachements.length }}</dd>
            </div>
            <div class="jauge">
              <dt class="jauge__libelle">RGPD</dt>
              <dd class="jauge__valeur">{{ rgpd === null ? '—' : rgpd.length }}</dd>
            </div>
          </dl>
          <p v-if="file.length === 0 && aTraiter !== null" class="vide">Rien n’attend de décision.</p>
          <ul v-else class="flex flex-col divide-y divide-ligne">
            <li v-for="e in file" :key="e.cle">
              <RouterLink
                :to="{ name: 'administration.a-traiter', query: { sel: e.sel } }"
                class="flex items-baseline gap-3 py-2 hover:text-accent"
              >
                <span class="w-14 shrink-0 text-right font-mono text-sm text-attenue">{{ attente(e.depuis) }}</span>
                <span class="min-w-0 flex-1">
                  <span class="block text-xs text-attenue">{{ e.nature }}</span>
                  <span class="block truncate">{{ e.libelle }}</span>
                </span>
              </RouterLink>
            </li>
          </ul>
        </article>

        <!-- Ce qui vient d'avoir lieu. -->
        <article class="carte lg:col-span-2">
          <h2 class="carte__titre">10 dernières actions</h2>
          <p v-if="dernieres.length === 0 && journalTotal !== null" class="vide">Le journal est vide.</p>
          <ul class="flex flex-col divide-y divide-ligne">
            <li v-for="l in dernieres" :key="l.id" class="flex items-baseline gap-3 py-2" data-ligne-recente>
              <span class="horodate">{{ heureCourte(l.occurredAt, maintenantDate) }}</span>
              <span class="etiquette" :style="{ '--teinte': couleurDe(familleDe(l.action)) }">{{ libelleAction(l.action) }}</span>
              <span class="min-w-0 flex-1 truncate">
                {{ l.subjectLabel ?? l.subjectType }}
                <span class="text-attenue">· {{ l.actorEmail ?? 'système' }}</span>
              </span>
            </li>
          </ul>
        </article>

        <!-- Les appels au modèle de langage : ce qui ressemble le plus à des « tâches ». -->
        <article class="carte lg:col-span-2">
          <h2 class="carte__titre">
            Derniers appels au modèle de langage
            <span class="text-attenue">· {{ nombre(appels24h) }} en 24 h</span>
          </h2>
          <p v-if="appels.length === 0 && appelsTotal !== null" class="vide">Aucun appel consigné.</p>
          <ul class="flex flex-col divide-y divide-ligne">
            <li v-for="a in appels.slice(0, 5)" :key="a.id" class="flex items-baseline gap-3 py-2">
              <span class="horodate">{{ heureCourte(a.occurredAt, maintenantDate) }}</span>
              <span class="min-w-0 flex-1">
                <span class="font-medium">{{ natureDAppel(a.subjectType) }}</span>
                <span class="ml-2 font-mono text-sm text-attenue">{{ a.subjectLabel ?? '' }}</span>
                <span class="block text-xs text-attenue">{{ detailsDAppel(a.changes).join(' · ') }}</span>
              </span>
            </li>
          </ul>
        </article>
      </section>

      <!-- ═════════════ Historique ═════════════ -->
      <section v-else-if="onglet === 'historique'" id="panneau-historique" role="tabpanel" aria-labelledby="onglet-historique" class="flex flex-col gap-4">
        <div class="carte flex flex-wrap items-end gap-3">
          <label class="champ">
            <span>Recherche</span>
            <input v-model="hTexte" type="search" placeholder="courriel, sujet, IP…" />
          </label>
          <label class="champ">
            <span>Action</span>
            <select v-model="hAction">
              <option value="">Toutes</option>
              <option v-for="a in ACTIONS_TRIEES" :key="a" :value="a">{{ libelleAction(a) }}</option>
            </select>
          </label>
          <label class="champ">
            <span>Compte</span>
            <select v-model="hActeur">
              <option value="">Tous</option>
              <option v-for="[id, courriel] in acteursConnus" :key="id" :value="String(id)">{{ courriel }}</option>
            </select>
          </label>
          <label class="champ">
            <span>Type de sujet</span>
            <select v-model="hType">
              <option value="">Tous</option>
              <option v-for="t in typesConnus" :key="t" :value="t">{{ t }}</option>
            </select>
          </label>
          <p class="ml-auto text-sm text-attenue" data-total-historique>
            {{ nombre(hTotal) }} ligne{{ hTotal > 1 ? 's' : '' }}
            <template v-if="hTexte.trim() !== ''"> · {{ historiqueFiltre.length }} sur cette page après recherche</template>
          </p>
        </div>

        <MessageErreur v-if="hErreur !== null" :erreur="hErreur" @action="chargerHistorique()" />

        <div class="carte overflow-x-auto p-0">
          <table class="tableau">
            <thead>
              <tr>
                <th scope="col">Quand</th>
                <th scope="col">Action</th>
                <th scope="col">Sujet</th>
                <th scope="col">Compte</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!hChargement && historiqueFiltre.length === 0">
                <td colspan="4" class="vide">Aucune ligne ne correspond.</td>
              </tr>
              <tr
                v-for="l in historiqueFiltre"
                :key="l.id"
                tabindex="0"
                class="cursor-pointer"
                :aria-label="`Détail de la ligne ${l.id}`"
                @click="detail = l"
                @keydown.enter="detail = l"
              >
                <td class="font-mono text-sm whitespace-nowrap text-attenue">{{ heureCourte(l.occurredAt, maintenantDate) }}</td>
                <td><span class="etiquette" :style="{ '--teinte': couleurDe(familleDe(l.action)) }">{{ libelleAction(l.action) }}</span></td>
                <td class="max-w-[38ch] truncate">
                  {{ l.subjectLabel ?? '—' }} <span class="text-xs text-attenue">{{ l.subjectType }}{{ l.subjectId ? ` #${l.subjectId}` : '' }}</span>
                </td>
                <td class="max-w-[28ch] truncate">{{ l.actorEmail ?? 'système' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <PaginationListe :page="hPage" :par-page="H_PAR_PAGE" :total="hTotal" :chargement="hChargement" @aller="(p: number) => (hPage = p)" />
      </section>

      <!-- ═════════════ Comptes et modèles ═════════════ -->
      <section v-else-if="onglet === 'entites'" id="panneau-entites" role="tabpanel" aria-labelledby="onglet-entites" class="grille">
        <article class="carte">
          <h2 class="carte__titre">Comptes</h2>
          <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="jauge"><dt class="jauge__libelle">Tous</dt><dd class="jauge__valeur">{{ nombre(comptes?.tous) }}</dd></div>
            <div class="jauge"><dt class="jauge__libelle">Actifs</dt><dd class="jauge__valeur">{{ nombre(comptes?.actif) }}</dd></div>
            <div class="jauge"><dt class="jauge__libelle">En attente</dt><dd class="jauge__valeur">{{ nombre(comptes?.en_attente) }}</dd></div>
            <div class="jauge"><dt class="jauge__libelle">Suspendus</dt><dd class="jauge__valeur">{{ nombre(comptes?.suspendu) }}</dd></div>
            <div class="jauge"><dt class="jauge__libelle">Administrateurs</dt><dd class="jauge__valeur">{{ nombre(comptes?.admins) }}</dd></div>
            <div class="jauge"><dt class="jauge__libelle">Liens d’invitation actifs</dt><dd class="jauge__valeur">{{ nombre(invitationsActives) }}</dd></div>
          </dl>
          <p class="mt-3 text-sm text-attenue">
            Cet écran ne modifie rien. Activer, suspendre ou changer un rôle se fait dans
            <RouterLink :to="{ name: 'administration.utilisateurs' }" class="text-accent underline">Utilisateurs</RouterLink>,
            un lien se crée ou se révoque dans
            <RouterLink :to="{ name: 'administration.invitations' }" class="text-accent underline">Invitations</RouterLink>.
          </p>
        </article>

        <article class="carte">
          <h2 class="carte__titre">Modèles de langage appelés</h2>
          <p v-if="modeles.length === 0" class="vide">Aucun appel dans les {{ appels.length }} dernières lignes.</p>
          <ul class="flex flex-col divide-y divide-ligne">
            <li v-for="m in modeles" :key="m.modele" class="py-2">
              <div class="flex items-baseline justify-between gap-3">
                <span class="font-mono">{{ m.modele }}</span>
                <span class="font-mono text-lg">{{ m.n }}</span>
              </div>
              <p class="text-xs text-attenue">{{ m.natures.join(' · ') }}</p>
            </li>
          </ul>
          <p class="mt-3 text-xs text-attenue">
            Sur les {{ appels.length }} derniers appels ({{ nombre(appelsTotal) }} au total). Le journal ne garde que des
            comptes : jamais le contenu envoyé, jamais la réponse, jamais la clé.
          </p>
        </article>
      </section>

      <!-- ═════════════ Statistiques ═════════════ -->
      <section v-else-if="onglet === 'stats'" id="panneau-stats" role="tabpanel" aria-labelledby="onglet-stats" class="flex flex-col gap-4">
        <MessageErreur v-if="erreurs.chantier" :erreur="erreurs.chantier" titre="Le chantier n’a pas pu être lu" @action="relancer(true)" />

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
          <div class="carte kpi">
            <span class="kpi__valeur">{{ nombre(chantier?.activite.demandesEnAttente) }}</span>
            <span class="kpi__libelle">Demandes en attente</span>
          </div>
          <div class="carte kpi">
            <span class="kpi__valeur">{{ nombre(chantier?.activite.membres.actifs) }}</span>
            <span class="kpi__libelle">Membres actifs</span>
          </div>
          <div class="carte kpi">
            <span class="kpi__valeur">{{ nombre(chantier?.activite.membres.connectesRecemment) }}</span>
            <span class="kpi__libelle">Connectés sur {{ chantier?.activite.membres.jours ?? '—' }} jours</span>
          </div>
          <div class="carte kpi">
            <span class="kpi__valeur">{{ nombre(chantier?.activite.actesEnAttente) }}</span>
            <span class="kpi__libelle">Actes en attente</span>
          </div>
        </div>

        <div class="grille">
          <article class="carte">
            <h2 class="carte__titre">Demandes par mois</h2>
            <p v-if="chantier === null" class="vide">Chargement du chantier…</p>
            <Barres
              v-else-if="demandesParMois.categories.length > 0"
              titre="Demandes proposées et validées, par mois"
              :categories="demandesParMois.categories"
              :series="demandesParMois.series"
              :hauteur-px="180"
            />
            <p v-else class="vide">Aucune demande encore.</p>
          </article>

          <article class="carte">
            <h2 class="carte__titre">Activité du journal, par jour</h2>
            <p v-if="journal.length === 0" class="vide">Le journal est vide.</p>
            <Barres
              v-else
              titre="Lignes du journal par jour, sur les quatorze derniers jours"
              :categories="parJour.map((j) => j.libelle)"
              :series="[{ nom: 'Lignes', valeurs: parJour.map((j) => j.n), couleur: 'var(--h-accent)' }]"
              :hauteur-px="180"
            />
            <p v-if="journal.length > 0" class="mt-2 text-xs text-attenue">
              Sur les {{ journal.length }} dernières lignes<template v-if="fenetre"> (depuis {{ fenetre }})</template>, pas sur tout le journal.
            </p>
          </article>

          <article class="carte">
            <h2 class="carte__titre">Répartition par famille d’actions</h2>
            <ul class="flex flex-col gap-2" role="list">
              <li v-for="f in parFamille" :key="f.famille" class="grid grid-cols-[10rem_1fr_3rem] items-center gap-3">
                <span class="text-sm">{{ f.libelle }}</span>
                <span class="h-3 rounded-full bg-ligne" aria-hidden="true">
                  <span class="block h-3 rounded-full" :style="{ width: `${(100 * f.n) / maxFamille}%`, background: f.couleur }" />
                </span>
                <span class="text-right font-mono">{{ f.n }}</span>
              </li>
            </ul>
          </article>

          <article class="carte">
            <h2 class="carte__titre">Fiches à compléter</h2>
            <dl class="grid grid-cols-2 gap-3">
              <div v-for="f in FICHES" :key="f.cle" class="jauge">
                <dt class="jauge__libelle">{{ f.libelle }}</dt>
                <dd class="jauge__valeur">{{ nombre(chantier?.fiches[f.cle].n) }}</dd>
              </div>
            </dl>
          </article>
        </div>
      </section>

      <!-- ═════════════ Téléphone ═════════════ -->
      <section v-else id="panneau-telephone" role="tabpanel" aria-labelledby="onglet-telephone" class="mx-auto flex max-w-md flex-col items-center gap-4">
        <div class="carte w-full">
          <div class="mx-auto w-full max-w-[280px]">
            <CodeQr v-if="adresse !== ''" :texte="adresse" libelle="Code QR de l’adresse du suivi" />
          </div>
          <p class="mt-4 font-mono text-sm break-all select-all" data-adresse>{{ adresse }}</p>
          <button
            type="button"
            class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-champ bg-accent px-4 font-medium text-fond"
            @click="copier"
          >
            {{ copie === 'oui' ? 'Lien copié' : 'Copier le lien' }}
          </button>
          <p v-if="copie === 'refuse'" class="mt-2 text-sm text-attenue">Le presse-papiers est refusé ici : sélectionnez l’adresse ci-dessus.</p>
        </div>
        <p class="text-sm text-attenue">
          Le code n’ouvre rien de plus que le site : sur le téléphone, la page demande de se connecter avec un compte
          administrateur, comme ici. Il est calculé dans le navigateur, aucune adresse ne part chez un tiers.
        </p>
        <p v-if="adresseLocale" class="text-sm text-alerte">
          Cette adresse est locale à cet ordinateur : un téléphone ne la joindra pas. En développement, la pile n’écoute
          que sur 127.0.0.1 — c’est voulu (voir le README).
        </p>
      </section>
    </main>

    <!-- Détail d'une ligne de l'historique. -->
    <div v-if="detail !== null" class="fixed inset-0 z-30 flex justify-end bg-black/50" @click.self="fermerDetail">
      <aside class="h-full w-full max-w-lg overflow-y-auto border-l border-ligne bg-panneau p-5" role="dialog" aria-modal="true" aria-labelledby="titre-detail">
        <div class="mb-4 flex items-start justify-between gap-3">
          <h2 id="titre-detail" class="text-lg font-bold">{{ libelleAction(detail.action) }} <span class="text-attenue">#{{ detail.id }}</span></h2>
          <button type="button" class="min-h-10 rounded-champ border border-ligne px-3 text-sm" @click="fermerDetail">Fermer</button>
        </div>
        <dl class="detail">
          <dt>Quand</dt>
          <dd>{{ new Date(detail.occurredAt).toLocaleString('fr-FR') }}</dd>
          <dt>Compte</dt>
          <dd>{{ detail.actorEmail ?? 'système' }}<template v-if="detail.actorUserId !== null"> (n° {{ detail.actorUserId }})</template></dd>
          <template v-if="detail.impersonatorEmail">
            <dt>Incarné par</dt>
            <dd>{{ detail.impersonatorEmail }}</dd>
          </template>
          <dt>Sujet</dt>
          <dd>{{ detail.subjectLabel ?? '—' }} <span class="text-attenue">{{ detail.subjectType }}{{ detail.subjectId ? ` #${detail.subjectId}` : '' }}</span></dd>
          <dt>Adresse IP</dt>
          <dd class="font-mono">{{ detail.ip ?? '—' }}</dd>
          <dt>Navigateur</dt>
          <dd class="text-sm break-words">{{ detail.userAgent ?? '—' }}</dd>
          <dt>Changements</dt>
          <dd>
            <pre v-if="detail.changes !== null" class="overflow-x-auto rounded-champ bg-fond p-3 text-xs">{{ JSON.stringify(detail.changes, null, 2) }}</pre>
            <span v-else>—</span>
          </dd>
        </dl>
      </aside>
    </div>
  </div>
</template>

<style scoped>
/*
 * Le suivi est **toujours sombre** : c'est un écran qu'on lit de loin, souvent
 * dans une pièce peu éclairée. Les valeurs sont celles du thème sombre de
 * `main.css` — aucune couleur nouvelle —, un cran plus contrastées pour le fond.
 * Les utilitaires Tailwind lisent `var(--h-…)` (`@theme inline`) : les
 * redéfinir ici suffit. Les teintes dérivées (`-doux`, `-bord`) sont calculées
 * sur `:root` : elles sont redites ici, sinon elles garderaient la clarté.
 */
.suivi {
  --h-fond: #101113;
  --h-texte: #ece9e4;
  --h-panneau: #1b1d20;
  --h-ligne: #33363b;
  --h-attenue: #a39d95;
  --h-accent: #d9a066;
  --h-accent-doux: #3a2c1d;
  --h-alerte: #e08a5a;
  --h-ok: #7db38c;
  --h-edition: #a891f2;
  --h-homme-bord: #6d93bd;
  --h-alerte-doux: color-mix(in srgb, var(--h-alerte) 13%, transparent);
  --h-alerte-bord: color-mix(in srgb, var(--h-alerte) 35%, transparent);
  --h-ok-doux: color-mix(in srgb, var(--h-ok) 13%, transparent);
  --h-ok-bord: color-mix(in srgb, var(--h-ok) 35%, transparent);
  --h-ombre-panneau: 0 6px 22px rgb(0 0 0 / 0.4);
  color-scheme: dark;
  font-size: 16px;
}

.grille {
  display: grid;
  gap: 1rem;
  grid-template-columns: minmax(0, 1fr);
}
@media (min-width: 1024px) {
  .grille {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.carte {
  border: 1px solid var(--h-ligne);
  border-radius: 10px;
  background: var(--h-panneau);
  padding: 1rem 1.1rem;
}
.carte__titre {
  margin-bottom: 0.75rem;
  font-size: 0.8rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--h-attenue);
}

.jauge,
.kpi {
  display: flex;
  flex-direction: column-reverse;
  gap: 0.15rem;
}
.jauge {
  border-radius: 7px;
  background: var(--h-fond);
  padding: 0.6rem 0.75rem;
}
.jauge__valeur,
.kpi__valeur {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: clamp(1.15rem, 4.5vw, 1.6rem);
  line-height: 1.1;
  white-space: nowrap;
}
.kpi__valeur {
  font-size: 2.2rem;
}
.jauge__libelle,
.kpi__libelle {
  font-size: 0.8rem;
  color: var(--h-attenue);
}
.kpi {
  flex-direction: column;
}

/* L'heure d'une ligne : étroite sur téléphone (la date passe dessous), sur une ligne sur bureau. */
.horodate {
  flex-shrink: 0;
  width: 5.5rem;
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 0.85rem;
  color: var(--h-attenue);
}
@media (min-width: 768px) {
  .horodate {
    width: 7.5rem;
    white-space: nowrap;
  }
}

.compteur {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
}
.compteur dt {
  color: var(--h-attenue);
}
.compteur dd {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 1.05rem;
  font-weight: 600;
}

.pastille {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  border-radius: 999px;
  border: 1px solid var(--h-ligne);
  padding: 0.2rem 0.7rem;
  font-size: 0.85rem;
}
.pastille__point {
  width: 0.65rem;
  height: 0.65rem;
  border-radius: 999px;
  background: var(--h-attenue);
}
.pastille--ok .pastille__point {
  background: var(--h-ok);
  box-shadow: 0 0 0 4px var(--h-ok-doux);
}
.pastille--attention .pastille__point {
  background: var(--h-accent);
}
.pastille--panne {
  border-color: var(--h-alerte-bord);
  color: var(--h-alerte);
}
.pastille--panne .pastille__point {
  background: var(--h-alerte);
  animation: battement 1.2s ease-in-out infinite;
}
@keyframes battement {
  50% {
    opacity: 0.35;
  }
}
@media (prefers-reduced-motion: reduce) {
  .pastille--panne .pastille__point {
    animation: none;
  }
}

.onglet {
  min-height: 2.75rem;
  white-space: nowrap;
  border-bottom: 2px solid transparent;
  padding: 0 0.9rem;
  font-size: 0.95rem;
  color: var(--h-attenue);
}
.onglet[aria-selected='true'] {
  border-bottom-color: var(--h-accent);
  color: var(--h-texte);
  font-weight: 600;
}
.onglet:hover {
  color: var(--h-texte);
}

.etiquette {
  flex-shrink: 0;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--teinte) 45%, transparent);
  background: color-mix(in srgb, var(--teinte) 14%, transparent);
  padding: 0.05rem 0.55rem;
  font-size: 0.8rem;
  white-space: nowrap;
}

.vide {
  padding: 0.75rem 0;
  color: var(--h-attenue);
  font-style: italic;
}

.champ {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.8rem;
  color: var(--h-attenue);
}
.champ input,
.champ select {
  min-height: 2.5rem;
  min-width: 11rem;
  border: 1px solid var(--h-ligne);
  border-radius: 7px;
  background: var(--h-fond);
  padding: 0 0.6rem;
  font-size: 0.95rem;
  color: var(--h-texte);
}

.tableau {
  width: 100%;
  border-collapse: collapse;
}
.tableau th {
  border-bottom: 1px solid var(--h-ligne);
  padding: 0.6rem 0.9rem;
  text-align: left;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--h-attenue);
}
.tableau td {
  border-bottom: 1px solid var(--h-ligne);
  padding: 0.55rem 0.9rem;
}
.tableau tbody tr:hover,
.tableau tbody tr:focus-visible {
  background: var(--h-fond);
  outline: none;
}

.detail {
  display: grid;
  grid-template-columns: 7rem 1fr;
  gap: 0.6rem 1rem;
}
.detail dt {
  color: var(--h-attenue);
  font-size: 0.85rem;
}
</style>
