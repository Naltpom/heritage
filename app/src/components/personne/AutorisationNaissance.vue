<script setup lang="ts">
/**
 * L'autorisation signée d'une personne vivante **sans compte** (ADR 0016) —
 * administrateur seulement, onglet Identité.
 *
 * Une personne vivante sans compte ne coche rien : elle ne partage donc rien de
 * sa date de naissance, et les membres n'en voient rien. Si elle a signé une
 * autorisation sur papier, l'administrateur en dépose le document (PDF ou
 * photo) et coche ce qu'elle accepte — année, mois, jour, indépendamment.
 *
 * Trois états, dits tels quels :
 *
 * - **un compte est rattaché** : seul son propre choix compte, aucun dépôt
 *   (le serveur le refuserait, `409 compte_rattache`) ;
 * - **une autorisation active** : ce qu'elle partage, depuis quand, la note,
 *   le document, et « Retirer » — qui l'archive, ne l'efface pas ;
 * - **aucune** : la phrase dit ce que cela veut dire pour les membres.
 *
 * Déposer à nouveau **remplace** l'autorisation active (archivée en
 * `remplacee`). L'historique se replie sous un `<details>` : il sert à
 * retrouver un document, pas à lire la fiche.
 *
 * La page ne monte ce bloc que pour une fiche `vivant`, un administrateur, et
 * hors vue empruntée (`PageFiche`) : sous la bascule, toute écriture rendrait
 * `409 vue_comme_membre_active`.
 */
import { computed, ref, watch } from 'vue'
import {
  analyserErreur,
  deposerAutorisationNaissance,
  lireAutorisationNaissance,
  retirerAutorisationNaissance,
  type AutorisationNaissance,
  type EtatAutorisationNaissance,
  type PartageNaissance as Partage,
  type RaisonArchivageAutorisation,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import PartageNaissance from '@/components/compte/PartageNaissance.vue'
import { partageParDefaut, resumePartage } from '@/components/compte/partageNaissance'

const { personId } = defineProps<{ personId: number }>()

/** La borne du serveur, redite ici pour refuser avant un envoi de 40 Mo. */
const TAILLE_MAXIMALE = 10 * 1024 * 1024
const TYPES_ACCEPTES = 'application/pdf,image/jpeg,image/png,image/webp'

const RAISONS: Record<RaisonArchivageAutorisation, string> = {
  remplacee: 'remplacée',
  compte_rattache: 'un compte a été rattaché',
  retiree: 'retirée',
}

const etat = ref<EtatAutorisationNaissance | null>(null)
const chargement = ref(false)
const erreur = ref<unknown>(null)

const document = ref<File | null>(null)
const partage = ref<Partage>(partageParDefaut())
const note = ref('')
const envoi = ref(false)
const erreurEnvoi = ref<unknown>(null)
const refusLocal = ref<string | null>(null)
/** Remonte le `<input type="file">` : c'est la seule façon de le vider. */
const versionFichier = ref(0)

const champs = computed(() => analyserErreur(erreurEnvoi.value)?.champs ?? {})
/** Ici, les refus arrivent sous `annee`, `mois`, `jour` — les champs du formulaire. */
const refusPartage = computed(() => {
  const refus: Partial<Record<keyof Partage, string>> = {}
  for (const cle of ['annee', 'mois', 'jour'] as const) {
    const message = champs.value[cle] ?? ''
    if (message.length > 0) refus[cle] = message
  }
  return refus
})
const refusDocument = computed(() => refusLocal.value ?? champs.value['document'] ?? '')

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    etat.value = await lireAutorisationNaissance(personId)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

watch(() => personId, () => void charger(), { immediate: true })

function choisirDocument(evenement: Event): void {
  const fichier = (evenement.target as HTMLInputElement).files?.[0] ?? null
  refusLocal.value = fichier !== null && fichier.size > TAILLE_MAXIMALE ? 'Le document dépasse 10 Mo.' : null
  document.value = fichier
}

const peutDeposer = computed(() => document.value !== null && refusLocal.value === null && !envoi.value)

async function deposer(): Promise<void> {
  const fichier = document.value
  if (fichier === null || !peutDeposer.value) return
  envoi.value = true
  erreurEnvoi.value = null
  try {
    etat.value = await deposerAutorisationNaissance(personId, { ...partage.value, document: fichier, note: note.value })
    document.value = null
    note.value = ''
    partage.value = partageParDefaut()
    versionFichier.value++
  } catch (cause) {
    erreurEnvoi.value = cause
  } finally {
    envoi.value = false
  }
}

async function retirer(): Promise<void> {
  if (envoi.value) return
  envoi.value = true
  erreurEnvoi.value = null
  try {
    etat.value = await retirerAutorisationNaissance(personId)
  } catch (cause) {
    erreurEnvoi.value = cause
  } finally {
    envoi.value = false
  }
}

function dateLisible(iso: string | null): string {
  if (iso === null) return ''
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

function ligneDHistorique(autorisation: AutorisationNaissance): string {
  const raison = autorisation.raisonArchivage === null ? '' : ` — ${RAISONS[autorisation.raisonArchivage]}`
  return `${dateLisible(autorisation.accordeeLe)} : ${resumePartage(autorisation)}${raison}`
}
</script>

<template>
  <div class="flex flex-col gap-3" data-autorisation-naissance>
    <SqueletteChargement v-if="chargement && etat === null" :lignes="2" />
    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <template v-else-if="etat !== null">
      <p v-if="etat.compteRattache" class="m-0 text-sm" data-etat="compte">
        Un compte est rattaché à cette fiche : seul son propre choix de partage compte.
      </p>

      <div v-else-if="etat.autorisation !== null" class="flex flex-col gap-1.5" data-etat="active">
        <p class="m-0 text-sm">
          Autorisation signée : partage
          <strong>{{ resumePartage(etat.autorisation) }}</strong>,
          accordée le {{ dateLisible(etat.autorisation.accordeeLe) }}.
        </p>
        <p v-if="etat.autorisation.note" class="m-0 text-sm text-attenue">{{ etat.autorisation.note }}</p>
        <div class="flex flex-wrap items-center gap-3">
          <a
            :href="etat.autorisation.documentUrl"
            target="_blank"
            rel="noopener"
            class="text-sm text-accent underline-offset-2 hover:underline"
          >
            Voir le document signé
          </a>
          <BoutonBase variante="secondaire" :chargement="envoi" :desactive="envoi" @click="retirer">
            Retirer
          </BoutonBase>
        </div>
      </div>

      <p v-else class="m-0 text-sm text-attenue" data-etat="aucune">
        Aucune autorisation : sans compte, cette personne ne partage pas sa date de naissance avec les
        membres.
      </p>

      <form
        v-if="!etat.compteRattache"
        class="flex flex-col gap-3 border-t border-ligne pt-3"
        novalidate
        @submit.prevent="deposer"
      >
        <label class="flex flex-col gap-1">
          <span class="text-sm font-medium">
            {{ etat.autorisation === null ? 'Document signé' : 'Remplacer par un nouveau document signé' }}
          </span>
          <input
            :key="versionFichier"
            type="file"
            :accept="TYPES_ACCEPTES"
            class="text-sm"
            @change="choisirDocument"
          />
          <span class="text-xs text-attenue">PDF, JPEG, PNG ou WebP, 10 Mo au plus.</span>
          <span v-if="refusDocument" class="text-xs text-alerte">{{ refusDocument }}</span>
        </label>

        <PartageNaissance
          v-model="partage"
          legende="Ce que la personne accepte de partager"
          sans-explication
          :erreurs="refusPartage"
          :desactive="envoi"
        />

        <label class="flex flex-col gap-1">
          <span class="text-sm font-medium">Note</span>
          <input
            :value="note"
            type="text"
            placeholder="Ex. : signée lors de la cousinade"
            class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            @input="note = ($event.target as HTMLInputElement).value"
          />
        </label>

        <MessageErreur
          v-if="erreurEnvoi !== null && Object.keys(champs).length === 0"
          :erreur="erreurEnvoi"
        />

        <BoutonBase type="submit" class="self-start" :chargement="envoi" :desactive="!peutDeposer">
          Déposer l’autorisation
        </BoutonBase>
      </form>

      <details v-if="etat.historique.length > 0" class="text-sm">
        <summary class="cursor-pointer text-attenue">Historique ({{ etat.historique.length }})</summary>
        <ul class="mt-1.5 flex flex-col gap-1 pl-4">
          <li v-for="ancienne in etat.historique" :key="ancienne.id" class="text-xs">
            {{ ligneDHistorique(ancienne) }} ·
            <a :href="ancienne.documentUrl" target="_blank" rel="noopener" class="text-accent underline">document</a>
          </li>
        </ul>
      </details>
    </template>
  </div>
</template>
