<script setup lang="ts">
/**
 * Le diff d'une demande, champ par champ.
 *
 * **Ce composant n'a qu'une entrée : les ops.** Pas de `administrateur`, pas de
 * `regime`, pas de session. C'est volontaire et c'est vérifié par un test.
 *
 * La charge de l'auteur (§ 6.5) porte `oldValue` et `newValue` ; celle de
 * l'administration (§ 6.7) ajoute `valeurCourante` et `aBouge`. La colonne
 * « valeur actuelle » se dessine donc **quand l'op la porte**, jamais parce que
 * le lecteur est administrateur — ce qui est la règle du § 1.3 (« ce qui est
 * absent de la charge utile ne s'affiche pas ») appliquée au cas où on serait
 * le plus tenté de la contourner.
 *
 * L'indicateur « a changé depuis le dépôt » suit la même règle, avec une nuance
 * qui compte : `aBouge: false` veut dire « le serveur a comparé, rien n'a
 * bougé » ; `aBouge` **absent** veut dire « cette charge n'a rien comparé ». On
 * n'affiche l'indicateur que dans le premier cas. Les confondre afficherait
 * « inchangé » comme une garantie, sur une réponse qui n'en donne aucune.
 */
import { computed } from 'vue'
import type { OpDemande } from '@/lib/api'
import type { CibleLoupe } from '@/components/medias/medias'
import { lignesDeDiff, porteLEtatActuel } from './diff'
import DocumentPropose from './DocumentPropose.vue'

const { ops } = defineProps<{ ops: OpDemande[] }>()

/** La loupe est tenue par la vue, jamais ici : « une seule image d'origine
 *  décodée à la fois », et une demande peut porter plusieurs documents. */
const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

const lignes = computed(() => lignesDeDiff(ops))
/** Une seule décision pour tout le tableau : la charge compare, ou elle ne
 *  compare pas. Colonne par colonne, on obtiendrait un tableau en dents de scie. */
const avecEtatActuel = computed(() => porteLEtatActuel(lignes.value))
</script>

<template>
  <ul v-if="lignes.length > 0" class="flex flex-col gap-2.5">
    <li
      v-for="ligne in lignes"
      :key="ligne.seq"
      class="rounded-champ border border-ligne bg-fond px-3.5 py-3"
    >
      <p class="text-sm font-semibold [overflow-wrap:anywhere]">{{ ligne.libelle }}</p>

      <dl class="mt-2 grid gap-x-4 gap-y-1.5 text-sm [overflow-wrap:anywhere] sm:grid-cols-[auto_minmax(0,1fr)]">
        <dt class="text-attenue">Valeur au dépôt</dt>
        <dd>{{ ligne.ancienne }}</dd>

        <template v-if="avecEtatActuel && ligne.courante !== null">
          <dt class="text-attenue">Valeur actuelle</dt>
          <dd :class="ligne.aBouge === true ? 'font-semibold text-alerte' : ''">
            {{ ligne.courante }}
          </dd>
        </template>

        <dt class="text-attenue">Valeur proposée</dt>
        <dd class="font-semibold">
          {{ ligne.proposee }}
          <DocumentPropose
            v-if="ligne.mediaId !== null"
            :media-id="ligne.mediaId"
            @agrandir="emit('agrandir', $event)"
          />
        </dd>
      </dl>

      <p v-if="ligne.aBouge === true" class="mt-2 text-xs font-semibold text-alerte">
        Cette valeur a changé depuis le dépôt de la demande.
      </p>
    </li>
  </ul>

  <p v-else class="text-sm text-attenue">Cette demande ne modifie aucun champ existant.</p>
</template>
