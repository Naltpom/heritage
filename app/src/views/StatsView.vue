<script lang="ts">
/**
 * La décision de cet écran qui se prouve sans navigateur — même forme, et même
 * raison, que `AnniversairesView` : un `<script setup>` ne s'importe pas.
 */
import type { Statistiques } from '@/lib/api'

export interface EtatDeLEcran {
  corps: 'squelette' | 'panne' | 'contenu'
  /** Un bandeau d'erreur au-dessus de chiffres déjà affichés, qui restent justes. */
  bandeau: boolean
}

export function etatDeLEcran(vue: Statistiques | null, erreur: unknown): EtatDeLEcran {
  const enPanne = erreur !== null && erreur !== undefined
  if (vue !== null) return { corps: 'contenu', bandeau: enPanne }
  return { corps: enPanne ? 'panne' : 'squelette', bandeau: false }
}

/** Les quatre listes du chantier, dans l'ordre de la page, avec leur libellé. */
export const LISTES_DU_CHANTIER = [
  { cle: 'sansDateDeNaissance', libelle: 'Sans date de naissance' },
  { cle: 'sansParents', libelle: 'Sans parents' },
  { cle: 'sansSource', libelle: 'Sans source' },
  { cle: 'nonVerifiees', libelle: 'Non vérifiées' },
] as const
</script>

<script setup lang="ts">
/**
 * Explorer › Stats — l'arbre en chiffres (décision du 02/10/2026).
 *
 * ────────────────────────────────────────────────────────────────────
 * CE QUI SE DÉCIDE ICI, ET NULLE PART AILLEURS :
 *
 * 1. **Rien ne se calcule ici.** Le serveur rend les chiffres déjà passés par
 *    le cercle de visibilité et par le seuil de discrétion (ADR 0015). Cet écran
 *    les met en page : un âge moyen recalculé ici supposerait d'avoir les dates
 *    de toutes les fiches, confidentielles comprises.
 *
 * 2. **Chaque graphique dit sur combien de personnes il porte** (« sur 412
 *    personnes »). Une moyenne sur trois fiches ne doit pas se lire comme une
 *    loi de l'histoire.
 *
 * 3. **Les règles de calcul sont dites à l'écran**, en une phrase repliable
 *    (« Comment ces chiffres sont calculés ») : les dates floues ne comptent
 *    pas, une année seule compte pour les âges, les vivants comptent.
 *
 * 4. **Le chantier est une seconde charge, et seulement pour l'administration.**
 *    Il n'est pas « masqué » pour un membre : il n'est jamais demandé — et le
 *    serveur le refuserait de toute façon (`/api/administration/…`).
 *
 * 5. **La portée : l'arbre du lecteur.** Les chiffres ne comptent que la
 *    famille reliée à sa fiche — pas les personnes sans lien connu. Seule
 *    l'administration a une bascule « Mon arbre / Toute la base », posée au-dessus
 *    des onglets d'Explorer (`usePorteeExplorer`) ; le serveur refuse `tout` à un
 *    membre de toute façon (il reçoit `arbre`).
 *
 * 6. **La mortalité des enfants est dite avec tact** : une phrase qui la situe
 *    avant le chiffre, la couleur des choses graves sans dramatisation.
 * ────────────────────────────────────────────────────────────────────
 *
 * La hauteur vient de `hauteurDuCadre()` (`views/cadre.ts`), comme les trois
 * autres onglets d'Explorer : l'écran défile dans son cadre, sous la rangée
 * d'onglets.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { hauteurDuCadre } from '@/views/cadre'
import { definirResume } from '@/composables/entete'
import { useSession } from '@/composables/useSession'
import { usePorteeExplorer } from '@/composables/usePorteeExplorer'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import Barres from '@/components/statistiques/Barres.vue'
import CarteRecord from '@/components/statistiques/CarteRecord.vue'
import Courbe from '@/components/statistiques/Courbe.vue'
import Palmares from '@/components/statistiques/Palmares.vue'
import {
  bornes,
  compte,
  coteDeBranche,
  couleurDeBranche,
  decimal,
  generationEtEpoque,
  nombre,
  nomDeBranche,
  part,
  pourcent,
  siecleCourt,
  siecleLong,
  surN,
} from '@/components/statistiques/format'
import {
  COULEUR_SEULE,
  demandesParMois,
  distanceParSiecle,
  douzeMois,
  estVide,
  hommesFemmesParSiecle,
  mortaliteParSiecle,
  parentsParSiecle,
} from '@/components/statistiques/series'
import {
  chargerChantier,
  chargerStatistiques,
  type Chantier,
  type CleDuChantier,
} from '@/lib/api'

const vue = shallowRef<Statistiques | null>(null)
const chantier = shallowRef<Chantier | null>(null)
const erreur = shallowRef<unknown>(null)
const chargement = ref(false)
const listeOuverte = ref<CleDuChantier | null>(null)
const { portee } = usePorteeExplorer()

const { administrateur } = useSession()

let abandon: AbortController | null = null

async function charger(): Promise<void> {
  abandon?.abort()
  const controleur = new AbortController()
  abandon = controleur
  chargement.value = true
  erreur.value = null

  try {
    const [stats, travaux] = await Promise.all([
      chargerStatistiques(portee.value, controleur.signal),
      // Décision 4 : jamais demandé pour un membre.
      administrateur.value ? chargerChantier(portee.value, controleur.signal) : Promise.resolve(null),
    ])
    if (controleur.signal.aborted) return
    vue.value = stats
    chantier.value = travaux
  } catch (cause) {
    if (!controleur.signal.aborted) erreur.value = cause
  } finally {
    if (!controleur.signal.aborted) chargement.value = false
  }
}

const etat = computed(() => etatDeLEcran(vue.value, erreur.value))

/* ------------------------------------------------------------------ */
/* Ce que la charge devient                                            */
/* ------------------------------------------------------------------ */

const arbre = computed(() => vue.value?.arbre ?? null)
const vie = computed(() => vue.value?.vie ?? null)

const periode = computed(() => {
  const p = arbre.value?.periode
  if (!p || p.debut === null) return '—'
  if (p.jusquAujourdhui) return `de ${p.debut} à aujourd’hui`
  return p.fin === null ? `depuis ${p.debut}` : `de ${p.debut} à ${p.fin}`
})

const ageAuDeces = computed(() => hommesFemmesParSiecle(vie.value?.ageAuDeces ?? []))
const premierMariage = computed(() => hommesFemmesParSiecle(vie.value?.premierMariage ?? []))
const ageDesParents = computed(() => parentsParSiecle(vie.value?.ageDesParents ?? []))
const moisNaissances = computed(() => douzeMois('Naissances', vie.value?.moisDesNaissances.mois ?? []))
const moisDeces = computed(() => douzeMois('Décès', vie.value?.moisDesDeces.mois ?? [], 'var(--h-attenue)'))
const mortalite = computed(() => mortaliteParSiecle(vie.value?.mortaliteInfantile ?? []))
const distance = computed(() => distanceParSiecle(vue.value?.lieux.distance ?? []))
const enfants = computed(() => {
  const lignes = vie.value?.enfantsParCouple ?? []
  return {
    lignes,
    n: lignes.reduce((s, l) => s + l.n, 0),
  }
})
const activite = computed(() => demandesParMois(chantier.value?.activite.demandesParMois ?? []))

// Arrondi à l'année sous la colonne : une décimale sur un petit échantillon serait une
// précision qu'il n'a pas, et elle ne tiendrait pas dans une colonne de téléphone.
const ansCourt = (v: number): string => String(Math.round(v))
const km = (v: number): string => `${nombre(Math.round(v))} km`

/** « née en 1929, en vie » ou « 1702 – 1805 ». */
const detailDoyen = computed(() => {
  const d = vue.value?.records.doyen
  if (!d) return ''
  if (!d.enCours) return bornes(d.debut, d.fin)
  return `${d.sexe === 'feminin' ? 'née' : 'né'} en ${d.debut ?? '?'}, en vie`
})

/** L'écart d'âge dans le couple, dit en clair. */
const ecart = computed(() => {
  const e = vie.value?.ecartDansLeCouple
  if (!e || e.moyenne === null) return null
  const sens = e.moyenne >= 0 ? 'le mari est l’aîné' : 'l’épouse est l’aînée'
  return { ...e, phrase: `En moyenne, ${sens} de ${decimal(Math.abs(e.moyenne))} an${Math.abs(e.moyenne) >= 2 ? 's' : ''}.` }
})

const resumeEntete = computed(() =>
  arbre.value === null ? '' : `${compte(arbre.value.personnes, 'personne')} · ${periode.value}`,
)
watch(resumeEntete, (texte) => definirResume(texte), { immediate: true })

watch(portee, () => void charger())

function basculerListe(cle: CleDuChantier): void {
  listeOuverte.value = listeOuverte.value === cle ? null : cle
}

/* ------------------------------------------------------------------ */
/* Cadre                                                               */
/* ------------------------------------------------------------------ */

const racine = ref<HTMLElement | null>(null)
const hauteurCadre = ref('70vh')

function mesurerHauteur(): void {
  const element = racine.value
  if (element === null) return
  hauteurCadre.value = hauteurDuCadre(element.getBoundingClientRect().top)
}

defineExpose({ remesurer: mesurerHauteur })

onMounted(() => {
  mesurerHauteur()
  window.addEventListener('resize', mesurerHauteur)
  void charger()
})

onBeforeUnmount(() => {
  abandon?.abort()
  window.removeEventListener('resize', mesurerHauteur)
})
</script>

<template>
  <div ref="racine" class="flex flex-col" :style="{ height: hauteurCadre }">
    <div v-if="etat.bandeau" class="border-b border-ligne px-4 py-2">
      <MessageErreur :erreur="erreur" titre="Les chiffres affichés restent justes, mais la mise à jour a échoué" @action="charger" />
    </div>

    <div class="relative min-h-0 flex-1 overflow-y-auto">
      <div v-if="etat.corps === 'squelette'" class="p-6">
        <SqueletteChargement :lignes="10" />
      </div>

      <div v-else-if="etat.corps === 'panne'" class="p-6">
        <MessageErreur :erreur="erreur" titre="Les statistiques n’ont pas pu être calculées" @action="charger" />
      </div>

      <div v-else-if="vue && arbre && vie" class="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <!-- ─── 1. L'arbre en chiffres ─────────────────────────────────── -->
        <section aria-labelledby="stats-arbre" class="flex flex-col gap-3">
          <h2 id="stats-arbre" class="m-0 text-lg font-semibold tracking-tight">L’arbre en chiffres</h2>

          <dl class="m-0 grid grid-cols-2 gap-2 md:grid-cols-4">
            <div class="rounded-panneau border border-ligne bg-panneau px-4 py-3">
              <dt class="text-xs text-attenue">Personnes</dt>
              <dd class="m-0 text-2xl font-semibold tabular-nums">{{ nombre(arbre.personnes) }}</dd>
            </div>
            <div class="rounded-panneau border border-ligne bg-panneau px-4 py-3">
              <dt class="text-xs text-attenue">Familles</dt>
              <dd class="m-0 text-2xl font-semibold tabular-nums">{{ nombre(arbre.familles) }}</dd>
            </div>
            <div class="rounded-panneau border border-ligne bg-panneau px-4 py-3">
              <dt class="text-xs text-attenue">Générations</dt>
              <dd class="m-0 text-2xl font-semibold tabular-nums">{{ nombre(arbre.generations) }}</dd>
            </div>
            <div class="rounded-panneau border border-ligne bg-panneau px-4 py-3">
              <dt class="text-xs text-attenue">Période couverte</dt>
              <dd class="m-0 text-base font-semibold leading-tight">{{ periode }}</dd>
            </div>
          </dl>

          <div class="grid gap-3 md:grid-cols-2">
            <PanneauBase titre="Part vérifiée">
              <p class="m-0 flex items-baseline gap-2">
                <span class="whitespace-nowrap text-3xl font-semibold tabular-nums text-ok">{{ pourcent(arbre.verification.fiches.verifiees, arbre.verification.fiches.total) }}</span>
                <span class="text-sm text-attenue">des fiches appuyées sur un acte ou un registre</span>
              </p>
              <p class="m-0 mt-1 text-xs text-attenue">
                {{ nombre(arbre.verification.fiches.verifiees) }} {{ surN(arbre.verification.fiches.total, 'fiche') }} — la même règle que « Vérifié » sur une fiche.
              </p>
              <ul class="m-0 mt-3 flex list-none flex-col gap-1 p-0 text-sm">
                <li v-for="[libelle, c] in ([['Naissances appuyées sur un acte', arbre.verification.naissances], ['Mariages appuyés sur un acte', arbre.verification.mariages], ['Décès appuyés sur un acte', arbre.verification.deces]] as const)" :key="libelle" class="flex justify-between gap-2">
                  <span>{{ libelle }}</span>
                  <span class="flex-none whitespace-nowrap font-mono text-xs tabular-nums text-attenue">{{ pourcent(c.verifiees, c.total) }} · {{ nombre(c.verifiees) }}/{{ nombre(c.total) }}</span>
                </li>
              </ul>
              <div v-if="arbre.verification.historique.length > 1" class="mt-4">
                <h3 class="m-0 mb-1 text-xs font-medium text-attenue">Évolution, mois par mois</h3>
                <Courbe :points="arbre.verification.historique" />
                <p class="m-0 mt-1 text-[11px] text-attenue">
                  Reconstruite d’après la date de saisie des citations : ce qui vient de l’import porte la date de l’import, et une source supprimée disparaît aussi du passé.
                </p>
              </div>
            </PanneauBase>

            <PanneauBase titre="Par branche">
              <p class="m-0 mb-2 text-xs text-attenue">Les branches lues depuis la racine de l’arbre ; la part claire est vérifiée.</p>
              <ul class="m-0 flex list-none flex-col gap-2 p-0">
                <li v-for="b in arbre.branches" :key="b.branche" class="text-sm">
                  <div class="flex items-baseline justify-between gap-2">
                    <span class="flex items-center gap-1.5 truncate">
                      <span class="inline-block h-2.5 w-2.5 flex-none rounded-full" :style="{ background: couleurDeBranche(b.branche) }" aria-hidden="true" />
                      <span class="font-medium">{{ nomDeBranche(b.branche, b.nom) }}</span>
                      <span v-if="b.nom" class="truncate text-xs text-attenue">{{ coteDeBranche(b.branche) }}</span>
                    </span>
                    <span class="flex-none font-mono text-xs tabular-nums text-attenue">{{ nombre(b.personnes) }} · {{ pourcent(b.verifiees, b.personnes) }} vérif.</span>
                  </div>
                  <div class="mt-1 h-2 overflow-hidden rounded-full bg-ligne/40" aria-hidden="true">
                    <div class="h-full rounded-full" :style="{ width: `${100 * part(b.personnes, arbre.personnes)}%`, background: couleurDeBranche(b.branche), opacity: 0.45 }">
                      <div class="h-full rounded-full" :style="{ width: `${100 * part(b.verifiees, b.personnes)}%`, background: couleurDeBranche(b.branche) }" />
                    </div>
                  </div>
                </li>
              </ul>
            </PanneauBase>
          </div>
        </section>

        <!-- ─── 2. La vie d'avant ──────────────────────────────────────── -->
        <section aria-labelledby="stats-vie" class="flex flex-col gap-3">
          <h2 id="stats-vie" class="m-0 text-lg font-semibold tracking-tight">La vie d’avant</h2>

          <div class="grid gap-3 md:grid-cols-2">
            <PanneauBase titre="Âge au décès, par siècle de naissance">
              <p v-if="estVide(ageAuDeces)" class="m-0 text-sm text-attenue">Pas encore assez de dates sûres.</p>
              <template v-else>
                <Barres titre="Âge moyen au décès des hommes et des femmes, par siècle de naissance" :categories="ageAuDeces.categories" :series="ageAuDeces.series" :format="ansCourt" />
                <p class="m-0 mt-2 text-xs text-attenue">
                  Moyenne en années, {{ surN(ageAuDeces.n, 'personne') }}. Les enfants morts jeunes y comptent : c’est ce qui fait l’espérance de vie d’autrefois.
                </p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Âge au premier mariage">
              <p v-if="estVide(premierMariage)" class="m-0 text-sm text-attenue">Pas encore assez de dates sûres.</p>
              <template v-else>
                <Barres titre="Âge moyen au premier mariage des hommes et des femmes, par siècle de naissance" :categories="premierMariage.categories" :series="premierMariage.series" :format="ansCourt" />
                <p class="m-0 mt-2 text-xs text-attenue">Moyenne en années, {{ surN(premierMariage.n, 'personne') }}.</p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Enfants par couple">
              <p v-if="enfants.lignes.length === 0" class="m-0 text-sm text-attenue">Aucun couple avec enfants connus.</p>
              <template v-else>
                <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
                  <li v-for="l in enfants.lignes" :key="l.generation" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 text-sm">
                    <span class="truncate">{{ generationEtEpoque(l.generation, l.annee) }}</span>
                    <span class="font-mono text-xs tabular-nums">{{ decimal(l.moyenne) }} <span class="text-attenue">({{ l.n }})</span></span>
                  </li>
                </ul>
                <p class="m-0 mt-2 text-xs text-attenue">
                  {{ surN(enfants.n, 'couple') }} ayant au moins un enfant connu — un couple sans enfant inscrit est le plus souvent une branche pas encore descendue.
                </p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Âge des parents à la naissance">
              <p v-if="estVide(ageDesParents)" class="m-0 text-sm text-attenue">Pas encore assez de dates sûres.</p>
              <template v-else>
                <Barres titre="Âge moyen des pères et des mères à la naissance d’un enfant, par siècle" :categories="ageDesParents.categories" :series="ageDesParents.series" :format="ansCourt" />
                <p class="m-0 mt-2 text-xs text-attenue">Moyenne en années, {{ surN(ageDesParents.n, 'naissance') }} (une par parent connu).</p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Écart d’âge dans le couple">
              <p v-if="ecart === null" class="m-0 text-sm text-attenue">Pas encore assez de dates sûres.</p>
              <template v-else>
                <p class="m-0 text-sm">{{ ecart.phrase }}</p>
                <div class="mt-3 flex h-3 overflow-hidden rounded-full" aria-hidden="true">
                  <span :style="{ flex: ecart.mariPlusAge, background: 'var(--h-homme-bord)' }" />
                  <span :style="{ flex: ecart.memeAnnee, background: 'var(--h-ligne)' }" />
                  <span :style="{ flex: ecart.epousePlusAgee, background: 'var(--h-femme-bord)' }" />
                </div>
                <p class="m-0 mt-2 text-xs text-attenue">
                  Mari plus âgé : {{ nombre(ecart.mariPlusAge) }} · même année : {{ nombre(ecart.memeAnnee) }} · épouse plus âgée : {{ nombre(ecart.epousePlusAgee) }} — {{ surN(ecart.n, 'couple') }}.
                </p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Les mois des naissances et des décès">
              <p v-if="vie.moisDesNaissances.n + vie.moisDesDeces.n === 0" class="m-0 text-sm text-attenue">Aucune date au mois près.</p>
              <template v-else>
                <h3 class="m-0 mb-1 text-xs font-medium text-attenue">Naissances</h3>
                <Barres titre="Naissances par mois de l’année" :categories="moisNaissances.categories" :series="moisNaissances.series" :format="nombre" :hauteur-px="80" />
                <h3 class="m-0 mt-3 mb-1 text-xs font-medium text-attenue">Décès</h3>
                <Barres titre="Décès par mois de l’année" :categories="moisDeces.categories" :series="moisDeces.series" :format="nombre" :hauteur-px="80" />
                <p class="m-0 mt-2 text-xs text-attenue">
                  {{ surN(vie.moisDesNaissances.n, 'naissance') }} et {{ surN(vie.moisDesDeces.n, 'décès', 'décès') }} datés au mois près — une année seule ne dit pas le mois.
                </p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Les enfants partis tôt" class="md:col-span-2">
              <p class="m-0 text-sm">
                Autrefois, beaucoup d’enfants ne passaient pas leurs premières années. Ils ont leur place dans l’arbre comme leurs frères et sœurs, et ce chiffre leur fait une place dans l’histoire de la famille.
              </p>
              <p v-if="estVide(mortalite)" class="m-0 mt-2 text-sm text-attenue">Pas encore assez de dates sûres.</p>
              <template v-else>
                <div class="mt-3">
                  <Barres titre="Part des enfants morts avant cinq ans, par siècle de naissance" :categories="mortalite.categories" :series="mortalite.series" :format="(v: number) => `${v} %`" :hauteur-px="100" />
                </div>
                <p class="m-0 mt-2 text-xs text-attenue">
                  Part des personnes mortes avant cinq ans, {{ surN(mortalite.n, 'personne') }} dont on connaît la naissance et le décès.
                </p>
              </template>
            </PanneauBase>
          </div>
        </section>

        <!-- ─── 3. Records, prénoms et noms ────────────────────────────── -->
        <section aria-labelledby="stats-records" class="flex flex-col gap-3">
          <h2 id="stats-records" class="m-0 text-lg font-semibold tracking-tight">Records</h2>

          <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
            <CarteRecord
              v-if="vue.records.doyen"
              :titre="vue.records.doyen.sexe === 'feminin' ? 'Doyenne' : 'Doyen'"
              :valeur="`${vue.records.doyen.valeur} ans`"
              :detail="detailDoyen"
              :personnes="vue.records.doyen.personnes"
            />
            <CarteRecord
              v-if="vue.records.fratrie"
              titre="Plus grande fratrie"
              :valeur="compte(vue.records.fratrie.valeur, 'enfant')"
              :personnes="vue.records.fratrie.personnes"
            />
            <CarteRecord
              v-if="vue.records.union"
              titre="Plus longue union"
              :valeur="`${vue.records.union.valeur} ans`"
              :detail="bornes(vue.records.union.debut, vue.records.union.fin, vue.records.union.enCours)"
              :personnes="vue.records.union.personnes"
            />
            <CarteRecord
              v-if="vue.records.ecartParentEnfant"
              titre="Parent le plus âgé à une naissance"
              :valeur="`${vue.records.ecartParentEnfant.valeur} ans`"
              :detail="`entre ${vue.records.ecartParentEnfant.debut} et ${vue.records.ecartParentEnfant.fin}`"
              :personnes="vue.records.ecartParentEnfant.personnes"
              liaison="→"
            />
          </div>

          <PanneauBase v-if="vue.records.ancetres.length > 0" titre="Le plus vieil ancêtre connu de chaque branche">
            <ul class="m-0 grid list-none gap-2 p-0 sm:grid-cols-2">
              <li v-for="a in vue.records.ancetres" :key="a.branche" class="flex items-baseline gap-2 text-sm">
                <span class="inline-block h-2.5 w-2.5 flex-none rounded-full" :style="{ background: couleurDeBranche(a.branche) }" aria-hidden="true" />
                <span class="flex-none font-mono text-xs tabular-nums text-attenue">{{ a.valeur }}</span>
                <RouterLink
                  v-for="p in a.personnes"
                  :key="p.id"
                  :to="{ name: 'personne.detail', params: { id: p.id } }"
                  class="truncate font-medium text-accent underline-offset-2 hover:underline"
                >{{ p.nom }}</RouterLink>
                <span class="truncate text-xs text-attenue">{{ nomDeBranche(a.branche, a.nom) }}</span>
              </li>
            </ul>
          </PanneauBase>

          <p class="m-0 text-xs text-attenue">
            Les records portent sur les fiches que vous pouvez ouvrir : quand le titulaire d’un record vous est caché, c’est le suivant qui s’affiche.
          </p>

          <div class="grid gap-3 md:grid-cols-2">
            <PanneauBase titre="Les prénoms les plus donnés">
              <p v-if="vue.prenomsEtNoms.prenomsParSiecle.length === 0" class="m-0 text-sm text-attenue">Pas encore assez de naissances datées.</p>
              <dl v-else class="m-0 flex flex-col gap-3">
                <div v-for="s in vue.prenomsEtNoms.prenomsParSiecle" :key="s.siecle">
                  <dt class="text-xs font-medium text-attenue">{{ siecleLong(s.siecle) }} · {{ surN(s.n, 'personne') }}</dt>
                  <dd class="m-0 mt-0.5 text-sm">
                    <span v-if="s.hommes.length"><span class="text-attenue">♂</span> {{ s.hommes.map((p) => `${p.libelle} (${p.n})`).join(', ') }}</span>
                    <br v-if="s.hommes.length && s.femmes.length" />
                    <span v-if="s.femmes.length"><span class="text-attenue">♀</span> {{ s.femmes.map((p) => `${p.libelle} (${p.n})`).join(', ') }}</span>
                  </dd>
                </div>
              </dl>
              <p class="m-0 mt-2 text-xs text-attenue">Le premier prénom inscrit, celui d’usage le plus souvent.</p>
            </PanneauBase>

            <PanneauBase titre="Prénoms transmis">
              <p v-if="vue.prenomsEtNoms.prenomsTransmis.length === 0" class="m-0 text-sm text-attenue">Aucun prénom porté par trois générations de suite.</p>
              <ul v-else class="m-0 flex list-none flex-col gap-1.5 p-0 text-sm">
                <li v-for="t in vue.prenomsEtNoms.prenomsTransmis" :key="t.prenom">
                  <span class="font-semibold">{{ t.prenom }}</span>
                  <span class="text-attenue">{{ ` — ${t.generations} générations de suite, depuis ` }}</span>
                  <RouterLink :to="{ name: 'personne.detail', params: { id: t.depuis.id } }" class="text-accent underline-offset-2 hover:underline">{{ t.depuis.nom }}</RouterLink>
                  <span v-if="t.annee" class="text-attenue">{{ ` (${t.annee})` }}</span>
                </li>
              </ul>
              <p class="m-0 mt-2 text-xs text-attenue">Un parent et son enfant qui portent le même premier prénom, puis cet enfant et le sien…</p>
            </PanneauBase>

            <PanneauBase titre="Les noms de famille" class="md:col-span-2">
              <p class="m-0 mb-2 text-sm">
                <span class="font-semibold">{{ nombre(vue.prenomsEtNoms.noms.patronymes) }}</span> patronymes dans l’arbre. Les plus fréquents :
              </p>
              <Palmares :lignes="vue.prenomsEtNoms.noms.frequents" />
              <p class="m-0 mt-2 text-xs text-attenue">{{ surN(vue.prenomsEtNoms.noms.n, 'personne') }}.</p>
            </PanneauBase>
          </div>
        </section>

        <!-- ─── 4. Lieux et métiers ────────────────────────────────────── -->
        <section aria-labelledby="stats-lieux" class="flex flex-col gap-3">
          <h2 id="stats-lieux" class="m-0 text-lg font-semibold tracking-tight">Lieux et métiers</h2>

          <div class="grid gap-3 md:grid-cols-2">
            <PanneauBase titre="Communes de naissance">
              <p v-if="vue.lieux.communes.palmares.length === 0" class="m-0 text-sm text-attenue">Aucun lieu de naissance reconnu.</p>
              <template v-else>
                <Palmares :lignes="vue.lieux.communes.palmares" />
                <p class="m-0 mt-2 text-xs text-attenue">{{ nombre(vue.lieux.communes.distinctes) }} communes, {{ surN(vue.lieux.communes.n, 'naissance') }} situées.</p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Départements de naissance">
              <p v-if="vue.lieux.departements.palmares.length === 0" class="m-0 text-sm text-attenue">Aucun département reconnu.</p>
              <template v-else>
                <Palmares :lignes="vue.lieux.departements.palmares" />
                <p class="m-0 mt-2 text-xs text-attenue">{{ surN(vue.lieux.departements.n, 'naissance') }} en France.</p>
              </template>
            </PanneauBase>

            <PanneauBase titre="De la naissance au décès">
              <p v-if="estVide(distance)" class="m-0 text-sm text-attenue">Pas encore assez de lieux situés aux deux bouts d’une vie.</p>
              <template v-else>
                <Barres titre="Distance médiane entre le lieu de naissance et le lieu de décès, par siècle de naissance" :categories="distance.categories" :series="distance.series" :format="km" />
                <ul class="m-0 mt-2 flex list-none flex-col gap-0.5 p-0 text-xs text-attenue">
                  <li v-for="d in vue.lieux.distance" :key="d.siecle">
                    {{ siecleCourt(d.siecle) }} : médiane {{ d.mediane === null ? '—' : km(d.mediane) }}, moyenne {{ d.moyenne === null ? '—' : km(d.moyenne) }} ({{ d.n }})
                  </li>
                </ul>
                <p class="m-0 mt-1 text-xs text-attenue">À vol d’oiseau, {{ surN(distance.n, 'personne') }}. La médiane dit le cas courant ; un seul grand départ suffit à tirer la moyenne.</p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Ailleurs qu’en France">
              <p v-if="vue.lieux.migrations.pays.length === 0" class="m-0 text-sm text-attenue">Aucun événement situé hors de France.</p>
              <template v-else>
                <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-sm">
                  <li v-for="p in vue.lieux.migrations.pays" :key="p.code">
                    <span class="font-medium">{{ p.nom }}</span>
                    <span class="text-attenue">{{ ` — ${compte(p.personnes, 'personne')}` }}</span>
                    <span v-if="p.regions.length" class="block text-xs text-attenue">{{ p.regions.map((r) => r.libelle).join(', ') }}</span>
                  </li>
                </ul>
                <p class="m-0 mt-2 text-xs text-attenue">
                  {{ compte(vue.lieux.migrations.neesHorsDeFrance, 'personne née', 'personnes nées') }} hors de France ; naissance, mariage, décès ou résidence, {{ surN(vue.lieux.migrations.n, 'personne') }} dont un lieu est situé.
                </p>
              </template>
            </PanneauBase>

            <PanneauBase titre="Les métiers, siècle par siècle" class="md:col-span-2">
              <p v-if="vue.lieux.metiers.length === 0" class="m-0 text-sm text-attenue">Aucune profession relevée.</p>
              <div v-else class="grid gap-4 sm:grid-cols-2">
                <div v-for="m in vue.lieux.metiers" :key="m.siecle">
                  <h3 class="m-0 mb-1.5 text-xs font-medium text-attenue">{{ siecleLong(m.siecle) }} · {{ surN(m.n, 'mention') }}</h3>
                  <Palmares :lignes="m.metiers" :couleur="COULEUR_SEULE" />
                </div>
              </div>
              <p class="m-0 mt-2 text-xs text-attenue">Le siècle de la mention quand elle est datée, sinon celui de la naissance.</p>
            </PanneauBase>
          </div>
        </section>

        <!-- ─── 5. Le chantier (administration) ────────────────────────── -->
        <section v-if="chantier" aria-labelledby="stats-chantier" class="flex flex-col gap-3">
          <h2 id="stats-chantier" class="m-0 text-lg font-semibold tracking-tight">Le chantier <span class="text-xs font-normal text-attenue">· administration</span></h2>

          <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
            <button
              v-for="l in LISTES_DU_CHANTIER"
              :key="l.cle"
              type="button"
              class="rounded-panneau border bg-panneau px-4 py-3 text-left"
              :class="listeOuverte === l.cle ? 'border-accent' : 'border-ligne'"
              :aria-expanded="listeOuverte === l.cle"
              :aria-controls="`chantier-${l.cle}`"
              @click="basculerListe(l.cle)"
            >
              <span class="block text-xs text-attenue">{{ l.libelle }}</span>
              <span class="block text-2xl font-semibold tabular-nums">{{ nombre(chantier.fiches[l.cle].n) }}</span>
            </button>
          </div>

          <PanneauBase v-if="listeOuverte" :id="`chantier-${listeOuverte}`" :titre="LISTES_DU_CHANTIER.find((l) => l.cle === listeOuverte)?.libelle ?? ''">
            <p v-if="chantier.fiches[listeOuverte].personnes.length === 0" class="m-0 text-sm text-attenue">Aucune fiche.</p>
            <ul v-else class="m-0 grid max-h-80 list-none gap-x-4 gap-y-1 overflow-y-auto p-0 text-sm sm:grid-cols-2">
              <li v-for="p in chantier.fiches[listeOuverte].personnes" :key="p.id">
                <RouterLink :to="{ name: 'personne.detail', params: { id: p.id } }" class="text-accent underline-offset-2 hover:underline">{{ p.nom }}</RouterLink>
                <span v-if="p.naissance" class="text-xs text-attenue">{{ ` (${p.naissance})` }}</span>
              </li>
            </ul>
          </PanneauBase>

          <div class="grid gap-3 md:grid-cols-2">
            <PanneauBase titre="Demandes, mois par mois">
              <Barres titre="Demandes proposées et validées, mois par mois, sur un an" :categories="activite.categories" :series="activite.series" :format="nombre" :hauteur-px="100" />
              <p class="m-0 mt-2 text-xs text-attenue">{{ surN(activite.n, 'demande proposée', 'demandes proposées') }} en douze mois.</p>
            </PanneauBase>
            <PanneauBase titre="La famille au travail">
              <dl class="m-0 grid grid-cols-2 gap-3">
                <div>
                  <dt class="text-xs text-attenue">Demandes en attente</dt>
                  <dd class="m-0 text-xl font-semibold tabular-nums">{{ nombre(chantier.activite.demandesEnAttente) }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-attenue">Actes demandés en attente</dt>
                  <dd class="m-0 text-xl font-semibold tabular-nums">{{ nombre(chantier.activite.actesEnAttente) }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-attenue">Comptes actifs</dt>
                  <dd class="m-0 text-xl font-semibold tabular-nums">{{ nombre(chantier.activite.membres.actifs) }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-attenue">Venus ces {{ chantier.activite.membres.jours }} jours</dt>
                  <dd class="m-0 text-xl font-semibold tabular-nums">{{ nombre(chantier.activite.membres.connectesRecemment) }}</dd>
                </div>
              </dl>
            </PanneauBase>
          </div>
        </section>

        <!-- ─── Les règles de calcul ───────────────────────────────────── -->
        <details class="rounded-panneau border border-ligne bg-panneau px-4 py-3 text-sm">
          <summary class="cursor-pointer font-medium">Comment ces chiffres sont calculés</summary>
          <ul class="m-0 mt-2 flex flex-col gap-1 pl-5 text-attenue">
            <li>Une date floue (« vers », « avant », « après », « entre ») ou inconnue ne compte pas. Une date exacte compte ; une année seule compte pour les âges et les siècles, pas pour les mois.</li>
            <li>Les personnes vivantes comptent partout : leur âge est calculé jusqu’à aujourd’hui.</li>
            <li>« Vérifié » veut dire appuyé sur un acte ou un registre — la même règle que sur une fiche.</li>
            <li>Les moyennes comptent toute la famille ; une case qui compterait moins de {{ vue.seuilDeDiscretion }} personnes ne retient que les fiches que vous pouvez ouvrir. Les records, les prénoms et les noms ne portent que sur ces fiches-là.</li>
            <li>Les générations et les branches sont lues depuis la racine de l’arbre.</li>
          </ul>
        </details>
      </div>
    </div>
  </div>
</template>
