<script setup lang="ts">
/**
 * La fiche visée par une demande, en aperçu, **sans quitter la file**.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QU'ELLE RÉPARE.** La file dit « Modification — Jean Voulgre ». Pour
 * savoir si la correction proposée a du sens, il fallait ouvrir la fiche dans
 * un autre écran, donc perdre le filtre, le tri et la position dans la file —
 * et revenir en arrière recharge tout. On regardait donc rarement, ce qui est
 * la pire façon de décider.
 *
 * **Elle ne décide rien, et ne propose rien.** Ni « proposer une modification »,
 * ni « supprimer », ni dépôt de document : ce sont les panneaux de
 * `PersonneView`, et les redoubler ici ferait deux surfaces pour un même geste,
 * dont une seule serait éprouvée. Ce qu'on voit est ce qu'on lit — plus un lien
 * vers la vraie fiche, pour qui veut y aller.
 *
 * **Le cercle de visibilité s'applique intégralement.** On appelle la même route
 * que la fiche, `GET /api/arbre/personnes/{id}`, et une personne vivante hors
 * cercle rend une fiche **réduite** — un `200` à sept clés, pas une erreur.
 * L'administrateur n'y échappe pas : son cercle est simplement le cercle
 * complet, et c'est le serveur qui le dit, jamais cet écran. Un `v-if` sur le
 * rôle serait la version front du `if ($estAdmin) { return $tout; }` qu'il a
 * fallu retirer du serveur.
 *
 * Fermeture au fond **et** à Échap, comme `LoupeMedia` : un panneau plein écran
 * sans échappement clavier est une impasse pour qui n'emploie pas la souris.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { lireFichePersonne, type FichePersonne, type FichePersonneComplete } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import EnTeteFiche from '@/components/personne/EnTeteFiche.vue'
import FicheRestreinte from '@/components/personne/FicheRestreinte.vue'
import IdentiteFiche from '@/components/personne/IdentiteFiche.vue'
import ParenteFiche from '@/components/personne/ParenteFiche.vue'

/** `null` ferme l'aperçu. La personne à montrer, et rien d'autre : pas de rôle,
 *  pas de session — voir l'en-tête. */
const { personneId } = defineProps<{ personneId: number | null }>()

const emit = defineEmits<{
  fermer: []
  /** Le portrait en grand. `EnTeteFiche` en offre le bouton ; sans cette
   *  remontée, ce bouton serait dessiné et ne ferait rien — un contrôle mort
   *  vaut moins qu'un contrôle absent. La loupe, elle, est tenue par la vue. */
  agrandir: [cible: CibleLoupe]
}>()

const fiche = ref<FichePersonne | null>(null)
const erreur = ref<unknown>(null)
const chargement = ref(false)

/**
 * La discrimination faite une fois, comme dans `PersonneView`.
 *
 * `FichePersonne` est une union sur `visibilite`, et le typeur refuse
 * `fiche.birth` tant qu'on n'a pas discriminé. Sans cela, `fiche.birth ?? '—'`
 * compilerait et afficherait un tiret — « on ne sait pas » — là où la vérité est
 * « vous n'avez pas le droit de savoir ».
 */
const complete = computed<FichePersonneComplete | null>(() =>
  fiche.value !== null && fiche.value.visibilite === 'complete' ? fiche.value : null,
)

const restreinte = computed(() =>
  fiche.value !== null && fiche.value.visibilite === 'restreinte' ? fiche.value : null,
)

let abandon: AbortController | null = null

async function charger(): Promise<void> {
  abandon?.abort()
  fiche.value = null
  erreur.value = null
  if (personneId === null) return

  const controleur = new AbortController()
  abandon = controleur
  chargement.value = true
  try {
    const lue = await lireFichePersonne(personneId)
    if (!controleur.signal.aborted) fiche.value = lue
  } catch (cause) {
    // La cause part brute à `MessageErreur`, qui appelle `analyserErreur()` :
    // aucun écran ne réinterprète un code d'erreur.
    if (!controleur.signal.aborted) erreur.value = cause
  } finally {
    if (!controleur.signal.aborted) chargement.value = false
  }
}

watch(() => personneId, charger, { immediate: true })

function auClavier(evenement: KeyboardEvent): void {
  if (evenement.key === 'Escape') emit('fermer')
}

onMounted(() => window.addEventListener('keydown', auClavier))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', auClavier)
  abandon?.abort()
})
</script>

<template>
  <div
    v-if="personneId !== null"
    class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/70 p-4"
    role="dialog"
    aria-modal="true"
    aria-label="Aperçu de la fiche"
    @click.self="emit('fermer')"
  >
    <div class="my-auto w-full max-w-2xl rounded-panneau bg-fond p-4 shadow-panneau">
      <div class="mb-3 flex items-start justify-between gap-2">
        <p class="text-sm text-attenue">Aperçu — cette fiche n’est pas modifiable ici.</p>
        <button
          type="button"
          class="cursor-pointer border-0 bg-transparent px-2 text-2xl leading-none text-texte"
          aria-label="Fermer l’aperçu"
          @click="emit('fermer')"
        >
          ×
        </button>
      </div>

      <PanneauBase v-if="chargement && fiche === null">
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de la fiche…</p>
        <SqueletteChargement :lignes="5" />
      </PanneauBase>

      <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

      <template v-else-if="fiche !== null">
        <EnTeteFiche :fiche="fiche" @agrandir="emit('agrandir', $event)" />

        <FicheRestreinte v-if="restreinte !== null" :fiche="restreinte" />

        <template v-else-if="complete !== null">
          <PanneauBase titre="Identité" class="mb-4">
            <IdentiteFiche :fiche="complete" />
          </PanneauBase>

          <PanneauBase titre="Parenté" class="mb-4">
            <ParenteFiche :fiche="complete" />
          </PanneauBase>
        </template>

        <p class="text-sm">
          <RouterLink
            :to="{ name: 'personne.detail', params: { id: personneId } }"
            class="text-accent underline"
          >
            Ouvrir la fiche complète ↗
          </RouterLink>
        </p>
      </template>
    </div>
  </div>
</template>
