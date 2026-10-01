<script setup lang="ts">
/**
 * La suggestion de l'analyse automatique (Gemini), sur une carte « À rattacher ».
 *
 * **Elle éclaire, elle ne décide pas.** Aucun rattachement ne part d'ici sans
 * un clic de l'administrateur, et les boutons « Rattacher à … » que ce
 * composant porte ne font que le rapporter à la carte, qui agit.
 *
 * Cinq états, décidés par `rattachements.ts` (`EtatDAnalyse`) :
 *
 *  - `attente` et `en_cours` — l'analyse prend 20 à 60 secondes, et les cartes
 *    passent **l'une après l'autre** : une carte peut attendre son tour ;
 *  - `prete` — verdict, résumé, fiche désignée, autres pistes, création ;
 *  - `non_configuree` — pas de clé : une phrase neutre, **pas** de « Relancer »,
 *    qui échouerait toujours pareil ;
 *  - `echec` — le message, et « Relancer ».
 *
 * Dans tous les cas, la carte reste utilisable à la main : c'est ce que la
 * dernière phrase de chaque état non prêt rappelle.
 *
 * `data-analyse` porte l'état sur le balisage, comme `data-rattachement` sur la
 * mention voisine : c'est la prise des tests de rendu.
 */
import { computed } from 'vue'
import type { SuggestionDeRattachement } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import { LIBELLES_CONFIANCE, LIBELLES_VERDICT, type EtatDAnalyse } from './rattachements'

const { analyse, suspendu = false, occupe = false } = defineProps<{
  analyse: EtatDAnalyse
  /** Écritures éteintes (bascule « voir comme »). */
  suspendu?: boolean
  /** Une action de la carte est en cours : les boutons attendent. */
  occupe?: boolean
}>()

const emit = defineEmits<{
  relancer: []
  rattacher: [personId: number, displayName: string]
}>()

const suggestion = computed<SuggestionDeRattachement | null>(() =>
  analyse.etat === 'prete' ? analyse.suggestion : null,
)

/** Passe par une fonction plutôt que par le gabarit : le rétrécissement du
 *  `v-if` ne traverse pas la fermeture d'un gestionnaire d'événement. */
function rattacherLaFiche(): void {
  const fiche = suggestion.value?.fiche ?? null
  if (fiche !== null && !fiche.dejaRattachee) emit('rattacher', fiche.personId, fiche.displayName)
}

function horodatage(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleString('fr-FR', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <section
    class="flex flex-col gap-2.5 rounded-panneau border border-ligne bg-fond px-3.5 py-3 text-sm"
    :data-analyse="analyse.etat"
    aria-label="Analyse automatique"
  >
    <header class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-xs font-semibold tracking-wide text-attenue uppercase">Analyse automatique</h4>
      <BoutonBase
        v-if="analyse.etat === 'prete' || analyse.etat === 'echec'"
        variante="discret"
        :desactive="suspendu || occupe"
        @click="emit('relancer')"
      >
        Relancer l’analyse
      </BoutonBase>
    </header>

    <!-- ── En attente ou en cours ─────────────────────────────────────── -->
    <p v-if="analyse.etat === 'attente'" class="text-attenue" role="status">
      En file : les analyses passent l’une après l’autre.
    </p>
    <p v-else-if="analyse.etat === 'en_cours'" class="text-attenue" role="status">
      Analyse en cours (20 à 60 secondes). Vous pouvez décider sans l’attendre.
    </p>

    <!-- ── Pas de clé : un réglage, pas une panne ─────────────────────── -->
    <p v-else-if="analyse.etat === 'non_configuree'" class="text-attenue">
      Analyse automatique indisponible : choisissez une fiche, ou créez-la.
    </p>

    <!-- ── Échec : le message, et de quoi relancer ────────────────────── -->
    <div v-else-if="analyse.etat === 'echec'" class="text-alerte" role="alert">
      <p>{{ analyse.message }}</p>
      <p v-if="analyse.attenteSecondes !== null" class="mt-0.5 text-xs">
        Nouvel essai possible dans {{ Math.ceil(analyse.attenteSecondes / 60) }} min environ.
      </p>
    </div>

    <!-- ── Prête ───────────────────────────────────────────────────────── -->
    <template v-else-if="suggestion !== null">
      <p class="font-semibold" :data-verdict="suggestion.verdict">{{ LIBELLES_VERDICT[suggestion.verdict] }}</p>
      <p class="whitespace-pre-line">{{ suggestion.resume }}</p>

      <ul v-if="suggestion.avertissements.length > 0" class="flex flex-col gap-1 text-xs text-accent">
        <li v-for="(avertissement, index) in suggestion.avertissements" :key="index">⚠ {{ avertissement }}</li>
      </ul>

      <!-- La fiche désignée. Déjà réclamée : montrée quand même, bouton éteint. -->
      <div v-if="suggestion.fiche !== null" class="rounded-champ border border-accent bg-panneau px-3 py-2">
        <p>
          <span class="font-medium">{{ suggestion.fiche.displayName }}</span>
          <span v-if="suggestion.fiche.ligneDates !== ''" class="ml-1.5 font-mono text-xs text-attenue tnum">
            {{ suggestion.fiche.ligneDates }}
          </span>
          <span class="ml-1.5 text-xs text-attenue">· {{ LIBELLES_CONFIANCE[suggestion.fiche.confiance] }}</span>
        </p>
        <p class="mt-0.5 text-attenue">{{ suggestion.fiche.justification }}</p>
        <p v-if="suggestion.fiche.dejaRattachee" class="mt-1 text-xs text-alerte">
          Cette fiche est déjà rattachée à un autre compte.
        </p>
        <div class="mt-2">
          <BoutonBase
            :desactive="suspendu || occupe || suggestion.fiche.dejaRattachee"
            @click="rattacherLaFiche"
          >
            Rattacher à {{ suggestion.fiche.displayName }}
          </BoutonBase>
        </div>
      </div>

      <!-- Les autres pistes : une ligne chacune, rattachables aussi. -->
      <div v-if="suggestion.alternatives.length > 0">
        <p class="mb-1 text-xs font-semibold text-attenue">Autres pistes</p>
        <ul class="flex flex-col gap-1">
          <li
            v-for="piste in suggestion.alternatives"
            :key="piste.personId"
            class="flex flex-wrap items-center justify-between gap-2 rounded-champ border border-ligne bg-panneau px-2.5 py-1.5"
          >
            <span class="min-w-0">
              <span class="font-medium">{{ piste.displayName }}</span>
              <span v-if="piste.ligneDates !== ''" class="ml-1.5 font-mono text-xs text-attenue tnum">
                {{ piste.ligneDates }}
              </span>
              <span class="block text-xs text-attenue">{{ piste.justification }}</span>
            </span>
            <BoutonBase
              variante="secondaire"
              :desactive="suspendu || occupe"
              @click="emit('rattacher', piste.personId, piste.displayName)"
            >
              Rattacher
            </BoutonBase>
          </li>
        </ul>
      </div>

      <!-- La création proposée : un aperçu, l'action est sur la carte. -->
      <div v-if="suggestion.creation !== null">
        <p class="mb-1 text-xs font-semibold text-attenue">
          Création proposée — {{ suggestion.creation.titre }}
          <span class="font-normal">· {{ LIBELLES_CONFIANCE[suggestion.creation.confiance] }}</span>
        </p>
        <p class="text-attenue">{{ suggestion.creation.justification }}</p>
      </div>

      <p class="text-xs text-attenue">
        Calculée le {{ horodatage(suggestion.calculeeLe) }} ({{ suggestion.modele }}). Rien n’est
        rattaché sans votre décision.
      </p>
    </template>
  </section>
</template>
