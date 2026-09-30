<script setup lang="ts">
/**
 * Enregistrer une démarche — ou corriger la description d'une démarche existante.
 *
 * À la création, le **premier message** se saisit dans le même geste : on
 * enregistre une démarche au moment où l'on vient d'envoyer la demande, et son
 * texte est alors sous la main. Le demander à part ferait un second geste que
 * personne ne fait.
 */
import { computed, ref } from 'vue'
import {
  analyserErreur,
  creerDemarche,
  decrireDemarche,
  type DescriptionDeDemarche,
  type DetailDeDemarche,
  type MessageASaisir,
  type PersonneDeLaDemarche,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import { estFicheProposee, type ChoixDePersonne } from '@/components/proposition/creation'
import FormulaireMessage from './FormulaireMessage.vue'
import {
  FORMES,
  NATURES,
  descriptionAEnvoyer,
  descriptionVide,
  erreursDuMessage,
  messageAEnvoyer,
  messageVide,
} from './demarches'

const { existante = null, personnesInitiales = [], suspendu = false } = defineProps<{
  /** Présente : on corrige sa description, sans premier message. */
  existante?: DetailDeDemarche | null
  /** À la création depuis une fiche : la personne de la fiche, déjà désignée. */
  personnesInitiales?: PersonneDeLaDemarche[]
  suspendu?: boolean
}>()

const emit = defineEmits<{ enregistree: [demarche: DetailDeDemarche]; annuler: [] }>()

const description = ref<DescriptionDeDemarche>(
  existante === null
    ? descriptionVide(personnesInitiales.map((p) => p.personId))
    : {
        nature: existante.nature,
        forme: existante.forme,
        intitule: existante.intitule,
        commune: existante.commune,
        dateDeLActe: existante.dateDeLActe,
        destinataire: existante.destinataire,
        personIds: existante.personnes.map((p) => p.personId),
      },
)
const personnes = ref<PersonneDeLaDemarche[]>(existante === null ? [...personnesInitiales] : [...existante.personnes])
const avecMessage = ref(existante === null)
const message = ref<MessageASaisir>(messageVide())

const erreur = ref<unknown>(null)
const enCours = ref(false)

const champs = computed<Record<string, string>>(() => (erreur.value === null ? {} : analyserErreur(erreur.value).champs))
const champsDuMessage = computed(() => erreursDuMessage(champs.value))
const dejaChoisis = computed(() => personnes.value.map((p) => `personne:${p.personId}`))

const classeChamp = 'rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte'

function ajouter(choix: ChoixDePersonne): void {
  if (estFicheProposee(choix)) return
  if (personnes.value.some((p) => p.personId === choix.personId)) return
  personnes.value = [...personnes.value, { personId: choix.personId, libelle: choix.libelle }]
}

function retirer(personId: number): void {
  personnes.value = personnes.value.filter((p) => p.personId !== personId)
}

async function enregistrer(): Promise<void> {
  if (enCours.value) return
  enCours.value = true
  erreur.value = null

  const corps = descriptionAEnvoyer({ ...description.value, personIds: personnes.value.map((p) => p.personId) })

  try {
    const relue =
      existante === null
        ? await creerDemarche(corps, avecMessage.value ? messageAEnvoyer(message.value) : null)
        : await decrireDemarche(existante.id, corps)
    emit('enregistree', relue)
  } catch (e) {
    erreur.value = e
  } finally {
    enCours.value = false
  }
}
</script>

<template>
  <form class="flex flex-col gap-4" @submit.prevent="enregistrer">
    <div class="grid gap-3 sm:grid-cols-2">
      <div class="flex flex-col gap-1">
        <label class="text-sm font-medium" for="demarche-nature">Pièce demandée</label>
        <select id="demarche-nature" v-model="description.nature" :class="classeChamp">
          <option v-for="n in NATURES" :key="n.valeur" :value="n.valeur">{{ n.libelle }}</option>
        </select>
      </div>
      <div class="flex flex-col gap-1">
        <label class="text-sm font-medium" for="demarche-forme">Forme</label>
        <select id="demarche-forme" v-model="description.forme" :class="classeChamp">
          <option v-for="f in FORMES" :key="f.valeur" :value="f.valeur">{{ f.libelle }}</option>
        </select>
      </div>
    </div>

    <ChampTexte
      v-model="description.intitule"
      etiquette="Intitulé"
      requis
      placeholder="Mariage PROVOST × MAGNOL, Rampieux, 1945"
      :erreur="champs['intitule'] ?? ''"
    />

    <div class="grid gap-3 sm:grid-cols-3">
      <ChampTexte
        :model-value="description.commune ?? ''"
        etiquette="Commune de l’acte"
        :erreur="champs['commune'] ?? ''"
        @update:model-value="description.commune = $event"
      />
      <ChampTexte
        :model-value="description.dateDeLActe ?? ''"
        etiquette="Date de l’acte"
        placeholder="1er septembre 1945, vers 1945…"
        :erreur="champs['dateDeLActe'] ?? ''"
        @update:model-value="description.dateDeLActe = $event"
      />
      <ChampTexte
        v-model="description.destinataire"
        etiquette="Demandée à"
        requis
        placeholder="Mairie de Rampieux"
        :erreur="champs['destinataire'] ?? ''"
      />
    </div>

    <div class="flex flex-col gap-2">
      <h3 class="text-sm font-medium text-texte">Personnes concernées</h3>
      <ul v-if="personnes.length > 0" class="flex flex-wrap gap-2">
        <li
          v-for="p in personnes"
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
      <SelecteurPersonne
        etiquette="Ajouter une personne de l’arbre"
        aide="Un acte de mariage concerne les deux époux : désignez-les tous les deux."
        :avec-propositions="false"
        :deja-choisis="dejaChoisis"
        @choisir="ajouter"
      />
      <p v-if="champs['personIds']" class="text-xs text-alerte">{{ champs['personIds'] }}</p>
    </div>

    <template v-if="existante === null">
      <label class="flex items-center gap-2 text-sm">
        <input v-model="avecMessage" type="checkbox" />
        J’ai le texte de la demande envoyée
      </label>
      <fieldset v-if="avecMessage" class="rounded-panneau border border-ligne p-3">
        <legend class="px-1 text-sm font-medium">La demande</legend>
        <FormulaireMessage v-model="message" :erreurs="champsDuMessage" />
      </fieldset>
    </template>

    <MessageErreur
      v-if="erreur !== null && Object.keys(champs).length === 0"
      :erreur="erreur"
      titre="La démarche n’a pas pu être enregistrée"
    />
    <p v-else-if="Object.keys(champs).length > 0" class="text-xs text-alerte">Certains champs sont à corriger.</p>

    <div class="flex gap-2">
      <BoutonBase type="submit" :chargement="enCours" :desactive="suspendu || personnes.length === 0">
        {{ existante === null ? 'Enregistrer la démarche' : 'Enregistrer' }}
      </BoutonBase>
      <BoutonBase variante="discret" @click="emit('annuler')">Annuler</BoutonBase>
    </div>
  </form>
</template>
