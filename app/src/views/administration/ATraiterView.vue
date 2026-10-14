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
 * referme le détail. `?auteur=`, `?tri=` et `?page=` aussi.
 *
 * **Les demandes sont paginées par l'API** (2026-10-14) : la file en lisait
 * cent, et il y en avait cent deux. Le tri par numéro (« N° croissant /
 * décroissant ») et le filtre « Demandé par » partent donc au serveur — triée
 * ou filtrée dans le navigateur, une page ne l'aurait été qu'en elle-même. Les
 * inscriptions et le RGPD, rares et lus en entier, se filtrent ici, et se
 * mêlent par date à la première page.
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
  listerAuteursDesDemandes,
  listerDemandesAdministration,
  listerDemandesRgpd,
  quitterVueComme,
  type AuteurDeLaFile,
  type Demande,
  type DemandeRgpdAdministration,
} from '@/lib/api'
import { rafraichirSession, useSession } from '@/composables/useSession'
import { definirResume } from '@/composables/entete'
import { compterATraiter, publierCompteurs } from '@/composables/modeAdmin'
import { annoncer } from '@/composables/toast'
import { useEstMobile } from '@/composables/useTiroirs'
import { useRattachements } from '@/composables/useRattachements'
import BoutonBase from '@/components/base/BoutonBase.vue'
import Feuillet from '@/components/base/Feuillet.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PaginationListe from '@/components/base/PaginationListe.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { nombreDePages, pageApresRetrait, pageDepuisRequete } from '@/components/base/pagination'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import LoupeMedia from '@/components/medias/LoupeMedia.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import CarteARattacher from '@/components/administration/CarteARattacher.vue'
import DetailDemandeATraiter from '@/components/administration/DetailDemandeATraiter.vue'
import DetailRgpdATraiter from '@/components/administration/DetailRgpdATraiter.vue'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from '@/components/administration/ecriture'
import { autresSurLaMemeFiche, requeteDeContexte, type SensDeTri } from '@/components/administration/file'
import { annonceEnchainement, issueDepuisRequete, type IssueDecision } from '@/components/administration/decision'
import { dateDuVerrou } from '@/components/compte/partageNaissance'
import {
  PASTILLES_DE_TYPE,
  PUCES,
  auteurDepuisRequete,
  auteursDeLaFile,
  compter,
  construireFile,
  dateCourte,
  demandesDe,
  filtreDepuisRequete,
  nomDeclare,
  resumeATraiter,
  selectionDepuisRequete,
  sousTitreDe,
  suivantApres,
  titreDe,
  triDepuisRequete,
  type ElementATraiter,
  type FiltreATraiter,
} from '@/components/administration/fileATraiter'

/** Les demandes lues par page : assez pour une séance, assez peu pour la lire d'un coup d'œil. */
const PAR_PAGE = 25

/** Les deux sens de tri, par numéro — les mêmes libellés que l'historique. */
const TRIS: { valeur: SensDeTri; libelle: string }[] = [
  { valeur: 'anciennes', libelle: 'N° croissant' },
  { valeur: 'recentes', libelle: 'N° décroissant' },
]

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()
const estMobile = useEstMobile()

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

/** La page de demandes affichée. */
const demandes = ref<Demande[]>([])
/** Le total des demandes en attente — de ce demandeur, s'il est choisi —, toutes pages confondues. */
const totalDemandes = ref(0)
const rgpd = ref<DemandeRgpdAdministration[]>([])
/** Ceux qui ont déposé une demande, pour le filtre « Demandé par ». */
const deposants = ref<readonly AuteurDeLaFile[]>([])
const rattachements = useRattachements(suspendu)
const { comptes } = rattachements

const chargement = ref(false)
/** Une page de demandes est en cours de lecture. */
const lecturePage = ref(false)
/** Chargé au moins une fois : avant, rien n'est compté ni annoncé. */
const charge = ref(false)
const erreur = ref<unknown>(null)
const dejaTraitee = ref(false)
const loupe = ref<CibleLoupe | null>(null)
/**
 * Après une décision sur la dernière demande de la page, la suivante vient de
 * la page d'après : le détail se tait le temps de la relire, plutôt que
 * d'ouvrir un instant la première de la liste.
 */
const enAttenteDuSuivant = ref(false)

/* ───────────────── La file ───────────────── */

const filtre = computed(() => filtreDepuisRequete(route.query['type']))
const auteur = computed(() => auteurDepuisRequete(route.query['auteur']))
const tri = computed(() => triDepuisRequete(route.query['tri']))
const page = computed(() => pageDepuisRequete(route.query['page']))

/** Le RGPD sans décision et les inscriptions, de ce demandeur s'il est choisi. */
const rgpdEnAttente = computed(() => demandesDe(rgpd.value.filter((g) => g.decision === null), auteur.value))
const comptesDuDemandeur = computed(() => demandesDe(comptes.value, auteur.value))

/** Tout ce que l'écran connaît — pour retrouver l'élément de l'adresse, même hors filtre. */
const file = computed(() => construireFile(demandes.value, comptes.value, rgpd.value, tri.value))
const comptesParType = computed(() =>
  compter({
    demande: totalDemandes.value,
    rattachement: comptesDuDemandeur.value.length,
    rgpd: rgpdEnAttente.value.length,
  }),
)
/**
 * La liste affichée. Sous « Tout », les inscriptions et le RGPD se mêlent à la
 * **première** page de demandes ; les pages suivantes n'ont que des demandes.
 */
const affichee = computed(() => {
  const premiere = page.value === 1
  switch (filtre.value) {
    case 'demande':
      return construireFile(demandes.value, [], [], tri.value)
    case 'rattachement':
      return construireFile([], comptesDuDemandeur.value, [], tri.value)
    case 'rgpd':
      return construireFile([], [], rgpdEnAttente.value, tri.value)
    default:
      return construireFile(
        demandes.value,
        premiere ? comptesDuDemandeur.value : [],
        premiere ? rgpdEnAttente.value : [],
        tri.value,
      )
  }
})
/** La pagination ne concerne que les demandes. */
const pagine = computed(() => filtre.value === 'tout' || filtre.value === 'demande')
const autres = computed(() => autresSurLaMemeFiche(demandes.value))
const auteurs = computed(() => auteursDeLaFile(deposants.value, comptes.value, rgpd.value))

/** L'élément ouvert : celui de l'adresse ; sur bureau, le premier à défaut. */
const selection = computed<ElementATraiter | null>(() => {
  if (enAttenteDuSuivant.value) return null
  const cle = selectionDepuisRequete(route.query['sel'])
  const demande = cle === null ? undefined : file.value.find((e) => e.cle === cle)
  if (demande !== undefined) return demande
  return estMobile.value ? null : (affichee.value[0] ?? null)
})

/**
 * Le contexte d'« Ouvrir la demande complète » : l'auteur et le tri choisis
 * ici, pour que « & suivant » y enchaîne dans le même ordre.
 */
const requeteDeLaFile = computed(() =>
  requeteDeContexte({ etat: 'en_attente', auteur: auteur.value, tri: tri.value, onglet: 'toutes', recherche: '' }),
)

function avecRequete(changements: Record<string, string | undefined>): Record<string, string> {
  const requete: Record<string, string> = {}
  for (const [cle, valeur] of Object.entries({ ...route.query, ...changements })) {
    if (typeof valeur === 'string' && valeur !== '') requete[cle] = valeur
  }
  return requete
}

function choisirFiltre(valeur: FiltreATraiter): void {
  void routeur.replace({
    query: avecRequete({ type: valeur === 'tout' ? undefined : valeur, sel: undefined, page: undefined }),
  })
}

/** Changer d'auteur ou de sens ramène à la première page : la page 3 d'une autre liste n'a pas de sens. */
function choisirAuteur(userId: number): void {
  void routeur.replace({
    query: avecRequete({ auteur: userId === 0 ? undefined : String(userId), sel: undefined, page: undefined }),
  })
}

function choisirTri(sens: SensDeTri): void {
  void routeur.replace({
    query: avecRequete({ tri: sens === 'anciennes' ? undefined : sens, sel: undefined, page: undefined }),
  })
}

function allerALaPage(n: number): void {
  void routeur.replace({ query: avecRequete({ page: n <= 1 ? undefined : String(n), sel: undefined }) })
}

/* ───────────────── Le feuillet « Trier et filtrer » ───────────────── */

const filtresOuverts = ref(false)
const libelleTri = computed(() => TRIS.find((t) => t.valeur === tri.value)?.libelle ?? 'N° croissant')
const libelleAuteur = computed(() =>
  auteur.value === 0 ? null : (auteurs.value.find((a) => a.userId === auteur.value)?.libelle ?? `Compte n° ${auteur.value}`),
)
/** Ce qui s'écarte du défaut : l'auteur, le tri. */
const filtresActifs = computed(() => (auteur.value === 0 ? 0 : 1) + (tri.value === 'anciennes' ? 0 : 1))

function reinitialiser(): void {
  void routeur.replace({ query: avecRequete({ auteur: undefined, tri: undefined, sel: undefined, page: undefined }) })
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

/** Le numéro de la dernière lecture de page : une réponse plus ancienne arrivée après est ignorée. */
let lectureCourante = 0

/** La page de demandes de l'adresse — avec son auteur et son sens. */
async function lireLesDemandes(): Promise<void> {
  const numero = ++lectureCourante
  lecturePage.value = true
  try {
    const lue = await listerDemandesAdministration({
      status: 'en_attente',
      auteur: auteur.value === 0 ? undefined : auteur.value,
      tri: tri.value,
      page: page.value,
      parPage: PAR_PAGE,
    })
    if (numero !== lectureCourante) return
    demandes.value = lue.elements
    totalDemandes.value = lue.total
    // Une page au-delà de la fin (`?page=9` gardé d'une file plus longue) : la dernière.
    const derniere = nombreDePages(lue.total, PAR_PAGE)
    if (page.value > derniere) allerALaPage(derniere)
  } finally {
    if (numero === lectureCourante) lecturePage.value = false
  }
}

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  // Les lectures partent ensemble ; les rattachements portent leurs propres
  // erreurs (et leurs suggestions arrivent ensuite, une à une).
  const lectureDesRattachements = rattachements.charger()
  try {
    const [, demandesRgpd] = await Promise.all([
      lireLesDemandes(),
      suspendu.value ? Promise.resolve({ elements: [] }) : listerDemandesRgpd(),
    ])
    rgpd.value = demandesRgpd.elements
  } catch (cause) {
    erreur.value = cause
  }
  await lectureDesRattachements
  chargement.value = false
  charge.value = true
}

/** Une autre page, un autre auteur, un autre sens : seules les demandes se relisent. */
async function changerDePage(): Promise<void> {
  erreur.value = null
  try {
    await lireLesDemandes()
  } catch (cause) {
    erreur.value = cause
  }
}

/**
 * Le filtre « Demandé par » se peuple une fois, au montage. Son échec ne
 * remonte pas : sans lui, le filtre ne propose que les inscrits et le RGPD, et
 * la file fonctionne entièrement — même motif que l'historique.
 */
async function chargerLesDeposants(): Promise<void> {
  try {
    deposants.value = (await listerAuteursDesDemandes()).elements
  } catch {
    deposants.value = []
  }
}

/**
 * La pastille d'« À traiter » et le résumé de l'en-tête suivent la file. Filtrée
 * par demandeur, la file ne dit plus combien attendent en tout : la pastille se
 * recompte alors à part.
 */
watch(
  [charge, lecturePage, totalDemandes, () => comptes.value.length, rgpd, auteur],
  () => {
    // Pendant la lecture d'une page, le total est encore celui de l'ancien filtre.
    if (!charge.value || lecturePage.value) return
    definirResume(resumeATraiter(comptesParType.value.tout))
    if (auteur.value !== 0) {
      void compterATraiter()
      return
    }
    publierCompteurs({
      demandes: totalDemandes.value,
      rattachements: comptes.value.length,
      rgpd: rgpd.value.filter((g) => g.decision === null).length,
    })
  },
)

// Pas de garde sur `charge` : une page hors limites corrigée pendant le premier
// chargement doit, elle aussi, être relue.
watch([auteur, tri, page], () => void changerDePage())

onMounted(() => {
  void charger()
  void chargerLesDeposants()
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

/**
 * L'élément quitte la file, et le suivant s'ouvre. Une demande décidée laisse
 * un trou dans la page : s'il en reste au-delà, la page est relue pour le
 * combler — et quand c'était la dernière de la page, la suivante est
 * justement celle qui remonte. Une page vidée recule d'une page.
 */
async function retirer(element: ElementATraiter, annonce: string): Promise<void> {
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

  let suivant = suivantApres(avant, element.cle, affichee.value)
  const position = avant.findIndex((e) => e.cle === element.cle)
  const plusLoin = suivant !== null && avant.findIndex((e) => e.cle === suivant) > position
  const resteAuDela = element.type === 'demande' && totalDemandes.value > (page.value - 1) * PAR_PAGE + demandes.value.length

  if (resteAuDela && !plusLoin) {
    enAttenteDuSuivant.value = true
    try {
      await lireLesDemandes()
      suivant = suivantApres(avant, element.cle, affichee.value)
    } catch {
      // La page n'a pas pu se relire : on garde ce qu'on a, la décision est partie.
    } finally {
      enAttenteDuSuivant.value = false
    }
  } else if (resteAuDela) {
    void lireLesDemandes().catch(() => undefined)
  }

  const nouvellePage = element.type === 'demande' ? pageApresRetrait(page.value, demandes.value.length) : page.value
  if (nouvellePage !== page.value) {
    allerALaPage(nouvellePage)
    return
  }
  void routeur.replace({ query: avecRequete({ sel: suivant ?? undefined }) })
}

function demandeDecidee(element: ElementATraiter & { type: 'demande' }, issue: IssueDecision): void {
  const n = element.demande.id
  void retirer(
    element,
    issue === 'refusee'
      ? `Demande n° ${n} refusée.`
      : issue === 'rejouee'
        ? `Demande n° ${n} validée sur la version actuelle.`
        : `Demande n° ${n} validée.`,
  )
}

function rgpdDecidee(element: ElementATraiter & { type: 'rgpd' }, decision: 'appliquee' | 'refusee'): void {
  void retirer(element, decision === 'appliquee' ? 'Demande RGPD appliquée.' : 'Demande RGPD refusée.')
}

function rattachementTraite(element: ElementATraiter & { type: 'rattachement' }): void {
  void retirer(element, `Inscription de ${nomDeclare(element.compte)} traitée.`)
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

        <!-- Le tri et le demandeur, derrière un seul bouton — comme l'historique. -->
        <button
          type="button"
          class="flex min-h-11 max-w-full min-w-0 items-center gap-2 self-start rounded-champ border bg-panneau px-3 text-[13.5px] md:min-h-9"
          :class="filtresActifs > 0 ? 'border-accent' : 'border-ligne'"
          aria-haspopup="dialog"
          data-ouvrir-filtres
          @click="filtresOuverts = true"
        >
          <span class="truncate">{{ libelleTri }}<template v-if="libelleAuteur !== null"> · {{ libelleAuteur }}</template></span>
          <span
            v-if="filtresActifs > 0"
            class="flex h-[18px] min-w-[18px] flex-none items-center justify-center rounded-pastille bg-accent px-[5px] text-[11px] font-bold text-fond"
            :aria-label="`${filtresActifs} filtre${filtresActifs > 1 ? 's' : ''} actif${filtresActifs > 1 ? 's' : ''}`"
          >{{ filtresActifs }}</span>
          <span class="text-attenue" aria-hidden="true">▾</span>
        </button>

        <SqueletteChargement v-if="chargement && !charge" :lignes="4" />

        <p
          v-else-if="charge && affichee.length === 0"
          class="rounded-panneau border border-ligne bg-panneau px-4 py-7 text-center text-attenue"
          role="status"
        >
          {{ auteur === 0 ? 'Rien n’attend de décision.' : 'Rien n’attend de décision pour ce demandeur.' }}
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
              <span class="font-semibold [overflow-wrap:anywhere]">{{ titreDe(element) }}</span>
              <span class="text-[13px] text-attenue [overflow-wrap:anywhere]">{{ sousTitreDe(element) }}</span>
            </button>
          </li>
        </ul>

        <PaginationListe
          v-if="pagine"
          :page="page"
          :par-page="PAR_PAGE"
          :total="totalDemandes"
          :chargement="lecturePage"
          @aller="allerALaPage"
        />

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
          <h3 class="text-lg font-bold tracking-[-.01em] [overflow-wrap:anywhere]">{{ titreDe(selection) }}</h3>
          <span v-if="sousTitreDuDetail(selection)" class="text-attenue [overflow-wrap:anywhere]">{{ sousTitreDuDetail(selection) }}</span>
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

  <Feuillet :ouvert="filtresOuverts" titre="Trier et filtrer" @fermer="filtresOuverts = false">
    <span class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Demandé par</span>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Demandeur des éléments affichés">
      <button
        v-for="a in [{ userId: 0, libelle: 'Tout le monde' }, ...auteurs]"
        :key="a.userId"
        type="button"
        class="flex min-h-11 max-w-full items-center rounded-pastille border px-[13px] text-left text-[13px] [overflow-wrap:anywhere] md:min-h-9"
        :class="a.userId === auteur ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
        :aria-pressed="a.userId === auteur"
        :data-auteur="a.userId"
        @click="choisirAuteur(a.userId)"
      >
        {{ a.libelle }}
      </button>
    </div>

    <span class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Trier</span>
    <Segmente :options="TRIS" :valeur="tri" etiquette="Ordre de la file" :colonnes="2" @choisir="choisirTri" />

    <div class="flex gap-2">
      <BoutonBase variante="secondaire" class="min-h-11 flex-1" @click="reinitialiser">Réinitialiser</BoutonBase>
      <BoutonBase class="min-h-11 flex-[2]" @click="filtresOuverts = false">
        Voir {{ comptesParType[filtre] }} élément{{ comptesParType[filtre] > 1 ? 's' : '' }}
      </BoutonBase>
    </div>
  </Feuillet>
</template>
