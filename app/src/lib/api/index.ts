/**
 * Point d'entrée unique du client d'API.
 *
 * Les écrans importent depuis `@/lib/api`, jamais depuis un fichier interne :
 * cela laisse la liberté de réorganiser le dossier sans toucher à trente vues.
 */

export * from './types'
export {
  ErreurApi,
  analyserErreur,
  conduiteATenir,
  estErreurApi,
  estRejouable,
  libelleDeConduite,
  messageDErreur,
  CODE_AUTHENTIFICATION_REQUISE,
  CODE_CIBLE_DISPARUE,
  CODE_DEMANDE_CADUQUE,
  CODE_REGIME_EN_ATTENTE,
  CODE_RESEAU,
  CODE_VERROU_CONCURRENT,
  CODE_VUE_COMME_ACTIVE,
} from './erreurs'
export type { AnalyseErreur, ConduiteATenir } from './erreurs'
export { appelBrut, construireUrl, requeteApi, surSessionPerdue } from './client'
export type { MethodeHttp, OptionsRequete, ReponseBrute } from './client'
export { etagConnu, oublierEtag, requeteRevalidee } from './revalidation'
export type { ResultatRevalide } from './revalidation'
export * from './routes'
/* Module `Tree` : fiche, famille, recherche, détail d'un nœud en attente
   (§ 5.3, § 5.4, § 5.5, § 6.6). `routes.ts` ne porte que le graphe. */
export * from './arbre'
export * from './inscription'
/* Module `Timeline` : la vue chronologique et sa préférence (§ 9). */
export * from './chronologie'
/* Module `Almanach` : la carte des lieux et les anniversaires (§ 19, § 20). Une
   seule charge par écran — les filtres et les mois sont des réglages de rendu. */
export * from './evenements'
/* Module `Proposal` : la file, le diff, valider et refuser (§ 6). */
export * from './propositions'
/* Module `Media` : portraits et preuves — téléverser, lister, servir, supprimer
   (§ 7). Aucune URL de fichier ne s'y fabrique : le serveur écrit `url` et
   `vignetteUrl` dans la charge du média, et c'est ce qui applique le cercle. */
export * from './medias'
/* Module `Question` : la page « Questions » — les trous de l'arbre que ce
   compte-là pourrait combler, et les trois façons d'y répondre (ADR 0008).
   Le `GET` de cette page ÉCRIT côté serveur : lire l'en-tête avant de
   l'appeler ailleurs qu'au montage de son écran. */
export * from './questions'
/* Modules `Core` et `Membership`, côté administrateur : invitations,
   utilisateurs, bascule « voir comme un membre » (§ 3, § 10). */
export * from './administration'
/* L'assistant de proposition : une analyse (texte + documents) rend des demandes
   prêtes à déposer ; le dépôt passe par l'analyse gardée côté serveur, jamais
   par `creerDemande()` — voir l'en-tête du module. */
export * from './assistant'
/* « Poser une question » : des conversations suivies avec l'assistant, en
   lecture seule sur l'arbre, gardées côté serveur par compte. */
export * from './conversations'
/* Module `Tirage` : les tirages au sort récurrents — « Noël côté papa ». Le
   serveur ne rend au lecteur que ses propres attributions (ADR 0009). */
export * from './tirages'
/* Module `Evenement` : les événements de famille — « Cousinade été 2027 » —, qui
   vient, et pour l'organisateur qui a vu sans répondre. Le fichier ne s'appelle
   pas `evenements.ts` : ce nom-là est celui de l'Almanach. */
export * from './evenementsFamiliaux'
/* Module `Demarche` : les actes demandés à une mairie ou à des archives, et
   leur suivi — chaque message avec son texte entier. Le serveur ne rend que
   les démarches dont le lecteur voit toutes les personnes. */
export * from './demarches'
/* Les notifications sur le téléphone (Web Push) : la clé publique du serveur et
   l'abonnement de cet appareil. La logique d'abonnement est dans
   `composables/usePush.ts`. */
export * from './push'
/* Les clés d'accès (passkeys) : enregistrer, lister, renommer, supprimer, et la
   connexion sans mot de passe. La conversion JSON ↔ `ArrayBuffer` est dans
   `lib/cleDAcces.ts`, l'enchaînement dans `composables/useClesDAcces.ts`. */
export * from './clesDAcces'
/* Module `Statistiques` : Explorer › Stats. Tout est calculé côté serveur, après
   le cercle de visibilité ; le chantier a sa propre route, d'administration. */
export * from './statistiques'
