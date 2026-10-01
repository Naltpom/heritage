<script setup lang="ts">
/**
 * « Votre photo » — le panneau qui suit la création du compte.
 *
 * La photo est **attendue**, pas facultative : c'est ce qui rend l'arbre vivant
 * pour les autres, et c'est aussi, pour un compte ouvert sans fiche, un indice
 * de plus pour l'administrateur qui le rattachera. Le panneau n'est donc pas
 * marqué « facultatif ». Mais on ne retient personne : un lien discret,
 * « Passer cette étape », mène à la suite, et l'écran d'état proposera encore
 * d'ajouter la photo plus tard.
 *
 * L'envoi réutilise `ChoixPortrait.vue` **tel quel** — importer ou prendre une
 * photo, la cadrer sous le cercle — et sa route,
 * `POST /api/medias/portrait-du-compte`. Pour un compte qui n'a pas encore de
 * fiche validée, le serveur dépose la photo **en attente** : seuls les
 * administrateurs (et le titulaire) la voient, et elle devient le portrait de la
 * fiche au rattachement. Pour un compte activé d'emblée, elle devient son
 * portrait tout de suite.
 */
import { ref } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import ChoixPortrait from '@/components/medias/ChoixPortrait.vue'

const { enAttente = true } = defineProps<{
  /** Le compte attend-il sa validation ? Change seulement ce qu'on dit de la visibilité. */
  enAttente?: boolean
}>()

const emit = defineEmits<{
  /** La suite : photo déposée (`true`) ou étape passée (`false`). */
  terminer: [photoDeposee: boolean]
}>()

const choixOuvert = ref(false)
</script>

<template>
  <PanneauBase>
    <template #entete>
      <h2 class="text-sm font-semibold tracking-tight">Votre photo</h2>
    </template>

    <div class="flex items-start gap-3.5">
      <div
        class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full border border-dashed border-ligne bg-accent-doux text-attenue"
        aria-hidden="true"
      >
        <!-- Silhouette neutre : le rond où ira le visage. -->
        <svg viewBox="0 0 24 24" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="12" cy="9" r="3.5" />
          <path d="M5 19.5c1.4-3.2 4-4.8 7-4.8s5.6 1.6 7 4.8" stroke-linecap="round" />
        </svg>
      </div>
      <div class="text-sm">
        <p>
          Une photo de vous, bien cadrée sur le visage, pour vous représenter dans l’arbre.
        </p>
        <p class="mt-2 text-attenue">
          <template v-if="enAttente">
            Seuls les administrateurs la voient jusqu’à la validation de votre compte.
          </template>
          <template v-else>
            Elle devient aussitôt le portrait de votre fiche.
          </template>
        </p>
      </div>
    </div>

    <div class="mt-4 flex flex-col items-center gap-2.5">
      <BoutonBase pleineLargeur @click="choixOuvert = true">Ajouter ma photo</BoutonBase>
      <!-- Discret, mais présent : on ne retient personne. -->
      <button
        type="button"
        class="text-xs text-attenue underline hover:text-texte"
        @click="emit('terminer', false)"
      >
        Passer cette étape
      </button>
    </div>

    <ChoixPortrait
      :ouvert="choixOuvert"
      @fermer="choixOuvert = false"
      @depose="emit('terminer', true)"
    />
  </PanneauBase>
</template>
