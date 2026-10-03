<script setup lang="ts">
/**
 * Le carnet de notes de l'administrateur — idées, chantiers, choses à ne pas
 * oublier, écrites depuis l'application.
 *
 * Le carnet est **personnel** : le serveur ne rend que les notes du compte
 * connecté, et une note d'un autre compte y est introuvable.
 *
 * Deux colonnes sur grand écran — la liste à gauche, la note ouverte à droite ;
 * l'une sous l'autre sur téléphone. L'enregistrement est explicite (bouton ou
 * Ctrl/⌘ + S) : chaque enregistrement laisse une ligne au journal d'audit, une
 * sauvegarde à chaque frappe le noierait. Quitter une note modifiée demande
 * confirmation.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { creerNote, listerNotes, modifierNote, supprimerNote, type NoteDuCarnet } from '@/lib/api'
import { analyserErreur } from '@/lib/api/erreurs'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import OngletsAdministration from './OngletsAdministration.vue'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from '@/components/administration/ecriture'
import {
  apercu,
  dateDeModification,
  filtrerNotes,
  modifiee,
  saisieDepuisNote,
  saisieVide,
  trierNotes,
} from '@/components/administration/carnet'

const { vueEmpruntee } = useSession()

const notes = ref<NoteDuCarnet[]>([])
const chargement = ref(false)
const erreur = ref<unknown>(null)
const recherche = ref('')

/** La note ouverte ; `null` : on écrit une nouvelle note. */
const ouverte = ref<NoteDuCarnet | null>(null)
const saisie = ref(saisieVide())
const erreursChamps = ref<Record<string, string>>({})
const envoi = ref(false)
const confirmation = ref<string | null>(null)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

const visibles = computed(() => filtrerNotes(notes.value, recherche.value))
const aEnregistrer = computed(() => modifiee(saisie.value, ouverte.value))

const libelleTotal = computed(() => `${notes.value.length} note${notes.value.length > 1 ? 's' : ''}`)

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    notes.value = await listerNotes()
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)

/* --- Changer de note sans perdre ce qui est tapé ----------------- */

const aConfirmer = ref<(DemandeDeConfirmation & { geste: () => void | Promise<void> }) | null>(null)

function siRienNeSePerd(geste: () => void): void {
  if (!aEnregistrer.value) {
    geste()
    return
  }
  aConfirmer.value = {
    titre: 'Abandonner les modifications ?',
    message: 'La note ouverte a des modifications qui ne sont pas enregistrées.',
    confirmer: 'Abandonner',
    danger: true,
    geste,
  }
}

function ouvrir(note: NoteDuCarnet): void {
  if (ouverte.value?.id === note.id) return
  siRienNeSePerd(() => {
    ouverte.value = note
    saisie.value = saisieDepuisNote(note)
    erreursChamps.value = {}
    confirmation.value = null
  })
}

function nouvelle(): void {
  siRienNeSePerd(() => {
    ouverte.value = null
    saisie.value = saisieVide()
    erreursChamps.value = {}
    confirmation.value = null
  })
}

async function confirmer(): Promise<void> {
  const demande = aConfirmer.value
  aConfirmer.value = null
  await demande?.geste()
}

/* --- Enregistrer, supprimer --------------------------------------- */

async function enregistrer(): Promise<void> {
  if (suspendu.value || envoi.value) return

  if (saisie.value.titre.trim() === '') {
    erreursChamps.value = { titre: 'Le titre est requis.' }
    return
  }

  envoi.value = true
  erreur.value = null
  erreursChamps.value = {}
  confirmation.value = null
  try {
    const note =
      ouverte.value === null ? await creerNote(saisie.value) : await modifierNote(ouverte.value.id, saisie.value)
    notes.value = trierNotes([...notes.value.filter((n) => n.id !== note.id), note])
    ouverte.value = note
    saisie.value = saisieDepuisNote(note)
    confirmation.value = 'Note enregistrée.'
  } catch (cause) {
    const champs = analyserErreur(cause).champs
    if (Object.keys(champs).length > 0) erreursChamps.value = champs
    else erreur.value = cause
  } finally {
    envoi.value = false
  }
}

function demanderSuppression(): void {
  const note = ouverte.value
  if (note === null || suspendu.value) return
  aConfirmer.value = {
    titre: 'Supprimer cette note ?',
    message: `« ${note.titre} » sera supprimée pour de bon.`,
    confirmer: 'Supprimer',
    danger: true,
    geste: async () => {
      envoi.value = true
      try {
        await supprimerNote(note.id)
        notes.value = notes.value.filter((n) => n.id !== note.id)
        ouverte.value = null
        saisie.value = saisieVide()
        confirmation.value = 'Note supprimée.'
      } catch (cause) {
        erreur.value = cause
      } finally {
        envoi.value = false
      }
    },
  }
}

/* --- Raccourci et garde de sortie --------------------------------- */

function surTouche(evenement: KeyboardEvent): void {
  if ((evenement.ctrlKey || evenement.metaKey) && evenement.key.toLowerCase() === 's') {
    evenement.preventDefault()
    void enregistrer()
  }
}

function surDechargement(evenement: BeforeUnloadEvent): void {
  if (aEnregistrer.value) evenement.preventDefault()
}

onMounted(() => {
  window.addEventListener('keydown', surTouche)
  window.addEventListener('beforeunload', surDechargement)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', surTouche)
  window.removeEventListener('beforeunload', surDechargement)
})

onBeforeRouteLeave(() => {
  if (!aEnregistrer.value) return true
  return window.confirm('La note ouverte a des modifications qui ne sont pas enregistrées. Quitter quand même ?')
})

// Une confirmation se tait dès qu'on reprend la saisie.
watch(saisie, () => (confirmation.value = null), { deep: true })
</script>

<template>
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
    <template v-if="!chargement" #compteur>{{ libelleTotal }}</template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-6">
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
      <PanneauBase>
        <template #entete>
          <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-sm font-semibold tracking-tight">Mon carnet</h2>
              <BoutonBase variante="secondaire" :desactive="suspendu" :title="infobulle" @click="nouvelle">
                Nouvelle note
              </BoutonBase>
            </div>
            <input
              v-model="recherche"
              type="search"
              aria-label="Chercher dans mes notes"
              placeholder="Chercher…"
              class="w-full rounded-champ border border-ligne bg-fond px-2.5 py-1 text-sm text-texte"
            />
          </div>
        </template>

        <SqueletteChargement v-if="chargement && notes.length === 0" :lignes="4" />

        <p v-else-if="visibles.length === 0" class="text-sm text-attenue">
          {{ notes.length === 0 ? 'Aucune note pour l’instant.' : 'Aucune note ne correspond.' }}
        </p>

        <ul v-else class="-mx-1 flex flex-col gap-0.5" aria-label="Mes notes">
          <li v-for="note in visibles" :key="note.id">
            <button
              type="button"
              class="w-full rounded-champ px-2 py-1.5 text-left hover:bg-accent-doux"
              :class="ouverte?.id === note.id ? 'bg-accent-doux' : ''"
              :aria-current="ouverte?.id === note.id ? 'true' : undefined"
              @click="ouvrir(note)"
            >
              <span class="flex items-center gap-1.5 text-sm font-medium">
                <span v-if="note.epinglee" class="text-accent" aria-label="Épinglée">●</span>
                <span class="truncate">{{ note.titre }}</span>
              </span>
              <span v-if="apercu(note.texte) !== ''" class="block truncate text-xs text-attenue">
                {{ apercu(note.texte) }}
              </span>
              <span class="block text-[11px] text-attenue">{{ dateDeModification(note.updatedAt) }}</span>
            </button>
          </li>
        </ul>
      </PanneauBase>

      <PanneauBase :titre="ouverte === null ? 'Nouvelle note' : 'Note'">
        <form novalidate class="flex flex-col gap-3.5" @submit.prevent="enregistrer">
          <ChampTexte
            v-model="saisie.titre"
            etiquette="Titre"
            requis
            placeholder="Idée de vidéo : le carnet"
            :erreur="erreursChamps['titre'] ?? ''"
          />

          <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">Texte</span>
            <textarea
              v-model="saisie.texte"
              rows="16"
              placeholder="Ce qu’il ne faut pas oublier…"
              class="min-h-[16rem] resize-y rounded-champ border bg-fond px-2.5 py-1.5 font-mono text-sm leading-relaxed text-texte"
              :class="erreursChamps['texte'] ? 'border-alerte' : 'border-ligne'"
            />
            <span v-if="erreursChamps['texte']" class="text-xs text-alerte">{{ erreursChamps['texte'] }}</span>
          </label>

          <label class="flex items-center gap-2 text-sm">
            <input v-model="saisie.epinglee" type="checkbox" class="accent-[var(--color-accent)]" />
            Épingler en tête du carnet
          </label>

          <div class="flex flex-wrap items-center gap-2">
            <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu || !aEnregistrer" :title="infobulle">
              {{ ouverte === null ? 'Créer la note' : 'Enregistrer' }}
            </BoutonBase>
            <BoutonBase
              v-if="ouverte !== null"
              variante="danger"
              :desactive="suspendu || envoi"
              :title="infobulle"
              @click="demanderSuppression"
            >
              Supprimer
            </BoutonBase>
            <span v-if="aEnregistrer" class="text-xs text-attenue">Modifications non enregistrées · Ctrl + S</span>
            <span v-else-if="ouverte !== null" class="text-xs text-attenue">{{ dateDeModification(ouverte.updatedAt) }}</span>
          </div>

          <p v-if="confirmation !== null" class="text-sm text-ok" role="status">{{ confirmation }}</p>
        </form>
      </PanneauBase>
    </div>
  </div>

  <DialogueConfirmation :demande="aConfirmer" :chargement="envoi" @confirmer="confirmer" @annuler="aConfirmer = null" />
</template>
