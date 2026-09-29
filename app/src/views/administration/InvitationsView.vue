<script setup lang="ts">
/**
 * Les invitations — § 3.
 *
 * **Aucune inscription ouverte** : on n'entre sur cet arbre que par un lien
 * d'invitation (§ 6.1 du dossier). Un lien porte une **description**, un
 * plafond d'inscriptions (ou aucun) et une échéance (ou aucune). Chaque personne
 * qui l'ouvre se décrit elle-même : le lien ne désigne plus personne à l'avance,
 * puisqu'il peut servir à toute une branche de la famille.
 *
 * **Le lien n'apparaît qu'une fois.** Le serveur ne stocke que `hash(jeton)` ;
 * `lien` n'existe que dans la réponse de création, jamais dans la liste (§ 3.2).
 * Il n'y a donc pas de bouton « renvoyer » — il est **impossible**, pas
 * manquant. Un lien perdu se révoque et se recrée.
 *
 * Les révoquées sont masquées par défaut : elles ne servent plus qu'à l'audit.
 */
import { computed, onMounted, ref, watch } from 'vue'
import {
  creerInvitation,
  listerInvitations,
  revoquerInvitation,
  type Invitation,
  type InvitationCreee,
  type StatutInvitation,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import OngletsAdministration from './OngletsAdministration.vue'
import LienInvitation from '@/components/administration/LienInvitation.vue'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from '@/components/administration/ecriture'
import { analyserErreur } from '@/lib/api/erreurs'

const { vueEmpruntee } = useSession()

const invitations = ref<Invitation[]>([])
const chargement = ref(false)
const erreur = ref<unknown>(null)

/** L'invitation qui vient d'être créée : le seul instant où le lien existe. */
const creee = ref<InvitationCreee | null>(null)

const description = ref('')
/** Chaîne vide : illimité. */
const plafond = ref('')
/** `''` : n'expire jamais. */
const duree = ref<string>('')
const envoi = ref(false)
const erreursChamps = ref<Record<string, string>>({})

/** Le filtre de la liste. `sans_revoquees` est le défaut. */
type Filtre = 'sans_revoquees' | 'toutes' | StatutInvitation
const filtre = ref<Filtre>('sans_revoquees')

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

const PASTILLES: Record<string, 'actif' | 'en_attente' | 'refusee' | 'neutre'> = {
  active: 'actif',
  utilisee: 'neutre',
  expiree: 'neutre',
  revoquee: 'refusee',
}

const LIBELLES: Record<string, string> = {
  active: 'Active',
  utilisee: 'Complète',
  expiree: 'Expirée',
  revoquee: 'Révoquée',
}

const DUREES = [
  { valeur: '', libelle: 'Jamais' },
  { valeur: '7', libelle: '7 jours' },
  { valeur: '30', libelle: '30 jours' },
  { valeur: '90', libelle: '3 mois' },
  { valeur: '365', libelle: '1 an' },
]

const STATUTS_MEMBRE: Record<string, string> = {
  actif: 'actif',
  en_attente: 'en attente de validation',
  suspendu: 'suspendu',
}

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const choix = filtre.value
    invitations.value = (
      await listerInvitations({
        parPage: 100,
        status: choix === 'sans_revoquees' || choix === 'toutes' ? undefined : choix,
        revoquees: choix === 'sans_revoquees' ? false : undefined,
      })
    ).elements
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)
watch(filtre, charger)

async function creer(): Promise<void> {
  if (suspendu.value || envoi.value) return

  const texte = description.value.trim()
  const brut = plafond.value.trim()
  const max = brut === '' ? null : Number(brut)
  erreursChamps.value = {}
  if (texte === '') erreursChamps.value['note'] = 'Une description est requise.'
  if (max !== null && (!Number.isInteger(max) || max < 1)) erreursChamps.value['maxUses'] = 'Un nombre entier, 1 ou plus.'
  if (Object.keys(erreursChamps.value).length > 0) return

  envoi.value = true
  erreur.value = null
  try {
    creee.value = await creerInvitation({
      note: texte,
      maxUses: max,
      expiresInDays: duree.value === '' ? null : Number(duree.value),
    })
    description.value = ''
    plafond.value = ''
    duree.value = ''
    await charger()
  } catch (cause) {
    const champs = analyserErreur(cause).champs
    if (Object.keys(champs).length > 0) erreursChamps.value = champs
    else erreur.value = cause
  } finally {
    envoi.value = false
  }
}

async function revoquer(invitation: Invitation): Promise<void> {
  if (suspendu.value) return
  erreur.value = null
  try {
    await revoquerInvitation(invitation.id)
    await charger()
  } catch (cause) {
    erreur.value = cause
  }
}

function dateLisible(valeur: string | null): string {
  if (valeur === null) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

function compteur(invitation: Invitation): string {
  const inscrits = `${invitation.useCount} inscrit${invitation.useCount > 1 ? 's' : ''}`
  return invitation.maxUses === null ? `${inscrits} · illimité` : `${inscrits} / ${invitation.maxUses}`
}
</script>

<template>
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-6">
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <LienInvitation v-if="creee !== null" :invitation="creee" @fermer="creee = null" />

    <PanneauBase titre="Nouveau lien d’invitation">
      <form novalidate class="flex flex-col gap-3.5" @submit.prevent="creer">
        <ChampTexte
          v-model="description"
          etiquette="Description"
          placeholder="Branche Romarin, cousins de Saule…"
          :erreur="erreursChamps['note'] ?? ''"
        />

        <div class="flex flex-wrap gap-3">
          <label class="flex min-w-[10rem] flex-1 flex-col gap-1">
            <span class="text-sm font-medium">Nombre d’inscriptions</span>
            <input
              v-model="plafond"
              type="number"
              min="1"
              inputmode="numeric"
              placeholder="Illimité"
              class="rounded-champ border bg-fond px-2.5 py-1.5 text-sm text-texte"
              :class="erreursChamps['maxUses'] ? 'border-alerte' : 'border-ligne'"
            />
            <span v-if="erreursChamps['maxUses']" class="text-xs text-alerte">{{ erreursChamps['maxUses'] }}</span>
          </label>

          <label class="flex min-w-[10rem] flex-1 flex-col gap-1">
            <span class="text-sm font-medium">Expire après</span>
            <select
              v-model="duree"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            >
              <option v-for="option in DUREES" :key="option.valeur" :value="option.valeur">
                {{ option.libelle }}
              </option>
            </select>
            <span v-if="erreursChamps['expiresInDays']" class="text-xs text-alerte">
              {{ erreursChamps['expiresInDays'] }}
            </span>
          </label>
        </div>

        <div>
          <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu" :title="infobulle">
            Créer le lien
          </BoutonBase>
        </div>
      </form>
    </PanneauBase>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <PanneauBase>
      <template #entete>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold tracking-tight">Invitations</h2>
          <select
            v-model="filtre"
            aria-label="Filtrer les invitations"
            class="rounded-champ border border-ligne bg-fond px-2 py-1 text-sm text-texte"
          >
            <option value="sans_revoquees">Sans les révoquées</option>
            <option value="active">Actives</option>
            <option value="utilisee">Complètes</option>
            <option value="expiree">Expirées</option>
            <option value="revoquee">Révoquées</option>
            <option value="toutes">Toutes</option>
          </select>
        </div>
      </template>

      <SqueletteChargement v-if="chargement && invitations.length === 0" :lignes="3" />

      <p v-else-if="invitations.length === 0" class="text-sm text-attenue">Aucune invitation.</p>

      <ul v-else class="flex flex-col divide-y divide-ligne">
        <li v-for="invitation in invitations" :key="invitation.id" class="py-3 first:pt-0 last:pb-0">
          <div class="flex flex-wrap items-center gap-2">
            <PastilleEtat
              :etat="PASTILLES[invitation.status] ?? 'neutre'"
              :libelle="LIBELLES[invitation.status] ?? invitation.status"
            />
            <span class="text-sm font-medium">{{ invitation.note ?? invitation.tokenPrefix + '…' }}</span>
            <span class="text-sm text-attenue tnum">{{ compteur(invitation) }}</span>

            <BoutonBase
              v-if="invitation.status === 'active'"
              variante="discret"
              class="ml-auto"
              :desactive="suspendu"
              :title="infobulle"
              @click="revoquer(invitation)"
            >
              Révoquer
            </BoutonBase>
          </div>

          <p class="mt-1 text-xs text-attenue">
            Créé le {{ dateLisible(invitation.createdAt) }} ·
            <template v-if="invitation.revokedAt">révoqué le {{ dateLisible(invitation.revokedAt) }}</template>
            <template v-else-if="invitation.expiresAt">expire le {{ dateLisible(invitation.expiresAt) }}</template>
            <template v-else>sans expiration</template>
            · <span class="font-mono">{{ invitation.tokenPrefix }}…</span>
          </p>

          <ul v-if="invitation.inscrits.length > 0" class="mt-2 flex flex-col gap-1 border-l-2 border-ligne pl-3">
            <li v-for="inscrit in invitation.inscrits" :key="inscrit.userId" class="text-sm">
              <span class="font-medium">{{ inscrit.personLabel ?? inscrit.email }}</span>
              <span v-if="inscrit.personLabel" class="text-attenue"> — {{ inscrit.email }}</span>
              <span class="text-xs text-attenue">
                · {{ dateLisible(inscrit.createdAt) }} · {{ STATUTS_MEMBRE[inscrit.memberStatus] ?? inscrit.memberStatus }}
              </span>
            </li>
          </ul>

          <!-- Les tentatives de l'heure : c'est ce qui montre qu'un lien est sondé. -->
          <p v-if="invitation.tentatives > 0" class="mt-1 text-xs text-alerte">
            {{ invitation.tentatives }} tentative{{ invitation.tentatives > 1 ? 's' : '' }} de
            rattachement dans la dernière heure.
          </p>
        </li>
      </ul>
    </PanneauBase>
  </div>
</template>
