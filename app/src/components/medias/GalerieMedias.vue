<script setup lang="ts">
/**
 * Les preuves d'une personne : la galerie, et le dépôt.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi la galerie charge ses médias elle-même, alors que la fiche en
 * porte déjà une liste.**
 *
 * `GET /api/arbre/personnes/{id}` rend `medias` — mais c'est la **vue validée**
 * de la fiche, et elle ne dit pas le statut de chaque pièce. Or la moitié de ce
 * que cet écran doit montrer est justement ce qui **attend une décision** : le
 * dépôt d'hier qui n'est pas encore validé, celui qui a été refusé. Le § 7.3 les
 * sert sur une autre route, avec `status=en_attente` — « un membre qui demande
 * `en_attente` ne reçoit **que les siens** ; un administrateur reçoit tous ceux
 * en attente, c'est sa file de validation ».
 *
 * D'où **deux requêtes** : `status=actif` (le défaut) et `status=en_attente`. Le
 * contrat ne prévoit pas d'en demander deux d'un coup, et inventer
 * `status=actif,en_attente` serait une syntaxe que personne n'a écrite côté
 * serveur. `fusionnerMedias()` dédoublonne le cas où un média change de statut
 * entre les deux appels.
 *
 * Et ce découplage a un avantage concret : déposer ou supprimer une pièce
 * recharge **la galerie**, pas la fiche entière — le lecteur ne perd ni sa
 * position ni le repli qu'il avait ouvert.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Le filtrage n'est pas fait ici.** Une personne vivante hors cercle n'a
 * aucun média : la route rend une liste vide, et le fichier rend `404` (§ 11.4,
 * porte n° 5). Ce composant n'est d'ailleurs monté que sur une fiche complète.
 *
 * Deux **actions** sont réservées, et ce sont les seules choses que le rôle du
 * lecteur décide ici :
 *
 * - **déposer** exige un rattachement validé (`MEMBRE`, § 7) — un compte en
 *   attente recevrait `403 regime_en_attente`, et aucun geste de sa part ne
 *   l'active : c'est l'administrateur qui valide ;
 * - **supprimer** exige `ROLE_ADMIN` (§ 7.6) — « un membre ne supprime pas un
 *   média validé : il dépose une demande ».
 *
 * Les deux sont doublées côté serveur. Masquer un bouton n'est pas une mesure de
 * sécurité ; c'est éviter de proposer un geste qui sera refusé.
 */
import { computed, ref, watch } from 'vue'
import { listerMedias, supprimerMedia, type Media } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { fusionnerMedias, type CibleLoupe } from './medias'
import TeleversementMedia from './TeleversementMedia.vue'
import VignetteMedia from './VignetteMedia.vue'

const { personId, nom } = defineProps<{ personId: number; nom: string }>()

const emit = defineEmits<{ agrandir: [cible: CibleLoupe] }>()

const { rattache, administrateur, vueEmpruntee } = useSession()

const medias = ref<Media[]>([])
const erreur = ref<unknown>(null)
const chargement = ref(false)
/** Une erreur **par média**, jamais une alerte globale : la suppression de la
 *  pièce n° 88 ne doit pas effacer les douze autres de l'écran. */
const erreurParMedia = ref<Record<number, unknown>>({})

/**
 * Sous la bascule « voir comme un membre », **toute écriture rend
 * `409 vue_comme_membre_active`** (§ 10.4). On n'offre donc pas le dépôt : le
 * bandeau permanent du cadre dit déjà pourquoi, et l'API refuse de son côté.
 */
const peutDeposer = computed(() => rattache.value && vueEmpruntee.value === null)
const peutSupprimer = computed(() => administrateur.value && vueEmpruntee.value === null)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  erreurParMedia.value = {}
  try {
    const [valides, attente] = await Promise.all([
      listerMedias({ subjectKind: 'personne', subjectId: personId }),
      listerMedias({ subjectKind: 'personne', subjectId: personId, status: 'en_attente' }),
    ])
    medias.value = fusionnerMedias(valides, attente)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

watch(() => personId, charger, { immediate: true })

async function supprimer(id: number): Promise<void> {
  // L'erreur va sur **ce** média : un `409 media_reference` dit que la pièce est
  // encore citée quelque part, et c'est une information sur elle, pas sur la
  // galerie.
  const sansCelui = { ...erreurParMedia.value }
  delete sansCelui[id]
  erreurParMedia.value = sansCelui
  try {
    await supprimerMedia(id)
    medias.value = medias.value.filter((media) => media.id !== id)
  } catch (cause) {
    erreurParMedia.value = { ...erreurParMedia.value, [id]: cause }
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <SqueletteChargement v-if="chargement && medias.length === 0" :lignes="2" hauteur="h-24" />

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <template v-else>
      <p v-if="medias.length === 0" class="m-0 text-sm text-attenue italic">
        Aucun document n’appuie encore cette fiche.
      </p>

      <ul
        v-else
        class="m-0 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 md:grid-cols-4"
      >
        <li v-for="media in medias" :key="media.id" class="m-0">
          <VignetteMedia
            :media="media"
            :supprimable="peutSupprimer"
            :erreur="erreurParMedia[media.id]"
            @agrandir="emit('agrandir', $event)"
            @supprimer="supprimer"
          />
        </li>
      </ul>
    </template>

    <TeleversementMedia
      v-if="peutDeposer"
      :person-id="personId"
      :nom="nom"
      class="border-t border-ligne pt-4"
      @televerse="charger"
    />
  </div>
</template>
