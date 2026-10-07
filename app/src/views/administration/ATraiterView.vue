<script setup lang="ts">
/**
 * « À traiter » — la file unique du mode administration (handoff « mode
 * Administration », § 4.1).
 *
 * Ce qui attend une décision, quelle qu'en soit la nature, dans **une** liste :
 * les demandes en attente (§ 6.7), les inscriptions à rattacher (ADR 0013), les
 * demandes RGPD sans décision (ADR 0016). Elles étaient trois onglets ; un
 * administrateur ouvrait les trois pour savoir s'il avait quelque chose à faire.
 *
 * **Mise en page.** Bureau : la liste à gauche, le détail de l'élément choisi à
 * droite — le premier est choisi d'office. Téléphone : la liste seule ;
 * toucher un élément ouvre son détail en plein écran, avec « ‹ À traiter » pour
 * revenir.
 *
 * **L'adresse porte l'état** : `?type=demande|rattachement|rgpd` filtre, `?sel=d:42`
 * ouvre un élément. Un lien profond (`administration.rgpd` redirige ici avec
 * `type=rgpd`) tombe donc sur la bonne file, et le bouton retour du téléphone
 * referme le détail.
 *
 * **Après une décision**, l'élément quitte la file, un toast le confirme
 * (« Demande n° 42 validée. ») et l'élément suivant s'ouvre — la logique de
 * « & suivant ». Un `409` « déjà traité » relit la file et le dit.
 *
 * **Sous une vue empruntée**, les rattachements et le RGPD ne se lisent pas
 * (`409`) : le bandeau le dit, et seules les demandes s'affichent — leurs
 * décisions éteintes, comme partout.
 *
 * Les demandes **traitées** ne sont pas ici : « Voir l'historique des
 * demandes », en bas de la liste, mène à la file complète.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  listerDemandesAdministration,
  listerDemandesRgpd,
  quitterVueComme,
  type Demande,
  type DemandeRgpdAdministration,
} from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import { definirResume } from '@/composables/entete'
import { publierCompteurs } from '@/composables/modeAdmin'
import { annoncer } from '@/composables/toast'
import { useEstMobile } from '@/composables/useTiroirs'
import { useRattachements } from '@/composables/useRattachements'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import LoupeMedia from '@/components/medias/LoupeMedia.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import CarteARattacher from '@/components/administration/CarteARattacher.vue'
import DetailDemandeATraiter from '@/components/administration/DetailDemandeATraiter.vue'
import DetailRgpdATraiter from '@/components/administration/DetailRgpdATraiter.vue'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from '@/components/administration/ecriture'
import { autresSurLaMemeFiche } from '@/components/administration/file'
import { annonceEnchainement, issueDepuisRequete, type IssueDecision } from '@/components/administration/decision'
import { dateDuVerrou } from '@/components/compte/partageNaissance'
import {
  PASTILLES_DE_TYPE,
  PUCES,
  compter,
  construireFile,
  dateCourte,
  filtreDepuisRequete,
  filtrer,
  nomDeclare,
  resumeATraiter,
  selectionDepuisRequete,
  sousTitreDe,
  suivantApres,
  titreDe,
  type ElementATraiter,
  type FiltreATraiter,
} from '@/components/administration/fileATraiter'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()
const estMobile = useEstMobile()

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

const demandes = ref<Demande[]>([])
/** Le total des demandes en attente, au-delà des 100 lues. */
const totalDemandes = ref(0)
const rgpd = ref<DemandeRgpdAdministration[]>([])
const rattachements = useRattachements(suspendu)
const { comptes } = rattachements

const chargement = ref(false)
/** Chargé au moins une fois : avant, rien n'est compté ni annoncé. */
const charge = ref(false)
const erreur = ref<unknown>(null)
const dejaTraitee = ref(false)
const loupe = ref<CibleLoupe | null>(null)

/* ───────────────── La file ───────────────── */

const filtre = computed(() => filtreDepuisRequete(route.query['type']))
const file = computed(() => construireFile(demandes.value, comptes.value, rgpd.value))
const comptesParType = computed(() => compter(file.value))
const affichee = computed(() => filtrer(file.value, filtre.value))
const autres = computed(() => autresSurLaMemeFiche(demandes.value))

/** L'élément ouvert : celui de l'adresse ; sur bureau, le premier à défaut. */
const selection = computed<ElementATraiter | null>(() => {
  const cle = selectionDepuisRequete(route.query['sel'])
  const demande = cle === null ? undefined : file.value.find((e) => e.cle === cle)
  if (demande !== undefined) return demande
  return estMobile.value ? null : (affichee.value[0] ?? null)
})

/** Le contexte d'« Ouvrir la demande complète » : la file par défaut, rien à ajouter. */
const requeteDeLaFile: Record<string, string> = {}

function avecRequete(changements: Record<string, string | undefined>): Record<string, string> {
  const requete: Record<string, string> = {}
  for (const [cle, valeur] of Object.entries({ ...route.query, ...changements })) {
    if (typeof valeur === 'string' && valeur !== '') requete[cle] = valeur
  }
  return requete
}

function choisirFiltre(valeur: FiltreATraiter): void {
  void routeur.replace({ query: avecRequete({ type: valeur === 'tout' ? undefined : valeur, sel: undefined }) })
}

/** Sur téléphone, ouvrir le détail est une page de plus : le retour la referme. */
function choisir(cle: string): void {
  const vers = { query: avecRequete({ sel: cle }) }
  void (estMobile.value ? routeur.push(vers) : routeur.replace(vers))
}

function fermerDetail(): void {
  const precedent = (globalThis.history?.state as { back?: unknown } | null)?.back
  if (typeof precedent === 'string') routeur.back()
  else void routeur.replace({ query: avecRequete({ sel: undefined }) })
}

/* ───────────────── Le chargement ───────────────── */

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  // Les trois lectures partent ensemble ; les rattachements portent leurs
  // propres erreurs (et leurs suggestions arrivent ensuite, une à une).
  const lectureDesRattachements = rattachements.charger()
  try {
    const [page, demandesRgpd] = await Promise.all([
      listerDemandesAdministration({ status: 'en_attente', parPage: 100 }),
      suspendu.value ? Promise.resolve({ elements: [] }) : listerDemandesRgpd(),
    ])
    demandes.value = page.elements
    totalDemandes.value = page.total
    rgpd.value = demandesRgpd.elements
  } catch (cause) {
    erreur.value = cause
  }
  await lectureDesRattachements
  chargement.value = false
  charge.value = true
}

/** La pastille d'« À traiter » et le résumé de l'en-tête suivent la file. */
watch(
  [charge, totalDemandes, () => comptesParType.value.rattachement, () => comptesParType.value.rgpd],
  () => {
    if (!charge.value) return
    const c = {
      demandes: totalDemandes.value,
      rattachements: comptesParType.value.rattachement,
      rgpd: comptesParType.value.rgpd,
    }
    publierCompteurs(c)
    definirResume(resumeATraiter(c.demandes + c.rattachements + c.rgpd))
  },
)

onMounted(() => {
  void charger()
  // Retour d'un « & suivant » qui a épuisé la file, depuis la demande complète.
  const precedente = Number(route.query['precedente'])
  const issue = issueDepuisRequete(route.query['issue'])
  if (Number.isInteger(precedente) && precedente > 0 && issue !== null) {
    annoncer(annonceEnchainement(precedente, issue, true))
    void routeur.replace({ query: avecRequete({ precedente: undefined, issue: undefined }) })
  }
})
onBeforeUnmount(() => rattachements.arreter())
// Entrer ou sortir de la vue empruntée change ce que l'écran a le droit de lire.
watch(suspendu, () => void charger())

/* ───────────────── Après une décision ───────────────── */

function retirer(element: ElementATraiter, annonce: string): void {
  const avant = affichee.value
  if (element.type === 'demande') {
    demandes.value = demandes.value.filter((d) => d.id !== element.demande.id)
    totalDemandes.value = Math.max(0, totalDemandes.value - 1)
  } else if (element.type === 'rattachement') {
    rattachements.retirer(element.compte.memberId)
  } else {
    rgpd.value = rgpd.value.filter((g) => g.id !== element.rgpd.id)
  }
  annoncer(annonce)
  const suivant = suivantApres(avant, element.cle, affichee.value)
  void routeur.replace({ query: avecRequete({ sel: suivant ?? undefined }) })
}

function demandeDecidee(element: ElementATraiter & { type: 'demande' }, issue: IssueDecision): void {
  const n = element.demande.id
  retirer(
    element,
    issue === 'refusee'
      ? `Demande n° ${n} refusée.`
      : issue === 'rejouee'
        ? `Demande n° ${n} validée sur la version actuelle.`
        : `Demande n° ${n} validée.`,
  )
}

function rgpdDecidee(element: ElementATraiter & { type: 'rgpd' }, decision: 'appliquee' | 'refusee'): void {
  retirer(element, decision === 'appliquee' ? 'Demande RGPD appliquée.' : 'Demande RGPD refusée.')
}

function rattachementTraite(element: ElementATraiter & { type: 'rattachement' }): void {
  retirer(element, `Inscription de ${nomDeclare(element.compte)} traitée.`)
}

/** Un autre administrateur est passé avant : la file est relue, et on le dit. */
async function relireApresConflit(): Promise<void> {
  dejaTraitee.value = true
  await charger()
}

async function quitter(): Promise<void> {
  erreur.value = null
  try {
    await quitterVueComme()
    await rafraichirSession()
  } catch (cause) {
    erreur.value = cause
  }
}

/* ───────────────── L'en-tête du détail ───────────────── */

function dateLongue(iso: string | null): string {
  if (iso === null) return ''
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

function metaDu(element: ElementATraiter): string {
  switch (element.type) {
    case 'demande':
      return `${element.demande.authorLabel}, ${dateLongue(element.demande.submittedAt ?? element.demande.createdAt)}`
    case 'rattachement':
      return `Inscrit le ${dateLongue(element.compte.inscritLe)}`
    case 'rgpd':
      return `${element.rgpd.userEmail} · le ${dateDuVerrou(element.rgpd.creeeLe)}`
  }
}

function sousTitreDuDetail(element: ElementATraiter): string {
  if (element.type === 'rgpd') return ''
  return sousTitreDe(element)
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      <p>{{ raisonSuspension }}</p>
      <p class="mt-1">Les inscriptions à rattacher et les demandes RGPD ne s’affichent pas sous une vue empruntée.</p>
      <div class="mt-2">
        <BoutonBase variante="secondaire" @click="quitter">Quitter la vue empruntée</BoutonBase>
      </div>
    </div>

    <p v-if="dejaTraitee" class="text-sm text-alerte" role="alert" data-deja-traitee>
      Cet élément a déjà été traité par un autre administrateur. La file est à jour.
    </p>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />
    <MessageErreur v-if="rattachements.erreur.value !== null" :erreur="rattachements.erreur.value" @action="charger" />

    <div class="flex items-start gap-5">
      <!-- ── La liste ─────────────────────────────────────────────────── -->
      <div v-if="!estMobile || selection === null" class="flex min-w-0 flex-[1_1_340px] flex-col gap-3">
        <div
          class="puces-defilantes flex gap-2 overflow-x-auto overflow-y-hidden py-0.5"
          role="group"
          aria-label="Type d’éléments affichés"
        >
          <button
            v-for="p in PUCES"
            :key="p.valeur"
            type="button"
            class="flex min-h-11 flex-none items-center gap-1.5 rounded-pastille border px-[13px] text-[13px] whitespace-nowrap md:min-h-9"
            :class="p.valeur === filtre ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
            :aria-pressed="p.valeur === filtre"
            :data-puce="p.valeur"
            @click="choisirFiltre(p.valeur)"
          >
            {{ p.libelle }}<span v-if="charge" class="font-medium text-attenue tnum">{{ comptesParType[p.valeur] }}</span>
          </button>
        </div>

        <SqueletteChargement v-if="chargement && !charge" :lignes="4" />

        <p
          v-else-if="charge && affichee.length === 0"
          class="rounded-panneau border border-ligne bg-panneau px-4 py-7 text-center text-attenue"
          role="status"
        >
          Rien n’attend de décision.
        </p>

        <ul
          v-if="affichee.length > 0"
          class="m-0 flex list-none flex-col gap-px overflow-hidden rounded-panneau border border-ligne bg-ligne p-0"
          aria-label="À traiter"
        >
          <li v-for="element in affichee" :key="element.cle">
            <button
              type="button"
              class="flex w-full flex-col gap-[3px] py-3 pr-3.5 pl-4 text-left"
              :class="
                !estMobile && selection?.cle === element.cle
                  ? 'bg-selection shadow-[inset_3px_0_0_var(--color-accent)]'
                  : 'bg-panneau hover:bg-survol'
              "
              :aria-current="!estMobile && selection?.cle === element.cle ? 'true' : undefined"
              :data-cle="element.cle"
              @click="choisir(element.cle)"
            >
              <span class="flex items-center gap-2">
                <PastilleEtat :etat="PASTILLES_DE_TYPE[element.type].ton" :libelle="PASTILLES_DE_TYPE[element.type].libelle" />
                <span class="flex-1" />
                <span class="text-xs whitespace-nowrap text-attenue">{{ dateCourte(element.date) }}</span>
              </span>
              <span class="font-semibold">{{ titreDe(element) }}</span>
              <span class="text-[13px] text-attenue [overflow-wrap:anywhere]">{{ sousTitreDe(element) }}</span>
            </button>
          </li>
        </ul>

        <RouterLink
          :to="{ name: 'administration.demandes', query: { etat: 'toutes' } }"
          class="self-start text-[13px] text-attenue underline hover:text-texte"
        >
          Voir l’historique des demandes
        </RouterLink>
      </div>

      <!-- ── Le détail ────────────────────────────────────────────────── -->
      <section
        v-if="selection !== null"
        class="flex min-w-0 flex-[1.7_1_420px] flex-col gap-3.5 rounded-panneau border border-ligne bg-panneau px-[18px] pt-[18px]"
        :aria-label="titreDe(selection)"
        :data-detail="selection.cle"
      >
        <button
          v-if="estMobile"
          type="button"
          class="-mt-2 -mb-1 min-h-11 self-start text-[15px] font-semibold text-accent"
          @click="fermerDetail"
        >
          ‹ À traiter
        </button>

        <div class="flex flex-col gap-1">
          <span class="flex flex-wrap items-center gap-2">
            <PastilleEtat :etat="PASTILLES_DE_TYPE[selection.type].ton" :libelle="PASTILLES_DE_TYPE[selection.type].libelle" />
            <span class="text-[12.5px] text-attenue [overflow-wrap:anywhere]">{{ metaDu(selection) }}</span>
          </span>
          <h3 class="text-lg font-bold tracking-[-.01em]">{{ titreDe(selection) }}</h3>
          <span v-if="sousTitreDuDetail(selection)" class="text-attenue">{{ sousTitreDuDetail(selection) }}</span>
        </div>

        <DetailDemandeATraiter
          v-if="selection.type === 'demande'"
          :demande="selection.demande"
          :autres="autres.get(selection.demande.id) ?? 0"
          :suspendu="suspendu"
          :infobulle="infobulle"
          :requete-de-la-file="requeteDeLaFile"
          @decidee="(issue) => demandeDecidee(selection as ElementATraiter & { type: 'demande' }, issue)"
          @recharger="relireApresConflit"
          @agrandir="loupe = $event"
        />

        <CarteARattacher
          v-else-if="selection.type === 'rattachement'"
          :key="selection.cle"
          integree
          :compte="selection.compte"
          :analyse="rattachements.analyseDe(selection.compte.memberId)"
          :transcription="rattachements.transcriptions.value[selection.compte.memberId]"
          :quota="rattachements.quota.value"
          :suspendu="suspendu"
          :infobulle="infobulle"
          @traite="rattachementTraite(selection as ElementATraiter & { type: 'rattachement' })"
          @lancer="rattachements.lancerLaRecherche"
          @transcrire="rattachements.transcrire"
          @corrigee="rattachements.transcriptionCorrigee"
        />

        <DetailRgpdATraiter
          v-else
          :rgpd="selection.rgpd"
          :suspendu="suspendu"
          :infobulle="infobulle"
          @decidee="(decision) => rgpdDecidee(selection as ElementATraiter & { type: 'rgpd' }, decision)"
          @deja-traitee="relireApresConflit"
        />
      </section>
    </div>
  </div>

  <LoupeMedia :cible="loupe" @fermer="loupe = null" />
</template>
