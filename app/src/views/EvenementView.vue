<script setup lang="ts">
/**
 * Un événement : l'annonce, **ma réponse**, qui vient — et, pour
 * l'organisateur, le suivi des invités.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ouvrir cette page, c'est voir l'annonce.** Le serveur pose la première vue
 * de l'invité au `GET` qui la charge : rien à cliquer, et c'est ce qui permet à
 * l'organisateur de distinguer « pas encore vu » de « vu, sans réponse ». Sous
 * la bascule « voir comme un membre », rien n'est marqué.
 * ────────────────────────────────────────────────────────────────────
 *
 * L'ordre suit le téléphone : le bandeau (titre, dates, lieu), mes deux
 * boutons **avant** la description — c'est le geste pour lequel on vient —,
 * puis qui vient. L'organisateur trouve ensuite le suivi, l'ajout d'invités et
 * les gestes qui engagent (modifier, annuler), chacun confirmé.
 *
 * Chaque écriture rend l'événement relu : l'écran remplace son état par la
 * réponse, sans recoller de morceaux.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  annulerEvenement,
  creerLienDInvite,
  inviterAEvenement,
  lireEvenement,
  noterLaReponseDUnInvite,
  relancerLesInvites,
  repondreAEvenement,
  retirerDeLEvenement,
  revoquerLienDInvite,
  type DetailDEvenement,
  type LigneDeSuivi,
  type ReponseAInvitation,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import BoutonsDeReponse from '@/components/evenements-familiaux/BoutonsDeReponse.vue'
import SelecteurDInvites from '@/components/evenements-familiaux/SelecteurDInvites.vue'
import SuiviDesInvites, { type LienAffiche } from '@/components/evenements-familiaux/SuiviDesInvites.vue'
import {
  compteDInvites,
  confirmationDAnnulation,
  confirmationDeRegeneration,
  confirmationDeRelance,
  confirmationDeRetrait,
  confirmationDeRevocation,
  DATES_MODIFIEES,
  etatDeLEvenement,
  messageDePartage,
  periodeEnTete,
  phraseDeMaReponse,
  phraseDeReponseNotee,
  quand,
} from '@/components/evenements-familiaux/evenements'
import { chargerLesInvitables, type Invitable } from '@/components/evenements-familiaux/invites'
import { adresseDuLien } from '@/components/tirages/tirages'
import { useSession } from '@/composables/useSession'

const route = useRoute()
const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const id = computed(() => Number(route.params['id']))
const evenement = ref<DetailDEvenement | null>(null)
const erreurDeChargement = ref<unknown>(null)
/** L'heure de la dernière lecture : les « il y a 2 j » du suivi se comptent depuis elle. */
const maintenant = ref(new Date())
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  erreurDeChargement.value = null

  try {
    evenement.value = await lireEvenement(id.value, avorteur.signal)
    maintenant.value = new Date()
  } catch (e) {
    erreurDeChargement.value = e
  }
}

watch(id, charger, { immediate: true })
onBeforeUnmount(() => avorteur?.abort())

const etat = computed(() => (evenement.value === null ? null : etatDeLEvenement(evenement.value, maintenant.value)))
const modifiable = computed(() => etat.value === 'a_venir' || etat.value === 'en_cours')

/* ───────────────── Écritures ───────────────── */

const enCours = ref<string | null>(null)
const erreur = ref<unknown>(null)

/** Une écriture à la fois ; sa réponse remplace l'événement. */
async function agir(cle: string, geste: () => Promise<DetailDEvenement>): Promise<boolean> {
  if (enCours.value !== null) return false
  enCours.value = cle
  erreur.value = null

  try {
    evenement.value = await geste()
    maintenant.value = new Date()
    return true
  } catch (e) {
    erreur.value = e
    return false
  } finally {
    enCours.value = null
  }
}

/** Le geste en attente de validation : rien ne part avant « Confirmer ». */
const aConfirmer = ref<(DemandeDeConfirmation & { geste: () => Promise<void> }) | null>(null)

function demander(confirmation: DemandeDeConfirmation, geste: () => Promise<void>): void {
  if (suspendu.value || enCours.value !== null) return
  aConfirmer.value = { ...confirmation, geste }
}

async function surConfirmer(): Promise<void> {
  const demande = aConfirmer.value
  if (demande === null) return
  await demande.geste()
  aConfirmer.value = null
}

/* Ma réponse — sans confirmation : elle se change d'un geste, jusqu'à la fin. */

const reponseEnCours = computed<ReponseAInvitation | null>(() =>
  enCours.value === 'reponse-present' ? 'present' : enCours.value === 'reponse-absent' ? 'absent' : null,
)

function repondre(reponse: ReponseAInvitation): void {
  if (suspendu.value) return
  void agir(`reponse-${reponse}`, () => repondreAEvenement(id.value, reponse))
}

/* ───────────────── Organisateur ───────────────── */

/**
 * Noter la réponse d'un invité à sa place — « grand-mère a répondu par
 * téléphone ». Sans confirmation, comme une réponse : elle se corrige d'un
 * geste, et la ligne dit ensuite « noté par … ».
 */
function noterReponse(ligne: LigneDeSuivi, reponse: ReponseAInvitation): void {
  if (suspendu.value) return
  void agir(`noter-${ligne.personId}-${reponse}`, () => noterLaReponseDUnInvite(id.value, ligne.personId, reponse))
}

function annuler(): void {
  const e = evenement.value
  if (e === null || e.suivi === null) return
  const aPrevenir = e.suivi.presents.length + e.suivi.absents.length + e.suivi.fantomes.length
  demander(confirmationDAnnulation(e.titre, aPrevenir), async () => {
    await agir('annuler', () => annulerEvenement(id.value))
  })
}

function relancer(): void {
  const n = evenement.value?.suivi?.aRelancer ?? 0
  demander(confirmationDeRelance(n), async () => {
    await agir('relancer', () => relancerLesInvites(id.value))
  })
}

function retirer(ligne: LigneDeSuivi): void {
  demander(confirmationDeRetrait(ligne.libelle), async () => {
    const ok = await agir(`retirer-${ligne.personId}`, () => retirerDeLEvenement(id.value, ligne.personId))
    if (ok && lienAffiche.value?.personId === ligne.personId) lienAffiche.value = null
  })
}

/* Ajouter des invités : le sélecteur, chargé au premier dépliement. */

const enAjout = ref(false)
const invitables = ref<Invitable[] | null>(null)
const erreurDesInvitables = ref<unknown>(null)
const aAjouter = ref<number[]>([])

const dejaInvites = computed(() => {
  const s = evenement.value?.suivi
  return s === null || s === undefined ? [] : [...s.presents, ...s.absents, ...s.fantomes, ...s.nonVus].map((l) => l.personId)
})

async function ouvrirLAjout(): Promise<void> {
  enAjout.value = true
  aAjouter.value = []
  if (invitables.value !== null) return
  erreurDesInvitables.value = null
  try {
    invitables.value = await chargerLesInvitables()
  } catch (e) {
    erreurDesInvitables.value = e
  }
}

async function inviter(): Promise<void> {
  if (aAjouter.value.length === 0 || suspendu.value) return
  const ok = await agir('inviter', () => inviterAEvenement(id.value, aAjouter.value))
  if (ok) {
    enAjout.value = false
    aAjouter.value = []
  }
}

/* ───────────────── Le lien personnel d'un invité sans compte ─────────────────
 *
 * La clé n'est rendue qu'à la création : « renvoyer le lien » à quelqu'un qui en
 * a déjà un, c'est en créer un nouveau — l'ancien meurt, d'où la confirmation.
 * Le panneau `LienDeLInvite` montre le lien tant qu'on ne l'a pas fermé. */

const lienAffiche = ref<LienAffiche | null>(null)

/** La réponse du lien n'est pas l'événement relu : on ne touche que le drapeau. */
function marquerLien(personId: number, actif: boolean): void {
  const s = evenement.value?.suivi
  if (s === null || s === undefined) return
  for (const l of [...s.presents, ...s.absents, ...s.fantomes, ...s.nonVus]) {
    if (l.personId === personId) l.lienActif = actif
  }
}

async function fabriquerLien(ligne: LigneDeSuivi): Promise<void> {
  const e = evenement.value
  if (e === null || enCours.value !== null) return
  enCours.value = `lien-${ligne.personId}`
  erreur.value = null

  try {
    const lien = await creerLienDInvite(id.value, ligne.personId)
    marquerLien(ligne.personId, true)
    const adresse = adresseDuLien(lien.url, window.location.origin)

    // Copie tentée d'office : c'est ce qu'on est venu faire. Refusée (Safari
    // après un appel réseau, contexte non sécurisé), le panneau garde le lien
    // affiché, et ses boutons Partager et Copier.
    let copie = false
    try {
      await navigator.clipboard.writeText(adresse)
      copie = true
    } catch {
      copie = false
    }
    lienAffiche.value = {
      personId: ligne.personId,
      libelle: ligne.libelle,
      adresse,
      message: messageDePartage(e.titre, ligne.libelle, e.debut, e.fin),
      copie,
    }
  } catch (err) {
    erreur.value = err
  } finally {
    enCours.value = null
  }
}

function copierLien(ligne: LigneDeSuivi): void {
  if (suspendu.value || enCours.value !== null) return
  // Pas encore de lien : rien ne meurt, pas de confirmation.
  if (!ligne.lienActif) {
    void fabriquerLien(ligne)
    return
  }
  demander(confirmationDeRegeneration(ligne.libelle), () => fabriquerLien(ligne))
}

function revoquerLien(ligne: LigneDeSuivi): void {
  demander(confirmationDeRevocation(ligne.libelle), async () => {
    enCours.value = `lien-${ligne.personId}`
    erreur.value = null
    try {
      await revoquerLienDInvite(id.value, ligne.personId)
      marquerLien(ligne.personId, false)
      if (lienAffiche.value?.personId === ligne.personId) lienAffiche.value = null
    } catch (e) {
      erreur.value = e
    } finally {
      enCours.value = null
    }
  })
}

const PASTILLES: Record<string, string> = {
  a_venir: 'bg-accent-doux text-accent',
  en_cours: 'bg-ok-doux text-ok',
  passe: 'border border-ligne text-attenue',
  annule: 'bg-alerte-doux text-alerte',
}
</script>

<template>
  <div class="mx-auto w-full max-w-2xl px-4 py-6">
    <SqueletteChargement v-if="evenement === null && erreurDeChargement === null" :lignes="5" />

    <MessageErreur
      v-else-if="evenement === null"
      :erreur="erreurDeChargement"
      titre="Cet événement n’a pas pu être chargé"
      @action="charger"
    />

    <template v-else>
      <!-- ───────── Le bandeau ───────── -->
      <header class="mb-5 flex flex-col gap-1.5">
        <span v-if="etat !== null" class="self-start rounded-pastille px-2.5 py-0.5 text-xs font-semibold" :class="PASTILLES[etat]">
          {{ quand(evenement, maintenant) }}
        </span>
        <h1 class="text-2xl leading-tight font-semibold text-texte" :class="evenement.annule ? 'line-through decoration-2' : ''">
          {{ evenement.titre }}
        </h1>
        <p class="text-base text-texte">{{ periodeEnTete(evenement.debut, evenement.fin) }}</p>
        <p v-if="evenement.lieu" class="flex items-center gap-1.5 text-sm text-texte">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M8 14s5-4.2 5-8A5 5 0 0 0 3 6c0 3.8 5 8 5 8z" /><circle cx="8" cy="6" r="1.8" />
          </svg>
          {{ evenement.lieu }}
        </p>
        <p class="text-xs text-attenue">
          Organisé par {{ evenement.organisateur.estMoi ? 'vous' : evenement.organisateur.libelle }} · {{ compteDInvites(evenement.nombreDInvites) }}
        </p>
      </header>

      <p v-if="motifSuspendu !== null" class="mb-4 rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
        {{ motifSuspendu }}
      </p>

      <p v-if="evenement.annule" class="mb-5 rounded-panneau border border-alerte-bord bg-alerte-doux p-4 text-sm font-semibold text-alerte" role="status">
        Cet événement est annulé.
      </p>

      <MessageErreur v-if="erreur !== null" class="mb-4" :erreur="erreur" titre="L’opération n’a pas abouti" />

      <!-- ───────── Ma réponse ───────── -->
      <section v-if="evenement.suisInvite" class="mb-5 flex flex-col gap-2 rounded-panneau border border-ligne bg-panneau p-4" aria-labelledby="titre-ma-reponse">
        <h2 id="titre-ma-reponse" class="text-sm font-semibold text-texte">
          {{ evenement.peutRepondre && evenement.maReponse === null ? 'Venez-vous ?' : 'Ma réponse' }}
        </h2>
        <template v-if="evenement.peutRepondre">
          <!-- Les dates ont bougé depuis la réponse : elle tient, mais on la confirme
               — toucher la réponse marquée suffit. -->
          <p
            v-if="evenement.reponseAConfirmer"
            class="rounded-panneau border border-alerte-bord bg-alerte-doux p-3 text-sm font-semibold text-alerte"
            role="status"
          >
            {{ DATES_MODIFIEES }}
          </p>
          <BoutonsDeReponse
            :reponse="evenement.maReponse"
            :en-cours="reponseEnCours"
            :confirmable="evenement.reponseAConfirmer"
            :desactive="suspendu || (enCours !== null && reponseEnCours === null)"
            @choisir="repondre"
          />
          <p class="text-xs text-attenue">Vous pouvez changer d’avis jusqu’à la fin de l’événement.</p>
        </template>
        <p v-else class="text-sm text-texte">{{ phraseDeMaReponse(evenement.maReponse) }}</p>
        <p v-if="evenement.maReponseNoteePar !== null" class="text-xs text-attenue">
          {{ phraseDeReponseNotee(evenement.maReponseNoteePar) }}
        </p>
      </section>

      <p v-if="evenement.description" class="mb-5 text-[15px] leading-relaxed whitespace-pre-line text-texte">
        {{ evenement.description }}
      </p>

      <!-- ───────── Qui vient ───────── -->
      <PanneauBase class="mb-5">
        <template #entete>
          <div class="flex items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold tracking-tight">Qui vient</h2>
            <span class="text-xs text-attenue tnum">{{ evenement.nombreDePresents }} présent{{ evenement.nombreDePresents > 1 ? 's' : '' }}</span>
          </div>
        </template>
        <ul v-if="evenement.quiVient.presents.length > 0" class="flex flex-wrap gap-2">
          <li
            v-for="p in evenement.quiVient.presents"
            :key="p.personId"
            class="rounded-pastille border border-ligne bg-fond px-3 py-1 text-sm text-texte"
          >
            {{ p.libelle }}<template v-if="p.estMoi"> (vous)</template>
          </li>
        </ul>
        <p v-if="evenement.quiVient.autres > 0" class="mt-2 text-xs text-attenue">
          Et {{ evenement.quiVient.autres }} autre{{ evenement.quiVient.autres > 1 ? 's' : '' }} personne{{ evenement.quiVient.autres > 1 ? 's' : '' }}.
        </p>
        <p v-if="evenement.nombreDePresents === 0" class="text-sm text-attenue">Personne n’a encore dit oui.</p>
        <p v-if="evenement.nombreDAbsents > 0 && evenement.suivi === null" class="mt-2 text-xs text-attenue">
          {{ evenement.nombreDAbsents }} invité{{ evenement.nombreDAbsents > 1 ? 's ont' : ' a' }} dit ne pas pouvoir venir.
        </p>
      </PanneauBase>

      <!-- ───────── L'organisateur ───────── -->
      <template v-if="evenement.suivi !== null">
        <PanneauBase titre="Suivi des invités" class="mb-5">
          <SuiviDesInvites
            :suivi="evenement.suivi"
            :maintenant="maintenant"
            :modifiable="modifiable"
            :desactive="suspendu"
            :en-cours="enCours"
            :lien-affiche="lienAffiche"
            @relancer="relancer"
            @retirer="retirer"
            @copier-lien="copierLien"
            @revoquer-lien="revoquerLien"
            @fermer-lien="lienAffiche = null"
            @noter-reponse="noterReponse"
          />
        </PanneauBase>

        <PanneauBase v-if="modifiable" titre="Inviter d’autres personnes" class="mb-5">
          <BoutonBase v-if="!enAjout" variante="secondaire" class="min-h-11" :desactive="suspendu" @click="ouvrirLAjout">
            Ajouter des invités
          </BoutonBase>
          <template v-else>
            <MessageErreur v-if="erreurDesInvitables !== null" :erreur="erreurDesInvitables" titre="Les personnes de l’arbre n’ont pas pu être chargées" />
            <SqueletteChargement v-else-if="invitables === null" :lignes="4" />
            <SelecteurDInvites v-else v-model="aAjouter" :invitables="invitables" :deja-invites="dejaInvites" />
            <div class="mt-3 flex flex-wrap gap-2">
              <BoutonBase
                class="min-h-11"
                :chargement="enCours === 'inviter'"
                :desactive="suspendu || aAjouter.length === 0"
                @click="inviter"
              >
                {{ aAjouter.length > 0 ? `Inviter et prévenir · ${compteDInvites(aAjouter.length)}` : 'Inviter' }}
              </BoutonBase>
              <BoutonBase variante="discret" class="min-h-11" @click="enAjout = false">Fermer</BoutonBase>
            </div>
          </template>
        </PanneauBase>

        <div v-if="modifiable" class="flex flex-wrap gap-2">
          <RouterLink
            v-if="!suspendu"
            :to="{ name: 'evenement.modifier', params: { id: evenement.id } }"
            class="inline-flex min-h-11 items-center justify-center rounded-champ border border-ligne bg-panneau px-4 text-sm font-semibold text-texte hover:border-accent"
          >
            Modifier l’annonce
          </RouterLink>
          <BoutonBase variante="danger" class="min-h-11" :chargement="enCours === 'annuler'" :desactive="suspendu" @click="annuler">
            Annuler l’événement
          </BoutonBase>
        </div>
      </template>
    </template>

    <DialogueConfirmation
      :demande="aConfirmer"
      :chargement="enCours !== null"
      @confirmer="surConfirmer"
      @annuler="aConfirmer = null"
    />
  </div>
</template>
