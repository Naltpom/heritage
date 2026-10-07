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
 *
 * **Mise en page** (handoff « mode Administration », § 4.3) : les filtres en
 * puces et « + Nouveau lien » sur une ligne ; le formulaire de création dans un
 * **feuillet** plutôt qu'en haut de page ; le lien créé dans un bandeau de
 * succès au-dessus de la liste, avec « Copier » ; les inscrits de chaque lien
 * **repliés** derrière « Voir les N derniers inscrits ».
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
import Feuillet from '@/components/base/Feuillet.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
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

const FILTRES: { valeur: Filtre; libelle: string }[] = [
  { valeur: 'sans_revoquees', libelle: 'Sans les révoquées' },
  { valeur: 'active', libelle: 'Actives' },
  { valeur: 'utilisee', libelle: 'Complètes' },
  { valeur: 'expiree', libelle: 'Expirées' },
  { valeur: 'revoquee', libelle: 'Révoquées' },
  { valeur: 'toutes', libelle: 'Toutes' },
]

/** Le feuillet « Nouveau lien ». */
const formulaireOuvert = ref(false)
/** Les invitations dont les inscrits sont dépliés. */
const deplies = ref<ReadonlySet<number>>(new Set())
const copie = ref(false)

function ouvrirLeFormulaire(): void {
  erreursChamps.value = {}
  formulaireOuvert.value = true
}

function basculerInscrits(id: number): void {
  const suivant = new Set(deplies.value)
  if (suivant.has(id)) suivant.delete(id)
  else suivant.add(id)
  deplies.value = suivant
}

async function copier(): Promise<void> {
  if (creee.value === null) return
  try {
    await navigator.clipboard.writeText(creee.value.lien)
    copie.value = true
  } catch {
    // Le presse-papiers peut être refusé (contexte non sécurisé, permission).
    // Ce n'est pas une panne : l'URL est affichée en entier et sélectionnable,
    // c'est le chemin de repli, et il suffit.
    copie.value = false
  }
}

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
  copie.value = false
  try {
    creee.value = await creerInvitation({
      note: texte,
      maxUses: max,
      expiresInDays: duree.value === '' ? null : Number(duree.value),
    })
    description.value = ''
    plafond.value = ''
    duree.value = ''
    formulaireOuvert.value = false
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

/** « expire le … », « révoqué le … », « sans expiration ». */
function echeance(invitation: Invitation): string {
  if (invitation.revokedAt) return `révoqué le ${dateLisible(invitation.revokedAt)}`
  if (invitation.expiresAt) return `expire le ${dateLisible(invitation.expiresAt)}`
  return 'sans expiration'
}

function compteur(invitation: Invitation): string {
  const inscrits = `${invitation.useCount} inscrit${invitation.useCount > 1 ? 's' : ''}`
  return invitation.maxUses === null ? `${inscrits} · illimité` : `${inscrits} / ${invitation.maxUses}`
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <div
        class="puces-defilantes flex min-w-0 flex-[1_1_260px] gap-2 overflow-x-auto overflow-y-hidden py-0.5"
        role="group"
        aria-label="Invitations affichées"
      >
        <button
          v-for="f in FILTRES"
          :key="f.valeur"
          type="button"
          class="flex min-h-11 flex-none items-center rounded-pastille border px-[13px] text-[13px] whitespace-nowrap md:min-h-9"
          :class="f.valeur === filtre ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
          :aria-pressed="f.valeur === filtre"
          @click="filtre = f.valeur"
        >
          {{ f.libelle }}
        </button>
      </div>
      <BoutonBase class="min-h-11 whitespace-nowrap md:min-h-9" :desactive="suspendu" :title="infobulle" @click="ouvrirLeFormulaire">
        + Nouveau lien
      </BoutonBase>
    </div>

    <!-- Le lien créé : le seul instant où il existe. -->
    <div
      v-if="creee !== null"
      class="flex flex-col gap-2 rounded-panneau border border-ok-bord bg-ok-doux px-4 py-3.5"
      data-lien-cree
    >
      <span class="font-semibold">Lien créé : « {{ creee.note ?? creee.tokenPrefix + '…' }} »</span>
      <div class="flex flex-wrap items-center gap-2">
        <!-- L'URL entière, en chasse fixe, sélectionnable. Elle sera recopiée à
             la main dans un message : la raccourcir la rendrait inutilisable. -->
        <code
          class="min-w-0 flex-[1_1_220px] rounded-champ border border-ligne bg-panneau px-2.5 py-2 font-mono text-[12.5px] break-all select-all"
        >{{ creee.lien }}</code>
        <BoutonBase class="min-h-11 md:min-h-9" @click="copier">{{ copie ? 'Copié' : 'Copier' }}</BoutonBase>
      </div>
      <span class="text-[12.5px] text-attenue">Ce lien n’apparaît qu’une fois. Perdu, il se révoque et se recrée.</span>
      <ul v-if="creee.avertissements.length > 0" class="list-disc pl-5 text-sm text-alerte">
        <li v-for="avertissement in creee.avertissements" :key="avertissement.code">{{ avertissement.message }}</li>
      </ul>
      <button type="button" class="self-start text-[13px] text-accent underline" @click="creee = null">
        J’ai copié le lien
      </button>
    </div>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <SqueletteChargement v-if="chargement && invitations.length === 0" :lignes="3" />

    <p
      v-else-if="invitations.length === 0"
      class="rounded-panneau border border-ligne bg-panneau px-4 py-7 text-center text-attenue"
    >
      Aucune invitation.
    </p>

    <ul v-else class="m-0 flex list-none flex-col gap-px overflow-hidden rounded-panneau border border-ligne bg-ligne p-0">
      <li
        v-for="invitation in invitations"
        :key="invitation.id"
        class="flex flex-col gap-1.5 bg-panneau px-4 py-3"
        :data-invitation="invitation.id"
      >
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
          <PastilleEtat
            :etat="PASTILLES[invitation.status] ?? 'neutre'"
            :libelle="LIBELLES[invitation.status] ?? invitation.status"
          />
          <span class="font-semibold">{{ invitation.note ?? invitation.tokenPrefix + '…' }}</span>
          <span class="text-[13px] text-attenue tnum">{{ compteur(invitation) }}</span>
          <span class="flex-1" />
          <button
            v-if="invitation.status === 'active'"
            type="button"
            class="min-h-11 rounded-champ px-3 text-[13px] font-semibold text-alerte hover:bg-alerte-doux disabled:cursor-not-allowed disabled:opacity-55 md:min-h-9"
            :disabled="suspendu"
            :title="infobulle"
            @click="revoquer(invitation)"
          >
            Révoquer
          </button>
        </div>

        <span class="text-[12.5px] text-attenue">
          Créé le {{ dateLisible(invitation.createdAt) }} · {{ echeance(invitation) }} ·
          <span class="font-mono">{{ invitation.tokenPrefix }}…</span>
        </span>

        <!-- Les tentatives de l'heure : c'est ce qui montre qu'un lien est sondé. -->
        <span v-if="invitation.tentatives > 0" class="text-[12.5px] text-alerte">
          {{ invitation.tentatives }} tentative{{ invitation.tentatives > 1 ? 's' : '' }} de rattachement dans la
          dernière heure.
        </span>

        <template v-if="invitation.inscrits.length > 0">
          <button
            type="button"
            class="min-h-8 self-start text-[13px] text-accent"
            :aria-expanded="deplies.has(invitation.id)"
            @click="basculerInscrits(invitation.id)"
          >
            {{
              deplies.has(invitation.id)
                ? 'Masquer les inscrits'
                : invitation.inscrits.length > 1
                  ? `Voir les ${invitation.inscrits.length} derniers inscrits`
                  : 'Voir le dernier inscrit'
            }}
          </button>
          <ul v-if="deplies.has(invitation.id)" class="m-0 flex list-none flex-col gap-1 rounded-champ bg-fond px-3 py-2">
            <li v-for="inscrit in invitation.inscrits" :key="inscrit.userId" class="text-[13px]">
              <span class="font-medium">{{ inscrit.personLabel ?? inscrit.email }}</span>
              <span class="text-attenue">
                <template v-if="inscrit.personLabel"> · {{ inscrit.email }}</template>
                · {{ dateLisible(inscrit.createdAt) }} · {{ STATUTS_MEMBRE[inscrit.memberStatus] ?? inscrit.memberStatus }}
              </span>
              <!-- Inscrit sans s'être trouvé (ADR 0013) : sa fiche se décide
                   dans « À traiter ». -->
              <RouterLink
                v-if="inscrit.matchKind === 'a_rattacher' && inscrit.memberStatus === 'en_attente'"
                :to="{ name: 'administration.a-traiter', query: { type: 'rattachement' } }"
                class="ml-1 font-semibold text-accent underline"
              >
                fiche à identifier
              </RouterLink>
            </li>
          </ul>
        </template>
      </li>
    </ul>
  </div>

  <Feuillet :ouvert="formulaireOuvert" titre="Nouveau lien d’invitation" @fermer="formulaireOuvert = false">
    <form novalidate class="flex flex-col gap-3.5" @submit.prevent="creer">
      <ChampTexte
        v-model="description"
        etiquette="Description"
        placeholder="Branche Romarin, cousins de Saule…"
        :erreur="erreursChamps['note'] ?? ''"
      />

      <label class="flex flex-col gap-1">
        <span class="text-[13.5px] font-medium">Nombre d’inscriptions</span>
        <input
          v-model="plafond"
          type="number"
          min="1"
          inputmode="numeric"
          placeholder="Illimité"
          class="min-h-11 rounded-champ border bg-fond px-2.5 text-sm text-texte md:min-h-9"
          :class="erreursChamps['maxUses'] ? 'border-alerte' : 'border-ligne'"
        />
        <span v-if="erreursChamps['maxUses']" class="text-xs text-alerte">{{ erreursChamps['maxUses'] }}</span>
      </label>

      <fieldset class="flex flex-col gap-1.5">
        <legend class="mb-1.5 text-[13.5px] font-medium">Expire après</legend>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="option in DUREES"
            :key="option.valeur"
            type="button"
            class="flex min-h-11 items-center rounded-pastille border px-[13px] text-[13px] md:min-h-9"
            :class="option.valeur === duree ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
            :aria-pressed="option.valeur === duree"
            @click="duree = option.valeur"
          >
            {{ option.libelle }}
          </button>
        </div>
        <span v-if="erreursChamps['expiresInDays']" class="text-xs text-alerte">{{ erreursChamps['expiresInDays'] }}</span>
      </fieldset>

      <MessageErreur v-if="erreur !== null" :erreur="erreur" />

      <BoutonBase type="submit" class="min-h-11" :chargement="envoi" :desactive="suspendu" :title="infobulle">
        Créer le lien
      </BoutonBase>
    </form>
  </Feuillet>
</template>
