<script setup lang="ts">
/**
 * Un tirage au sort : à qui j'offre cette année, qui vient, et — pour
 * l'organisateur — les réglages, les participants et le tirage lui-même.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le nom du receveur est masqué jusqu'au geste « Découvrir »**
 * (`CarteAttribution`).
 *
 * Le serveur ne rend au lecteur que ses propres attributions ; l'écran ajoute
 * une précaution de plus, parce qu'on l'ouvre volontiers devant d'autres, un
 * soir de famille.
 * ────────────────────────────────────────────────────────────────────
 *
 * Chaque écriture rend le tirage relu : l'écran remplace son état par la
 * réponse, sans recoller de morceaux.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ajouterParticipant,
  analyserErreur,
  annulerLeTirage,
  creerLienDuParticipant,
  definirPresence,
  lancerLeTirage,
  lireTirage,
  ouvrirEdition,
  reglerTirage,
  rejoindreTirage,
  retirerParticipant,
  revoquerLienDuParticipant,
  supprimerTirage,
  type DetailDeTirage,
  type EditionDuTirage,
  type ParticipantDuTirage,
  type PresenceAuTirage,
  type ReglesDuTirage,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import DialogueConfirmation, { type DemandeDeConfirmation } from '@/components/base/DialogueConfirmation.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import { estFicheProposee, type ChoixDePersonne } from '@/components/proposition/creation'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import CarteAttribution from '@/components/tirages/CarteAttribution.vue'
import FormulaireReglages from '@/components/tirages/FormulaireReglages.vue'
import ActionsDuLien from '@/components/tirages/ActionsDuLien.vue'
import LienDuParticipant from '@/components/tirages/LienDuParticipant.vue'
import {
  PRESENCES,
  adresseDuLien,
  anneeProposee,
  confirmationDAnnulation,
  confirmationDePresence,
  confirmationDeRegeneration,
  confirmationDeRetrait,
  confirmationDeRevocation,
  confirmationDeSuppression,
  confirmationDuTirage,
  dateLongue,
  libelleDeMemoire,
  libelleDuJour,
  monPersonId,
} from '@/components/tirages/tirages'
import { useSession } from '@/composables/useSession'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const id = computed(() => Number(route.params['id']))
const tirage = ref<DetailDeTirage | null>(null)
const erreurDeChargement = ref<unknown>(null)
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  erreurDeChargement.value = null

  try {
    tirage.value = await lireTirage(id.value, avorteur.signal)
  } catch (e) {
    erreurDeChargement.value = e
  }
}

watch(id, charger, { immediate: true })
onBeforeUnmount(() => avorteur?.abort())

/* ───────────────── Écritures ───────────────── */

const enCours = ref<string | null>(null)
const erreur = ref<unknown>(null)

/** Une écriture à la fois ; sa réponse remplace le tirage. */
async function agir(cle: string, geste: () => Promise<DetailDeTirage>): Promise<boolean> {
  if (enCours.value !== null) return false
  enCours.value = cle
  erreur.value = null

  try {
    tirage.value = await geste()
    return true
  } catch (e) {
    erreur.value = e
    return false
  } finally {
    enCours.value = null
  }
}

/* ───────────────── L'édition courante ───────────────── */

const moi = computed(() => (tirage.value === null ? null : monPersonId(tirage.value)))

/** L'édition à venir la plus proche ; à défaut, la plus récente. */
const edition = computed<EditionDuTirage | null>(() => {
  const editions = tirage.value?.editions ?? []
  const aVenir = editions.filter((e) => !e.passee)
  return aVenir.at(-1) ?? editions[0] ?? null
})

const anciennes = computed(() => (tirage.value?.editions ?? []).filter((e) => e !== edition.value))

const suivis = computed(() => new Set((tirage.value?.participants ?? []).filter((p) => p.suivi).map((p) => p.personId)))

function peutReglerLaPresence(personId: number): boolean {
  return (tirage.value?.peutGerer ?? false) || suivis.value.has(personId)
}

/* ───────────────── L'étape de validation ───────────────── */

/**
 * Le geste en attente de validation. Chaque geste qui engage — présence,
 * retrait, lancer ou annuler le tirage, supprimer — passe par là : on dit ce
 * qu'il va changer, et rien ne part avant « Confirmer ».
 */
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

/**
 * Un refus remet les listes de présence à la valeur enregistrée : le `<select>`
 * a déjà basculé sous le doigt, et rien d'autre ne l'y ramènerait.
 */
const versionDesPresences = ref(0)
function surAnnuler(): void {
  aConfirmer.value = null
  versionDesPresences.value += 1
}

function changerPresence(annee: number, personId: number, libelle: string, presence: PresenceAuTirage): void {
  demander(confirmationDePresence(libelle, personId === moi.value, presence, annee), async () => {
    await agir(`presence-${personId}`, () => definirPresence(id.value, annee, personId, presence))
  })
}

const jeSuisArriveApres = computed(
  () => moi.value !== null && (edition.value?.arrivesApresLeTirage ?? []).includes(moi.value),
)

const memoireRelachee = computed(() => {
  const e = edition.value
  const t = tirage.value
  return e !== null && t !== null && e.memoireAppliquee !== null && e.memoireAppliquee < t.memoire
    ? e.memoireAppliquee
    : null
})

/* ───────────────── Organisateur ───────────────── */

const anneeAOuvrir = computed(() => (tirage.value === null ? null : anneeProposee(tirage.value, new Date())))

function tirer(e: EditionDuTirage): void {
  const presents = e.presences.filter((p) => p.presence !== 'absente').length
  demander(confirmationDuTirage(e.annee, presents), async () => {
    await agir('tirer', () => lancerLeTirage(id.value, e.annee))
  })
}

function annuler(annee: number): void {
  demander(confirmationDAnnulation(annee), async () => {
    await agir('annuler', () => annulerLeTirage(id.value, annee))
  })
}

function supprimer(): void {
  const nom = tirage.value?.nom ?? ''
  demander(confirmationDeSuppression(nom), supprimerPourDeBon)
}

async function supprimerPourDeBon(): Promise<void> {
  enCours.value = 'supprimer'
  try {
    await supprimerTirage(id.value)
    await routeur.push({ name: 'evenements' })
  } catch (e) {
    erreur.value = e
  } finally {
    enCours.value = null
  }
}

/* Participants */

const referent = ref<number | null>(null)
const dejaChoisis = computed(() => (tirage.value?.participants ?? []).map((p) => `personne:${p.personId}`))

function ajouter(choix: ChoixDePersonne): void {
  if (estFicheProposee(choix)) return
  const avec = referent.value
  void agir('ajouter', () => ajouterParticipant(id.value, choix.personId, avec)).then((ok) => {
    if (ok) referent.value = null
  })
}

function retirer(participant: ParticipantDuTirage): void {
  demander(confirmationDeRetrait(participant.libelle, participant.estMoi), async () => {
    await agir(`retirer-${participant.personId}`, () => retirerParticipant(id.value, participant.personId))
  })
}

/* ───────────────── Le lien personnel d'un participant ─────────────────
 *
 * Pour qu'une personne sans compte suive SON tirage (`/t/<clé>`). Le référent
 * du participant le crée, le régénère ou le révoque ; l'organisateur aussi, mais
 * seulement pour un participant sans compte ni référent — tenir la clé, c'est
 * lire l'attribution (ADR 0009). Le serveur en décide, l'écran ne fait que ne
 * pas proposer un geste qu'il refuserait.
 *
 * **La clé n'est rendue qu'à la création** : le serveur n'en garde qu'une
 * empreinte. « Copier son lien » sur un participant qui en a déjà un, c'est
 * donc en fabriquer un nouveau — l'ancien meurt, d'où la confirmation. Le
 * panneau `LienDuParticipant` montre le lien tant qu'on ne l'a pas fermé.
 */

/**
 * Pas pour soi : on a déjà son compte. Pour les autres, c'est le serveur qui
 * dit (`peutPartagerLien`) : le référent, ou l'organisateur pour qui n'a ni
 * compte ni référent.
 */
function peutGererLeLien(p: ParticipantDuTirage): boolean {
  return !p.estMoi && p.peutPartagerLien
}

const gestionDeLiens = computed(() => (tirage.value?.participants ?? []).some(peutGererLeLien))

const lienAffiche = ref<{ personId: number; libelle: string; adresse: string; copie: boolean } | null>(null)

/** La réponse du lien n'est pas le tirage relu : on ne touche que le drapeau. */
function marquerLien(personId: number, actif: boolean): void {
  const p = tirage.value?.participants.find((x) => x.personId === personId)
  if (p !== undefined) p.lienActif = actif
}

async function fabriquerLien(p: ParticipantDuTirage): Promise<void> {
  if (enCours.value !== null) return
  enCours.value = `lien-${p.personId}`
  erreur.value = null

  try {
    const lien = await creerLienDuParticipant(id.value, p.personId)
    marquerLien(p.personId, true)
    const adresse = adresseDuLien(lien.url, window.location.origin)

    // Copie tentée d'office : c'est ce qu'on est venu faire. Refusée (Safari
    // après un appel réseau, contexte non sécurisé), le panneau garde le lien
    // affiché et son propre bouton.
    let copie = false
    try {
      await navigator.clipboard.writeText(adresse)
      copie = true
    } catch {
      copie = false
    }
    lienAffiche.value = { personId: p.personId, libelle: p.libelle, adresse, copie }
  } catch (e) {
    erreur.value = e
  } finally {
    enCours.value = null
  }
}

function copierLien(p: ParticipantDuTirage): void {
  if (suspendu.value || enCours.value !== null) return
  // Pas encore de lien : rien ne meurt, pas de confirmation.
  if (!p.lienActif) {
    void fabriquerLien(p)
    return
  }
  demander(confirmationDeRegeneration(p.libelle), () => fabriquerLien(p))
}

function revoquerLien(p: ParticipantDuTirage): void {
  demander(confirmationDeRevocation(p.libelle), async () => {
    enCours.value = `lien-${p.personId}`
    erreur.value = null
    try {
      await revoquerLienDuParticipant(id.value, p.personId)
      marquerLien(p.personId, false)
      if (lienAffiche.value?.personId === p.personId) lienAffiche.value = null
    } catch (e) {
      erreur.value = e
    } finally {
      enCours.value = null
    }
  })
}

/* Réglages */

const enReglage = ref(false)
const brouillon = ref<ReglesDuTirage | null>(null)
const erreurDeReglage = ref<unknown>(null)
const erreursDeChamps = computed<Record<string, string>>(() =>
  erreurDeReglage.value === null ? {} : analyserErreur(erreurDeReglage.value).champs,
)

function ouvrirLesReglages(): void {
  const t = tirage.value
  if (t === null) return
  brouillon.value = {
    nom: t.nom,
    jour: t.jour,
    mois: t.mois,
    lieu: t.lieu,
    ouvert: t.ouvert,
    memoire: t.memoire,
    exclureConjoints: t.exclureConjoints,
  }
  erreurDeReglage.value = null
  enReglage.value = true
}

async function enregistrerLesReglages(): Promise<void> {
  const regles = brouillon.value
  if (regles === null || enCours.value !== null) return
  enCours.value = 'regler'
  erreurDeReglage.value = null

  try {
    tirage.value = await reglerTirage(id.value, regles)
    enReglage.value = false
  } catch (e) {
    erreurDeReglage.value = e
  } finally {
    enCours.value = null
  }
}

const classeSelect = 'h-9 rounded-champ border border-ligne bg-fond px-2 text-sm text-texte'
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <SqueletteChargement v-if="tirage === null && erreurDeChargement === null" :lignes="5" />

    <MessageErreur
      v-else-if="tirage === null"
      :erreur="erreurDeChargement"
      titre="Ce tirage n’a pas pu être chargé"
      @action="charger"
    />

    <template v-else>
      <header class="mb-6">
        <h1 class="text-xl font-semibold text-texte">{{ tirage.nom }}</h1>
        <p class="mt-1 text-sm text-attenue">
          Chaque {{ libelleDuJour(tirage.jour, tirage.mois) }}<template v-if="tirage.lieu"> · {{ tirage.lieu }}</template>
          · organisé par {{ tirage.organisateur.estMoi ? 'vous' : tirage.organisateur.libelle }}
        </p>
        <p class="mt-1 text-xs text-attenue">
          {{ libelleDeMemoire(tirage.memoire) }}<template v-if="tirage.exclureConjoints"> · les couples ne se tirent pas</template>
          · {{ tirage.ouvert ? 'ouvert à la famille' : 'sur invitation de l’organisateur' }}
        </p>
      </header>

      <p v-if="motifSuspendu !== null" class="mb-4 rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
        {{ motifSuspendu }}
      </p>

      <MessageErreur v-if="erreur !== null" class="mb-4" :erreur="erreur" titre="L’opération n’a pas abouti" />

      <div v-if="tirage.peutRejoindre" class="mb-6 flex items-center gap-3 rounded-panneau border border-ligne bg-panneau p-4">
        <p class="flex-1 text-sm text-texte">Vous ne participez pas encore à ce tirage.</p>
        <BoutonBase :chargement="enCours === 'rejoindre'" :desactive="suspendu" @click="agir('rejoindre', () => rejoindreTirage(id))">
          Rejoindre
        </BoutonBase>
      </div>

      <!-- ───────── L'édition de l'année ───────── -->
      <PanneauBase v-if="edition !== null" class="mb-6">
        <template #entete>
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold tracking-tight">Édition {{ edition.annee }}</h2>
            <span class="text-xs text-attenue">{{ dateLongue(edition.date) }} · {{ edition.statutLibelle }}</span>
          </div>
        </template>

        <div class="flex flex-col gap-4">
          <template v-if="edition.statut === 'tiree'">
            <CarteAttribution
              v-for="a in edition.mesAttributions"
              :key="`${edition.annee}-${a.donneur.personId}`"
              :attribution="a"
              :mon-person-id="moi"
            />
            <p v-if="edition.mesAttributions.length === 0 && !jeSuisArriveApres" class="text-sm text-attenue">
              Le tirage est fait. Vous n’y participez pas cette année.
            </p>
            <p v-if="jeSuisArriveApres" class="text-sm text-attenue">
              Vous avez rejoint après le tirage : l’organisateur doit le refaire pour vous inclure.
            </p>
            <p v-if="memoireRelachee !== null && tirage.peutGerer" class="text-xs text-attenue">
              Groupe trop petit pour la règle choisie : le tirage n’a évité que {{ memoireRelachee }}
              année{{ memoireRelachee > 1 ? 's' : '' }} passée{{ memoireRelachee > 1 ? 's' : '' }}.
            </p>
          </template>
          <p v-else class="text-sm text-attenue">
            Le tirage n’a pas encore eu lieu : dites si vous venez.
          </p>

          <div>
            <h3 class="mb-2 text-sm font-medium text-texte">Qui vient</h3>
            <ul class="flex flex-col divide-y divide-ligne">
              <li v-for="p in edition.presences" :key="p.personId" class="flex items-center justify-between gap-3 py-2">
                <span class="text-sm text-texte">{{ p.libelle }}</span>
                <select
                  v-if="peutReglerLaPresence(p.personId) && !edition.passee"
                  :key="`${p.personId}-${versionDesPresences}`"
                  :class="classeSelect"
                  :value="p.presence"
                  :disabled="suspendu || enCours !== null"
                  :aria-label="`Présence de ${p.libelle}`"
                  @change="changerPresence(edition.annee, p.personId, p.libelle, ($event.target as HTMLSelectElement).value as PresenceAuTirage)"
                >
                  <option v-for="o in PRESENCES" :key="o.valeur" :value="o.valeur">{{ o.libelle }}</option>
                </select>
                <span v-else class="text-xs text-attenue">{{ p.presenceLibelle }}</span>
              </li>
            </ul>
          </div>

          <div v-if="tirage.peutGerer && !edition.passee" class="flex flex-wrap items-center gap-2 border-t border-ligne pt-4">
            <BoutonBase
              v-if="edition.statut === 'ouverte'"
              :chargement="enCours === 'tirer'"
              :desactive="suspendu || enCours !== null"
              @click="tirer(edition)"
            >
              Lancer le tirage {{ edition.annee }}
            </BoutonBase>
            <BoutonBase
              v-else
              variante="secondaire"
              :chargement="enCours === 'annuler'"
              :desactive="suspendu || enCours !== null"
              @click="annuler(edition.annee)"
            >
              Annuler le tirage
            </BoutonBase>
            <p class="w-full text-xs text-attenue">
              Comme tout le monde, vous ne voyez que votre propre tirage.
            </p>
          </div>
        </div>
      </PanneauBase>

      <PanneauBase v-else class="mb-6">
        <p class="text-sm text-attenue">Aucune édition n’est ouverte pour l’instant.</p>
      </PanneauBase>

      <div v-if="tirage.peutGerer && anneeAOuvrir !== null && (edition === null || edition.passee)" class="mb-6">
        <BoutonBase
          variante="secondaire"
          :chargement="enCours === 'ouvrir'"
          :desactive="suspendu || enCours !== null"
          @click="agir('ouvrir', () => ouvrirEdition(id, anneeAOuvrir as number))"
        >
          Ouvrir l’édition {{ anneeAOuvrir }}
        </BoutonBase>
      </div>

      <!-- ───────── Participants ───────── -->
      <PanneauBase :titre="`Participants (${tirage.participants.length})`" class="mb-6">
        <ul class="flex flex-col divide-y divide-ligne">
          <li v-for="p in tirage.participants" :key="p.personId" class="flex flex-col gap-2 py-2">
            <div class="flex items-center justify-between gap-3">
              <span class="text-sm text-texte">
                {{ p.libelle }}<template v-if="p.estMoi"> (vous)</template>
                <span v-if="p.referent" class="block text-xs text-attenue">accompagné·e par {{ p.referent.libelle }}</span>
              </span>
              <BoutonBase
                v-if="tirage.peutGerer || p.suivi"
                variante="discret"
                :chargement="enCours === `retirer-${p.personId}`"
                :desactive="suspendu || enCours !== null"
                @click="retirer(p)"
              >
                {{ p.estMoi ? 'Quitter' : 'Retirer' }}
              </BoutonBase>
            </div>

            <!-- Le lien personnel : sans compte, la personne suit son tirage par là. -->
            <ActionsDuLien
              v-if="peutGererLeLien(p)"
              :lien-actif="p.lienActif"
              :chargement="enCours === `lien-${p.personId}`"
              :desactive="suspendu || enCours !== null"
              @copier="copierLien(p)"
              @revoquer="revoquerLien(p)"
            />

            <LienDuParticipant
              v-if="lienAffiche !== null && lienAffiche.personId === p.personId"
              :libelle="lienAffiche.libelle"
              :adresse="lienAffiche.adresse"
              :deja-copie="lienAffiche.copie"
              @fermer="lienAffiche = null"
            />
          </li>
        </ul>

        <p v-if="gestionDeLiens" class="mt-3 text-xs text-attenue">
          Le lien personnel sert à quelqu’un sans compte. Il ne s’affiche qu’une fois : ne le partagez qu’avec cette personne.
        </p>
        <p v-if="tirage.peutGerer" class="mt-2 text-xs text-attenue">
          Avec un compte, pas besoin de lien : le tirage reste secret, même pour vous.
        </p>

        <div v-if="tirage.peutGerer" class="mt-4 flex flex-col gap-2 border-t border-ligne pt-4">
          <label class="flex flex-col gap-1 text-sm text-texte">
            Accompagné·e par (facultatif)
            <select
              :class="classeSelect"
              :value="referent ?? ''"
              @change="referent = ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value)"
            >
              <option value="">Personne (a son propre compte)</option>
              <option v-for="p in tirage.participants" :key="p.personId" :value="p.personId">{{ p.libelle }}</option>
            </select>
            <span class="text-xs text-attenue">Pour un enfant sans compte : cette personne lira son tirage.</span>
          </label>
          <SelecteurPersonne
            etiquette="Ajouter une personne de l’arbre"
            aide="Seules les personnes vivantes sont proposées."
            :avec-propositions="false"
            vivants-seulement
            :deja-choisis="dejaChoisis"
            @choisir="ajouter"
          />
        </div>
      </PanneauBase>

      <!-- ───────── Les années passées ───────── -->
      <PanneauBase v-if="anciennes.length > 0" titre="Les autres années" class="mb-6">
        <ul class="flex flex-col gap-2">
          <li v-for="e in anciennes" :key="e.annee" class="text-sm text-texte">
            <span class="font-medium">{{ e.annee }}</span>
            <span class="text-attenue"> · {{ e.statutLibelle }}</span>
            <template v-for="a in e.passee ? e.mesAttributions : []" :key="a.donneur.personId">
              · {{ a.donneur.personId === moi ? 'vous avez offert' : `${a.donneur.libelle} a offert` }} à {{ a.receveur.libelle }}
            </template>
          </li>
        </ul>
      </PanneauBase>

      <!-- ───────── Réglages ───────── -->
      <PanneauBase v-if="tirage.peutGerer" titre="Réglages">
        <form v-if="enReglage && brouillon !== null" class="flex flex-col gap-5" @submit.prevent="enregistrerLesReglages">
          <FormulaireReglages v-model="brouillon" :erreurs="erreursDeChamps" :memoire-conseillee="tirage.memoireConseillee" />
          <MessageErreur
            v-if="erreurDeReglage !== null && Object.keys(erreursDeChamps).length === 0"
            :erreur="erreurDeReglage"
            titre="Les réglages n’ont pas pu être enregistrés"
          />
          <div class="flex gap-2">
            <BoutonBase type="submit" :chargement="enCours === 'regler'" :desactive="suspendu">Enregistrer</BoutonBase>
            <BoutonBase variante="discret" @click="enReglage = false">Annuler</BoutonBase>
          </div>
        </form>
        <div v-else class="flex flex-wrap items-center gap-2">
          <BoutonBase variante="secondaire" :desactive="suspendu" @click="ouvrirLesReglages">Modifier les réglages</BoutonBase>
          <BoutonBase variante="discret" :chargement="enCours === 'supprimer'" :desactive="suspendu" @click="supprimer">
            Supprimer le tirage
          </BoutonBase>
        </div>
      </PanneauBase>
    </template>

    <DialogueConfirmation
      :demande="aConfirmer"
      :chargement="enCours !== null"
      @confirmer="surConfirmer"
      @annuler="surAnnuler"
    />
  </div>
</template>
