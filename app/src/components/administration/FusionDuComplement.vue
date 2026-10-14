<script setup lang="ts">
/**
 * **Un complément, et sa fusion** — § 6.7 bis, ADR 0017.
 *
 * Ce que la fusion ferait, lu dans le rapport que le serveur a calculé à blanc :
 * les champs vides de la proposition que le complément remplit, les **conflits à
 * trancher** (la proposition l'emporte, sauf si l'on coche le complément), les
 * liens repris et ceux qui sont écartés — avec leur raison —, les pièces qui
 * suivent. Puis « Fusionner », après confirmation : le geste ne se défait pas.
 *
 * Il sert à deux endroits, avec le même rapport : sur la proposition d'origine
 * (`ComplementsDeDemande`, un par complément en attente) et sur le complément
 * lui-même (`BandeauDeComplement`, qui lit l'aperçu par sa route).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Les liens se nomment par la demande du complément.** Le rapport ne les
 * désigne que par leur rang ; l'écran du complément les tient déjà
 * (`liensDuComplement`), celui de l'origine les lit ici, une fois. Si la lecture
 * échoue, on dit « lien n° 2 du complément » — jamais un identifiant de personne.
 *
 * **Les empreintes partent avec le clic** : si l'un des deux auteurs a complété
 * sa demande depuis l'aperçu, le serveur rend `409` sans rien écrire, et
 * « Recharger » (`MessageErreur`) relit tout — jamais on ne rejoue en aveugle.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onMounted, ref } from 'vue'
import {
  fusionnerComplement,
  invaliderGraphe,
  lireDemandeAdministration,
  type CoteRetenu,
  type LienDemande,
  type RapportDeFusion,
  type ResultatFusion,
  type ChampDeFiche,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import { choixParDefaut, confirmationDeFusion, corpsDeFusion, rapportLisible, type ChoixDeFusion } from './complements'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from './ecriture'

const {
  origineId,
  complementId,
  auteur = null,
  depuis = null,
  libelle = null,
  ligneDates = null,
  rapport,
  empreinteOrigine = null,
  empreinteComplement = null,
  liensDuComplement = null,
  entete = true,
} = defineProps<{
  origineId: number
  complementId: number
  /** Le numéro, le nom et l'auteur du complément — inutiles sur sa propre page. */
  entete?: boolean
  auteur?: string | null
  depuis?: string | null
  libelle?: string | null
  ligneDates?: string | null
  rapport: RapportDeFusion
  /** Ce qu'on a lu de l'origine, et du complément : à renvoyer avec la fusion. */
  empreinteOrigine?: string | null
  empreinteComplement?: string | null
  /** Les liens du complément quand l'écran les tient déjà ; lus ici sinon. */
  liensDuComplement?: LienDemande[] | null
}>()

const emit = defineEmits<{
  /** La fusion est faite : l'origine et le complément sont à relire. */
  fusionne: [resultat: ResultatFusion]
  /** L'état affiché est périmé (une empreinte, une demande tranchée) : relire. */
  recharger: []
}>()

const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

/* --- Les liens, nommés ---------------------------------------------- */

const liensLus = ref<LienDemande[] | null>(null)
const liens = computed(() => liensDuComplement ?? liensLus.value)

onMounted(async () => {
  const aNommer = rapport.liensAjoutes.length + rapport.liensIgnores.length
  if (liensDuComplement !== null || aNommer === 0) return
  try {
    liensLus.value = (await lireDemandeAdministration(complementId)).liens
  } catch {
    // Les rangs suffiront : nommer les liens aide à décider, ne pas y arriver
    // n'empêche pas de fusionner.
  }
})

const lisible = computed(() => rapportLisible(rapport, liens.value))

/* --- Le choix ------------------------------------------------------- */

const choix = ref<ChoixDeFusion>(choixParDefaut(rapport.conflits))

function choisir(champ: ChampDeFiche, cote: CoteRetenu): void {
  choix.value = { ...choix.value, [champ]: cote }
}

/* --- La fusion ------------------------------------------------------ */

const confirmation = ref<DemandeDeConfirmation | null>(null)
const envoi = ref(false)
const erreur = ref<unknown>(null)

function demander(): void {
  if (suspendu.value || envoi.value) return
  confirmation.value = confirmationDeFusion(origineId, complementId, rapport, choix.value)
}

async function fusionner(): Promise<void> {
  if (suspendu.value || envoi.value) return
  envoi.value = true
  erreur.value = null
  try {
    const resultat = await fusionnerComplement(
      origineId,
      complementId,
      corpsDeFusion(choix.value, rapport.conflits, { origine: empreinteOrigine, complement: empreinteComplement }),
    )
    // Un nœud en attente de moins (le complément, dans le graphe de l'administrateur),
    // une proposition réécrite : l'`ETag` du graphe est périmé.
    invaliderGraphe()
    confirmation.value = null
    emit('fusionne', resultat)
  } catch (cause) {
    confirmation.value = null
    erreur.value = cause
  } finally {
    envoi.value = false
  }
}

function dateLisible(valeur: string | null): string {
  if (valeur === null || valeur.length === 0) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <article
    class="flex flex-col gap-3 rounded-champ border border-ligne bg-fond px-3.5 py-3 text-sm"
    :data-fusion="complementId"
  >
    <header v-if="entete" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
      <RouterLink :to="{ name: 'demande.detail', params: { id: complementId } }" class="font-semibold text-accent underline">
        Complément n° {{ complementId }}
      </RouterLink>
      <span v-if="libelle !== null" class="font-medium">{{ libelle }}</span>
      <span v-if="ligneDates !== null" class="font-mono text-xs text-attenue">{{ ligneDates }}</span>
      <span v-if="auteur !== null" class="text-attenue">
        proposé par {{ auteur }}<template v-if="depuis !== null">, le {{ dateLisible(depuis) }}</template>
      </span>
    </header>

    <p v-if="lisible.vide" class="m-0 text-attenue" data-rien-a-fusionner>
      Il n’apporte ni champ, ni lien, ni pièce que la proposition n’ait déjà : la fusion le
      clôt seulement.
    </p>

    <section v-if="lisible.remplis.length > 0" class="flex flex-col gap-1" data-remplis>
      <h4 class="m-0 text-xs font-semibold tracking-wide text-attenue uppercase">Ce qu’il ajoute</h4>
      <ul class="m-0 flex list-none flex-col gap-0.5 p-0">
        <li v-for="r in lisible.remplis" :key="r.champ">
          <span class="font-semibold">{{ r.etiquette }}</span> : <span class="whitespace-pre-line [overflow-wrap:anywhere]">{{ r.valeur }}</span>
        </li>
      </ul>
    </section>

    <!-- Les conflits : la proposition l'emporte, sauf choix contraire. Un groupe de
         boutons radio par champ, l'origine cochée d'office. -->
    <fieldset v-if="lisible.conflits.length > 0" class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0" data-conflits>
      <legend class="mb-1 text-xs font-semibold tracking-wide text-attenue uppercase">À trancher</legend>
      <div
        v-for="c in lisible.conflits"
        :key="c.champ"
        class="flex flex-col gap-1 rounded-champ border border-alerte-bord bg-alerte-doux px-2.5 py-2"
        :data-conflit="c.champ"
      >
        <span class="font-semibold">{{ c.etiquette }}</span>
        <label class="flex cursor-pointer items-start gap-2">
          <input
            type="radio"
            class="mt-0.5 size-4 shrink-0 accent-accent"
            :name="`retenir-${complementId}-${c.champ}`"
            value="origine"
            :checked="choix[c.champ] !== 'complement'"
            :disabled="envoi"
            @change="choisir(c.champ, 'origine')"
          />
          <span>Garder la proposition : {{ c.origine }}</span>
        </label>
        <label class="flex cursor-pointer items-start gap-2">
          <input
            type="radio"
            class="mt-0.5 size-4 shrink-0 accent-accent"
            :name="`retenir-${complementId}-${c.champ}`"
            value="complement"
            :checked="choix[c.champ] === 'complement'"
            :disabled="envoi"
            @change="choisir(c.champ, 'complement')"
          />
          <span>Prendre le complément : {{ c.complement }}</span>
        </label>
      </div>
    </fieldset>

    <section v-if="lisible.liensAjoutes.length + lisible.liensEcartes.length > 0" class="flex flex-col gap-1" data-liens-fusion>
      <h4 class="m-0 text-xs font-semibold tracking-wide text-attenue uppercase">Ses liens</h4>
      <ul class="m-0 flex list-none flex-col gap-0.5 p-0">
        <li v-for="l in lisible.liensAjoutes" :key="`a${l.seqComplement}`" data-lien-ajoute>
          <span class="font-semibold text-ok">Ajouté</span> — {{ l.libelle }}
        </li>
        <li v-for="l in lisible.liensEcartes" :key="`e${l.seqComplement}`" class="text-attenue" data-lien-ecarte>
          <span class="font-semibold">Écarté</span> — {{ l.libelle }} : {{ l.raison }}
        </li>
      </ul>
    </section>

    <p v-if="lisible.medias !== null" class="m-0" data-medias-fusion>{{ lisible.medias }}</p>
    <p v-if="lisible.repointees !== null" class="m-0 text-attenue">{{ lisible.repointees }}</p>

    <p v-if="raisonSuspension !== null" class="m-0 text-accent" role="status">{{ raisonSuspension }}</p>
    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="emit('recharger')" />

    <div class="flex flex-wrap items-center gap-2">
      <BoutonBase data-fusionner :desactive="suspendu || envoi" :chargement="envoi" :title="infobulle" @click="demander">
        Fusionner dans la demande n° {{ origineId }}
      </BoutonBase>
    </div>

    <DialogueConfirmation :demande="confirmation" :chargement="envoi" @confirmer="fusionner" @annuler="confirmation = null" />
  </article>
</template>
