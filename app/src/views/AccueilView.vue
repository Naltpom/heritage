<script setup lang="ts">
/**
 * L'accueil — la page où tout lecteur connecté atterrit (`/home`).
 *
 * Refonte du 30/10/2026 (maquette Claude Design « 1a », et « 2a » pour le
 * téléphone) : **aujourd'hui d'abord**. Sur bureau, deux colonnes — la fête du
 * jour et les jours qui viennent à gauche, mes événements à droite ; sur
 * téléphone, une seule colonne, dans cet ordre : la fête du jour, mes
 * événements, les jours qui viennent. Plus de « Bonjour, … » : la date suffit.
 *
 * 1. **les accès rapides** — « Poser une question sur l'arbre » en barre sur
 *    toute la largeur, « Ouvrir l'arbre » et « Ajouter une personne » (en haut à
 *    droite sur bureau, sous la barre sur téléphone) ;
 * 2. **à fêter aujourd'hui** — une carte par fête des vivants, puis les
 *    défunts en une ligne en retrait, sous « En mémoire » ;
 * 3. **mes événements** (02/10/2026 : une seule section, « le tirage au sort
 *    est un événement en soi ») — **tous** ceux qui m'attendent, le plus
 *    proche d'abord : les rassemblements où je suis invité ou que j'organise,
 *    avec ma réponse ou les deux boutons Présent / Absent
 *    (`CarteEvenementAccueil`) — la carte vue, l'annonce l'est aussi —, et mes
 *    tirages de Noël (ceux du site noel2026, que j'ai créés ou auxquels j'ai
 *    répondu) : le compte à rebours, ma réponse, et une fois le tirage fait
 *    « Découvrir à qui j'offre », en violet (`CarteNoelAccueil`) ;
 * 4. **les jours qui viennent** — les sept jours suivants, une ligne par fête.
 *    Le calendrier complet reste dans Explorer.
 *
 * Les anniversaires viennent de `GET /api/anniversaires`, l'année entière en une
 * fois : glisser d'un jour à l'autre ne redemande rien. Les rassemblements
 * exigent un compte rattaché ; un compte en attente ne les demande donc pas.
 * Les tirages de Noël, non : `GET /api/noel/mes-tirages` répond à tout compte
 * connecté — c'est le public du site de Noël, où l'on s'inscrit sans attendre.
 *
 * **Aucun receveur n'est rendu sans geste**, même au lecteur : l'accueil
 * s'ouvre volontiers devant d'autres. Le nom n'entre dans le DOM qu'au clic.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import {
  chargerAnniversaires,
  listerEvenements,
  listerMesTiragesDeNoel,
  marquerVu,
  repondreAEvenement,
  type ReponseAInvitation,
  type ResumeDEvenement,
  type ResumeDuTirageDeNoel,
  type VueAnniversaires,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import CarteNoelAccueil from '@/components/accueil/CarteNoelAccueil.vue'
import CarteEvenementAccueil from '@/components/accueil/CarteEvenementAccueil.vue'
import { mesEvenements } from '@/components/evenements-familiaux/liste-commune'
import {
  anniversairesDuJour,
  decaler,
  detailCourt,
  detailDeLaFete,
  estDesVivants,
  initiales,
  jourEnToutesLettres,
  joursQuiViennent,
  libelleJourAVenir,
  natureDeLaFete,
  nomsDesSujets,
  teinte,
  titreDeLaFete,
} from '@/components/accueil/jours'

const { rattache, vueEmpruntee } = useSession()

const aujourdhui = decaler(new Date(), 0)
const annee = aujourdhui.getFullYear()

/* ───────────────── Chargement ───────────────── */

const anniversaires = shallowRef<VueAnniversaires | null>(null)
const erreurAnniversaires = ref<unknown>(null)
const tirages = shallowRef<ResumeDuTirageDeNoel[]>([])
const evenements = shallowRef<ResumeDEvenement[]>([])
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  const { signal } = avorteur
  erreurAnniversaires.value = null

  // En parallèle, et chacun pour soi : des tirages en panne ne retirent pas
  // les anniversaires, ni l'inverse — ni les événements.
  await Promise.all([
    chargerAnniversaires(signal).then(
      (vue) => (anniversaires.value = vue),
      (e: unknown) => (erreurAnniversaires.value = e),
    ),
    // Au mieux : des tirages de Noël en panne ne se signalent pas, la section
    // montre le reste — et le site de Noël reste là.
    listerMesTiragesDeNoel(signal).then(
      (liste) => (tirages.value = liste),
      () => (tirages.value = []),
    ),
    rattache.value
      ? listerEvenements(signal).then(
          (liste) => {
            evenements.value = liste.evenements
            signalerLaVue()
          },
          () => (evenements.value = []),
        )
      : Promise.resolve(),
  ])
}

onMounted(charger)
onBeforeUnmount(() => avorteur?.abort())

/* ───────────────── Mes événements ───────────────── */

/** Rassemblements et tirages de Noël mêlés, le plus proche d'abord (`mesEvenements`). */
const mesProchains = computed(() => mesEvenements(evenements.value, tirages.value, aujourdhui))

/** La réponse qui part, et pour quel rassemblement : une à la fois. */
const reponseEnCours = ref<{ id: number; reponse: ReponseAInvitation } | null>(null)
const erreurDeReponse = ref<{ id: number; erreur: unknown } | null>(null)

/**
 * Les cartes montrent les annonces : elles sont vues — chacune de celles où
 * l'on est invité. Au mieux : un échec ne se signale pas, l'annonce sera
 * marquée à l'ouverture de sa page. Rien sous la bascule « voir comme » :
 * l'administrateur ne voit pas à la place du membre.
 */
function signalerLaVue(): void {
  if (vueEmpruntee.value !== null) return
  for (const element of mesProchains.value) {
    if (element.type === 'rassemblement' && element.evenement.suisInvite) {
      void marquerVu(element.evenement.id).catch(() => undefined)
    }
  }
}

async function repondre(id: number, reponse: ReponseAInvitation): Promise<void> {
  if (reponseEnCours.value !== null) return
  reponseEnCours.value = { id, reponse }
  erreurDeReponse.value = null
  try {
    // La réponse est l'événement relu ; son résumé remplace celui de la liste.
    const relu = await repondreAEvenement(id, reponse)
    evenements.value = evenements.value.map((x) => (x.id === relu.id ? relu : x))
  } catch (erreur: unknown) {
    erreurDeReponse.value = { id, erreur }
  } finally {
    reponseEnCours.value = null
  }
}

/* ───────────────── Aujourd'hui, et les jours qui viennent ───────────────── */

const jours = computed(() => anniversaires.value?.jours ?? [])
const fetesDuJour = computed(() => anniversairesDuJour(jours.value, aujourdhui))
const fetesDesVivants = computed(() => fetesDuJour.value.filter(estDesVivants))
const fetesEnMemoire = computed(() => fetesDuJour.value.filter((f) => !estDesVivants(f)))

/** Au-delà, la liste deviendrait le calendrier : il a sa page. */
const LIGNES_A_VENIR = 8
const aVenir = computed(() => joursQuiViennent(jours.value, aujourdhui))

/** Les teintes des fiches de l'arbre, écrites en entier pour que Tailwind les voie. */
const CLASSES_DE_TEINTE = {
  homme: 'bg-homme border-homme-bord',
  femme: 'bg-femme border-femme-bord',
  inconnu: 'bg-inconnu border-inconnu-bord',
} as const
</script>

<template>
  <div class="mx-auto flex w-full max-w-7xl flex-col gap-5 px-4 pt-5 pb-6 lg:gap-8 lg:px-14 lg:pt-10 lg:pb-12">
    <h1 class="sr-only">Accueil</h1>

    <!-- ───────── La date ; sur bureau, les deux boutons à sa droite ───────── -->
    <div class="flex items-end justify-between gap-6">
      <p class="font-mono text-[11px] tracking-[.04em] text-attenue uppercase lg:text-xs" data-date>
        {{ jourEnToutesLettres(aujourdhui) }}<span class="ms-[1ch] hidden lg:inline">{{ annee }}</span>
      </p>
      <nav aria-label="Accès rapides" class="hidden gap-2.5 lg:flex">
        <RouterLink
          v-if="rattache && vueEmpruntee === null"
          :to="{ name: 'proposition.creation', query: { mode: 'formulaire' } }"
          class="flex h-10 items-center gap-2 rounded-champ border border-ligne px-4 text-[13px] font-semibold text-texte transition-colors hover:border-accent hover:text-accent"
        >
          <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="8" cy="6.5" r="3" /><path d="M2.5 17c0-3 2.5-5 5.5-5s5.5 2 5.5 5" /><path d="M16 5v6M13 8h6" />
          </svg>
          Ajouter une personne
        </RouterLink>
        <RouterLink
          :to="{ name: 'arbre' }"
          class="flex h-10 items-center gap-2 rounded-champ bg-accent px-[18px] text-[13px] font-semibold text-fond transition-opacity hover:opacity-90"
        >
          <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
            <circle cx="10" cy="4" r="2" /><circle cx="4" cy="15" r="2" /><circle cx="16" cy="15" r="2" />
            <path d="M10 6v4M10 10H4v3M10 10h6v3" />
          </svg>
          Ouvrir l’arbre
        </RouterLink>
      </nav>
    </div>

    <!-- ───────── La question à l'IA, puis (téléphone) les deux boutons ───────── -->
    <div class="flex flex-col gap-2">
      <RouterLink
        v-if="rattache && vueEmpruntee === null"
        :to="{ name: 'proposition.creation', query: { mode: 'question' } }"
        class="group flex h-12 items-center gap-2.5 rounded-panneau border border-ligne bg-panneau px-3.5 transition-colors hover:border-accent lg:h-[52px] lg:gap-3 lg:pr-2 lg:pl-4"
        data-question
      >
        <svg class="flex-none" width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="var(--color-accent)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 4h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H9l-4 3v-3H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" />
        </svg>
        <span class="min-w-0 flex-1 truncate text-sm text-attenue">
          Poser une question sur l’arbre…<span class="hidden lg:inline"> « Qui est né à Limoges avant 1900 ? »</span>
        </span>
        <span class="hidden h-9 items-center rounded-champ bg-accent-doux px-3.5 text-[13px] font-semibold text-accent lg:flex" aria-hidden="true">Demander</span>
      </RouterLink>

      <nav aria-label="Accès rapides" class="grid grid-cols-2 gap-2 lg:hidden">
        <RouterLink
          :to="{ name: 'arbre' }"
          class="flex h-11 items-center justify-center gap-2 rounded-champ bg-accent px-3 text-[13px] font-semibold text-fond transition-opacity hover:opacity-90"
        >
          Ouvrir l’arbre
        </RouterLink>
        <RouterLink
          v-if="rattache && vueEmpruntee === null"
          :to="{ name: 'proposition.creation', query: { mode: 'formulaire' } }"
          class="flex h-11 items-center justify-center gap-2 rounded-champ border border-ligne px-3 text-[13px] font-semibold text-texte transition-colors hover:border-accent hover:text-accent"
        >
          Ajouter une personne
        </RouterLink>
      </nav>

      <p v-if="!rattache" class="rounded-panneau border border-dashed border-ligne p-3 text-xs text-attenue">
        Vous pourrez ajouter des personnes une fois votre rattachement validé.
      </p>
    </div>

    <!-- ───────── Deux colonnes sur bureau, une sur téléphone ─────────
         Sur téléphone, la colonne de gauche s'efface (`contents`) : ses deux
         sections encadrent alors « Mes événements » grâce à `order`. -->
    <div class="flex flex-col gap-5 lg:grid lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:items-start lg:gap-8">
      <div class="contents lg:flex lg:flex-col lg:gap-7">
        <!-- ───────── À fêter aujourd'hui ───────── -->
        <section aria-labelledby="titre-aujourdhui" class="order-1 flex flex-col gap-2.5 lg:order-none lg:gap-3.5">
          <div class="flex items-baseline justify-between">
            <h2 id="titre-aujourdhui" class="text-sm font-semibold lg:text-[15px]">À fêter aujourd’hui</h2>
            <RouterLink v-if="rattache" :to="{ name: 'anniversaires' }" class="hidden text-xs text-accent hover:underline lg:inline">
              Calendrier complet
            </RouterLink>
          </div>

          <SqueletteChargement v-if="anniversaires === null && erreurAnniversaires === null" :lignes="3" />
          <MessageErreur
            v-else-if="anniversaires === null"
            :erreur="erreurAnniversaires"
            titre="Les anniversaires n’ont pas pu être chargés"
            @action="charger"
          />
          <template v-else>
            <article
              v-for="f in fetesDesVivants"
              :key="f.cle"
              class="flex items-center gap-3.5 rounded-panneau border border-ligne bg-panneau p-4 lg:gap-5 lg:p-6"
              data-fete
            >
              <span
                class="flex size-14 flex-none items-center justify-center rounded-full border-2 text-lg font-semibold text-texte lg:size-[72px] lg:text-[22px]"
                :class="CLASSES_DE_TEINTE[teinte(f)]"
                aria-hidden="true"
              >
                {{ initiales(f) }}
              </span>
              <div class="flex min-w-0 flex-1 flex-col lg:gap-0.5">
                <span class="text-[11px] font-semibold text-accent lg:text-xs">{{ natureDeLaFete(f) }}</span>
                <RouterLink
                  v-if="f.sujets[0]"
                  :to="{ name: 'personne.detail', params: { id: f.sujets[0].personId } }"
                  class="text-[17px] leading-snug font-semibold tracking-[-0.01em] text-texte hover:underline lg:text-xl"
                >
                  {{ titreDeLaFete(f, annee) }}
                </RouterLink>
                <span class="tnum text-xs text-attenue lg:text-[13px]">{{ detailDeLaFete(f, annee) }}</span>
              </div>
              <RouterLink
                v-if="f.sujets[0]"
                :to="{ name: 'personne.detail', params: { id: f.sujets[0].personId } }"
                class="hidden h-9 flex-none items-center rounded-champ border border-ligne px-3.5 text-[13px] font-semibold text-texte transition-colors hover:border-accent hover:text-accent lg:flex"
              >
                Voir la fiche
              </RouterLink>
            </article>

            <p v-if="fetesDesVivants.length === 0" class="rounded-panneau border border-dashed border-ligne p-4 text-sm text-attenue">
              Rien à fêter aujourd’hui.
            </p>

            <!-- Les défunts en retrait : lisibles, mais après les vivants et d'un ton plus bas. -->
            <ul v-if="fetesEnMemoire.length > 0" class="en-memoire flex flex-col gap-1 px-1 lg:px-6 lg:py-1" aria-label="En mémoire">
              <li v-for="(f, i) in fetesEnMemoire" :key="f.cle" class="flex items-center gap-2.5 text-[13px] lg:gap-3.5">
                <span
                  class="flex-none text-[10px] font-semibold tracking-[.06em] text-attenue uppercase lg:w-[92px] lg:text-[11px]"
                  :class="i > 0 ? 'invisible' : ''"
                  :aria-hidden="i > 0 ? 'true' : undefined"
                >En mémoire</span>
                <RouterLink
                  v-if="f.sujets[0]"
                  :to="{ name: 'personne.detail', params: { id: f.sujets[0].personId } }"
                  class="min-w-0 truncate text-texte hover:underline"
                >
                  {{ nomsDesSujets(f) }}
                </RouterLink>
                <span class="flex-none font-mono text-[11px] text-attenue lg:text-xs">{{ detailCourt(f, annee) }}</span>
              </li>
            </ul>
          </template>
        </section>

        <!-- ───────── Les jours qui viennent ───────── -->
        <section v-if="anniversaires !== null" aria-labelledby="titre-a-venir" class="order-3 flex flex-col lg:order-none">
          <h2 id="titre-a-venir" class="mb-1 text-sm font-semibold lg:mb-1.5 lg:text-[13px] lg:text-attenue">Les jours qui viennent</h2>
          <ul class="flex flex-col">
            <li
              v-for="j in aVenir.slice(0, LIGNES_A_VENIR)"
              :key="j.cle"
              class="grid min-h-11 grid-cols-[68px_minmax(0,1fr)_auto] items-center gap-2.5 border-t border-ligne lg:grid-cols-[92px_minmax(0,1fr)_auto] lg:gap-3.5 lg:py-3"
              data-a-venir
            >
              <span class="font-mono text-[11px] text-attenue lg:text-xs">{{ libelleJourAVenir(j.date, aujourdhui) }}</span>
              <span class="truncate text-[13px] lg:text-sm" :class="estDesVivants(j.evenement) ? '' : 'opacity-60'">
                <template v-if="!estDesVivants(j.evenement)">† </template>
                <RouterLink
                  v-if="j.evenement.sujets[0]"
                  :to="{ name: 'personne.detail', params: { id: j.evenement.sujets[0].personId } }"
                  class="text-texte hover:underline"
                >
                  {{ nomsDesSujets(j.evenement) }}
                </RouterLink>
              </span>
              <span class="text-xs text-attenue">{{ detailCourt(j.evenement, annee) }}</span>
            </li>
            <li v-if="aVenir.length === 0" class="border-t border-ligne py-3 text-[13px] text-attenue">
              Rien à fêter dans les sept prochains jours.
            </li>
            <li v-else-if="aVenir.length > LIGNES_A_VENIR && rattache" class="border-t border-ligne py-3 text-xs">
              <RouterLink :to="{ name: 'anniversaires' }" class="text-accent hover:underline">
                Et {{ aVenir.length - LIGNES_A_VENIR }} de plus · le calendrier complet
              </RouterLink>
            </li>
          </ul>
        </section>
      </div>

      <!-- ───────── Mes événements : rassemblements et Noëls, le plus proche d'abord ─────────
           Sans condition de rattachement : un compte en attente n'a pas de
           rassemblements, mais peut avoir ses tirages de Noël. -->
      <section aria-labelledby="titre-mes-evenements" class="order-2 flex flex-col gap-2.5 lg:order-none lg:gap-3.5">
        <div class="flex items-baseline justify-between">
          <h2 id="titre-mes-evenements" class="text-sm font-semibold lg:text-[15px]">Mes événements</h2>
          <RouterLink v-if="rattache" :to="{ name: 'evenements' }" class="text-xs text-accent hover:underline">
            Tous<span class="hidden lg:inline"> les événements</span>
          </RouterLink>
        </div>
        <template v-for="element in mesProchains" :key="element.cle">
          <CarteEvenementAccueil
            v-if="element.type === 'rassemblement'"
            :evenement="element.evenement"
            :aujourdhui="aujourdhui"
            :en-cours="reponseEnCours?.id === element.evenement.id ? reponseEnCours.reponse : null"
            :erreur="erreurDeReponse?.id === element.evenement.id ? erreurDeReponse.erreur : null"
            :desactive="vueEmpruntee !== null || (reponseEnCours !== null && reponseEnCours.id !== element.evenement.id)"
            @repondre="(r) => repondre(element.evenement.id, r)"
          />
          <CarteNoelAccueil v-else :tirage="element.tirage" :aujourdhui="aujourdhui" />
        </template>
        <p v-if="mesProchains.length === 0" class="rounded-panneau border border-dashed border-ligne p-4 text-sm text-attenue">
          Aucun événement à venir.
        </p>
      </section>
    </div>
  </div>
</template>

<style scoped>
/* Les défunts en retrait : lisibles, mais d'un ton plus bas que les vivants. */
.en-memoire {
  opacity: 0.7;
}
.en-memoire:hover,
.en-memoire:focus-within {
  opacity: 1;
}
</style>
