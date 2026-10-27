---
title: "Atlas"
slug: "atlas"
type: "project"
date: 2026-10-26
subtitle: "Une plateforme .NET qui unifie les données publiques françaises des entreprises et de la propriété industrielle, verrouillée par les tests"
seo_description: "Atlas agrège les données publiques françaises des entreprises et de la propriété industrielle derrière une API .NET en architecture hexagonale testée."
featured: false
status: "en-cours"
role: "Conception, architecture, développement et tests, en solo"
period_start: 2026-05-26
period_end: ""
repo_url: "https://github.com/morgan-voltz/atlas"
demo_url: ""
client: ""
is_fil_rouge: false
tags: [csharp, dotnet, architecture-hexagonale, cqrs, entity-framework-core, postgresql, xunit, securite, uno-platform]
skills: ["4.0.2/E06", "4.0.2/E07", "4.0.2/E08", "4.0.2/E09"]
---

Atlas est une plateforme .NET que je développe seul depuis fin mai 2026, pour agréger les données publiques françaises sur les entreprises (registre national RNE) et sur la propriété industrielle (marques, brevets et dessins et modèles via l'INPI), avec un moteur de veille qui ajoute par-dessus les publications légales et l'actualité sectorielle. « Atlas » est un nom de code : le nom définitif sera choisi au moment du lancement commercial, pas avant.

Le backend est complet sur ce premier périmètre — comptes avec double authentification, recherche d'entreprise et de titres de propriété industrielle, favoris, veille avec alertes — et un premier client web est livré. Le modèle économique n'est pas encore tranché entre un SaaS à l'abonnement et une licence annuelle, mais une couche Premium est déjà isolée pour accueillir ce choix sans réécrire le cœur du produit.

## Le problème

Les données publiques françaises sur les entreprises et sur la propriété industrielle existent, mais éclatées entre des portails hétérogènes : le registre national des entreprises d'un côté, les bases marques et brevets de l'INPI de l'autre, les publications légales du BODACC ailleurs encore. Les agrégateurs commerciaux qui proposent une vue unifiée restent fermés et payants au-delà d'un usage très basique. Sept profils professionnels vivent cette fragmentation au quotidien : avocats et juristes, cabinets de propriété industrielle, équipes compliance et KYC, experts-comptables, investisseurs en fusion-acquisition, veille concurrentielle, développeurs qui voudraient consommer ces données sans réinventer l'intégration.

Le problème produit est donc de bâtir une porte d'entrée unique vers ces sources, avec une recherche d'entreprise, un accès aux titres de propriété industrielle et une veille qui alerte plutôt que d'obliger à revérifier chaque source à la main. Le problème technique, lui, est d'ordre méthodologique : comment construire seul, sur plusieurs mois, un système dont le public visé — juristes, compliance — ne tolère pas l'à-peu-près, sans avoir la revue de pairs d'une équipe pour rattraper les dérives ?

## Les décisions techniques

**L'architecture hexagonale est vérifiée par un projet de tests, pas laissée à la discipline.** Les dépendances entre les 18 projets de la solution suivent un sens unique — `Atlas.Shared` puis `Atlas.Domain` puis `Atlas.Application` (et sa variante `.Premium`) puis les huit projets `Atlas.Infrastructure.*` puis `Atlas.Api` — et un projet dédié, `Atlas.Architecture.Tests`, fait échouer la CI dès qu'une couche référence ce qu'elle ne doit pas, y compris l'interdiction pour un client de parler à l'infrastructure plutôt qu'à l'API HTTP. Sur un projet sans second regard humain, ce test remplace la revue de code que je n'ai pas.

**Le domaine refuse l'implicite : les erreurs et les identifiants sont des valeurs, jamais des surprises.** Chaque cas d'usage passe par un type `Result<T>` : un échec de validation ou une règle métier violée se lit dans la valeur de retour du gestionnaire, jamais dans une exception, et les validations tournent avant lui par un comportement MediatR dédié. Un SIREN suit la même logique : jamais un `string` nu, mais un value object qui vérifie son format par l'algorithme de Luhn, une valeur mal formée étant rejetée à la frontière du domaine.

**La persistance passe par un ORM que la CI exerce pour de vrai, le déploiement par une machine qu'on peut reconstruire en une commande.** Les données vivent dans PostgreSQL via Entity Framework Core ; la CI applique les migrations contre une base neuve à chaque exécution nocturne, et Hangfire y planifie les tâches de fond. La préproduction tient sur un VPS unique en docker-compose — API, base, jobs et fichiers statiques du client web, derrière un reverse proxy Caddy qui gère seul son certificat TLS, domaine unique pour éviter le CORS.

**La sécurité est pensée pour un public qui ne pardonne pas l'amateurisme.** Mots de passe hachés en Argon2id, jetons JWT courts signés en RS256 avec un refresh token rotatif conservé côté serveur, double authentification TOTP, et identifiants INPI chiffrés en AES-256-GCM au repos, déchiffrés le temps d'une requête, jamais journalisés — interdit absolu écrit dans la documentation.

**Chaque décision structurante est datée et ne s'efface jamais, même quand elle change d'avis.** 29 ADR tracent les choix d'architecture, y compris les revirements : la stratégie client est passée de Blazor WebAssembly à MAUI (mobile) et Avalonia (desktop), puis à une interface unique en Uno Platform ; chaque changement porte l'ADR précédent marqué « remplacé » plutôt que réécrit. Le domaine, lui, n'a jamais eu à changer une ligne pour absorber ces trois stratégies, parce que les clients ne lui parlent que par l'API HTTP.

**La CI vérifie le projet contre le monde réel, pas seulement contre lui-même.** En plus des tests unitaires et des tests d'architecture joués à chaque push, un workflow nocturne démarre l'API et rejoue des requêtes contre l'API INPI de production via des collections Bruno, pour détecter une rupture de contrat côté fournisseur avant qu'un utilisateur ne la découvre. Resté rouge chaque nuit depuis sa création fin mai, il n'a retrouvé un vert stable que le 25 septembre 2026 — et sa première exécution utile a justement servi à repérer un changement d'authentification côté INPI. Le mécanisme est encore jeune, mais le principe tient : confronter le code à une vraie API tierce plutôt qu'à des mocks.

## Ce que j'en ai appris

Qu'une architecture où le domaine ne dépend de rien d'autre que lui-même absorbe un changement radical de stratégie client sans obliger à toucher au cœur du produit : les trois technologies de client essayées en quatre mois n'ont jamais demandé de retoucher `Atlas.Domain` ni `Atlas.Application`. Que la rigueur a un coût en vitesse — tenir des tests d'architecture, une CI qui interroge une vraie API tierce la nuit, 29 ADR à jour — qui se voit forcément dans le rythme des livraisons, mais que c'est le prix à payer pour un public qui ne tolère pas l'approximatif. Et que documenter une décision prise, même quand elle n'est pas encore exécutée partout, évite d'avoir à la reconstituer de mémoire des mois plus tard.

Une précision, parce que le dépôt est public et que `git log` la rend vérifiable : je ne tape pas chaque ligne seul au sens strict. Je pratique le développement agentique avec Claude comme pair de programmation, sous des contraintes que je pose moi-même — j'ai un outil dédié à ce mode de travail dans le dépôt, `Atlas.DevEye`. La majorité des commits de la branche principale créditent Claude comme co-auteur ; je reste le seul contributeur humain, l'auteur des décisions d'architecture et le relecteur final. Dire « solo » sans dire cela serait incomplet.

Ce que je ferais autrement : la trajectoire du projet a glissé d'un modèle pensé open source vers un modèle commercial propriétaire en cours de route, sans que je mette à jour la licence et le README à la même vitesse — le fichier `LICENSE` porte lui-même la note « à remplacer avant publication publique », alors qu'elle l'est déjà. Un décalage à corriger avant toute autre visibilité externe.

## En chiffres

- 18 projets de production dans `src/` et 11 projets de tests dans `tests/` (29 au total), architecture hexagonale en couches
- Environ 47 000 lignes de code applicatif C#, environ 11 000 lignes de tests
- 472 tests xUnit (453 `[Fact]` + 19 `[Theory]`), plus un projet dédié aux règles d'architecture
- 74 endpoints exposés par l'API (Minimal APIs)
- 29 ADR, 76 fiches de features
- 221 commits sur la branche principale, fin mai à fin septembre 2026 ; 151 (≈68 %) créditent Claude comme co-auteur, et je reste l'unique contributeur humain du dépôt

## Liens

- Dépôt : public, sur GitHub (`github.com/morgan-voltz/atlas`). Pas encore de release taguée.
- Démo : aucune démo publique pour l'instant ; le site de documentation utilisateur prévu n'est pas encore déployé.
- Documentation : 29 ADR et les fiches de features numérotées vivent dans le dépôt (`docs/`).
