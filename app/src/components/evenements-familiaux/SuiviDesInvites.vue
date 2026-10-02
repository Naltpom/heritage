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
 * Le composant **ne charge ni n'écrit rien** : il émet, la page décide et fait
 * confirmer. Suite : `suivi-des-invites-rendu.test.ts`.
 */
import { computed } from 'vue'
import type { LigneDeSuivi, SuiviDEvenement } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ActionsDuLien from '@/components/tirages/ActionsDuLien.vue'
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
  /** La clé du geste en cours (`relancer`, `retirer-12`, `lien-12`), pour son bouton. */
  enCours?: string | null
  lienAffiche?: LienAffiche | null
}>()

const emit = defineEmits<{
  relancer: []
  retirer: [ligne: LigneDeSuivi]
  copierLien: [ligne: LigneDeSuivi]
  revoquerLien: [ligne: LigneDeSuivi]
  fermerLien: []
}>()

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
