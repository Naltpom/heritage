<script setup lang="ts">
/**
 * Le suivi de l'organisateur : qui vient, qui ne vient pas, **qui a vu sans
 * répondre** — les fantômes 👻 —, et qui n'a pas encore ouvert l'annonce.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Rien ici n'arrive à un invité.** Le serveur ne rend `suivi` qu'à qui gère
 * l'événement ; ce composant n'est monté que s'il est là.
 * ────────────────────────────────────────────────────────────────────
 *
 * Quatre groupes, dans l'ordre où l'organisateur les lit : les réponses
 * d'abord (ce qu'il sait), puis les silences (ce qu'il doit faire). Chaque
 * ligne dit **depuis quand** — « vu il y a 2 j », « a répondu hier » — et, pour
 * ceux qui n'ont pas vu, s'ils ont un compte (ils ont reçu la notification) ou
 * s'il faut leur envoyer un lien.
 *
 * En tête, **« Relancer »** : une notification aux invités qui ont un compte et
 * n'ont pas répondu, une fois par jour au plus — le bouton dit pourquoi il est
 * éteint. Pour ceux sans compte, l'application ne peut rien envoyer : le
 * rappel le dit, et leurs lignes portent le geste du lien.
 *
 * **Noter une réponse à la place de l'invité** (ADR 0014, addendum du
 * 02/10/2026) — « grand-mère a répondu par téléphone » : chaque ligne porte un
 * « Noter sa réponse » discret, qui déplie les deux boutons Présent / Absent.
 * La ligne dit ensuite « noté par … » : la réponse n'est pas une vue, et le
 * suivi ne prétend pas que l'invité a ouvert l'annonce.
 *
 * Le composant **ne charge ni n'écrit rien** : il émet, la page décide et fait
 * confirmer. Suite : `suivi-des-invites-rendu.test.ts`.
 */
import { computed, ref, watch } from 'vue'
import type { LigneDeSuivi, ReponseAInvitation, SuiviDEvenement } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import BoutonsDeReponse from './BoutonsDeReponse.vue'
import ActionsDuLien from './ActionsDuLien.vue'
import LienDeLInvite from './LienDeLInvite.vue'
import { GROUPES_DU_SUIVI, detailDeLaLigne, etatDeLaRelance, rappelDesLiens } from './evenements'

export interface LienAffiche {
  personId: number
  libelle: string
  adresse: string
  message: string
  copie: boolean
}

const {
  suivi,
  maintenant,
  modifiable,
  desactive = false,
  enCours = null,
  lienAffiche = null,
} = defineProps<{
  suivi: SuiviDEvenement
  maintenant: Date
  /** Ni annulé ni terminé : on peut encore inviter, retirer, relancer. */
  modifiable: boolean
  desactive?: boolean
  /**
   * La clé du geste en cours (`relancer`, `retirer-12`, `lien-12`,
   * `noter-12-present`), pour son bouton.
   */
  enCours?: string | null
  lienAffiche?: LienAffiche | null
}>()

const emit = defineEmits<{
  relancer: []
  retirer: [ligne: LigneDeSuivi]
  copierLien: [ligne: LigneDeSuivi]
  revoquerLien: [ligne: LigneDeSuivi]
  fermerLien: []
  noterReponse: [ligne: LigneDeSuivi, reponse: ReponseAInvitation]
}>()

/** La ligne dont « Noter sa réponse » est déplié — une à la fois. */
const ligneOuverte = ref<number | null>(null)

// Le suivi relu (la réponse est enregistrée) replie la ligne ; une erreur la
// laisse dépliée, pour réessayer.
watch(
  () => suivi,
  () => (ligneOuverte.value = null),
)

/** La réponse que porte une ligne, déduite de son groupe. */
function reponseDuGroupe(groupe: string): ReponseAInvitation | null {
  return groupe === 'presents' ? 'present' : groupe === 'absents' ? 'absent' : null
}

function noterEnCours(personId: number): ReponseAInvitation | null {
  if (enCours === `noter-${personId}-present`) return 'present'
  if (enCours === `noter-${personId}-absent`) return 'absent'
  return null
}

const relance = computed(() => etatDeLaRelance(suivi, maintenant))
const rappel = computed(() => rappelDesLiens(suivi))
const total = computed(() => GROUPES_DU_SUIVI.reduce((n, g) => n + suivi[g.cle].length, 0))

const TONS: Record<string, string> = {
  presents: 'bg-ok-doux text-ok',
  absents: 'bg-fond text-attenue border border-ligne',
  fantomes: 'bg-alerte-doux text-alerte',
  nonVus: 'bg-accent-doux text-accent',
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Les quatre compteurs, d'un coup d'œil. -->
    <dl class="grid grid-cols-4 gap-2 text-center" :aria-label="`Suivi des ${total} invités`">
      <div v-for="g in GROUPES_DU_SUIVI" :key="g.cle" class="rounded-panneau px-1 py-2" :class="TONS[g.cle]">
        <dt class="text-[11px] leading-tight font-semibold">{{ g.cle === 'fantomes' ? 'Vu, sans réponse' : g.titre }}</dt>
        <dd class="text-xl font-bold tnum">{{ suivi[g.cle].length }}</dd>
      </div>
    </dl>

    <div v-if="modifiable" class="flex flex-col gap-1.5 rounded-panneau border border-ligne bg-fond p-3">
      <BoutonBase
        :desactive="desactive || !relance.actif"
        :chargement="enCours === 'relancer'"
        pleine-largeur
        @click="emit('relancer')"
      >
        {{ relance.libelle }}
      </BoutonBase>
      <p class="text-xs text-attenue">{{ relance.aide }}</p>
      <p v-if="rappel !== null" class="text-xs font-semibold text-texte">{{ rappel }}</p>
    </div>

    <section v-for="g in GROUPES_DU_SUIVI" :key="g.cle" :aria-labelledby="`suivi-${g.cle}`">
      <div class="mb-1 flex items-baseline justify-between gap-2">
        <h3 :id="`suivi-${g.cle}`" class="text-sm font-semibold text-texte">
          {{ g.titre }} <span class="tnum text-attenue">({{ suivi[g.cle].length }})</span>
        </h3>
        <span class="text-xs text-attenue">{{ g.aide }}</span>
      </div>

      <p v-if="suivi[g.cle].length === 0" class="text-xs text-attenue">Personne.</p>

      <ul v-else class="flex flex-col divide-y divide-ligne">
        <li v-for="l in suivi[g.cle]" :key="l.personId" class="flex flex-col gap-2 py-2">
          <div class="flex min-h-11 items-center justify-between gap-3">
            <span class="min-w-0">
              <span class="block truncate text-[15px] text-texte">{{ l.libelle }}<template v-if="l.estMoi"> (vous)</template></span>
              <span class="block text-xs text-attenue">{{ detailDeLaLigne(l, g.cle, maintenant) }}</span>
            </span>
            <BoutonBase
              v-if="modifiable"
              variante="discret"
              :chargement="enCours === `retirer-${l.personId}`"
              :desactive="desactive || enCours !== null"
              :aria-label="`Retirer ${l.libelle} des invités`"
              @click="emit('retirer', l)"
            >
              Retirer
            </BoutonBase>
          </div>

          <!-- Une réponse donnée de vive voix : l'organisateur la note. -->
          <div v-if="modifiable && l.peutNoterReponse" class="flex flex-col gap-2">
            <button
              v-if="ligneOuverte !== l.personId"
              type="button"
              class="self-start text-xs text-accent underline disabled:opacity-55"
              :disabled="desactive || enCours !== null"
              @click="ligneOuverte = l.personId"
            >
              {{ reponseDuGroupe(g.cle) === null ? 'Noter sa réponse' : 'Corriger sa réponse' }}
            </button>
            <div v-else class="flex flex-col gap-1.5 rounded-panneau border border-ligne bg-fond p-2">
              <p class="text-xs text-attenue">Réponse donnée de vive voix — elle sera notée à votre nom :</p>
              <BoutonsDeReponse
                :reponse="reponseDuGroupe(g.cle)"
                :en-cours="noterEnCours(l.personId)"
                :desactive="desactive"
                @choisir="(r) => emit('noterReponse', l, r)"
              />
              <button type="button" class="self-start text-xs text-attenue underline" @click="ligneOuverte = null">Fermer</button>
            </div>
          </div>

          <!-- Sans compte, l'invité répond par son lien : l'organisateur le crée et l'envoie. -->
          <ActionsDuLien
            v-if="l.peutPartagerLien"
            :lien-actif="l.lienActif"
            :chargement="enCours === `lien-${l.personId}`"
            :desactive="desactive || enCours !== null"
            @copier="emit('copierLien', l)"
            @revoquer="emit('revoquerLien', l)"
          />

          <LienDeLInvite
            v-if="lienAffiche !== null && lienAffiche.personId === l.personId"
            :libelle="lienAffiche.libelle"
            :adresse="lienAffiche.adresse"
            :message="lienAffiche.message"
            :deja-copie="lienAffiche.copie"
            @fermer="emit('fermerLien')"
          />
        </li>
      </ul>
    </section>

    <p class="text-xs text-attenue">
      Ce suivi n’est visible que de l’organisateur et des administrateurs. Les invités voient qui vient, pas qui a vu ni qui a décliné.
    </p>
  </div>
</template>
