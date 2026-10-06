<script setup lang="ts">
/**
 * **« Demander » : relire avant d'envoyer — ou apprendre que la personne
 * existe déjà** (§ 6.3 ter).
 *
 * Deux visages, une seule modale, parce que c'est un seul geste pour le
 * contributeur : il a cliqué « Envoyer la proposition », et l'écran lui répond
 * avant que rien ne parte.
 *
 * - **Aucun doublon** : le récapitulatif de ce qui va partir, et « Confirmer
 *   l'envoi ». Si la vérification n'a pas pu se faire (réseau, `429`), un mot
 *   discret le dit — on ne bloque pas une contribution pour un contrôle de
 *   confort.
 * - **Des doublons** : « Attention : cette personne existe déjà dans l'arbre »
 *   ou « … est déjà en cours de validation », la correspondance la plus proche
 *   (et de quoi passer aux autres), la comparaison champ par champ — identique,
 *   diverge, ajouté par vous, déjà connu —, puis trois issues : **c'est bien une
 *   nouvelle personne**, **mettre à jour** (ma demande) ou **proposer une
 *   modification** (la fiche de l'arbre), **ne rien faire**.
 *
 * La demande d'un autre membre n'est jamais une correspondance : le serveur
 * n'en dit qu'un drapeau, sans contenu (ADR 0004). La modale le dit en une
 * phrase, et n'offre aucune mise à jour.
 *
 * Un `<dialog>` natif ouvert en modal, comme `ModaleCorrection` : focus piégé,
 * Échap qui ferme (et vaut « ne rien faire »), reste de la page inerte. Le focus
 * part sur le geste qui n'envoie rien — un Entrée réflexe ne doit pas déposer.
 * La modale ne dépose rien elle-même : elle rapporte un choix au parent.
 */
import { computed, nextTick, ref, useId, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import type { DoublonsProbables, MaDemandeDoublon, PersonneDoublon } from '@/lib/api'
import type { SaisieCreation } from './creation'
import {
  LIBELLES_ETAT,
  aDesDoublons,
  apporteQuelqueChose,
  comparer,
  correspondances,
  datesDeCorrespondance,
  nomDeCorrespondance,
  recapitulatifCreation,
  titreAvertissement,
  type EtatComparaison,
} from './doublons'

const {
  ouverte,
  saisie,
  doublons = null,
  verificationEchouee = false,
  envoi = false,
  erreur = null,
} = defineProps<{
  /** Le parent tient l'ouverture. */
  ouverte: boolean
  saisie: SaisieCreation
  /** La réponse du serveur ; `null` quand la vérification a échoué. */
  doublons?: DoublonsProbables | null
  verificationEchouee?: boolean
  envoi?: boolean
  erreur?: unknown
}>()

const emit = defineEmits<{
  /** « C'est bien une nouvelle personne » / « Confirmer l'envoi ». */
  nouvelle: []
  completer: [demande: MaDemandeDoublon]
  modifier: [personne: PersonneDoublon]
  annuler: []
}>()

const dialogue = ref<HTMLDialogElement | null>(null)
const prudent = ref<InstanceType<typeof BoutonBase> | null>(null)
const id = useId()

const avertir = computed(() => doublons !== null && aDesDoublons(doublons))
const liste = computed(() => (doublons === null ? [] : correspondances(doublons)))
const rang = ref(0)
const choisie = computed(() => liste.value[rang.value] ?? null)
const lignes = computed(() => (choisie.value === null ? [] : comparer(saisie, choisie.value)))
const recapitulatif = computed(() => recapitulatifCreation(saisie))

// Une nouvelle réponse repart de la meilleure correspondance.
watch(
  () => doublons,
  () => {
    rang.value = 0
  },
)

watch(
  () => ouverte,
  async (actuelle) => {
    const element = dialogue.value
    if (element === null) return
    if (actuelle && !element.open) {
      element.showModal()
      await nextTick()
      ;(prudent.value?.$el as HTMLElement | undefined)?.focus()
    } else if (!actuelle && element.open) {
      element.close()
    }
  },
  { flush: 'post' },
)

/** Échap ferme le `<dialog>` de lui-même : c'est « ne rien faire ». */
function surFermeture(): void {
  if (ouverte) emit('annuler')
}

/** Le cadre d'une ligne, et la couleur de son état. L'état est **aussi écrit**
 *  en toutes lettres : la couleur seule ne dit rien à un lecteur d'écran, ni à
 *  qui ne distingue pas le vert du rouge. */
const CADRE_ETAT: Record<EtatComparaison, string> = {
  identique: 'border-ok-bord bg-ok-doux',
  divergent: 'border-alerte-bord bg-alerte-doux',
  ajoute: 'border-edition-bord bg-edition-doux',
  existant: 'border-ligne bg-fond',
  non_comparable: 'border-ligne bg-fond',
}

const TEXTE_ETAT: Record<EtatComparaison, string> = {
  identique: 'text-ok',
  divergent: 'text-alerte',
  ajoute: 'text-edition-texte',
  existant: 'text-attenue',
  non_comparable: 'text-attenue',
}
</script>

<template>
  <dialog
    ref="dialogue"
    class="m-auto w-[min(40rem,calc(100vw-1.5rem))] rounded-panneau border border-ligne bg-panneau p-0 text-texte shadow-panneau backdrop:bg-black/50"
    :aria-labelledby="`${id}-titre`"
    :aria-describedby="`${id}-description`"
    data-modale-envoi-creation
    @close="surFermeture"
  >
    <div v-if="ouverte" class="flex max-h-[85dvh] flex-col gap-4 overflow-y-auto p-5 max-md:p-4">
      <!-- ── Des doublons ─────────────────────────────────────────── -->
      <template v-if="avertir && doublons !== null">
        <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold" data-titre>
          {{ titreAvertissement(choisie) }}
        </h2>
        <p :id="`${id}-description`" class="m-0 text-sm text-attenue">
          <template v-if="choisie !== null">
            Voici ce qui existe déjà, ce qui diverge et ce que vous ajoutez. Est-ce bien une nouvelle
            personne ?
          </template>
          <template v-else>
            Une personne semblable a déjà été proposée. Est-ce bien une nouvelle personne ?
          </template>
        </p>

        <!-- Plusieurs correspondances : on passe de l'une à l'autre. -->
        <div
          v-if="liste.length > 1"
          role="group"
          aria-label="Personnes qui ressemblent à votre saisie"
          class="flex flex-wrap gap-1.5"
          data-correspondances
        >
          <button
            v-for="(correspondance, index) in liste"
            :key="correspondance.cle"
            type="button"
            class="min-h-9 rounded-pastille border px-3 text-sm max-md:min-h-11"
            :class="index === rang ? 'border-accent bg-accent-doux font-semibold' : 'border-ligne hover:border-accent'"
            :aria-pressed="index === rang"
            @click="rang = index"
          >
            {{ nomDeCorrespondance(correspondance) }}
            <span class="text-xs text-attenue">
              · {{ correspondance.source === 'arbre' ? 'dans l’arbre' : 'votre demande' }}
            </span>
          </button>
        </div>

        <section v-if="choisie !== null" class="flex flex-col gap-2" data-correspondance>
          <p class="m-0 text-sm">
            <span class="font-semibold">{{ nomDeCorrespondance(choisie) }}</span>
            <span v-if="datesDeCorrespondance(choisie) !== ''" class="text-attenue">
              · {{ datesDeCorrespondance(choisie) }}
            </span>
            <br />
            <span class="text-xs text-attenue">
              <template v-if="choisie.source === 'arbre'">Fiche validée de l’arbre.</template>
              <template v-else>
                Votre demande n° {{ choisie.demande.demandeId }}, en attente de validation.
              </template>
            </span>
          </p>

          <ul class="m-0 flex list-none flex-col gap-1.5 p-0" data-comparaison>
            <li
              v-for="ligne in lignes"
              :key="ligne.cle"
              class="rounded-champ border px-2.5 py-1.5 text-sm"
              :class="CADRE_ETAT[ligne.etat]"
              :data-etat="ligne.etat"
              :data-champ="ligne.cle"
            >
              <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <span class="font-medium text-texte">{{ ligne.etiquette }}</span>
                <span class="text-xs font-semibold" :class="TEXTE_ETAT[ligne.etat]">
                  {{ LIBELLES_ETAT[ligne.etat] }}
                </span>
              </div>
              <dl class="m-0 mt-0.5 grid grid-cols-[auto_1fr] gap-x-2 text-texte">
                <template v-if="ligne.existant !== ''">
                  <dt class="text-xs text-attenue">Existant</dt>
                  <dd class="m-0 min-w-0 break-words">{{ ligne.existant }}</dd>
                </template>
                <template v-if="ligne.saisi !== '' && ligne.etat !== 'identique'">
                  <dt class="text-xs text-attenue">Votre saisie</dt>
                  <dd class="m-0 min-w-0 break-words">{{ ligne.saisi }}</dd>
                </template>
              </dl>
            </li>
          </ul>

          <p
            v-if="choisie.source === 'demande' && !apporteQuelqueChose(lignes)"
            class="m-0 text-xs text-attenue"
          >
            Votre saisie n’apporte rien de plus à cette demande.
          </p>
        </section>

        <p
          v-if="doublons.enCoursAilleurs"
          class="m-0 rounded-panneau border border-ligne bg-fond px-3 py-2 text-sm"
          role="note"
          data-en-cours-ailleurs
        >
          Un autre membre a déjà proposé une personne semblable : sa demande attend la validation d’un
          administrateur. Vous ne pouvez pas la modifier ; si vous envoyez la vôtre, l’administrateur
          rapprochera les deux.
        </p>

        <MessageErreur v-if="erreur !== null" :erreur="erreur" />

        <div class="flex flex-wrap justify-end gap-2 max-md:flex-col-reverse max-md:items-stretch">
          <BoutonBase ref="prudent" variante="secondaire" :desactive="envoi" class="max-md:min-h-11" @click="emit('annuler')">
            Ne rien faire
          </BoutonBase>
          <BoutonBase
            v-if="choisie?.source === 'demande'"
            variante="secondaire"
            :chargement="envoi"
            class="max-md:min-h-11"
            data-geste="completer"
            @click="emit('completer', choisie.demande)"
          >
            Mettre à jour la demande existante
          </BoutonBase>
          <BoutonBase
            v-else-if="choisie?.source === 'arbre'"
            variante="secondaire"
            :desactive="envoi"
            class="max-md:min-h-11"
            data-geste="modifier"
            @click="emit('modifier', choisie.personne)"
          >
            Proposer une modification de la fiche existante
          </BoutonBase>
          <BoutonBase :chargement="envoi" class="max-md:min-h-11" data-geste="nouvelle" @click="emit('nouvelle')">
            C’est bien une nouvelle personne
          </BoutonBase>
        </div>
      </template>

      <!-- ── Aucun doublon : le récapitulatif ──────────────────────── -->
      <template v-else>
        <h2 :id="`${id}-titre`" class="m-0 text-base font-semibold" data-titre>Relire avant d’envoyer</h2>
        <p :id="`${id}-description`" class="m-0 text-sm text-attenue">
          Un administrateur validera ou refusera cette proposition ; d’ici là, vous seul la voyez.
        </p>

        <p
          v-if="verificationEchouee"
          class="m-0 text-xs text-attenue"
          role="status"
          data-verification-echouee
        >
          La recherche de doublons n’a pas pu se faire : vérifiez que cette personne n’est pas déjà
          dans l’arbre avant d’envoyer.
        </p>

        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm" data-recapitulatif>
          <template v-for="ligne in recapitulatif" :key="ligne.etiquette">
            <dt class="text-attenue">{{ ligne.etiquette }}</dt>
            <dd class="m-0 min-w-0 break-words">{{ ligne.valeur }}</dd>
          </template>
        </dl>

        <MessageErreur v-if="erreur !== null" :erreur="erreur" />

        <div class="flex flex-wrap justify-end gap-2 max-md:flex-col-reverse max-md:items-stretch">
          <BoutonBase ref="prudent" variante="secondaire" :desactive="envoi" class="max-md:min-h-11" @click="emit('annuler')">
            Revenir
          </BoutonBase>
          <BoutonBase :chargement="envoi" class="max-md:min-h-11" data-geste="nouvelle" @click="emit('nouvelle')">
            Confirmer l’envoi
          </BoutonBase>
        </div>
      </template>
    </div>
  </dialog>
</template>
