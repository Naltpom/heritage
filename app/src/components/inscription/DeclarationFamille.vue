<script setup lang="ts">
/**
 * « Décrivez votre famille » — le recours de celui qui ne s'est pas trouvé.
 *
 * Le lien d'invitation circule dans un groupe familial ; une partie de ceux qui
 * l'ouvrent n'ont pas encore de fiche, ou ont une fiche dont le nom, les prénoms
 * ou la date diffèrent de ce qu'ils savent d'eux-mêmes. Plutôt que de les
 * laisser à la porte, on leur demande de **décrire leur famille** : un
 * administrateur s'en servira pour retrouver leur fiche, ou pour la créer, et
 * pourra se faire aider par une analyse automatique.
 *
 * Ce composant ne fait que recueillir : l'identité **déjà saisie** pour la
 * recherche (reprise telle quelle, modifiable dans le formulaire au-dessus), et
 * le texte libre. Il ne stocke rien, n'envoie rien ; la vue retient la
 * déclaration dans `fluxInscription` (en mémoire) et passe à l'étape 2.
 *
 * Trois choses y sont délibérées :
 *
 * - **le champ est obligatoire, et d'une longueur minimale** (60 caractères) :
 *   « c'est moi » ne permet à personne de retrouver quoi que ce soit ;
 * - **la mention est obligatoire** : le candidat doit savoir, avant d'écrire sur
 *   ses parents vivants, que seuls les administrateurs le liront et qu'un modèle
 *   de langage (Google Gemini) peut l'analyser ;
 * - **l'écran ne dit rien de la recherche qui a précédé.** Il s'ouvre de la même
 *   façon que la recherche n'ait rien rendu ou qu'elle ait rendu d'autres
 *   personnes — et ne distingue donc pas davantage les causes d'un résultat vide.
 */
import { computed, ref, useId } from 'vue'
import type { DeclarationDeFamille } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import {
  DESCRIPTION_MAXIMALE,
  EXEMPLE_DESCRIPTION,
  MENTION_DESCRIPTION,
  compteurDescription,
  erreursDeclaration,
} from './declaration'

const { surname, givenNames, birthDate } = defineProps<{
  /** L'identité saisie pour la recherche, reprise telle quelle. */
  surname: string
  givenNames: string
  /** `YYYY-MM-DD`, comme à l'étape 1b. */
  birthDate: string
}>()

/** Le texte libre. `defineModel` : la vue garde le brouillon si le panneau se referme. */
const description = defineModel<string>('description', { default: '' })

const emit = defineEmits<{
  /** La déclaration est complète : la vue la retient et passe à l'étape 2. */
  continuer: [declaration: DeclarationDeFamille]
  /** Revenir aux candidats (ou à la recherche). */
  annuler: []
}>()

const identifiant = useId()
const idAide = `${identifiant}-aide`
const idCompteur = `${identifiant}-compteur`
const idErreur = `${identifiant}-erreur`

/** Vrai après un premier « Continuer » refusé : on ne souligne pas un texte
 *  que le candidat n'a pas fini d'écrire. */
const aTenteEnvoi = ref(false)

const declaration = computed<DeclarationDeFamille>(() => ({
  surname,
  givenNames,
  birthDate,
  description: description.value,
}))
const erreurs = computed(() => erreursDeclaration(declaration.value))
const erreurTexte = computed(() => (aTenteEnvoi.value ? (erreurs.value.description ?? '') : ''))
/** L'identité vient du formulaire au-dessus : ses erreurs s'affichent ici, en une ligne. */
const erreurIdentite = computed(() =>
  aTenteEnvoi.value
    ? (erreurs.value.surname ?? erreurs.value.givenNames ?? erreurs.value.birthDate ?? '')
    : '',
)
const compteur = computed(() => compteurDescription(description.value))

/** La date ferme, lue à la française pour le rappel — sans passer par `Date`,
 *  qui la décalerait d'un jour selon le fuseau. */
const dateLisible = computed(() => {
  const forme = /^(\d{4})-(\d{2})-(\d{2})$/.exec(birthDate)
  return forme === null ? '—' : `${forme[3]}/${forme[2]}/${forme[1]}`
})

function continuer(): void {
  aTenteEnvoi.value = true
  if (Object.keys(erreurs.value).length > 0) return
  emit('continuer', declaration.value)
}
</script>

<template>
  <PanneauBase>
    <form novalidate @submit.prevent="continuer">
      <h2 class="text-base font-semibold">Décrivez votre famille</h2>
      <p class="mt-2 text-sm text-attenue">
        Pas trouvé votre fiche ? Dites ce que vous savez de votre famille, même approximatif.
      </p>

      <!-- L'identité reprise de la recherche : elle se corrige au-dessus. -->
      <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-champ border border-ligne px-3 py-2 text-sm">
        <dt class="text-attenue">Nom</dt>
        <dd class="font-medium">{{ surname.trim() || '—' }}</dd>
        <dt class="text-attenue">Prénom·s</dt>
        <dd class="font-medium">{{ givenNames.trim() || '—' }}</dd>
        <dt class="text-attenue">Naissance</dt>
        <dd class="tnum font-medium">{{ dateLisible }}</dd>
      </dl>
      <p class="mt-1 text-xs text-attenue">À corriger plus haut si besoin.</p>
      <p v-if="erreurIdentite.length > 0" class="mt-1 text-xs text-alerte" role="alert">
        {{ erreurIdentite }}
      </p>

      <div class="mt-4 flex flex-col gap-1">
        <label :for="identifiant" class="text-sm font-medium">
          Votre famille
          <span class="text-alerte" aria-hidden="true">*</span>
          <span class="lecteur-ecran">(obligatoire)</span>
        </label>
        <p :id="idAide" class="text-xs text-attenue">{{ MENTION_DESCRIPTION }}</p>
        <textarea
          :id="identifiant"
          v-model="description"
          rows="7"
          required
          :maxlength="DESCRIPTION_MAXIMALE + 200"
          :placeholder="EXEMPLE_DESCRIPTION"
          :aria-describedby="`${idAide} ${idCompteur}${erreurTexte.length > 0 ? ` ${idErreur}` : ''}`"
          :aria-invalid="erreurTexte.length > 0 ? 'true' : undefined"
          class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
          :class="erreurTexte.length > 0 ? 'border-alerte' : 'border-ligne'"
        />
        <p :id="idCompteur" class="tnum text-right text-xs text-attenue" aria-live="polite">
          {{ compteur }}
        </p>
        <p v-if="erreurTexte.length > 0" :id="idErreur" class="text-xs text-alerte">
          {{ erreurTexte }}
        </p>
      </div>

      <div class="mt-4 flex flex-col gap-2">
        <BoutonBase type="submit" pleineLargeur>Continuer</BoutonBase>
        <BoutonBase variante="discret" pleineLargeur @click="emit('annuler')">
          Revenir à la recherche
        </BoutonBase>
      </div>
    </form>
  </PanneauBase>
</template>
