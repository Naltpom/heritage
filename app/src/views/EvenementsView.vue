<script setup lang="ts">
/**
 * **Les événements** — rassemblements et tirages au sort, dans une seule liste.
 *
 * Demande du commanditaire (02/10/2026) : « le tirage au sort c'est un
 * événement en soi ; tout fusionner serait plus simple pour les utilisateurs ».
 * Une entrée, une liste, un bouton « Créer un événement » qui demande le type.
 * Les pages de détail, elles, restent celles de chaque module : on ouvre un
 * rassemblement sur `evenement.detail`, un tirage sur `tirage.detail`.
 *
 * La fusion se fait **ici**, à la lecture : `GET /api/evenements` et
 * `GET /api/tirages` en parallèle, rangés par `fusionner` (ADR 0014, addendum
 * — les modules et leurs tables restent séparés). Chacun pour soi : une panne
 * des tirages n'empêche pas de voir les rassemblements, et la page le dit.
 *
 * Deux listes : **à venir** (en cours compris, et tous les tirages — ils
 * reviennent chaque année), le plus proche d'abord ; puis **passés et
 * annulés**, repliés — on vient ici pour ce qui arrive. Chaque ligne porte sa
 * pastille « Rassemblement » ou « Tirage au sort ». `?type=` restreint la
 * liste à une sorte (l'ancienne adresse `/tirages` y mène, filtrée).
 *
 * Ce qu'on y voit : les rassemblements où l'on est invité ou qu'on organise
 * (tous pour un administrateur) ; les tirages dont on est, qu'on organise, ou
 * ouverts à la famille — ceux-là avec « Rejoindre ». **Rien n'y nomme à qui
 * l'on offre** : la liste s'ouvre volontiers devant d'autres.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  listerEvenements,
  listerTirages,
  rejoindreTirage,
  type ListeDEvenements,
  type ListeDeTirages,
  type ResumeDEvenement,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import { bilan, periodeEnTete, quand } from '@/components/evenements-familiaux/evenements'
import {
  fusionner,
  lireTypeDEvenement,
  type ElementDeLaListe,
  type TypeDEvenement,
} from '@/components/evenements-familiaux/liste-commune'
import PastilleDeType from '@/components/evenements-familiaux/PastilleDeType.vue'
import { dansCombien, libelleDate } from '@/components/accueil/jours'
import { etatDansLaListe } from '@/components/tirages/tirages'
import { useSession } from '@/composables/useSession'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const aujourdhui = new Date()

/* ───────────────── Chargement : deux appels, chacun pour soi ───────────────── */

const rassemblements = ref<ListeDEvenements | null>(null)
const tirages = ref<ListeDeTirages | null>(null)
const erreurRassemblements = ref<unknown>(null)
const erreurTirages = ref<unknown>(null)
const charge = ref(false)
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  erreurRassemblements.value = null
  erreurTirages.value = null

  await Promise.all([
    listerEvenements(signal).then(
      (liste) => (rassemblements.value = liste),
      (e: unknown) => (erreurRassemblements.value = e),
    ),
    listerTirages(signal).then(
      (liste) => (tirages.value = liste),
      (e: unknown) => (erreurTirages.value = e),
    ),
  ])
  if (!signal.aborted) charge.value = true
}

/** Les deux en panne : rien à montrer, un seul message. */
const toutEnPanne = computed(() => rassemblements.value === null && tirages.value === null && charge.value)

/* ───────────────── Le filtre par type ───────────────── */

const filtre = computed<TypeDEvenement | null>(() => lireTypeDEvenement(route.query['type']))

const FILTRES: { valeur: TypeDEvenement | null; libelle: string }[] = [
  { valeur: null, libelle: 'Tous' },
  { valeur: 'rassemblement', libelle: 'Rassemblements' },
  { valeur: 'tirage', libelle: 'Tirages au sort' },
]

function filtrer(valeur: TypeDEvenement | null): void {
  void routeur.replace({ name: 'evenements', query: valeur === null ? {} : { type: valeur } })
}

const listes = computed(() =>
  fusionner(rassemblements.value?.evenements ?? [], tirages.value?.tirages ?? [], aujourdhui, filtre.value),
)

/* ───────────────── Ce que dit chaque ligne ───────────────── */

function maReponse(e: ResumeDEvenement): { texte: string; classe: string } | null {
  if (!e.suisInvite) return null
  if (e.reponseAConfirmer && e.peutRepondre) return { texte: 'À confirmer', classe: 'bg-alerte-doux text-alerte' }
  if (e.maReponse === 'present') return { texte: 'Présent', classe: 'bg-ok-doux text-ok' }
  if (e.maReponse === 'absent') return { texte: 'Absent', classe: 'border border-ligne text-attenue' }
  if (!e.peutRepondre) return null
  return { texte: 'À répondre', classe: 'bg-accent text-fond' }
}

function organiseParMoi(element: ElementDeLaListe): boolean {
  return element.type === 'tirage' ? element.tirage.organisateur.estMoi : element.evenement.organisateur.estMoi
}

/* ───────────────── Rejoindre un tirage ouvert à la famille ───────────────── */

const rejointEnCours = ref<number | null>(null)
const erreurDeJonction = ref<unknown>(null)

async function rejoindre(id: number): Promise<void> {
  rejointEnCours.value = id
  erreurDeJonction.value = null

  try {
    await rejoindreTirage(id)
    await routeur.push({ name: 'tirage.detail', params: { id } })
  } catch (e) {
    erreurDeJonction.value = e
  } finally {
    rejointEnCours.value = null
  }
}

onMounted(charger)
onBeforeUnmount(() => avorteur?.abort())
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-texte">Événements</h1>
        <p class="mt-1 text-sm text-attenue">
          Cousinades, retrouvailles, tirages au sort de Noël : qui vient, et à qui offrir.
        </p>
        <!-- L'aide en vidéo, à l'endroit où l'on se pose la question. -->
        <RouterLink
          :to="{ name: 'aide', query: { video: 'les-evenements' } }"
          data-aide-evenements
          class="mt-1 inline-flex min-h-11 items-center gap-2 text-sm text-accent hover:underline"
        >
          <span class="flex size-5 flex-none items-center justify-center rounded-full border-[1.5px] border-current text-[11px] font-bold" aria-hidden="true">?</span>
          Voir la vidéo : comment marchent les événements
        </RouterLink>
      </div>
      <RouterLink
        v-if="!suspendu"
        :to="{ name: 'evenement.nouveau' }"
        class="inline-flex min-h-11 items-center justify-center rounded-champ border border-accent bg-accent px-4 text-sm font-semibold text-fond hover:opacity-90"
      >
        Créer un événement
      </RouterLink>
      <BoutonBase v-else desactive>Créer un événement</BoutonBase>
    </header>

    <p v-if="motifSuspendu !== null" class="mb-4 rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
      {{ motifSuspendu }}
    </p>

    <!-- Le filtre : trois pastilles, l'adresse le retient (`?type=`). -->
    <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Filtrer par type">
      <button
        v-for="f in FILTRES"
        :key="f.libelle"
        type="button"
        class="min-h-9 rounded-pastille border px-3 text-sm transition-colors"
        :class="filtre === f.valeur ? 'border-accent bg-accent-doux font-semibold text-accent' : 'border-ligne bg-panneau text-texte hover:border-accent'"
        :aria-pressed="filtre === f.valeur"
        @click="filtrer(f.valeur)"
      >
        {{ f.libelle }}
      </button>
    </div>

    <SqueletteChargement v-if="!charge" :lignes="3" />

    <MessageErreur v-else-if="toutEnPanne" :erreur="erreurRassemblements" titre="Les événements n’ont pas pu être chargés" @action="charger" />

    <template v-else>
      <!-- Une moitié en panne : l'autre s'affiche, et la page dit ce qui manque. -->
      <MessageErreur
        v-if="erreurRassemblements !== null"
        class="mb-4"
        :erreur="erreurRassemblements"
        titre="Les rassemblements n’ont pas pu être chargés"
        @action="charger"
      />
      <MessageErreur
        v-if="erreurTirages !== null"
        class="mb-4"
        :erreur="erreurTirages"
        titre="Les tirages au sort n’ont pas pu être chargés"
        @action="charger"
      />
      <MessageErreur v-if="erreurDeJonction !== null" class="mb-4" :erreur="erreurDeJonction" titre="Impossible de rejoindre ce tirage" />

      <p
        v-if="listes.aVenir.length === 0"
        class="rounded-panneau border border-ligne bg-panneau p-6 text-sm text-attenue"
      >
        Aucun événement à venir. Créez le prochain : la famille sera prévenue.
      </p>

      <ul class="flex flex-col gap-3">
        <li v-for="element in listes.aVenir" :key="element.cle">
          <!-- ───────── Un rassemblement ───────── -->
          <RouterLink
            v-if="element.type === 'rassemblement'"
            :to="{ name: 'evenement.detail', params: { id: element.evenement.id } }"
            class="flex items-center gap-3 rounded-panneau border border-ligne bg-panneau p-4 transition-colors hover:border-accent"
          >
            <span class="min-w-0 flex-1">
              <PastilleDeType type="rassemblement" />
              <span class="mt-1 block truncate font-semibold text-texte">{{ element.evenement.titre }}</span>
              <span class="mt-0.5 block text-sm text-texte">{{ periodeEnTete(element.evenement.debut, element.evenement.fin) }}</span>
              <span class="mt-0.5 block truncate text-xs text-attenue">
                {{ quand(element.evenement, aujourdhui) }}<template v-if="element.evenement.lieu"> · {{ element.evenement.lieu }}</template>
                · {{ bilan(element.evenement.nombreDePresents, element.evenement.nombreDInvites) }}
              </span>
            </span>
            <span class="flex shrink-0 flex-col items-end gap-1">
              <span
                v-if="maReponse(element.evenement)"
                class="rounded-pastille px-2 py-0.5 text-xs font-semibold"
                :class="maReponse(element.evenement)?.classe"
              >
                {{ maReponse(element.evenement)?.texte }}
              </span>
              <span v-if="organiseParMoi(element)" class="rounded-pastille bg-accent-doux px-2 py-0.5 text-xs text-texte">Vous organisez</span>
            </span>
          </RouterLink>

          <!-- ───────── Un tirage au sort ───────── -->
          <div
            v-else
            class="flex flex-wrap items-center justify-between gap-3 rounded-panneau border border-ligne bg-panneau p-4 transition-colors hover:border-accent"
          >
            <RouterLink :to="{ name: 'tirage.detail', params: { id: element.tirage.id } }" class="min-w-0 flex-1">
              <PastilleDeType type="tirage" />
              <span class="mt-1 block truncate font-semibold text-texte">{{ element.tirage.nom }}</span>
              <span class="mt-0.5 block text-sm text-texte">{{ libelleDate(element.date) }} {{ element.date.getFullYear() }}</span>
              <span class="mt-0.5 block truncate text-xs text-attenue">
                {{ dansCombien(element.date, aujourdhui) }}<template v-if="element.tirage.lieu"> · {{ element.tirage.lieu }}</template>
                · {{ element.tirage.nombreDeParticipants }} participant{{ element.tirage.nombreDeParticipants > 1 ? 's' : '' }}
              </span>
              <span class="mt-1 block text-sm text-texte">{{ etatDansLaListe(element.tirage) }}</span>
            </RouterLink>
            <span class="flex shrink-0 flex-col items-end gap-1">
              <span v-if="organiseParMoi(element)" class="rounded-pastille bg-accent-doux px-2 py-0.5 text-xs text-texte">Vous organisez</span>
              <BoutonBase
                v-if="element.tirage.peutRejoindre"
                variante="secondaire"
                :chargement="rejointEnCours === element.tirage.id"
                :desactive="suspendu || rejointEnCours !== null"
                @click="rejoindre(element.tirage.id)"
              >
                Rejoindre
              </BoutonBase>
            </span>
          </div>
        </li>
      </ul>

      <details v-if="listes.passes.length > 0" class="mt-6">
        <summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold text-texte">
          Passés et annulés ({{ listes.passes.length }})
        </summary>
        <ul class="mt-2 flex flex-col gap-2">
          <template v-for="element in listes.passes" :key="element.cle">
            <li v-if="element.type === 'rassemblement'">
              <RouterLink
                :to="{ name: 'evenement.detail', params: { id: element.evenement.id } }"
                class="flex min-h-11 items-center justify-between gap-3 rounded-panneau border border-ligne bg-panneau px-4 py-3 text-sm"
              >
                <span class="min-w-0">
                  <span class="block truncate text-texte" :class="element.evenement.annule ? 'line-through' : ''">{{ element.evenement.titre }}</span>
                  <span class="block text-xs text-attenue">{{ periodeEnTete(element.evenement.debut, element.evenement.fin) }}</span>
                </span>
                <span class="shrink-0 text-xs text-attenue">{{ quand(element.evenement, aujourdhui) }}</span>
              </RouterLink>
            </li>
          </template>
        </ul>
      </details>
    </template>
  </div>
</template>
