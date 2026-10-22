/**
 * La table des routes — séparée de l'instance du routeur, et pour une raison
 * concrète.
 *
 * `createRouter({ history: createWebHistory() })` lit `window.location` **au
 * chargement du module**. Tant que la table vivait dans `index.ts`, l'importer
 * depuis un test exécutait cette ligne et échouait sur `window is not defined` :
 * la table de routes — la liste des écrans, qui peut les ouvrir, et vers où
 * partent les refus — était la seule partie du socle qu'aucun test ne pouvait
 * regarder. Elle vit donc ici, en données pures.
 *
 * **Les noms de route sont un contrat.** Une notification porte `linkRoute` +
 * `linkParams` plutôt qu'une URL construite, « pour qu'une refonte du routage
 * front ne casse pas les notifications d'hier » (§ 8 du contrat). Renommer une
 * route ci-dessous rend muettes les notifications déjà écrites en base. On
 * ajoute, on ne renomme pas. Le contrat cite nommément `demande.detail`.
 */

import type { RouteRecordRaw } from 'vue-router'
import type { NiveauRequis } from './acces'
import type { ParentDeDetail, Section } from '@/composables/entete'

declare module 'vue-router' {
  interface RouteMeta {
    /** Niveau exigé. Obligatoire : une route sans `acces` déclaré serait une
     *  route dont personne ne sait qui peut l'ouvrir. */
    acces: NiveauRequis
    /** Suffixe du titre de page. « Heritage » est ajouté par la garde. */
    titre: string
    /** `false` masque l'en-tête applicatif (connexion, inscription). */
    cadre?: boolean
    /** L'onglet d'exploration allumé dans l'en-tête. Par défaut, déduit du nom
     *  de route (`sectionDe`) : la fiche et les formulaires gardent « Arbre ». */
    section?: Section
    /** Écran de DÉTAIL : sur téléphone, l'en-tête affiche « ‹ {libelle} » pour y remonter. */
    parent?: ParentDeDetail
    /** L'écran porte sa propre recherche sur téléphone (l'arbre, en bas) : l'en-tête
     *  n'y montre pas sa loupe, pour ne pas avoir deux recherches à l'écran. */
    rechercheLocale?: boolean
  }
}

/**
 * Le titre que l'en-tête du téléphone affiche pour une route. C'est celui de la
 * route, sauf dans Explorer : ses quatre onglets sont déjà nommés dans la rangée
 * juste en dessous, et « Chronologie » au-dessus de l'onglet « Chronologie »
 * dirait deux fois la même chose. L'en-tête dit donc où l'on est — « Explorer » —
 * et la rangée, quel onglet. L'onglet du navigateur (`document.title`) garde,
 * lui, le titre précis de la route : c'est lui qu'on retrouve dans l'historique.
 */
export function titreDEntete(nom: string, meta: { section?: Section; titre?: string }): string {
  if (sectionDe(nom, meta) === 'explorer') return 'Explorer'
  return typeof meta.titre === 'string' ? meta.titre : ''
}

const VERS_ARBRE: ParentDeDetail = { nom: 'arbre', libelle: 'Arbre' }

/** L'onglet de l'en-tête pour une route, sans lire la route dans l'en-tête. */
export function sectionDe(nom: string, meta: { section?: Section }): Section {
  if (meta.section) return meta.section
  if (nom === 'accueil') return 'accueil'
  // L'écran d'attente est l'accueil d'un compte en attente : c'est l'entrée
  // « Inscription » de sa barre du bas qui s'allume (ADR 0003, 01/10/2026).
  if (nom === 'inscription.etat') return 'accueil'
  if (nom === 'chronologie' || nom === 'carte' || nom === 'anniversaires' || nom === 'stats' || nom === 'explorer') return 'explorer'
  // Le mode administration se déduit de la route, il n'est pas un état à part :
  // un lien profond ou un favori y entre, le bouton retour en sort.
  if (nom === 'administration' || nom.startsWith('administration.')) return 'administration'
  if (nom === 'moi' || nom === 'compte' || nom === 'aide' || nom.startsWith('demande') || nom.startsWith('evenement') || nom === 'demarches' || nom === 'questions') return 'moi'
  if (nom === 'arbre' || nom.startsWith('arbre.') || nom.startsWith('personne.') || nom.startsWith('proposition.')) return 'arbre'
  return 'autre'
}

/** Le site du tirage de Noël (noel2026), vers lequel renvoie `noel.tirage`. */
export const SITE_DE_NOEL = 'https://noel2026.naltpom.fr'

/** Exportée pour que les tests montent la table sur un historique en mémoire :
 *  `createWebHistory()` a besoin d'un `window`, que la suite de tests n'a pas. */
export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'racine',
    // Le composant n'est jamais rendu : la garde globale redirige selon le
    // régime, une fois la session connue. Une `redirect` de déclaration serait
    // évaluée **avant** la garde, donc avant que la session soit chargée.
    component: () => import('@/views/AccueilView.vue'),
    meta: { acces: 'public', titre: 'Accueil' },
  },

  {
    // La page d'accueil d'un membre (`routeParDefaut`) : les anniversaires du
    // jour en carrousel, ses événements, l'arbre et « proposer ». `membre` : elle
    // lit l'arbre, et un compte en attente n'en voit plus rien (ADR 0003,
    // révision du 01/10/2026) — sa page à lui est `inscription.etat`.
    path: '/home',
    name: 'accueil',
    component: () => import('@/views/AccueilView.vue'),
    meta: { acces: 'membre', titre: 'Accueil' },
  },

  {
    // L'ancienne adresse de l'arbre à niveaux de détail, devenu `/arbre` : les
    // liens déjà partagés continuent de marcher.
    path: '/histoire',
    name: 'histoire',
    redirect: { name: 'arbre' },
    meta: { acces: 'membre', titre: 'Arbre' },
  },

  /* --- Entrée : connexion et inscription (§ 2, § 4) --------------- */
  {
    path: '/connexion',
    name: 'connexion',
    component: () => import('@/views/ConnexionView.vue'),
    meta: { acces: 'anonyme', titre: 'Connexion', cadre: false },
  },
  {
    // Le lien d'invitation arrive sous **deux** formes, et les deux doivent
    // marcher. Le contrat bâtit le lien avec le jeton dans le **chemin** —
    // `https://heritage.local/inscription/kJ8s-2Qb…` (§ 3.2), et c'est cette
    // forme-là qui part par courriel — tandis que la forme `?jeton=…` est celle
    // qu'on écrit à la main. Le paramètre est donc optionnel : `/inscription`
    // seul reste valable, et la vue lit l'un ou l'autre.
    //
    // `/inscription/rattachement`, `/inscription/compte` et `/inscription/etat`
    // ne sont pas ambigus pour autant : `vue-router` classe un segment statique
    // **au-dessus** d'un paramètre, quel que soit l'ordre de déclaration. C'est
    // vérifié dans `views/entree-routage.test.ts`, parce que c'est le genre de
    // règle qui se casse en silence.
    //
    // Un jeton inconnu, mal formé, expiré, révoqué ou déjà consommé rend **404**
    // côté API, avec le même message dans les cinq cas (§ 4) : l'écran ne doit
    // pas les distinguer. Le jeton n'est pas filtré ici pour cette raison —
    // refuser une forme invalide côté routeur la rendrait distinguable d'un
    // jeton simplement inconnu.
    path: '/inscription/:jeton?',
    name: 'inscription.jeton',
    component: () => import('@/views/InscriptionJetonView.vue'),
    meta: { acces: 'anonyme', titre: 'Invitation', cadre: false },
  },
  {
    path: '/inscription/rattachement',
    name: 'inscription.rattachement',
    component: () => import('@/views/InscriptionRattachementView.vue'),
    meta: { acces: 'anonyme', titre: 'Rattachement', cadre: false },
  },
  {
    path: '/inscription/compte',
    name: 'inscription.compte',
    component: () => import('@/views/InscriptionCompteView.vue'),
    meta: { acces: 'anonyme', titre: 'Création du compte', cadre: false },
  },
  {
    // Étape 3 : elle exige une **session**, pas l'anonymat — le compte existe
    // déjà, il attend sa validation (§ 4.4). C'est la **page d'attente** : la
    // page par défaut d'un compte en attente (`routeParDefaut`) et la
    // destination de tous ses refus de niveau « membre » — l'arbre compris.
    path: '/inscription/etat',
    name: 'inscription.etat',
    component: () => import('@/views/InscriptionEtatView.vue'),
    meta: { acces: 'attente', titre: 'Votre inscription' },
  },

  /* --- Le compte ------------------------------------------------------ */
  {
    // Courriel et mot de passe modifiables ; nom et prénom en lecture seule —
    // ce sont ceux de la fiche rattachée, et les corriger passe par une demande.
    // `attente` et non `membre` : un compte non encore validé peut déjà corriger
    // son courriel ou son mot de passe, ce sont les siens, pas une donnée de
    // l'arbre.
    path: '/compte',
    name: 'compte',
    component: () => import('@/views/CompteView.vue'),
    meta: { acces: 'attente', titre: 'Mon compte' },
  },
  {
    // Le lien reçu à la nouvelle adresse (§ 2.5). `public` et non `anonyme` :
    // on y arrive depuis un courriel, tantôt sur un autre appareil sans session,
    // tantôt sur l'appareil où l'on vient de faire la demande — donc connecté.
    // `anonyme` renverrait ce second cas vers l'arbre sans rien confirmer, et
    // c'est le cas courant. Le jeton est passé en prop plutôt que lu par
    // `useRoute()` — la vue reste rendable hors routeur.
    path: '/compte/courriel/:jeton',
    name: 'compte.courriel',
    component: () => import('@/views/CompteCourrielView.vue'),
    props: true,
    meta: { acces: 'public', titre: 'Changement de courriel', cadre: false },
  },

  /* --- Consultation : membres validés seulement -------------------
   *
   * **Révision du 01/10/2026 (ADR 0003).** Ces écrans étaient ouverts au
   * compte en attente — « l'arbre ancien » : les défunts, et les vivants en
   * nœuds restreints, nom complet compris. Le lien d'invitation circule dans un
   * groupe de messagerie : n'importe qui pouvait s'inscrire sous un faux nom et
   * lire les noms des vivants de la famille. Un compte en attente ne voit donc
   * plus **rien** de l'arbre : tous ces écrans exigent `membre`, la garde
   * l'emmène à l'écran d'attente, et l'API lui répond `403 regime_en_attente`.
   *
   * Un seul arbre. `/tree` (l'arbre plein écran repris de zéro) et
   * `/arbre/ancien` (la disposition `ressorts`) ont été retirés le 30/09/2026 :
   * aucun écran n'y menait, et deux arbres de plus étaient deux façons de plus
   * de se perdre. Ils tombent désormais sur l'attrape-tout.
   */
  {
    // **`/arbre` sert l'arbre en rectangles de familles** (`ArbreGroupesView`, moteur
    // `arbre2/groupes.ts`) : chaque famille dans son rectangle, deux cartes entre deux
    // rectangles. Né sur `/arbre-2` le 09/10/2026, passé sur `/arbre` le même jour à la
    // demande du commanditaire. Le NOM `arbre` ne change pas — `routeParDefaut()`, le
    // menu et les notifications y renvoient.
    path: '/arbre',
    name: 'arbre',
    component: () => import('@/views/ArbreGroupesView.vue'),
    meta: { acces: 'membre', titre: 'Arbre', rechercheLocale: true },
  },
  {
    // **`/arbre-review` : le même écran que `/arbre`, redessiné** (demande du 22/10/2026) —
    // mêmes données, mêmes cartes, mêmes interactions ; seules changent la disposition des
    // cartes et les lignes (`arbre-review/disposition.ts`). Une page d'essai, à comparer
    // avec `/arbre` : aucune entrée de menu, les mêmes gardes. Le nom commence par `arbre.`,
    // l'en-tête allume donc « Arbre ».
    path: '/arbre-review',
    name: 'arbre.review',
    component: () => import('@/arbre-review/ArbreReviewView.vue'),
    meta: { acces: 'membre', titre: 'Arbre', rechercheLocale: true },
  },
  {
    // **`/arbre-2` garde l'arbre à niveaux de détail d'avant** (`HistoireView`, moteur
    // `histoire/lignees.ts`) : tout l'arbre d'un coup, sans rectangles. Aucun menu n'y
    // mène, on y va par l'adresse. Nom neuf : `arbre.blocs` était celui d'une maquette
    // retirée le 30/09, et il ne doit pas revenir.
    path: '/arbre-2',
    name: 'arbre.lignees',
    component: () => import('@/views/HistoireView.vue'),
    meta: { acces: 'membre', titre: 'Arbre', rechercheLocale: true },
  },
  {
    // `{id}` est un entier : un identifiant provisoire (`#12/p1`) ne correspond
    // à aucune route côté API et n'a pas à en avoir une ici non plus. Le détail
    // d'un nœud en attente passe par la demande qui le porte (§ 6.6).
    path: '/personnes/:id(\\d+)',
    name: 'personne.detail',
    component: () => import('@/views/PersonneView.vue'),
    meta: { acces: 'membre', titre: 'Fiche', parent: VERS_ARBRE },
  },
  {
    // « Voir tous les résultats », depuis la recherche de l'en-tête (qui s'arrête à
    // huit) : la même recherche, jusqu'au plafond de l'API — 50, non paginée (§ 5.5).
    // `?q=` porte la saisie. `membre`, comme l'arbre qu'elle lit.
    path: '/recherche',
    name: 'recherche',
    component: () => import('@/views/RechercheView.vue'),
    meta: { acces: 'membre', titre: 'Recherche', section: 'arbre', parent: VERS_ARBRE },
  },
  {
    // **Explorer** : une page, quatre onglets qui glissent — Chronologie, Carte,
    // Calendrier (refonte du 30/09/2026), puis Stats (02/10/2026). Les trois
    // premiers écrans sont devenus des routes enfants et **gardent leur nom** (`chronologie`, `carte`, `anniversaires`) :
    // les noms de route sont un contrat, on n'en renomme aucun. Les anciennes
    // adresses (`/chronologie`, `/carte`, `/anniversaires`) redirigent plus bas.
    //
    // `membre` pour les trois, comme l'arbre : ils lisent les personnes de
    // l'arbre, leurs dates et leurs lieux, et un compte en attente n'en voit
    // plus rien (ADR 0003, révision du 01/10/2026). Chaque enfant porte son
    // niveau explicitement — la garde lit le `meta` de la route résolue.
    path: '/explorer',
    name: 'explorer',
    component: () => import('@/views/ExplorerView.vue'),
    meta: { acces: 'membre', titre: 'Explorer' },
    redirect: { name: 'chronologie' },
    children: [
      {
        path: 'chronologie',
        name: 'chronologie',
        component: () => import('@/views/ChronologieView.vue'),
        meta: { acces: 'membre', titre: 'Chronologie' },
      },
      {
        path: 'carte',
        name: 'carte',
        component: () => import('@/views/CarteView.vue'),
        meta: { acces: 'membre', titre: 'Carte' },
      },
      {
        path: 'calendrier',
        name: 'anniversaires',
        component: () => import('@/views/AnniversairesView.vue'),
        meta: { acces: 'membre', titre: 'Calendrier' },
      },
      {
        // Le quatrième onglet (02/10/2026) : l'arbre en chiffres. `membre` comme
        // les trois autres : il lit des dates et des lieux. Sa section
        // « chantier » est réservée à l'administration, mais c'est le serveur qui
        // en décide (`/api/administration/stats/chantier`), pas la route.
        path: 'stats',
        name: 'stats',
        component: () => import('@/views/StatsView.vue'),
        meta: { acces: 'membre', titre: 'Statistiques' },
      },
    ],
  },
  // Les adresses d'avant Explorer : favoris et liens déjà partagés.
  { path: '/chronologie', redirect: { name: 'chronologie' }, meta: { acces: 'membre', titre: 'Chronologie' } },
  { path: '/carte', redirect: { name: 'carte' }, meta: { acces: 'membre', titre: 'Carte' } },
  { path: '/anniversaires', redirect: { name: 'anniversaires' }, meta: { acces: 'membre', titre: 'Calendrier' } },

  {
    // **Moi** : la quatrième entrée de la barre du bas. Tout ce qui est
    // personnel ou rare, en une liste : mon compte, mes demandes, événements (et Noël),
    // actes demandés, questions, administration, déconnexion. `attente` : un
    // compte en attente y trouve son inscription, son compte et la
    // déconnexion — rien qui lise l'arbre.
    path: '/moi',
    name: 'moi',
    component: () => import('@/views/MoiView.vue'),
    meta: { acces: 'attente', titre: 'Moi' },
  },

  {
    // **L'aide** : les vidéos de présentation, en cartes (`lib/videos.ts`).
    // On y entre par « Moi » (et le menu de l'avatar sur bureau) — pas par une
    // cinquième entrée de la barre du bas, qui en a quatre et pas une de plus.
    // Elle allume donc l'onglet « Moi » (`sectionDe`).
    //
    // `membre` et non `attente` : les vidéos montrent l'arbre en action — ses
    // filtres, la carte, le calendrier — c'est-à-dire des écrans qu'un compte
    // en attente ne peut pas encore ouvrir (ADR 0003, révision du 01/10/2026).
    // Celui-là a déjà vu « Bienvenue » sur l'écran de son invitation.
    //
    // `?video=<id>` ouvre directement une vidéo : c'est une REQUÊTE et non un
    // segment de chemin, l'écran reste le même — il porte seulement un lecteur
    // par-dessus. L'identifiant est un contrat, comme un nom de route.
    path: '/aide',
    name: 'aide',
    component: () => import('@/views/AideView.vue'),
    meta: { acces: 'membre', titre: 'Aide' },
  },

  {
    // **Le toucher d'une notification push.** Le NOM et le CHEMIN sont un
    // contrat avec le service worker (`public/sw.js`), qui ouvre
    // `/notification/<id>?r=<linkRoute>&p=<linkParams JSON>` : les notifications
    // déjà affichées sur les téléphones portent cette adresse, et une
    // notification ne se réécrit pas. On ne renomme ni l'un ni l'autre.
    //
    // Écran de passage : il marque lue puis remplace l'adresse par le lien de
    // la notification (voir la vue). Sans cadre — il ne s'affiche qu'un
    // instant, et un en-tête qui clignote avant l'écran voulu se verrait.
    // `attente` : toute session, même en attente de validation, reçoit des
    // notifications (« compte à valider », « invitation utilisée »…).
    path: '/notification/:id(\\d+)',
    name: 'notification.ouvrir',
    component: () => import('@/views/OuvrirNotificationView.vue'),
    meta: { acces: 'attente', titre: 'Notification', cadre: false },
  },

  /* --- Contribution : rattachement validé exigé (§ 12.1) ---------- */
  {
    // **La page « Questions »** (ADR 0008).
    //
    // `membre` et non `attente` : une question nomme une personne de l'arbre,
    // et un compte en attente n'en lit aucune (ADR 0003, révision du
    // 01/10/2026) — l'API lui répond `403 regime_en_attente`.
    path: '/questions',
    name: 'questions',
    component: () => import('@/views/QuestionsView.vue'),
    meta: { acces: 'membre', titre: 'Questions' },
  },
  {
    // **Les anciennes adresses des tirages au sort** (ADR 0009). Le module
    // `Tirage` d'heritage a été retiré : les tirages de la famille sont ceux du
    // site de Noël, montrés dans « Événements ». Des favoris et des liens
    // partagés mènent encore à `/tirages`, `/tirages/nouveau` ou `/tirages/12` :
    // ils arrivent sur la liste, filtrée sur Noël, plutôt que sur « page
    // introuvable ». Sans nom : plus rien ne doit y renvoyer. Les notifications
    // `tirage_effectue` déjà écrites citaient `tirage.detail` — un nom inconnu
    // n'est simplement plus un lien (`lienDeNotification`).
    path: '/tirages/:reste(.*)*',
    redirect: { name: 'evenements', query: { type: 'noel' } },
    meta: { acces: 'membre', titre: 'Événements' },
  },
  {
    // Nom cité par les notifications `noel_tirage_*` : le tirage de Noël vit sur
    // son propre site (noel2026), la route ne fait qu'y renvoyer.
    path: '/noel/:code',
    name: 'noel.tirage',
    component: () => import('@/views/IntrouvableView.vue'),
    beforeEnter: (vers) => {
      window.location.assign(`${SITE_DE_NOEL}/t/${encodeURIComponent(String(vers.params.code))}`)
      return false
    },
    meta: { acces: 'public', titre: 'Tirage de Noël' },
  },
  {
    // **Les événements** — rassemblements (« Cousinade été 2027 ») et tirages
    // de Noël (ceux du site noel2026), dans une seule liste depuis le
    // 02/10/2026 (ADR 0014, addendum). `?type=rassemblement` ou `?type=noel`
    // la restreint (l'ancien `?type=tirage` vaut `noel`). `membre` : on y est
    // invité par la personne de l'arbre à laquelle on est rattaché, et l'API
    // refuse tout compte en attente. Ils allument l'onglet « Moi »
    // (`sectionDe`), d'où on y entre.
    path: '/evenements',
    name: 'evenements',
    component: () => import('@/views/EvenementsView.vue'),
    meta: { acces: 'membre', titre: 'Événements' },
  },
  {
    // Déclarée avant le détail par lisibilité seulement : `:id(\d+)` ne
    // reconnaît pas « nouveau ». Sans `?type=`, l'écran demande d'abord ce
    // qu'on crée — un rassemblement, ou un tirage de Noël (qui se crée sur le
    // site de Noël) —, puis montre le formulaire.
    path: '/evenements/nouveau',
    name: 'evenement.nouveau',
    component: () => import('@/views/EvenementFormulaireView.vue'),
    meta: { acces: 'membre', titre: 'Nouvel événement', parent: { nom: 'evenements', libelle: 'Événements' } },
  },
  {
    // Nom cité par les notifications `evenement_invitation`, `evenement_relance`
    // et `evenement_annule` : on ne le renomme pas. Ouvrir cette page pose la
    // première vue de l'invité (côté serveur).
    path: '/evenements/:id(\\d+)',
    name: 'evenement.detail',
    component: () => import('@/views/EvenementView.vue'),
    meta: { acces: 'membre', titre: 'Événement', parent: { nom: 'evenements', libelle: 'Événements' } },
  },
  {
    path: '/evenements/:id(\\d+)/modifier',
    name: 'evenement.modifier',
    component: () => import('@/views/EvenementFormulaireView.vue'),
    meta: { acces: 'membre', titre: 'Modifier l’événement', parent: { nom: 'evenements', libelle: 'Événements' } },
  },
  {
    // **Le lien personnel d'un invité sans compte** : il lit l'annonce et
    // répond, sans session. `public` (le lien s'ouvre aussi sur le téléphone d'un membre
    // connecté), `cadre: false`, chemin court (`/e/`) qui se recopie dans un
    // SMS, clé non filtrée ici — une forme invalide tombe sur « ce lien n'est
    // plus valable », pas sur « page introuvable ».
    path: '/e/:cle',
    name: 'evenement.lien',
    component: () => import('@/views/EvenementLienView.vue'),
    props: true,
    meta: { acces: 'public', titre: 'Invitation', cadre: false },
  },
  {
    // Les actes demandés à une mairie ou à des archives, et leur suivi (module
    // `Demarche`). `membre` : l'API refuse tout compte en attente, et ne rend
    // que les démarches dont le lecteur voit toutes les personnes.
    path: '/actes-demandes',
    name: 'demarches',
    component: () => import('@/views/DemarchesView.vue'),
    meta: { acces: 'membre', titre: 'Actes demandés' },
  },
  {
    // Nom cité par le contrat (§ 8, `linkRoute: "demande.detail"`). La route
    // existe dès maintenant pour que les notifications aient une cible ; l'écran
    // revient à l'agent des propositions.
    path: '/demandes/:id(\\d+)',
    name: 'demande.detail',
    component: () => import('@/views/DemandeView.vue'),
    meta: { acces: 'membre', titre: 'Demande', parent: { nom: 'demandes.miennes', libelle: 'Mes demandes' } },
  },
  {
    // **La liste de ses propres demandes.**
    //
    // Elle manquait, et son absence rendait inutilisable une capacité entière :
    // le serveur refuse avec un motif obligatoire, le stocke, le sert — et
    // l'auteur n'avait aucun chemin pour l'atteindre. `demande.detail` existait
    // bien, mais on n'y arrive qu'en connaissant l'identifiant, c'est-à-dire en
    // tapant `/demandes/6` dans la barre d'adresse.
    //
    // Déclarée **avant** rien de particulier : `/mes-demandes` ne peut entrer en
    // collision avec `/demandes/:id(\d+)`, qui exige le préfixe `/demandes/` et
    // un entier. Un chemin propre plutôt qu'un `/demandes` nu, parce que
    // `/demandes` seul se lirait comme « toutes les demandes » — ce que voit un
    // administrateur, et qui est un autre écran.
    //
    // `membre` et non `attente` : un compte dont le rattachement n'est pas
    // validé n'a, par construction, aucune demande — l'API lui refuse le dépôt
    // (§ 12.2). Lui ouvrir une liste toujours vide serait lui promettre un
    // écran qui ne dira jamais rien.
    path: '/mes-demandes',
    name: 'demandes.miennes',
    component: () => import('@/views/MesDemandesView.vue'),
    meta: { acces: 'membre', titre: 'Mes demandes' },
  },
  {
    // Les deux formulaires de proposition. `membre` et non `attente` : « le
    // défaut fermé vaut aussi en écriture » — un compte dont le rattachement
    // n'est pas validé ne dépose pas, et l'API le lui redirait en
    // `403 regime_en_attente` (§ 12.2). La garde l'emmène vers l'écran qui
    // explique l'attente, pas vers la connexion : il est bien connecté.
    //
    // `?refaire=<id>` pré-remplit le formulaire depuis une demande caduque ou
    // refusée (§ 6.10) — « une caducité sans ce bouton est une contribution
    // perdue ». Le paramètre est une **requête** et non un segment de chemin :
    // l'écran reste le même, c'est son contenu initial qui change.
    path: '/personnes/:id(\\d+)/proposer',
    name: 'proposition.modification',
    component: () => import('@/views/PropositionModificationView.vue'),
    meta: { acces: 'membre', titre: 'Proposer une modification', parent: { nom: 'arbre', libelle: 'Retour' } },
  },
  {
    // **Demander la suppression d'une fiche** (§ 6.4 quater).
    //
    // Écran séparé et non panneau de la fiche, pour la même raison que
    // `proposition.modification` : il **relit la cible à son ouverture**, et ce
    // qu'il affiche — l'inventaire de ce que la suppression emporte — doit être
    // celui d'aujourd'hui, pas celui du chargement de la fiche, qui peut dater
    // de vingt minutes. Un panneau incrusté hériterait de cet état périmé et
    // annoncerait « 0 note » sur une fiche qui vient d'en recevoir une.
    //
    // `membre` comme les deux autres formulaires : un compte en attente de
    // rattachement ne dépose pas, et l'API le lui redirait en
    // `403 regime_en_attente`.
    path: '/personnes/:id(\\d+)/supprimer',
    name: 'proposition.suppression',
    component: () => import('@/views/PropositionSuppressionView.vue'),
    meta: { acces: 'membre', titre: 'Demander la suppression', parent: { nom: 'arbre', libelle: 'Retour' } },
  },
  {
    // L'ancienne adresse de l'écran des liens. La parenté se propose désormais
    // dans le formulaire de modification ; le nom reste pour les liens déjà
    // partagés et les favoris.
    path: '/personnes/:id(\\d+)/liens',
    name: 'proposition.liens',
    redirect: (to) => ({ name: 'proposition.modification', params: to.params, hash: '#parente' }),
  },
  {
    // Pas de `:id` : la personne n'existe pas encore, et n'aura d'identifiant
    // qu'à la validation. Elle vit jusque-là sous une référence provisoire
    // propre à la demande (§ 6.4 du dossier, § 9.5).
    path: '/personnes/proposer',
    name: 'proposition.creation',
    component: () => import('@/views/PropositionCreationView.vue'),
    meta: { acces: 'membre', titre: 'Assistant', parent: VERS_ARBRE },
  },

  /* --- Administration -------------------------------------------- */
  {
    // Le **suivi en direct** : un tableau de bord plein cadre, sombre, à lire de
    // loin — santé du service, file « À traiter », journal d'audit, chantier.
    // Lecture seule, sans route d'API nouvelle (`lib/api/suivi.ts`).
    //
    // Route de premier niveau, et non enfant d'`administration` : l'enfant
    // hériterait du cadre de 1 080 px d'`AdministrationView`, et l'écran veut
    // toute la largeur. `cadre: false` retire l'en-tête et la barre du bas ;
    // l'écran porte son propre en-tête et sa sortie. Le nom commence par
    // `administration.` : la section, donc le mode admin, s'en déduit.
    path: '/administration/suivi',
    name: 'administration.suivi',
    component: () => import('@/views/administration/SuiviView.vue'),
    meta: { acces: 'administration', titre: 'Suivi en direct', cadre: false },
  },
  {
    // Le **Monde** : le même suivi en maquette 3D — une île, un objet par
    // partie du système, animé par les données réelles. Mêmes lectures que le
    // suivi, aucune route d'API nouvelle ; Three.js n'est chargé que par cet
    // écran. Plein cadre, comme le suivi, et pour la même raison.
    path: '/administration/monde',
    name: 'administration.monde',
    component: () => import('@/views/administration/MondeView.vue'),
    meta: { acces: 'administration', titre: 'Monde', cadre: false },
  },
  {
    // Le **mode administration** (handoff « mode Administration », option 1c).
    // Toute route `administration.*` y est : l'en-tête passe en fond accent, la
    // navigation montre À traiter · Utilisateurs · Invitations · Sources
    // (`sectionDe` rend `administration`). Le mode se déduit de la route : un
    // lien profond ou un favori y entre, le bouton retour en sort.
    //
    // Les sous-écrans sont des **routes enfants**, chacune avec son `meta`
    // (`app/README.md`, « Ajouter une route »). Chacune a donc son chemin, donc
    // son lien profond.
    //
    // `redirect` est résolu **avant** la garde globale, qui verra donc le
    // `meta.acces` de l'enfant. Chaque enfant porte le sien explicitement plutôt
    // que de compter sur la fusion avec le parent : une route dont personne ne
    // sait qui peut l'ouvrir est exactement ce que `RouteMeta` interdit.
    path: '/administration',
    name: 'administration',
    component: () => import('@/views/AdministrationView.vue'),
    meta: { acces: 'administration', titre: 'Administration' },
    redirect: { name: 'administration.a-traiter' },
    children: [
      {
        // La file unique : demandes en attente, inscriptions à rattacher,
        // demandes RGPD en attente. `?type=tout|demande|rattachement|rgpd`
        // filtre, `?sel=<clé>` ouvre un élément (`d:42`, `r:7`, `g:3`).
        path: 'a-traiter',
        name: 'administration.a-traiter',
        component: () => import('@/views/administration/ATraiterView.vue'),
        meta: { acces: 'administration', titre: 'À traiter' },
      },
      {
        // **Conservée** : les noms de route sont un contrat. Elle garde la file
        // complète, filtres d'état compris, pour consulter les demandes
        // **traitées** — validées, refusées, caduques. Les demandes en attente
        // se décident dans « À traiter ».
        path: 'demandes',
        name: 'administration.demandes',
        component: () => import('@/views/administration/FileDemandesView.vue'),
        meta: { acces: 'administration', titre: 'Historique des demandes' },
      },
      {
        // Les inscriptions sans fiche (ADR 0013). Le nom est un contrat : la
        // notification `compte_en_attente` d'une inscription à rattacher y mène
        // (`linkRoute: 'administration.rattachements'`). Elles se décident
        // désormais dans « À traiter ».
        path: 'rattachements',
        name: 'administration.rattachements',
        redirect: { name: 'administration.a-traiter', query: { type: 'rattachement' } },
        meta: { acces: 'administration', titre: 'À rattacher' },
      },
      {
        path: 'invitations',
        name: 'administration.invitations',
        component: () => import('@/views/administration/InvitationsView.vue'),
        meta: { acces: 'administration', titre: 'Invitations' },
      },
      {
        path: 'utilisateurs',
        name: 'administration.utilisateurs',
        component: () => import('@/views/administration/UtilisateursView.vue'),
        meta: { acces: 'administration', titre: 'Utilisateurs' },
      },
      {
        // Les notices des fonds que les citations désignent (ADR 0012).
        path: 'sources',
        name: 'administration.sources',
        component: () => import('@/views/administration/SourcesView.vue'),
        meta: { acces: 'administration', titre: 'Sources' },
      },
      {
        // Les demandes RGPD des membres (ADR 0016, révision du 3 octobre
        // 2026). Le nom est un contrat : la notification `demande_rgpd` y mène
        // (`linkRoute: 'administration.rgpd'`). Elles se décident désormais
        // dans « À traiter », filtrée sur le RGPD.
        path: 'rgpd',
        name: 'administration.rgpd',
        redirect: { name: 'administration.a-traiter', query: { type: 'rgpd' } },
        meta: { acces: 'administration', titre: 'Demandes RGPD' },
      },
    ],
  },

  /* --- Sortie ------------------------------------------------------ */
  {
    /**
     * `/deconnexion` rendait « Page introuvable ».
     *
     * Le bouton « Se déconnecter » de l'en-tête, lui, a toujours fonctionné : il
     * appelle `deconnecter()` puis pousse vers `connexion`, sans jamais passer par
     * une URL. Le chemin `/deconnexion` n'existait donc nulle part — et c'est
     * pourtant celui qu'on tape, qu'on met en favori, et qu'on donne au téléphone
     * de quelqu'un pour lui faire fermer sa session. L'écran d'erreur générique
     * était la seule réponse, sur le geste le plus attendu d'une application
     * familiale partagée.
     *
     * ### Pourquoi un `beforeEnter` et pas un `redirect`
     *
     * Une `redirect` de déclaration est résolue **pendant la résolution de la
     * route**, avant toute garde : les `beforeEnter` d'un enregistrement qui
     * redirige ne sont jamais appelés. On atterrirait sur la connexion **sans
     * s'être déconnecté** — une route qui a l'air de marcher et qui laisse la
     * session ouverte, c'est-à-dire pire que la page introuvable qu'elle remplace.
     * La déconnexion doit avoir lieu, donc elle est dans une garde.
     *
     * ### Pourquoi `acces: 'public'` et surtout pas `'anonyme'`
     *
     * `'anonyme'` veut dire « réservé à qui n'a pas de session ». La garde globale
     * s'exécute **avant** ce `beforeEnter` et lit le régime d'**avant** la
     * déconnexion : un membre connecté serait donc renvoyé vers l'arbre par
     * `routeParDefaut()` sans jamais être déconnecté. C'est exactement la route
     * qu'un visiteur connecté doit pouvoir ouvrir ; `'public'` est le seul niveau
     * qui laisse passer les deux régimes.
     *
     * ### Le composant n'est pas rendu
     *
     * Les composants asynchrones sont résolus **après** les gardes d'entrée : ce
     * `import()` n'est jamais exécuté tant que la garde redirige. Il est déclaré
     * parce que `vue-router` exige un composant, et c'est `ConnexionView` plutôt
     * qu'un écran vide : si la garde cessait un jour de rediriger, on verrait la
     * connexion — la destination voulue — et non une page blanche.
     */
    path: '/deconnexion',
    name: 'deconnexion',
    component: () => import('@/views/ConnexionView.vue'),
    meta: { acces: 'public', titre: 'Déconnexion', cadre: false },
    beforeEnter: async () => {
      // Import différé : ce module doit rester des **données pures**, importables
      // par un test sans réveiller la chaîne qui mène au client d'API. Il est
      // chargé au premier passage sur cette route, pas au chargement de la table.
      const { deconnecter, useSession } = await import('@/composables/useSession')

      // Sans session, il n'y a rien à fermer. L'appel se solderait par une `401`,
      // que le rattrapage global traite comme une session perdue — donc une
      // seconde redirection vers la connexion, concurrente de celle-ci, qui
      // emporterait un `?suite=` parasite.
      if (useSession().connecte.value) {
        await deconnecter()
      }

      return { name: 'connexion' }
    },
  },

  /* --- Impasses --------------------------------------------------- */
  {
    path: '/acces-refuse',
    name: 'acces-refuse',
    component: () => import('@/views/AccesRefuseView.vue'),
    meta: { acces: 'public', titre: 'Accès refusé' },
  },
  {
    path: '/:chemin(.*)*',
    name: 'introuvable',
    component: () => import('@/views/IntrouvableView.vue'),
    meta: { acces: 'public', titre: 'Page introuvable' },
  },
]
