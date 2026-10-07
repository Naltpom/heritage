<script setup lang="ts">
/**
 * « Qui voit ma date de naissance ? » — pour l'année, le mois et le jour,
 * chacun **indépendamment** : tous les vivants de l'arbre, mes proches, ou
 * personne (ADR 0016, révision du 7 octobre 2026).
 *
 * « Le jour et le mois à tous, l'année à mes proches » est le choix le plus
 * courant : on souhaite l'anniversaire sans dire l'âge. Aucune ligne n'en
 * entraîne une autre, et l'écran n'en déduit rien : c'est le serveur qui
 * réduit les dates, à la composante près.
 *
 * La règle est **réciproque**, et le texte le dit avant qu'on choisisse : on
 * voit d'une personne vivante ce que chacun montre à l'autre — à ses proches
 * dès que les deux sont au moins à « Mes proches », aux autres si les deux
 * sont à « Tous ». Les personnes décédées ne sont pas concernées. La phrase
 * sur la photo dit l'autre moitié de la règle — sans photo de soi, celles des
 * vivants arrivent floutées — parce qu'on la découvre sinon sans explication.
 *
 * **Le verrou** : partager avec tous est immédiat, mais une composante à
 * « Tous » le reste au moins trente jours. La phrase d'avertissement le dit
 * **avant** qu'on choisisse. Une ligne à « Tous » que `verrou` mentionne a ses
 * deux autres choix éteints, avec la date jusqu'à laquelle elle tient et le
 * moyen d'en sortir plus tôt : une demande RGPD (`@demande-rgpd`, avec la
 * composante). Entre « Mes proches » et « Personne », rien n'est jamais
 * éteint. À l'inscription, il n'y a pas d'état antérieur, donc pas de verrou.
 *
 * Servi à l'inscription (étape 2), dans « Mon compte », et à l'administrateur
 * qui dépose l'autorisation signée d'un vivant sans compte. Le composant ne
 * parle pas au serveur : il tient l'objet, l'écran l'envoie.
 */
import { computed, useId } from 'vue'
import type { NiveauDePartage, PartageNaissance, VerrouDePartage } from '@/lib/api'
import { NIVEAUX, dateDuVerrou, verrouillee } from './partageNaissance'

const {
  erreurs = {},
  desactive = false,
  legende = 'Qui voit ma date de naissance ?',
  sansExplication = false,
  verrou = {},
  avertissementAppuye = false,
} = defineProps<{
  /** Les refus du serveur, par composante (`annee`, `mois`, `jour`). */
  erreurs?: Partial<Record<keyof PartageNaissance, string>>
  desactive?: boolean
  legende?: string
  /** Pour l'autorisation signée d'un tiers : la règle s'adresse au membre
   *  (« vous »), elle n'a rien à dire à l'administrateur qui choisit pour un autre. */
  sansExplication?: boolean
  /** Les composantes passées à « Tous » il y a moins de trente jours (`verrouJusquAu`). */
  verrou?: VerrouDePartage
  /** L'avertissement des trente jours en gras : une composante vient de passer à « Tous ». */
  avertissementAppuye?: boolean
}>()

const emit = defineEmits<{
  /** « Faites une demande RGPD » sous une ligne verrouillée. */
  'demande-rgpd': [cle: keyof PartageNaissance]
}>()

const partage = defineModel<PartageNaissance>({ required: true })

const id = useId()

const COMPOSANTES: readonly { cle: keyof PartageNaissance; libelle: string }[] = [
  { cle: 'annee', libelle: 'Année' },
  { cle: 'mois', libelle: 'Mois' },
  { cle: 'jour', libelle: 'Jour' },
]

/** L'avertissement tient tant qu'il reste une ligne qui ne soit pas déjà à
 *  « Tous » et verrouillée. */
const resteAOuvrir = computed(() =>
  COMPOSANTES.some((composante) => verrouillee(partage.value, verrou, composante.cle) === null),
)

/** Un choix éteint : tout désactivé, ou une ligne verrouillée à « Tous » dont
 *  on voudrait descendre. */
function eteint(cle: keyof PartageNaissance, niveau: NiveauDePartage): boolean {
  return desactive || (niveau !== 'tous' && verrouillee(partage.value, verrou, cle) !== null)
}

/** Un nouvel objet à chaque choix : le parent garde un `ref` simple, et
 *  `defineModel` ne voit pas une mutation en place. */
function choisir(cle: keyof PartageNaissance, niveau: NiveauDePartage): void {
  partage.value = { ...partage.value, [cle]: niveau }
}
</script>

<template>
  <fieldset
    class="flex flex-col gap-2"
    :aria-describedby="sansExplication ? undefined : `${id}-regle`"
    data-partage-naissance
  >
    <legend class="mb-1 text-sm font-medium">{{ legende }}</legend>

    <div class="flex flex-col gap-1.5">
      <div
        v-for="composante in COMPOSANTES"
        :key="composante.cle"
        class="flex flex-wrap items-center gap-x-4 gap-y-1"
        role="radiogroup"
        :aria-label="composante.libelle"
        :data-ligne="composante.cle"
      >
        <span class="w-14 text-sm font-medium">{{ composante.libelle }}</span>
        <label
          v-for="choix in NIVEAUX"
          :key="choix.niveau"
          class="flex items-center gap-1.5 text-sm"
          :class="eteint(composante.cle, choix.niveau) ? 'text-attenue' : ''"
        >
          <input
            type="radio"
            class="size-4 accent-[var(--color-accent)]"
            :name="`${id}-${composante.cle}`"
            :value="choix.niveau"
            :checked="partage[composante.cle] === choix.niveau"
            :disabled="eteint(composante.cle, choix.niveau)"
            :data-composante="composante.cle"
            :data-niveau="choix.niveau"
            @change="choisir(composante.cle, choix.niveau)"
          />
          {{ choix.libelle }}
        </label>
      </div>
    </div>

    <template v-for="composante in COMPOSANTES" :key="`verrou-${composante.cle}`">
      <p
        v-if="verrouillee(partage, verrou, composante.cle) !== null"
        class="text-xs text-attenue"
        :data-verrou="composante.cle"
      >
        {{ composante.libelle }} — Partagé avec tous jusqu’au
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
        v-if="resteAOuvrir"
        class="text-xs leading-relaxed"
        :class="avertissementAppuye ? 'font-semibold text-alerte' : 'text-attenue'"
        data-avertissement-partage
      >
        Une information partagée avec tous le reste au moins 30 jours.
      </p>
      <p :id="`${id}-regle`" class="text-xs leading-relaxed text-attenue">
        C’est réciproque : vous voyez la date d’une personne vivante seulement si chacun la montre à
        l’autre — celle de vos proches si vous êtes tous deux au moins à « Mes proches », celle des
        autres si vous êtes tous deux à « Tous ». Les personnes décédées ne sont pas concernées.
        Modifiable ensuite dans votre compte.
      </p>
      <p class="text-xs leading-relaxed text-attenue" data-definition-proches>
        Vos proches : vos parents et grands-parents, vos enfants et petits-enfants, vos frères et
        sœurs, votre conjoint, ses parents et grands-parents, les conjoints de vos enfants et petits-enfants.
      </p>
      <p class="text-xs leading-relaxed text-attenue">
        Sans photo de vous (même en attente de validation), les photos des personnes vivantes
        s’affichent floutées.
      </p>
    </template>
  </fieldset>
</template>
