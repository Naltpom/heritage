<script setup lang="ts">
/**
 * **Portraits repérés** — sous les demandes, une carte par visage que
 * l'assistant a reconnu dans une photo jointe.
 *
 * Chaque carte montre l'aperçu **recadré localement** : le navigateur a encore
 * les fichiers envoyés à l'analyse, et le carré posé sur le cadre du visage
 * (`zoneDepuisCadre`) s'affiche en rond, comme il le sera dans l'arbre. Rien
 * n'est découpé pour l'aperçu : c'est l'image d'origine, agrandie et décalée
 * sous un masque rond — le carré n'est tiré en JPEG qu'au dépôt.
 *
 * Cochée par défaut, comme une demande, et rien ne part d'ici : la case dit
 * seulement si le portrait fera partie du dépôt. « Ajuster » ouvre le recadrage
 * sous un cercle (le parent tient la boîte de dialogue).
 *
 * **Une personne restreinte n'a que son nom** — `personneDuPortrait()` passe
 * par `datesAffichables()`, la règle des cartes de demande. Une personne
 * **proposée** n'a pas de fiche : pas de lien, « nouvelle personne proposée »
 * et le nom de son brouillon.
 */
import { useId } from 'vue'
import type { DemandeProposee, PortraitPropose } from '@/lib/api'
import { zoneDepuisCadre, type CadreVisage } from '@/components/medias/cadrage'
import { empechementDuPortrait, personneDuPortrait } from './assistant'

/** Où en est l'aperçu d'un portrait. `pret` : l'image est décodée, `url` est
 *  l'URL d'objet de son document et `cadre` le cadrage courant (proposé ou
 *  ajusté). */
export type ApercuPortrait =
  | { etat: 'chargement' }
  | { etat: 'illisible' }
  | { etat: 'pret'; url: string; largeur: number; hauteur: number; cadre: CadreVisage; ajuste: boolean }

const {
  portraits,
  demandes,
  cochees,
  demandesCochees,
  apercus,
  desactive = false,
} = defineProps<{
  portraits: readonly PortraitPropose[]
  demandes: readonly DemandeProposee[]
  cochees: ReadonlySet<string>
  demandesCochees: ReadonlySet<string>
  /** Par clé de portrait ; absent = en chargement. */
  apercus: Readonly<Record<string, ApercuPortrait>>
  desactive?: boolean
}>()

const emit = defineEmits<{
  basculer: [cle: string, cochee: boolean]
  ajuster: [cle: string]
}>()

const identifiant = useId()

function apercuDe(cle: string): ApercuPortrait {
  return apercus[cle] ?? { etat: 'chargement' }
}

/**
 * L'image d'origine placée pour que le carré voulu remplisse le rond. En
 * pourcentages du rond (carré, donc `top` et `left` se rapportent au même côté) :
 * l'aperçu suit la taille de la carte sans rien recalculer.
 */
function styleApercu(apercu: Extract<ApercuPortrait, { etat: 'pret' }>): Record<string, string> {
  const zone = zoneDepuisCadre(apercu.cadre, apercu)
  const pc = (v: number) => `${(v / zone.cote) * 100}%`
  return {
    width: pc(apercu.largeur),
    height: pc(apercu.hauteur),
    left: pc(-zone.x),
    top: pc(-zone.y),
  }
}
</script>

<template>
  <section class="flex flex-col gap-3" :aria-labelledby="`${identifiant}-titre`">
    <div>
      <h2 :id="`${identifiant}-titre`" class="m-0 text-base font-semibold">Portraits repérés</h2>
      <p class="m-0 mt-0.5 text-sm text-attenue">
        L’assistant a reconnu des visages dans vos photos. Chaque portrait coché sera proposé comme
        photo de la personne, en attente de validation.
      </p>
    </div>

    <ul class="m-0 flex list-none flex-col gap-3 p-0">
      <li v-for="portrait in portraits" :key="portrait.cle">
        <article
          class="rounded-panneau border bg-panneau transition-colors"
          :class="cochees.has(portrait.cle) ? 'border-accent' : 'border-ligne opacity-80'"
          :aria-labelledby="`${identifiant}-${portrait.cle}`"
        >
          <div class="flex items-start gap-3 px-4 py-3.5">
            <label
              class="-m-2 flex size-11 flex-none cursor-pointer items-center justify-center"
              :class="desactive || apercuDe(portrait.cle).etat === 'illisible' ? 'cursor-not-allowed' : ''"
            >
              <input
                type="checkbox"
                class="size-[18px] cursor-pointer accent-accent"
                :checked="cochees.has(portrait.cle)"
                :disabled="desactive || apercuDe(portrait.cle).etat === 'illisible'"
                :aria-label="`Inclure le portrait : ${personneDuPortrait(portrait, demandes).nom ?? 'nouvelle personne proposée'}`"
                @change="emit('basculer', portrait.cle, ($event.target as HTMLInputElement).checked)"
              />
            </label>

            <!-- L'aperçu, en rond : ce que l'arbre montrera. -->
            <div
              class="relative size-20 flex-none overflow-hidden rounded-full border border-ligne bg-fond md:size-24"
            >
              <img
                v-if="apercuDe(portrait.cle).etat === 'pret'"
                :src="(apercuDe(portrait.cle) as Extract<ApercuPortrait, { etat: 'pret' }>).url"
                alt=""
                draggable="false"
                class="absolute max-w-none"
                :style="styleApercu(apercuDe(portrait.cle) as Extract<ApercuPortrait, { etat: 'pret' }>)"
              />
              <span
                v-else
                class="flex h-full w-full items-center justify-center px-1 text-center text-[11px] text-attenue"
              >
                {{ apercuDe(portrait.cle).etat === 'illisible' ? 'Aperçu indisponible' : 'Aperçu…' }}
              </span>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
              <span class="text-[11.5px] tracking-wide text-attenue uppercase">
                Portrait · document {{ portrait.document + 1 }}
              </span>

              <h3 :id="`${identifiant}-${portrait.cle}`" class="m-0 text-[15px] leading-snug font-semibold">
                <template v-if="personneDuPortrait(portrait, demandes).personId !== null">
                  <RouterLink
                    :to="{ name: 'personne.detail', params: { id: personneDuPortrait(portrait, demandes).personId } }"
                    class="text-texte underline decoration-ligne underline-offset-2 hover:decoration-accent"
                  >
                    {{ personneDuPortrait(portrait, demandes).nom }}
                  </RouterLink>
                  <span
                    v-if="personneDuPortrait(portrait, demandes).dates !== null"
                    class="ml-1.5 text-xs font-normal text-attenue tabular-nums"
                  >
                    {{ personneDuPortrait(portrait, demandes).dates }}
                  </span>
                </template>
                <template v-else>
                  <span v-if="personneDuPortrait(portrait, demandes).nom !== null">
                    {{ personneDuPortrait(portrait, demandes).nom }}
                  </span>
                  <span
                    class="rounded-pastille border border-ligne bg-fond px-2 py-0.5 text-[11.5px] font-medium text-attenue"
                    :class="personneDuPortrait(portrait, demandes).nom !== null ? 'ml-1.5' : ''"
                  >
                    nouvelle personne proposée
                  </span>
                </template>
              </h3>

              <p class="m-0 text-sm text-attenue">{{ portrait.justification }}</p>

              <p
                v-if="cochees.has(portrait.cle) && empechementDuPortrait(portrait, demandesCochees) !== null"
                class="m-0 rounded-champ border border-alerte-bord bg-alerte-doux px-2.5 py-1.5 text-xs"
              >
                {{ empechementDuPortrait(portrait, demandesCochees) }}
              </p>
              <p v-if="apercuDe(portrait.cle).etat === 'illisible'" class="m-0 text-xs text-alerte">
                Cette image ne peut pas être lue ici : le portrait ne peut pas être préparé.
              </p>

              <div class="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  class="inline-flex min-h-11 items-center justify-center rounded-champ border border-ligne bg-panneau px-3 text-sm font-semibold text-texte transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-55 md:min-h-9"
                  :disabled="desactive || apercuDe(portrait.cle).etat !== 'pret'"
                  @click="emit('ajuster', portrait.cle)"
                >
                  Ajuster le cadrage
                </button>
                <span
                  v-if="apercuDe(portrait.cle).etat === 'pret' && (apercuDe(portrait.cle) as Extract<ApercuPortrait, { etat: 'pret' }>).ajuste"
                  class="text-xs text-attenue"
                >
                  Cadrage ajusté
                </span>
              </div>
            </div>
          </div>
        </article>
      </li>
    </ul>
  </section>
</template>
