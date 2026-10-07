<script setup lang="ts">
/**
 * L'autorisation signée d'une personne vivante **sans compte** (ADR 0016) —
 * administrateur seulement, onglet Identité.
 *
 * Une personne vivante sans compte ne choisit rien : le défaut tient, sa date
 * de naissance n'est montrée qu'à ses proches (révision du 7 octobre 2026). Si
 * elle a signé une autorisation sur papier, l'administrateur en dépose le
 * document (PDF ou photo) et pose ce qu'elle accepte — pour l'année, le mois
 * et le jour, indépendamment : tous, ses proches, personne.
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
import BoutonsPhotos from '@/components/medias/BoutonsPhotos.vue'
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

function choisirDocument(fichier: File | null): void {
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
          Autorisation signée :
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
        Aucune autorisation : sans compte, cette personne ne montre sa date de naissance qu’à ses
        proches.
      </p>

      <form
        v-if="!etat.compteRattache"
        class="flex flex-col gap-3 border-t border-ligne pt-3"
        novalidate
        @submit.prevent="deposer"
      >
        <div class="flex flex-col gap-1.5">
          <span class="text-sm font-medium">
            {{ etat.autorisation === null ? 'Document signé' : 'Remplacer par un nouveau document signé' }}
          </span>
          <BoutonsPhotos
            :multiple="false"
            :accept="TYPES_ACCEPTES"
            importer="Importer le document"
            importer-aide="PDF ou image"
            prendre="Photographier le document"
            prendre-aide="Avec l’appareil photo"
            :desactive="envoi"
            @fichiers="(f) => choisirDocument(f[0] ?? null)"
          />
          <span v-if="document !== null" class="flex flex-wrap items-center gap-2 text-sm">
            <span class="min-w-0 truncate">{{ document.name }}</span>
            <button type="button" class="text-xs text-attenue underline" @click="choisirDocument(null)">Retirer</button>
          </span>
          <span class="text-xs text-attenue">PDF, JPEG, PNG ou WebP, 10 Mo au plus.</span>
          <span v-if="refusDocument" class="text-xs text-alerte">{{ refusDocument }}</span>
        </div>

        <PartageNaissance
          v-model="partage"
          legende="Qui voit sa date de naissance ?"
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
