<script setup lang="ts">
/**
 * **Créer un tirage au sort** — le formulaire de la création, sorti de
 * l'ancienne page « Tirages » le 02/10/2026 : les tirages sont désormais des
 * événements comme les autres, et se créent par « Créer un événement », type
 * « Tirage au sort » (ADR 0014, addendum). Rien n'a changé de ce qu'il fait :
 * les règles (`FormulaireReglages`), les participants, puis la page du tirage.
 *
 * Le lecteur est inscrit d'office — l'organisateur participe presque toujours,
 * et se retire d'un geste. Sa personne vient de `GET /api/tirages` (`moi`),
 * chargée au montage ; un compte sans rattachement commence sans participant.
 *
 * Il charge et écrit lui-même (la création n'a qu'un appelant, la page de
 * création), et émet `abandon` quand on renonce.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { analyserErreur, creerTirage, listerTirages, type PersonneDuTirage, type ReglesDuTirage } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import { estFicheProposee, type ChoixDePersonne } from '@/components/proposition/creation'
import FormulaireReglages from './FormulaireReglages.vue'

const { suspendu = false } = defineProps<{
  /** Les écritures sont suspendues (bascule « voir comme ») : le bouton s'éteint. */
  suspendu?: boolean
}>()

const emit = defineEmits<{ abandon: [] }>()

const routeur = useRouter()

const regles = ref<ReglesDuTirage>({ nom: '', jour: 24, mois: 12, lieu: null, ouvert: false, memoire: 2, exclureConjoints: true })
const participants = ref<PersonneDuTirage[]>([])
const erreur = ref<unknown>(null)
const enCours = ref(false)
let avorteur: AbortController | null = null

onMounted(async () => {
  avorteur = new AbortController()
  try {
    const liste = await listerTirages(avorteur.signal)
    // On s'inscrit d'office — sauf si l'on a déjà commencé à choisir.
    if (liste.moi !== null && participants.value.length === 0) participants.value = [liste.moi]
  } catch {
    // Sans la personne du lecteur, on commence sans participant : rien d'autre ne manque.
  }
})
onBeforeUnmount(() => avorteur?.abort())

const erreursDeChamps = computed<Record<string, string>>(() => (erreur.value === null ? {} : analyserErreur(erreur.value).champs))

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
  if (enCours.value || suspendu) return
  enCours.value = true
  erreur.value = null

  try {
    const cree = await creerTirage(regles.value, participants.value.map((p) => p.personId))
    await routeur.replace({ name: 'tirage.detail', params: { id: cree.id } })
  } catch (e) {
    erreur.value = e
  } finally {
    enCours.value = false
  }
}
</script>

<template>
  <form class="flex flex-col gap-5" @submit.prevent="creer">
    <PanneauBase titre="Le tirage">
      <FormulaireReglages v-model="regles" :erreurs="erreursDeChamps" />
    </PanneauBase>

    <PanneauBase titre="Les participants">
      <div class="flex flex-col gap-2">
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
        <p v-else class="text-xs text-attenue">Personne pour l’instant : vous pourrez en ajouter plus tard.</p>
        <SelecteurPersonne
          etiquette="Ajouter une personne de l’arbre"
          aide="Un enfant sans compte peut participer : un parent lira son tirage."
          :avec-propositions="false"
          vivants-seulement
          :deja-choisis="dejaChoisis"
          @choisir="ajouter"
        />
        <p v-if="erreursDeChamps['participants']" class="text-xs text-alerte">{{ erreursDeChamps['participants'] }}</p>
      </div>
    </PanneauBase>

    <MessageErreur
      v-if="erreur !== null && Object.keys(erreursDeChamps).length === 0"
      :erreur="erreur"
      titre="Le tirage n’a pas pu être créé"
    />

    <div class="flex gap-2">
      <BoutonBase type="submit" class="min-h-11" :chargement="enCours" :desactive="suspendu">Créer le tirage</BoutonBase>
      <BoutonBase variante="discret" class="min-h-11" @click="emit('abandon')">Annuler</BoutonBase>
    </div>
  </form>
</template>
