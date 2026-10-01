<script setup lang="ts">
/**
 * Les doublons probables — « deux cousins proposeront le même arrière-grand-oncle ».
 *
 * Le serveur les calcule depuis `dedup_key` : les autres demandes `en_attente`
 * de même empreinte (§ 9.5, § 6.7). Il n'y a **aucun `personId`** dans ce bloc,
 * et c'est logique plutôt que prudent : la personne n'existe pas encore, aucune
 * des deux demandes n'a été validée.
 *
 * Ce que l'écran en fait : permettre de **refuser l'une en renvoyant à
 * l'autre**. L'auteur reçoit alors « votre proposition rejoint la demande
 * n° 11 » plutôt qu'un refus sec (§ 6.4 du dossier) — c'est la différence entre
 * quelqu'un qui comprend et quelqu'un qui ne contribuera plus.
 *
 * Le rapprochement se fait dans le formulaire de refus (`doublonDeId`) ; ce bloc
 * ne fait que montrer ce qu'il y a à rapprocher, avec un lien vers l'autre
 * demande pour pouvoir la lire avant de trancher.
 */
import type { DoublonProbable } from '@/lib/api'

const { doublons } = defineProps<{ doublons: DoublonProbable[] }>()
</script>

<template>
  <div v-if="doublons.length > 0">
    <p class="text-sm text-attenue">
      {{
        doublons.length === 1
          ? 'Une autre demande en attente décrit peut-être la même personne.'
          : `${doublons.length} autres demandes en attente décrivent peut-être la même personne.`
      }}
      Validez-en une, puis refusez les autres comme doublons.
    </p>

    <ul class="mt-2.5 flex flex-col gap-1.5">
      <li
        v-for="doublon in doublons"
        :key="doublon.id"
        class="flex flex-wrap items-baseline gap-x-2 rounded-champ border border-ligne bg-fond px-3 py-2 text-sm"
      >
        <RouterLink
          :to="{ name: 'demande.detail', params: { id: doublon.id } }"
          class="font-semibold text-accent underline"
        >
          Demande n° {{ doublon.id }}
        </RouterLink>
        <span class="font-medium">{{ doublon.libelle }}</span>
        <span class="text-attenue">proposée par {{ doublon.authorLabel }}</span>
      </li>
    </ul>
  </div>
</template>
