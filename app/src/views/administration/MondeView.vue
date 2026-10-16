<script setup lang="ts">
/**
 * **Le Monde** — `/administration/monde`, administration seulement.
 *
 * Le tableau de suivi en maquette 3D : une île flottante, un objet par partie
 * du système, animé par les données réelles. Mêmes lectures que le suivi
 * (`useDonneesDuSuivi`), aucune route d'API nouvelle, lecture seule.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce qui vit ici, et ce qui vit ailleurs.** La vue porte tout ce qui est
 * HTML — en-tête, dock, panneau de détail, étiquettes —, donc tout ce qui se
 * lit au clavier et au lecteur d'écran : chaque zone a son équivalent texte
 * dans le panneau. La 3D (`components/monde/scene.ts`) est chargée à part, au
 * montage, et Three.js avec elle : il ne pèse rien sur le reste de
 * l'application. Si WebGL manque, le dock et le panneau restent utilisables.
 *
 * **Paramètres d'adresse** (captures, liens directs) :
 * `#theme=dark|light&zone=<id>&tour=0`.
 *
 * **Visite automatique** après 20 s sans geste ; le premier geste rend la main.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useDonneesDuSuivi } from '@/components/suivi/useDonneesDuSuivi'
import {
  detailsDAppel,
  elementsEnAttente,
  heureCourte,
  modelesAppeles,
  natureDAppel,
  pointsDeCourbe,
  SEUIL_CONNEXIONS_ECHOUEES,
} from '@/components/suivi/suivi'
import {
  CADENCE_MONDE_MS,
  VISITE_APRES_MS,
  VISITE_ETAPE_MS,
  ZONES,
  etatDuMonde,
  lireParametres,
  valeurDeZone,
  zone as definition,
  type DonneesDuMonde,
  type IdZone,
} from '@/components/monde/monde'
import type { SceneDuMonde } from '@/components/monde/scene'

const {
  maintenant,
  derniereSante,
  journal,
  echecs,
  appels,
  demandes,
  demandesTotal,
  rattachements,
  rgpd,
  comptes,
  invitationsActives,
  chantier,
  echecs24h,
  aTraiter,
  etat,
  enLigne,
  texteFraicheur,
  latences,
  disponibilite,
  latenceMediane,
} = useDonneesDuSuivi({ cadence: () => CADENCE_MONDE_MS, avecChantier: () => true })

/* ───────────────── L'état du monde ───────────────── */

const attente = computed(() => elementsEnAttente(demandes.value, rattachements.value, rgpd.value))

const donnees = computed<DonneesDuMonde>(() => ({
  sante: derniereSante.value,
  attente: attente.value.map((a) => ({ cle: a.cle, nature: a.type, depuis: a.depuis })),
  aTraiter: aTraiter.value,
  comptes: comptes.value,
  journal: journal.value,
  appels: appels.value,
  echecs24h: echecs24h.value,
  invitationsActives: invitationsActives.value,
  chantier: chantier.value,
}))
const maintenantDate = computed(() => new Date(maintenant.value))
const monde = computed(() => etatDuMonde(donnees.value, maintenantDate.value))

/* ───────────────── Thème ───────────────── */

type ModeTheme = 'auto' | 'clair' | 'nuit'
const CLE_THEME = 'heritage.monde.theme'
const modeTheme = ref<ModeTheme>('auto')
const systemeSombre = ref(false)
const nuit = computed(() => modeTheme.value === 'nuit' || (modeTheme.value === 'auto' && systemeSombre.value))
const LIBELLES_THEME: Record<ModeTheme, string> = { auto: 'Auto', clair: 'Clair', nuit: 'Nuit' }

function basculerTheme(): void {
  const ordre: ModeTheme[] = ['auto', 'clair', 'nuit']
  modeTheme.value = ordre[(ordre.indexOf(modeTheme.value) + 1) % 3]!
  try {
    localStorage.setItem(CLE_THEME, modeTheme.value)
  } catch {
    // Stockage refusé (navigation privée) : le choix vaut pour cette visite.
  }
}

/* ───────────────── Zone choisie, panneau ───────────────── */

const choisie = ref<IdZone | null>(null)
const survolee = ref<IdZone | null>(null)
const largeEcran = ref(true)

function ouvrir(id: IdZone, parUtilisateur = true): void {
  if (parUtilisateur) arreterVisite()
  choisie.value = id
  scene.value?.voler(id, parUtilisateur ? 1.8 : 3.2)
}

function ensemble(parUtilisateur = true): void {
  if (parUtilisateur) arreterVisite()
  choisie.value = null
  scene.value?.voler(null, 2)
}

// Le panneau couvre la droite sur bureau, la moitié basse sur téléphone : l'image glisse d'autant.
watch([choisie, largeEcran], () => {
  const ouvert = choisie.value !== null
  scene.value?.decaler(ouvert && largeEcran.value ? 230 : 0, ouvert && !largeEcran.value ? window.innerHeight * 0.26 : 0)
})

/** Flèches gauche/droite dans le dock (motif « barre d'outils »). */
function clavierDock(e: KeyboardEvent): void {
  if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
  const boutons = [...(e.currentTarget as HTMLElement).querySelectorAll<HTMLButtonElement>('button')]
  const i = boutons.indexOf(document.activeElement as HTMLButtonElement)
  const j = (i + (e.key === 'ArrowRight' ? 1 : boutons.length - 1)) % boutons.length
  boutons[j]?.focus()
  e.preventDefault()
}

/* ───────────────── Visite automatique ───────────────── */

const visite = ref(false)
let visiteAutorisee = true
let dernierGeste = Date.now()
let etapeVisite = 0
let prochaineEtape = 0

function geste(): void {
  dernierGeste = Date.now()
  if (visite.value) arreterVisite()
}
function arreterVisite(): void {
  visite.value = false
}
function tic(): void {
  const t = Date.now()
  if (!visiteAutorisee || document.hidden) return
  if (!visite.value && t - dernierGeste > VISITE_APRES_MS) {
    visite.value = true
    etapeVisite = choisie.value === null ? 0 : ZONES.findIndex((z) => z.id === choisie.value) + 1
    prochaineEtape = 0
  }
  if (visite.value && t >= prochaineEtape) {
    ouvrir(ZONES[etapeVisite % ZONES.length]!.id, false)
    etapeVisite++
    prochaineEtape = t + VISITE_ETAPE_MS
  }
}

/* ───────────────── La scène ───────────────── */

const toile = ref<HTMLElement | null>(null)
const etiquettes = ref<Partial<Record<IdZone, HTMLElement>>>({})
const scene = shallowRef<SceneDuMonde | null>(null)
const sansWebGL = ref(false)
const pret = ref(false)

function poserEtiquette(id: IdZone, el: unknown): void {
  if (el instanceof HTMLElement) etiquettes.value[id] = el
}

let minuterieVisite: ReturnType<typeof setInterval> | undefined
let mqSombre: MediaQueryList | null = null
let mqCalme: MediaQueryList | null = null
const surSombre = (e: MediaQueryListEvent): void => void (systemeSombre.value = e.matches)
const surCalme = (e: MediaQueryListEvent): void => scene.value?.mouvementReduit(e.matches)
const surLargeur = (): void => void (largeEcran.value = window.innerWidth > 900)
function surTouche(e: KeyboardEvent): void {
  geste()
  if (e.key === 'Escape' && choisie.value !== null) ensemble()
}

onMounted(async () => {
  const p = lireParametres(window.location.hash)
  try {
    const garde = localStorage.getItem(CLE_THEME)
    if (garde === 'auto' || garde === 'clair' || garde === 'nuit') modeTheme.value = garde
  } catch {
    // Stockage illisible : thème automatique.
  }
  if (p.theme !== null) modeTheme.value = p.theme
  visiteAutorisee = p.visite
  mqSombre = window.matchMedia('(prefers-color-scheme: dark)')
  systemeSombre.value = mqSombre.matches
  mqSombre.addEventListener('change', surSombre)
  mqCalme = window.matchMedia('(prefers-reduced-motion: reduce)')
  mqCalme.addEventListener('change', surCalme)
  surLargeur()
  window.addEventListener('resize', surLargeur)
  window.addEventListener('keydown', surTouche)
  window.addEventListener('pointerdown', geste)
  window.addEventListener('wheel', geste, { passive: true })
  minuterieVisite = setInterval(tic, 500)

  try {
    const { creerScene } = await import('@/components/monde/scene')
    if (toile.value === null) return
    scene.value = creerScene(toile.value, {
      etiquettes: etiquettes.value,
      surSurvol: (id) => (survolee.value = id),
      surClic: (id) => ouvrir(id),
      surGeste: geste,
      nuit: nuit.value,
      mouvementReduit: mqCalme.matches,
    })
    scene.value.appliquer(monde.value)
  } catch {
    sansWebGL.value = true
  }
  pret.value = true
  if (p.zone !== null) ouvrir(p.zone)
})

onBeforeUnmount(() => {
  clearInterval(minuterieVisite)
  mqSombre?.removeEventListener('change', surSombre)
  mqCalme?.removeEventListener('change', surCalme)
  window.removeEventListener('resize', surLargeur)
  window.removeEventListener('keydown', surTouche)
  window.removeEventListener('pointerdown', geste)
  window.removeEventListener('wheel', geste)
  scene.value?.detruire()
  scene.value = null
})

watch(monde, (e) => scene.value?.appliquer(e))
watch(nuit, (n) => scene.value?.theme(n))

/* ───────────────── Ce que le panneau dit ───────────────── */

const n = (x: number | null | undefined): string => (x === null || x === undefined ? '–' : x.toLocaleString('fr-FR'))
const heure = computed(() =>
  maintenantDate.value.toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }),
)
const zoneChoisie = computed(() => (choisie.value === null ? null : definition(choisie.value)))
const aujourdhui = (iso: string): boolean => new Date(iso).toDateString() === maintenantDate.value.toDateString()
const connexionsDuJour = computed(() => journal.value.filter((l) => l.action === 'connexion' && aujourdhui(l.occurredAt)))
const invitationsDuJour = computed(() => journal.value.filter((l) => l.action === 'invitation_utilisee' && aujourdhui(l.occurredAt)))
const modeles = computed(() => modelesAppeles(appels.value))
const moisCourant = computed(() => {
  const d = maintenantDate.value
  return chantier.value?.activite.demandesParMois.find((m) => m.mois === `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`) ?? null
})
const jaugeLatence = computed(() => (latenceMediane.value === null ? 0 : Math.min(100, (latenceMediane.value / 800) * 100)))

const TEINTE_TYPE: Record<'demande' | 'inscription' | 'rgpd', string> = { demande: '#C9955A', inscription: '#8FA3AD', rgpd: '#B5653A' }
</script>

<template>
  <div class="monde" :data-theme="nuit ? 'dark' : 'light'" data-monde>
    <div class="monde__ciel" aria-hidden="true" />
    <div ref="toile" class="monde__toile" />

    <!-- Étiquettes accrochées aux objets : la scène les place à chaque image. -->
    <div class="monde__etiquettes">
      <button
        v-for="z in ZONES"
        :key="z.id"
        :ref="(el) => poserEtiquette(z.id, el)"
        type="button"
        class="puce"
        :class="{ 'puce--survol': survolee === z.id, 'puce--choisie': choisie === z.id, 'puce--estompee': choisie !== null && choisie !== z.id }"
        :style="{ '--c': z.couleur }"
        tabindex="-1"
        :data-etiquette="z.id"
        @click="ouvrir(z.id)"
      >
        <span class="puce__point" aria-hidden="true" />
        <b>{{ z.nom }}</b>
        <span class="puce__valeur">{{ valeurDeZone(z.id, monde, donnees) }}</span>
      </button>
    </div>

    <p v-if="!pret" class="monde__chargement">Le monde se construit…</p>
    <p v-if="sansWebGL" class="feuille monde__sans3d" role="status">
      La 3D n’est pas disponible sur cet appareil. Le dock et les panneaux restent utilisables, et le
      <RouterLink :to="{ name: 'administration.suivi' }">tableau de suivi</RouterLink> dit la même chose.
    </p>

    <!-- En-tête -->
    <header class="haut">
      <div class="feuille titre">
        <span class="logo" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z" />
            <path d="M4 7.5l8 4.5 8-4.5M12 12v9" />
          </svg>
        </span>
        <div>
          <h1>Monde</h1>
          <p class="titre__sous">
            <span>{{ heure }}</span>
            <span class="etat" :class="`etat--${etat.niveau}`" role="status" data-etat-global :data-niveau="etat.niveau">
              <i aria-hidden="true" />{{ etat.libelle }}
            </span>
            <span v-if="!enLigne && derniereSante !== null" class="hors-ligne">hors ligne · dernière réponse {{ texteFraicheur }}</span>
          </p>
        </div>
      </div>
      <div class="outils">
        <button type="button" class="feuille bouton" title="Vue d’ensemble (Échap)" @click="ensemble()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
          </svg>
          <span>Vue d’ensemble</span>
        </button>
        <button type="button" class="feuille bouton" :aria-label="`Thème : ${LIBELLES_THEME[modeTheme]}`" @click="basculerTheme">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z" />
          </svg>
          <span>{{ LIBELLES_THEME[modeTheme] }}</span>
        </button>
        <RouterLink :to="{ name: 'administration.suivi' }" class="feuille bouton">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <path d="M4 10v4M8 6v12M12 3v18M16 7v10M20 10v4" />
          </svg>
          <span>Tableau de suivi</span>
        </RouterLink>
      </div>
    </header>

    <!-- Panneau de détail : l'équivalent texte de chaque zone. -->
    <aside
      class="feuille panneau"
      :class="{ 'panneau--ouvert': zoneChoisie !== null }"
      :aria-hidden="zoneChoisie === null"
      :inert="zoneChoisie === null"
      aria-live="polite"
      aria-labelledby="titre-zone"
      data-panneau
    >
      <template v-if="zoneChoisie !== null">
        <div class="panneau__tete">
          <span class="panneau__icone" :style="{ '--c': zoneChoisie.couleur }" aria-hidden="true" />
          <div>
            <h2 id="titre-zone">{{ zoneChoisie.nom }}</h2>
            <p class="panneau__sous">{{ valeurDeZone(zoneChoisie.id, monde, donnees) }}</p>
          </div>
          <button type="button" class="fermer" aria-label="Fermer le panneau" @click="ensemble()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </div>
        <p class="panneau__phrase">{{ zoneChoisie.phrase }}</p>

        <div class="panneau__corps">
          <!-- Maison des archives -->
          <template v-if="zoneChoisie.id === 'maison'">
            <dl class="chiffres">
              <div class="chiffre"><dt>Base de données</dt><dd>{{ derniereSante === null ? '–' : derniereSante.ok ? 'répond' : 'ne répond pas' }}</dd></div>
              <div class="chiffre"><dt>Disponibilité</dt><dd>{{ disponibilite === null ? '–' : `${disponibilite} %` }}</dd></div>
              <div class="chiffre chiffre--large">
                <dt>Latence médiane</dt>
                <dd>{{ latenceMediane === null ? '–' : `${latenceMediane} ms` }}</dd>
                <div class="jauge" aria-hidden="true"><i :style="{ width: `${jaugeLatence}%` }" /></div>
              </div>
            </dl>
            <h3>Latence, dernières sondes</h3>
            <svg v-if="latences.length > 1" class="courbe" viewBox="0 0 300 60" preserveAspectRatio="none" role="img" :aria-label="`Latence des ${latences.length} dernières sondes`">
              <polyline :points="pointsDeCourbe(latences, 300, 60)" fill="none" stroke="currentColor" stroke-width="2.4" vector-effect="non-scaling-stroke" />
            </svg>
            <p v-else class="vide">Encore trop peu de mesures.</p>
          </template>

          <!-- Tapis -->
          <template v-else-if="zoneChoisie.id === 'file'">
            <dl class="chiffres chiffres--3">
              <div class="chiffre"><dt>Demandes</dt><dd>{{ n(demandesTotal) }}</dd></div>
              <div class="chiffre"><dt>Inscriptions</dt><dd>{{ rattachements === null ? '–' : rattachements.length }}</dd></div>
              <div class="chiffre"><dt>RGPD</dt><dd>{{ rgpd === null ? '–' : rgpd.length }}</dd></div>
            </dl>
            <h3>Du plus ancien au plus récent</h3>
            <p v-if="attente.length === 0" class="vide">{{ aTraiter === null ? '–' : 'Rien n’attend de décision.' }}</p>
            <ul v-else class="liste">
              <li v-for="a in attente.slice(0, 8)" :key="a.cle">
                <span class="tag" :style="{ '--c': TEINTE_TYPE[a.type] }">{{ a.type === 'demande' ? 'demande' : a.type === 'inscription' ? 'inscription' : 'RGPD' }}</span>
                <RouterLink :to="{ name: 'administration.a-traiter', query: { sel: a.sel } }" class="liste__texte">
                  <b>{{ a.libelle }}</b>
                  <span>{{ a.nature }}<template v-if="a.depuis"> · depuis {{ heureCourte(a.depuis, maintenantDate) }}</template></span>
                </RouterLink>
              </li>
            </ul>
          </template>

          <!-- Tour des comptes -->
          <template v-else-if="zoneChoisie.id === 'comptes'">
            <dl class="chiffres">
              <div class="chiffre"><dt>Actifs</dt><dd>{{ n(comptes?.actif) }}</dd></div>
              <div class="chiffre"><dt>En attente</dt><dd>{{ n(comptes?.en_attente) }}</dd></div>
              <div class="chiffre"><dt>Suspendus</dt><dd>{{ n(comptes?.suspendu) }}</dd></div>
              <div class="chiffre"><dt>Administrateurs</dt><dd>{{ n(comptes?.admins) }}</dd></div>
            </dl>
            <h3>Connexions aujourd’hui · {{ connexionsDuJour.length }}</h3>
            <p v-if="connexionsDuJour.length === 0" class="vide">Personne ne s’est connecté aujourd’hui.</p>
            <ul v-else class="liste">
              <li v-for="l in connexionsDuJour.slice(0, 8)" :key="l.id">
                <span class="liste__heure">{{ heureCourte(l.occurredAt, maintenantDate) }}</span>
                <span class="liste__texte"><b>{{ l.actorEmail ?? '—' }}</b></span>
              </li>
            </ul>
            <p class="note"><RouterLink :to="{ name: 'administration.utilisateurs' }">Gérer les comptes</RouterLink></p>
          </template>

          <!-- Portail -->
          <template v-else-if="zoneChoisie.id === 'portail'">
            <dl class="chiffres">
              <div class="chiffre"><dt>Liens actifs</dt><dd>{{ n(invitationsActives) }}</dd></div>
              <div class="chiffre"><dt>Utilisées aujourd’hui</dt><dd>{{ invitationsDuJour.length }}</dd></div>
            </dl>
            <h3>Arrivées du jour</h3>
            <p v-if="invitationsDuJour.length === 0" class="vide">Aucune invitation utilisée aujourd’hui.</p>
            <ul v-else class="liste">
              <li v-for="l in invitationsDuJour" :key="l.id">
                <span class="liste__heure">{{ heureCourte(l.occurredAt, maintenantDate) }}</span>
                <span class="liste__texte"><b>{{ l.actorEmail ?? l.subjectLabel ?? '—' }}</b></span>
              </li>
            </ul>
            <p class="note"><RouterLink :to="{ name: 'administration.invitations' }">Gérer les invitations</RouterLink></p>
          </template>

          <!-- Nuage -->
          <template v-else-if="zoneChoisie.id === 'nuage'">
            <dl class="chiffres">
              <div class="chiffre"><dt>Appels en 24 h</dt><dd>{{ monde.nuage.parJour }}</dd></div>
              <div class="chiffre"><dt>Il pleut</dt><dd>{{ monde.nuage.pluie ? 'oui' : 'non' }}</dd></div>
            </dl>
            <h3>Modèles appelés</h3>
            <p v-if="modeles.length === 0" class="vide">Aucun appel consigné.</p>
            <ul v-else class="liste">
              <li v-for="mo in modeles" :key="mo.modele">
                <span class="liste__heure">{{ mo.n }}</span>
                <span class="liste__texte"><b>{{ mo.modele }}</b><span>{{ mo.natures.join(' · ') }}</span></span>
              </li>
            </ul>
            <h3>Derniers appels</h3>
            <ul class="liste">
              <li v-for="a in appels.slice(0, 5)" :key="a.id">
                <span class="liste__heure">{{ heureCourte(a.occurredAt, maintenantDate) }}</span>
                <span class="liste__texte"><b>{{ natureDAppel(a.subjectType) }}</b><span>{{ detailsDAppel(a.changes).join(' · ') }}</span></span>
              </li>
            </ul>
          </template>

          <!-- Arbre -->
          <template v-else-if="zoneChoisie.id === 'arbre'">
            <dl class="chiffres">
              <div class="chiffre"><dt>Sans date de naissance</dt><dd>{{ n(chantier?.fiches.sansDateDeNaissance.n) }}</dd></div>
              <div class="chiffre"><dt>Sans parents</dt><dd>{{ n(chantier?.fiches.sansParents.n) }}</dd></div>
              <div class="chiffre"><dt>Sans source</dt><dd>{{ n(chantier?.fiches.sansSource.n) }}</dd></div>
              <div class="chiffre"><dt>Non vérifiées</dt><dd>{{ n(chantier?.fiches.nonVerifiees.n) }}</dd></div>
              <div class="chiffre chiffre--large">
                <dt>Ce mois-ci</dt>
                <dd>{{ moisCourant === null ? '0' : moisCourant.validees }}<small> validées sur {{ moisCourant?.proposees ?? 0 }} proposées</small></dd>
              </div>
            </dl>
            <p v-if="chantier === null" class="vide">Chargement du chantier…</p>
          </template>

          <!-- Phare -->
          <template v-else>
            <dl class="chiffres">
              <div class="chiffre"><dt>Échecs en 24 h</dt><dd>{{ echecs24h }}</dd></div>
              <div class="chiffre"><dt>Seuil d’alerte</dt><dd>{{ SEUIL_CONNEXIONS_ECHOUEES }}</dd></div>
            </dl>
            <h3>Dernières connexions échouées</h3>
            <p v-if="echecs.length === 0" class="vide">Aucune connexion échouée consignée.</p>
            <ul v-else class="liste">
              <li v-for="l in echecs.slice(0, 6)" :key="l.id">
                <span class="liste__heure">{{ heureCourte(l.occurredAt, maintenantDate) }}</span>
                <span class="liste__texte"><b>{{ l.subjectLabel ?? l.actorEmail ?? '—' }}</b><span>{{ l.ip ?? '' }}</span></span>
              </li>
            </ul>
          </template>
        </div>
      </template>
    </aside>

    <p class="feuille visite" :class="{ 'visite--on': visite }" aria-live="polite">
      <i aria-hidden="true" />{{ visite ? 'Visite automatique · touchez pour reprendre la main' : '' }}
    </p>

    <nav class="feuille dock" aria-label="Zones du monde" @keydown="clavierDock">
      <button
        v-for="z in ZONES"
        :key="z.id"
        type="button"
        :aria-pressed="choisie === z.id"
        :style="{ '--c': z.couleur }"
        :data-zone="z.id"
        @click="ouvrir(z.id)"
      >
        <i aria-hidden="true" />{{ z.nom }}
      </button>
    </nav>
  </div>
</template>

<style scoped>
/*
 * Le Monde en **carnet d'archiviste** : une maquette dessinée à l'encre sur
 * papier crème, rehaussée d'aquarelle. La palette est celle de la charte
 * d'Heritage (brun #8A5A2B, crème, sauge, ocre) ; elle reste locale à cet
 * écran et ne touche aucun jeton de l'application. Nuit : papier bleu nuit,
 * encre dorée. Le grain du papier est un SVG `data:` (la CSP l'autorise pour
 * les images ; rien ne vient d'ailleurs). Titres en serif système : aucune
 * police distante.
 */
.monde {
  --papier: #f4ecdc;
  --papier-2: #ebdfc6;
  --encre: #3b2a1e;
  --doux: rgba(59, 42, 30, 0.74);
  --trait: rgba(59, 42, 30, 0.62);
  --trait-2: rgba(59, 42, 30, 0.16);
  --accent: #8a5a2b;
  --ombre-papier: 3px 4px 0 rgba(59, 42, 30, 0.13), 0 10px 26px rgba(59, 42, 30, 0.12);
  --grain: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .23 0 0 0 0 .16 0 0 0 0 .1 0 0 0 .09 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23g)'/%3E%3C/svg%3E");
  --serif: 'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif;
  position: fixed;
  inset: 0;
  overflow: hidden;
  background: var(--papier);
  color: var(--encre);
  color-scheme: light;
}
.monde[data-theme='dark'] {
  --papier: #1b2235;
  --papier-2: #232c44;
  --encre: #efe6d2;
  --doux: rgba(239, 230, 210, 0.76);
  --trait: rgba(217, 181, 106, 0.72);
  --trait-2: rgba(217, 181, 106, 0.2);
  --accent: #d9b56a;
  --ombre-papier: 3px 4px 0 rgba(0, 0, 0, 0.35), 0 10px 26px rgba(0, 0, 0, 0.35);
  --grain: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='g'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .95 0 0 0 0 .9 0 0 0 0 .8 0 0 0 .06 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23g)'/%3E%3C/svg%3E");
  color-scheme: dark;
}
.monde__ciel,
.monde__toile,
.monde__etiquettes {
  position: absolute;
  inset: 0;
}
/* La page du carnet : papier, grain, bords légèrement assombris. */
.monde__ciel {
  background:
    var(--grain),
    radial-gradient(120% 90% at 50% 40%, transparent 55%, rgba(120, 84, 44, 0.22) 100%),
    var(--papier);
}
.monde[data-theme='dark'] .monde__ciel {
  background:
    var(--grain),
    radial-gradient(120% 90% at 50% 30%, #222b44 0%, #161c2d 60%, #0f1320 100%);
}
.monde__etiquettes {
  pointer-events: none;
}
.feuille {
  border: 1.5px solid var(--trait);
  background: var(--grain), var(--papier);
  box-shadow: var(--ombre-papier);
}
.monde__chargement {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  font-family: var(--serif);
  font-size: 20px;
  font-style: italic;
}
.monde__sans3d {
  position: absolute;
  top: 50%;
  left: 50%;
  max-width: 420px;
  padding: 16px 20px;
  border-radius: 4px;
  text-align: center;
  transform: translate(-50%, -50%);
}
.monde__sans3d a {
  text-decoration: underline;
}

/* ───── Étiquettes de musée, accrochées à leur objet par un fil d'encre ───── */
.puce {
  position: absolute;
  top: 0;
  left: 0;
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-top: -12px;
  padding: 5px 10px 6px;
  border: 1px solid var(--trait);
  border-radius: 2px;
  background: var(--grain), var(--papier);
  box-shadow: 2px 2px 0 var(--trait-2);
  white-space: nowrap;
  pointer-events: auto;
  cursor: pointer;
  transition: opacity 0.4s, background 0.2s, border-color 0.2s;
  will-change: transform;
}
.puce::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  width: 1px;
  height: 12px;
  background: var(--trait);
}
.puce__point {
  align-self: center;
  width: 10px;
  height: 10px;
  border: 1px solid var(--trait);
  border-radius: 1px;
  background: var(--c);
}
.puce b {
  font-family: var(--serif);
  font-size: 12.5px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.puce__valeur {
  color: var(--doux);
  font-family: var(--serif);
  font-size: 13.5px;
  font-style: italic;
  font-variant-numeric: tabular-nums;
}
.puce--survol,
.puce--choisie {
  border-color: var(--accent);
  box-shadow: 2px 2px 0 var(--accent);
}
.puce--estompee {
  opacity: 0.35 !important;
}

/* ───── En-tête ───── */
.haut {
  position: absolute;
  top: 16px;
  left: 16px;
  right: 16px;
  z-index: 4;
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 12px;
  pointer-events: none;
}
.titre {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 18px 10px 12px;
  border-radius: 4px;
  pointer-events: auto;
}
/* Le cachet de l'archiviste. */
.logo {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex: none;
  border: 2px solid var(--accent);
  border-radius: 50%;
  color: var(--accent);
  box-shadow: inset 0 0 0 3px var(--papier), inset 0 0 0 4px var(--accent);
  transform: rotate(-8deg);
}
.logo svg {
  width: 22px;
  height: 22px;
}
.titre h1 {
  margin: 0;
  font-family: var(--serif);
  font-size: 26px;
  font-style: italic;
  font-weight: 700;
  line-height: 1.05;
}
.titre__sous {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
  margin-top: 3px;
  color: var(--doux);
  font-size: 13px;
}
.titre__sous > span:first-child {
  font-family: var(--serif);
  font-style: italic;
}
.titre__sous > span:first-child::first-letter {
  text-transform: uppercase;
}
.etat {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}
.etat i {
  display: inline-block;
  width: 9px;
  height: 9px;
  border: 1px solid var(--trait);
  border-radius: 50%;
  background: #a89c86;
}
.etat--ok i {
  background: #7e9468;
}
.etat--attention i {
  background: #c9955a;
}
.etat--panne i {
  background: #b5413a;
}
.hors-ligne {
  color: #a8382f;
  font-weight: 600;
}
.monde[data-theme='dark'] .hors-ligne {
  color: #f0a08c;
}
.outils {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-left: auto;
  pointer-events: auto;
}
.bouton {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 42px;
  padding: 0 14px;
  border-radius: 4px;
  color: var(--encre);
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.bouton:hover {
  background: var(--grain), var(--papier-2);
}
.bouton svg {
  width: 17px;
  height: 17px;
}
.bouton:focus-visible,
.dock button:focus-visible,
.fermer:focus-visible,
.puce:focus-visible,
.liste a:focus-visible,
.note a:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 3px;
}

/* ───── Panneau : une fiche d'inventaire ───── */
.panneau {
  position: absolute;
  top: 96px;
  right: 16px;
  bottom: 92px;
  z-index: 5;
  display: flex;
  flex-direction: column;
  width: 420px;
  padding: 22px 22px 18px;
  border-radius: 4px;
  opacity: 0;
  transform: translateX(calc(100% + 40px)) rotate(0.6deg);
  transition: transform 0.6s cubic-bezier(0.2, 0.9, 0.25, 1), opacity 0.4s;
}
.panneau--ouvert {
  opacity: 1;
  transform: none;
}
.panneau__tete {
  display: flex;
  align-items: center;
  gap: 14px;
  padding-bottom: 12px;
  border-bottom: 1.5px solid var(--trait);
}
.panneau__icone {
  width: 40px;
  height: 40px;
  flex: none;
  border: 1.5px solid var(--trait);
  border-radius: 2px;
  background: var(--c);
  box-shadow: 2px 2px 0 var(--trait-2);
}
.panneau h2 {
  margin: 0;
  font-family: var(--serif);
  font-size: 23px;
  font-weight: 700;
  line-height: 1.1;
}
.panneau__sous {
  margin-top: 2px;
  color: var(--doux);
  font-family: var(--serif);
  font-size: 14px;
  font-style: italic;
}
.fermer {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  flex: none;
  margin-left: auto;
  border: 1px solid var(--trait);
  border-radius: 4px;
  cursor: pointer;
}
.fermer:hover {
  background: var(--papier-2);
}
.fermer svg {
  width: 17px;
  height: 17px;
}
.panneau__phrase {
  margin: 12px 0 4px;
  color: var(--doux);
  font-family: var(--serif);
  font-size: 15px;
  line-height: 1.45;
}
.panneau__corps {
  min-height: 0;
  margin: 4px -8px 0;
  padding: 0 8px 4px;
  overflow-y: auto;
  scrollbar-width: thin;
}
.panneau h3 {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 18px 0 8px;
  color: var(--accent);
  font-family: var(--serif);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}
.panneau h3::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--trait-2);
}
.chiffres {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 18px;
  margin: 10px 0;
}
.chiffres--3 {
  grid-template-columns: repeat(3, 1fr);
}
.chiffre {
  display: flex;
  flex-direction: column;
  padding: 9px 0 10px;
  border-bottom: 1px dashed var(--trait-2);
}
.chiffre dt {
  color: var(--doux);
  font-size: 12.5px;
}
.chiffre dd {
  margin: 0;
  font-family: var(--serif);
  font-size: 28px;
  font-weight: 700;
  font-variant-numeric: tabular-nums lining-nums;
  line-height: 1.15;
}
.chiffre dd small {
  margin-left: 4px;
  color: var(--doux);
  font-size: 13.5px;
  font-style: italic;
  font-weight: 400;
}
.chiffre--large {
  grid-column: 1 / -1;
}
.jauge {
  height: 7px;
  margin-top: 7px;
  overflow: hidden;
  border: 1px solid var(--trait);
  border-radius: 1px;
}
.jauge i {
  display: block;
  height: 100%;
  background: repeating-linear-gradient(-45deg, var(--accent) 0 3px, transparent 3px 6px);
  transition: width 0.8s;
}
.courbe {
  display: block;
  width: 100%;
  height: 60px;
  color: var(--accent);
}
.liste {
  margin: 0;
  padding: 0;
  list-style: none;
}
.liste li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 2px;
  border-bottom: 1px dotted var(--trait);
}
.liste__heure {
  min-width: 52px;
  font-family: var(--serif);
  font-size: 14px;
  font-style: italic;
  font-variant-numeric: tabular-nums;
}
.liste__texte {
  min-width: 0;
  flex: 1;
}
.liste__texte b {
  display: block;
  overflow: hidden;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.liste__texte span {
  color: var(--doux);
  font-size: 12.5px;
}
a.liste__texte:hover b {
  text-decoration: underline;
}
.tag {
  flex: none;
  padding: 1px 7px 2px;
  border: 1px solid var(--trait);
  border-radius: 2px;
  background: color-mix(in srgb, var(--c) 45%, transparent);
  font-family: var(--serif);
  font-size: 11.5px;
  font-style: italic;
}
.vide {
  padding: 8px 2px;
  color: var(--doux);
  font-family: var(--serif);
  font-size: 15px;
  font-style: italic;
}
.note {
  margin-top: 12px;
  font-size: 14px;
}
.note a {
  color: var(--accent);
  text-decoration: underline;
}

/* ───── Dock : les onglets d'un classeur ───── */
.dock {
  position: absolute;
  bottom: 18px;
  left: 50%;
  z-index: 4;
  display: flex;
  gap: 2px;
  max-width: calc(100% - 32px);
  padding: 5px;
  overflow-x: auto;
  border-radius: 4px;
  transform: translateX(-50%);
}
.dock button {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 42px;
  padding: 0 12px 0 10px;
  border-bottom: 2px solid transparent;
  font-family: var(--serif);
  font-size: 14.5px;
  white-space: nowrap;
  cursor: pointer;
}
.dock button i {
  width: 11px;
  height: 11px;
  border: 1px solid var(--trait);
  border-radius: 1px;
  background: var(--c);
}
.dock button:hover {
  background: var(--papier-2);
}
.dock button[aria-pressed='true'] {
  border-bottom-color: var(--accent);
  background: var(--papier-2);
  font-weight: 700;
}
.visite {
  position: absolute;
  bottom: 86px;
  left: 50%;
  z-index: 4;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 4px;
  font-family: var(--serif);
  font-size: 14px;
  font-style: italic;
  opacity: 0;
  transform: translateX(-50%);
  transition: opacity 0.5s;
  pointer-events: none;
}
.visite--on {
  opacity: 1;
}
.visite i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--accent);
  animation: clin 1.6s ease-in-out infinite;
}
@keyframes clin {
  50% {
    opacity: 0.25;
  }
}

@media (prefers-reduced-motion: reduce) {
  .panneau,
  .puce,
  .jauge i {
    transition: none;
  }
  .visite i {
    animation: none;
  }
}
@media (max-width: 900px) {
  .panneau {
    top: auto;
    right: 12px;
    bottom: 80px;
    left: 12px;
    width: auto;
    height: 52vh;
    padding: 18px;
  }
  .dock {
    right: 12px;
    left: 12px;
    max-width: none;
    transform: none;
  }
  .titre h1 {
    font-size: 21px;
  }
  .bouton span {
    display: none;
  }
  .puce b {
    display: none;
  }
}
</style>
