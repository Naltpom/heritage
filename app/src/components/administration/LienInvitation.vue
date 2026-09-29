<script setup lang="ts">
/**
 * Le lien d'invitation, affiché pour copie manuelle.
 *
 * **C'est le seul moment où ce lien existe.** Le serveur ne stocke que
 * `hash(jeton)` ; la liste des invitations ne porte pas de clé `lien` — absente,
 * pas `null` (§ 3.2). Une fois ce panneau fermé, le lien est **définitivement
 * perdu** : il n'y a pas de route pour le redemander, et il ne peut pas y en
 * avoir. L'écran doit donc le dire **avant** qu'on ferme, pas après.
 *
 * Trois choix d'affichage, tous dictés par cette propriété :
 *
 * - **l'URL est affichée en entier, en chasse fixe**, et pas raccourcie. Elle
 *   sera recopiée à la main dans un message ; un « … » au milieu la rendrait
 *   inutilisable, et 43 caractères de jeton se relisent mal en chasse
 *   proportionnelle ;
 * - **elle est rendue telle que le serveur l'a écrite.** Le jeton est dans le
 *   **chemin** — `/inscription/<jeton>` —, l'URL est absolue et bâtie sur le
 *   `Host` de la requête. On ne la recompose pas : le `tokenPrefix` ne fait que
 *   8 caractères sur 43 ;
 * - **la fermeture est explicite.** Pas de disparition automatique, pas de
 *   fermeture au clic à côté.
 *
 * `courrielEnvoye: false` avec un envoi demandé n'est pas un échec de la
 * création : l'invitation existe, le lien est là, seul le courriel n'est pas
 * parti (§ 3.1). Perdre l'invitation parce que le serveur de courriel est éteint
 * serait absurde.
 */
import { ref } from 'vue'
import type { InvitationCreee } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'

const { invitation } = defineProps<{ invitation: InvitationCreee }>()

const emit = defineEmits<{ fermer: [] }>()

const copie = ref(false)

async function copier(): Promise<void> {
  try {
    await navigator.clipboard.writeText(invitation.lien)
    copie.value = true
  } catch {
    // Le presse-papiers peut être refusé (contexte non sécurisé, permission).
    // Ce n'est pas une panne : l'URL est affichée en entier et sélectionnable,
    // c'est le chemin de repli, et il suffit.
    copie.value = false
  }
}
</script>

<template>
  <PanneauBase ombre>
    <template #entete>
      <h2 class="text-sm font-semibold tracking-tight">Le lien d’invitation</h2>
    </template>

    <p class="text-sm font-semibold text-alerte">
      Copiez ce lien maintenant : il ne sera plus affiché.
    </p>

    <!-- L'URL entière, en chasse fixe, sélectionnable. Elle sera recopiée à la
         main dans un message : la raccourcir la rendrait inutilisable. -->
    <p
      class="mt-3 overflow-x-auto rounded-champ border border-ligne bg-fond px-3 py-2 font-mono text-xs break-all select-all"
    >
      {{ invitation.lien }}
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-2">
      <BoutonBase variante="secondaire" @click="copier">Copier le lien</BoutonBase>
      <span v-if="copie" class="text-sm text-ok" role="status">Copié.</span>
      <BoutonBase variante="discret" @click="emit('fermer')">J’ai copié le lien</BoutonBase>
    </div>

    <template v-if="invitation.avertissements.length > 0" #pied>
      <ul class="list-disc pl-5 text-sm text-alerte">
        <li v-for="avertissement in invitation.avertissements" :key="avertissement.code">
          {{ avertissement.message }}
        </li>
      </ul>
    </template>
  </PanneauBase>
</template>
