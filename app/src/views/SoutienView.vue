<script setup lang="ts">
/**
 * « Soutenir le site » (`/soutenir`) — les dons Liberapay qui paient le serveur.
 *
 * **Le site ne touche à aucun paiement.** Le bouton mène à la page de don de
 * Liberapay ; c'est là que chacun choisit son montant, son rythme (une fois,
 * chaque semaine, chaque mois, chaque année) et s'il veut apparaître ou non.
 * Un don ne débloque rien ici : la page le dit, et c'est le principe.
 *
 * Ce que la page montre vient de `GET /api/soutien` : le rythme du moment
 * (dernier relevé Liberapay), une jauge vers l'objectif mensuel, et
 * l'historique par mois et par année. **Ce sont des dons promis**, pas de
 * l'argent encaissé — Liberapay prélève les donateurs à l'avance puis reverse
 * chaque semaine. L'historique commence au premier relevé : avant, on ne sait
 * rien, et on n'invente rien.
 *
 * **L'administrateur** règle ici le pseudo Liberapay et l'objectif, par la
 * route générique des réglages (`PATCH /api/administration/reglages/{cle}`).
 * Il n'existe pas d'autre écran de réglages : sans ce panneau, il faudrait
 * passer par `curl`. Le panneau n'est qu'une commodité — le serveur refuse la
 * route à tout autre compte.
 */
import { computed, onMounted, ref, watch } from 'vue'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { formaterMontant, libelleDuMois, partDeLObjectif, pseudoValide } from '@/components/soutien/soutien'
import { useAppelApi } from '@/composables/useAppelApi'
import { useSession } from '@/composables/useSession'
import {
  CLE_OBJECTIF_MENSUEL,
  CLE_PSEUDO_LIBERAPAY,
  analyserErreur,
  lireSoutien,
  modifierReglageDuSoutien,
} from '@/lib/api'

const { administrateur } = useSession()
const { donnees: bilan, erreur, chargement, executer } = useAppelApi(lireSoutien)
onMounted(executer)

const configure = computed(() => (bilan.value?.configure ? bilan.value : null))
const actuel = computed(() => configure.value?.actuel ?? null)
const devise = computed(() => actuel.value?.devise ?? 'EUR')
const part = computed(() =>
  actuel.value && bilan.value ? partDeLObjectif(actuel.value.parMois, bilan.value.objectifMensuel) : null,
)

const dateDuReleve = computed(() =>
  actuel.value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long' }).format(new Date(actuel.value.releveLe))
    : '',
)
const depuis = computed(() =>
  configure.value?.depuis
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'UTC' }).format(
        new Date(`${configure.value.depuis}T00:00:00Z`),
      )
    : '',
)

/* --- Réglages (administration) ------------------------------------------ */

const pseudo = ref('')
const objectif = ref('')
const enregistrement = ref(false)
const erreurReglages = ref<unknown>(null)
const enregistre = ref(false)

watch(
  bilan,
  (valeur) => {
    if (!valeur) return
    pseudo.value = valeur.configure ? valeur.pseudo : pseudo.value
    objectif.value = String(valeur.objectifMensuel)
  },
  { immediate: true },
)

const erreurPseudo = computed(() =>
  pseudo.value.trim() !== '' && !pseudoValide(pseudo.value.trim())
    ? 'Lettres, chiffres, « . », « _ » et « - » seulement, 32 au plus.'
    : '',
)
const champsReglages = computed(() =>
  erreurReglages.value ? analyserErreur(erreurReglages.value).champs : ({} as Record<string, string>),
)

async function enregistrer(): Promise<void> {
  if (erreurPseudo.value) return
  enregistrement.value = true
  erreurReglages.value = null
  enregistre.value = false
  try {
    await modifierReglageDuSoutien(CLE_PSEUDO_LIBERAPAY, pseudo.value.trim())
    await modifierReglageDuSoutien(CLE_OBJECTIF_MENSUEL, Number(objectif.value))
    enregistre.value = true
    await executer()
  } catch (e) {
    erreurReglages.value = e
  } finally {
    enregistrement.value = false
  }
}
</script>

<template>
  <div class="mx-auto w-full max-w-2xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-texte">Soutenir le site</h1>
      <p class="mt-2 text-[15px] leading-relaxed text-texte">
        Le site tourne sur un serveur loué<template v-if="bilan && bilan.objectifMensuel > 0">
          {{ formaterMontant(bilan.objectifMensuel * 100) }} par mois</template>.
        Rien n’est obligatoire, et un don ne débloque rien : c’est seulement pour aider à le payer,
        si vous en avez envie. Vous choisissez le montant, et si c’est une fois ou régulièrement.
      </p>
    </header>

    <SqueletteChargement v-if="chargement && !bilan" :lignes="1" hauteur="h-40" />
    <MessageErreur v-else-if="erreur" :erreur="erreur" @action="executer" />

    <template v-else-if="bilan">
      <PanneauBase v-if="!configure">
        <p class="m-0 text-sm text-attenue">Les dons ne sont pas encore ouverts.</p>
      </PanneauBase>

      <div v-else class="flex flex-col gap-4">
        <PanneauBase>
          <div class="flex flex-col gap-4">
            <a
              :href="configure.lien"
              target="_blank"
              rel="noopener noreferrer"
              class="flex min-h-12 items-center justify-center rounded-panneau bg-accent px-4 text-[15px] font-semibold text-fond hover:opacity-90"
            >
              Faire un don sur Liberapay
            </a>
            <p class="m-0 text-xs leading-relaxed text-attenue">
              Liberapay est une association à but non lucratif : elle ne prend aucune commission,
              seuls les frais bancaires du paiement s’appliquent. Sur sa page, vous choisissez aussi
              d’apparaître publiquement, discrètement (sans le montant) ou pas du tout.
            </p>
          </div>
        </PanneauBase>

        <PanneauBase titre="Ce mois-ci">
          <p v-if="!actuel" class="m-0 text-sm text-attenue">
            Aucun relevé pour l’instant : Liberapay n’a pas répondu. Les chiffres apparaîtront au
            prochain passage.
          </p>
          <div v-else class="flex flex-col gap-3">
            <p class="m-0 text-[15px]">
              <span class="text-2xl font-bold tnum">{{ formaterMontant(actuel.parMois, devise) }}</span>
              <span class="text-attenue">
                par mois<template v-if="bilan.objectifMensuel > 0">
                  sur {{ formaterMontant(bilan.objectifMensuel * 100, devise) }}</template></span>
            </p>
            <div
              v-if="part !== null"
              class="h-3 w-full overflow-hidden rounded-full bg-accent-doux"
              role="progressbar"
              :aria-valuenow="part"
              aria-valuemin="0"
              aria-valuemax="100"
              :aria-label="`${part} % de l’objectif mensuel`"
            >
              <div class="h-full rounded-full bg-accent" :style="{ width: `${part}%` }" />
            </div>
            <p class="m-0 text-sm text-attenue">
              {{ actuel.donateurs }} donateur{{ actuel.donateurs > 1 ? 's' : '' }} ·
              {{ formaterMontant(actuel.parSemaine, devise) }} par semaine · relevé le {{ dateDuReleve }}
            </p>
          </div>
        </PanneauBase>

        <PanneauBase v-if="configure.mois.length" titre="Historique">
          <div class="flex flex-col gap-4">
            <ul v-if="configure.annees.length" class="m-0 flex list-none flex-wrap gap-2 p-0">
              <li
                v-for="a in configure.annees"
                :key="a.annee"
                class="rounded-pastille bg-accent-doux px-3 py-1 text-sm text-accent"
              >
                {{ a.annee }} : <strong class="tnum">{{ formaterMontant(a.centimes, devise) }}</strong>
              </li>
            </ul>
            <table class="w-full text-sm">
              <caption class="lecteur-ecran">Dons promis par mois</caption>
              <tbody class="divide-y divide-ligne">
                <tr v-for="m in configure.mois" :key="m.mois">
                  <th scope="row" class="py-1.5 text-left font-normal first-letter:uppercase">
                    {{ libelleDuMois(m.mois) }}
                    <span v-if="!m.complet" class="text-attenue">(en cours)</span>
                  </th>
                  <td class="py-1.5 text-right tnum">{{ formaterMontant(m.centimes, devise) }}</td>
                </tr>
              </tbody>
            </table>
            <p class="m-0 text-xs leading-relaxed text-attenue">
              Dons promis, estimés d’après le rythme relevé chaque jour sur Liberapay
              <template v-if="depuis">depuis le {{ depuis }}</template>. Ce n’est pas le détail des
              versements, qui n’est visible que sur Liberapay.
            </p>
          </div>
        </PanneauBase>
      </div>

      <PanneauBase v-if="administrateur" titre="Réglages (administration)" class="mt-4">
        <form class="flex flex-col gap-3" @submit.prevent="enregistrer">
          <ChampTexte
            v-model="pseudo"
            etiquette="Pseudo Liberapay"
            aide="Le nom du compte qui reçoit les dons (liberapay.com/<pseudo>). Vide : la page est fermée."
            :erreur="erreurPseudo || champsReglages['value']"
            autocomplete="off"
          />
          <ChampTexte
            v-model="objectif"
            type="number"
            etiquette="Objectif mensuel, en euros"
            aide="Ce que coûte le serveur par mois. 0 retire la jauge."
          />
          <MessageErreur v-if="erreurReglages" :erreur="erreurReglages" />
          <div class="flex items-center gap-3">
            <BoutonBase type="submit" variante="principal" :chargement="enregistrement" :desactive="!!erreurPseudo">
              Enregistrer
            </BoutonBase>
            <span v-if="enregistre" class="text-sm text-attenue">Enregistré.</span>
          </div>
        </form>
      </PanneauBase>
    </template>
  </div>
</template>
