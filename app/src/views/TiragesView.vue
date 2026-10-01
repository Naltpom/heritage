<script setup lang="ts">
/**
 * Les tirages au sort de la famille — ADR 0009.
 *
 * La liste montre les tirages dont le lecteur est, qu'il organise, ou qui sont
 * ouverts à la famille. **Elle ne nomme jamais à qui il offre** : elle s'ouvre
 * volontiers devant d'autres, et c'est l'écran du tirage qui le dévoile.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  analyserErreur,
  creerTirage,
  listerTirages,
  rejoindreTirage,
  type ListeDeTirages,
  type PersonneDuTirage,
  type ReglesDuTirage,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import { estFicheProposee, type ChoixDePersonne } from '@/components/proposition/creation'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import FormulaireReglages from '@/components/tirages/FormulaireReglages.vue'
import { etatDansLaListe, libelleDuJour } from '@/components/tirages/tirages'
import { useSession } from '@/composables/useSession'

const routeur = useRouter()
const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const liste = ref<ListeDeTirages | null>(null)
const erreur = ref<unknown>(null)
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  erreur.value = null

  try {
    liste.value = await listerTirages(avorteur.signal)
  } catch (e) {
    erreur.value = e
  }
}

/* ───────────────── Création ───────────────── */

const enCreation = ref(false)
const regles = ref<ReglesDuTirage>(reglesParDefaut())
const participants = ref<PersonneDuTirage[]>([])
const erreurDeCreation = ref<unknown>(null)
const creationEnCours = ref(false)

const erreursDeChamps = computed<Record<string, string>>(() =>
  erreurDeCreation.value === null ? {} : analyserErreur(erreurDeCreation.value).champs,
)

function reglesParDefaut(): ReglesDuTirage {
  return { nom: '', jour: 24, mois: 12, lieu: null, ouvert: false, memoire: 2, exclureConjoints: true }
}

function ouvrirLaCreation(): void {
  regles.value = reglesParDefaut()
  // On s'inscrit d'office : l'organisateur participe presque toujours. Il peut se retirer.
  participants.value = liste.value?.moi ? [liste.value.moi] : []
  erreurDeCreation.value = null
  enCreation.value = true
}

function ajouter(choix: ChoixDePersonne): void {
  if (estFicheProposee(choix)) return
  if (participants.value.some((p) => p.personId === choix.personId)) return
  participants.value = [...participants.value, { personId: choix.personId, libelle: choix.libelle }]
}

function retirer(personId: number): void {
  participants.value = participants.value.filter((p) => p.personId !== personId)
}

const dejaChoisis = computed(() => participants.value.map((p) => `personne:${p.personId}`))

async function creer(): Promise<void> {
  if (creationEnCours.value) return
  creationEnCours.value = true
  erreurDeCreation.value = null

  try {
    const cree = await creerTirage(regles.value, participants.value.map((p) => p.personId))
    await routeur.push({ name: 'tirage.detail', params: { id: cree.id } })
  } catch (e) {
    erreurDeCreation.value = e
  } finally {
    creationEnCours.value = false
  }
}

/* ───────────────── Rejoindre ───────────────── */

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
    <header class="mb-6 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-texte">Tirages au sort</h1>
        <p class="mt-1 text-sm text-attenue">
          Chaque année, un tirage désigne à qui chacun offre un cadeau. Vous ne voyez que le vôtre.
        </p>
      </div>
      <BoutonBase v-if="!enCreation && liste !== null" :desactive="suspendu" @click="ouvrirLaCreation">
        Créer un tirage
      </BoutonBase>
    </header>

    <p v-if="motifSuspendu !== null" class="mb-4 rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
      {{ motifSuspendu }}
    </p>

    <PanneauBase v-if="enCreation" titre="Nouveau tirage" class="mb-6">
      <form class="flex flex-col gap-5" @submit.prevent="creer">
        <FormulaireReglages v-model="regles" :erreurs="erreursDeChamps" />

        <div class="flex flex-col gap-2">
          <h3 class="text-sm font-medium text-texte">Participants</h3>
          <ul v-if="participants.length > 0" class="flex flex-wrap gap-2">
            <li
              v-for="p in participants"
              :key="p.personId"
              class="flex items-center gap-1 rounded-pastille border border-ligne bg-fond py-1 pl-3 pr-1 text-sm"
            >
              {{ p.libelle }}
              <button
                type="button"
                class="rounded-pastille px-2 text-attenue hover:text-texte"
                :aria-label="`Retirer ${p.libelle}`"
                @click="retirer(p.personId)"
              >
                ×
              </button>
            </li>
          </ul>
          <p v-else class="text-xs text-attenue">Personne pour l’instant. Vous pourrez aussi en ajouter plus tard.</p>
          <SelecteurPersonne
            etiquette="Ajouter une personne de l’arbre"
            aide="Seules les personnes vivantes sont proposées. Un enfant sans compte peut participer : un parent lira son tirage pour lui."
            :avec-propositions="false"
            vivants-seulement
            :deja-choisis="dejaChoisis"
            @choisir="ajouter"
          />
          <p v-if="erreursDeChamps['participants']" class="text-xs text-alerte">{{ erreursDeChamps['participants'] }}</p>
        </div>

        <MessageErreur
          v-if="erreurDeCreation !== null && Object.keys(erreursDeChamps).length === 0"
          :erreur="erreurDeCreation"
          titre="Le tirage n’a pas pu être créé"
        />

        <div class="flex gap-2">
          <BoutonBase type="submit" :chargement="creationEnCours" :desactive="suspendu">Créer</BoutonBase>
          <BoutonBase variante="discret" @click="enCreation = false">Annuler</BoutonBase>
        </div>
      </form>
    </PanneauBase>

    <SqueletteChargement v-if="liste === null && erreur === null" :lignes="3" />

    <MessageErreur v-else-if="liste === null" :erreur="erreur" titre="Les tirages n’ont pas pu être chargés" @action="charger" />

    <template v-else>
      <MessageErreur v-if="erreurDeJonction !== null" class="mb-4" :erreur="erreurDeJonction" titre="Impossible de rejoindre ce tirage" />

      <p
        v-if="liste.tirages.length === 0 && !enCreation"
        class="rounded-panneau border border-ligne bg-panneau p-6 text-sm text-attenue"
      >
        Aucun tirage pour l’instant. Créez celui de Noël, d’un anniversaire, ou de toute fête qui revient chaque année.
      </p>

      <ul class="flex flex-col gap-3">
        <li
          v-for="tirage in liste.tirages"
          :key="tirage.id"
          class="flex flex-wrap items-center justify-between gap-3 rounded-panneau border border-ligne bg-panneau p-4"
        >
          <RouterLink :to="{ name: 'tirage.detail', params: { id: tirage.id } }" class="min-w-0 flex-1">
            <p class="font-medium text-texte">{{ tirage.nom }}</p>
            <p class="mt-0.5 text-xs text-attenue">
              {{ libelleDuJour(tirage.jour, tirage.mois) }}<template v-if="tirage.lieu"> · {{ tirage.lieu }}</template>
              · {{ tirage.nombreDeParticipants }} participant{{ tirage.nombreDeParticipants > 1 ? 's' : '' }}
            </p>
            <p class="mt-1 text-sm text-texte">{{ etatDansLaListe(tirage) }}</p>
          </RouterLink>
          <div class="flex items-center gap-2">
            <span v-if="tirage.organisateur.estMoi" class="rounded-pastille bg-accent-doux px-2 py-0.5 text-xs text-texte">
              Vous organisez
            </span>
            <BoutonBase
              v-if="tirage.peutRejoindre"
              variante="secondaire"
              :chargement="rejointEnCours === tirage.id"
              :desactive="suspendu || rejointEnCours !== null"
              @click="rejoindre(tirage.id)"
            >
              Rejoindre
            </BoutonBase>
          </div>
        </li>
      </ul>
    </template>
  </div>
</template>
