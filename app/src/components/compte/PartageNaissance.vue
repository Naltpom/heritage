<script setup lang="ts">
/**
 * « J'accepte de partager ma date de naissance » — trois cases **indépendantes**
 * (ADR 0016, réciprocité des vivants).
 *
 * Année, mois, jour se cochent séparément : « le jour et le mois, pas
 * l'année » est le choix le plus courant — on souhaite l'anniversaire sans
 * dire l'âge. Aucune case n'en entraîne une autre, et l'écran n'en déduit
 * rien : c'est le serveur qui réduit les dates, à la composante près.
 *
 * La règle est **réciproque**, et le texte le dit avant qu'on coche : ce
 * qu'on ne partage pas, on ne le voit pas non plus chez les autres personnes
 * vivantes. Les personnes décédées ne sont pas concernées. La phrase sur la
 * photo dit l'autre moitié de la règle — sans photo de soi, celles des
 * vivants arrivent floutées — parce qu'on la découvre sinon sans explication.
 *
 * **Le verrou** : partager est immédiat, mais une composante partagée reste
 * visible au moins trente jours. La phrase d'avertissement le dit **avant**
 * qu'on coche. Une case cochée que `verrou` mentionne est éteinte, avec la
 * date jusqu'à laquelle elle tient et le moyen de la retirer plus tôt : une
 * demande RGPD (`@demande-rgpd`, avec la composante). Une case non cochée
 * n'est jamais éteinte. À l'inscription, il n'y a pas d'état antérieur, donc
 * pas de verrou.
 *
 * Servi à l'inscription (étape 2) et dans « Mon compte ». Le composant ne
 * parle pas au serveur : il tient l'objet, l'écran l'envoie.
 */
import { computed, useId } from 'vue'
import type { PartageNaissance, VerrouDePartage } from '@/lib/api'
import { dateDuVerrou, verrouillee } from './partageNaissance'

const {
  erreurs = {},
  desactive = false,
  legende = 'J’accepte de partager ma date de naissance',
  sansExplication = false,
  verrou = {},
  avertissementAppuye = false,
} = defineProps<{
  /** Les refus du serveur, par composante (`annee`, `mois`, `jour`). */
  erreurs?: Partial<Record<keyof PartageNaissance, string>>
  desactive?: boolean
  legende?: string
  /** Pour l'autorisation signée d'un tiers : la règle s'adresse au membre
   *  (« vous »), elle n'a rien à dire à l'administrateur qui coche pour un autre. */
  sansExplication?: boolean
  /** Les composantes partagées il y a moins de trente jours (`verrouJusquAu`). */
  verrou?: VerrouDePartage
  /** L'avertissement des trente jours en gras : une case vient d'être cochée. */
  avertissementAppuye?: boolean
}>()

const emit = defineEmits<{
  /** « Faites une demande RGPD » sous une case verrouillée. */
  'demande-rgpd': [cle: keyof PartageNaissance]
}>()

const partage = defineModel<PartageNaissance>({ required: true })

const id = useId()

const COMPOSANTES: readonly { cle: keyof PartageNaissance; libelle: string }[] = [
  { cle: 'annee', libelle: 'Année' },
  { cle: 'mois', libelle: 'Mois' },
  { cle: 'jour', libelle: 'Jour' },
]

/** Un nouvel objet à chaque case : le parent garde un `ref` simple, et
 *  `defineModel` ne voit pas une mutation en place. */
/** L'avertissement tient tant qu'il reste une case à cocher qui ne soit pas
 *  déjà partagée et verrouillée. */
const resteACocher = computed(() =>
  COMPOSANTES.some((composante) => verrouillee(partage.value, verrou, composante.cle) === null),
)

function cocher(cle: keyof PartageNaissance, valeur: boolean): void {
  partage.value = { ...partage.value, [cle]: valeur }
}
</script>

<template>
  <fieldset
    class="flex flex-col gap-2"
    :aria-describedby="sansExplication ? undefined : `${id}-regle`"
    data-partage-naissance
  >
    <legend class="mb-1 text-sm font-medium">{{ legende }}</legend>

    <div class="flex flex-wrap gap-x-5 gap-y-1.5">
      <label v-for="composante in COMPOSANTES" :key="composante.cle" class="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          class="size-4 accent-[var(--color-accent)]"
          :checked="partage[composante.cle]"
          :disabled="desactive || verrouillee(partage, verrou, composante.cle) !== null"
          :data-composante="composante.cle"
          @change="cocher(composante.cle, ($event.target as HTMLInputElement).checked)"
        />
        {{ composante.libelle }}
      </label>
    </div>

    <template v-for="composante in COMPOSANTES" :key="`verrou-${composante.cle}`">
      <p
        v-if="verrouillee(partage, verrou, composante.cle) !== null"
        class="text-xs text-attenue"
        :data-verrou="composante.cle"
      >
        {{ composante.libelle }} — Partagé jusqu’au
        {{ dateDuVerrou(verrouillee(partage, verrou, composante.cle)!) }} au minimum. Pour le retirer avant,
        <button
          type="button"
          class="underline hover:text-texte"
          :data-demande-rgpd="composante.cle"
          @click="emit('demande-rgpd', composante.cle)"
        >faites une demande RGPD</button>.
      </p>
    </template>

    <template v-for="composante in COMPOSANTES" :key="`erreur-${composante.cle}`">
      <p v-if="erreurs[composante.cle]" class="text-xs text-alerte">{{ erreurs[composante.cle] }}</p>
    </template>

    <template v-if="!sansExplication">
      <p
        v-if="resteACocher"
        class="text-xs leading-relaxed"
        :class="avertissementAppuye ? 'font-semibold text-alerte' : 'text-attenue'"
        data-avertissement-partage
      >
        Une information partagée reste visible des autres membres au moins 30 jours.
      </p>
      <p :id="`${id}-regle`" class="text-xs leading-relaxed text-attenue">
        Ce que vous ne partagez pas, vous ne le verrez pas non plus chez les autres personnes vivantes.
        Les personnes décédées ne sont pas concernées. Modifiable ensuite dans votre compte.
      </p>
      <p class="text-xs leading-relaxed text-attenue">
        Sans photo de vous (même en attente de validation), les photos des personnes vivantes
        s’affichent floutées.
      </p>
    </template>
  </fieldset>
</template>
