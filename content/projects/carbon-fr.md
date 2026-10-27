---
title: "carbon-fr"
slug: "carbon-fr"
type: "project"
date: 2026-10-26
subtitle: "Une API qui mesure l'intensité carbone de l'électricité française sans jamais trancher les débats qu'elle informe"
seo_description: "carbon-fr est une API open source en Rust qui mesure l'intensité carbone de l'électricité française, référencée comme réutilisation sur data.gouv.fr."
featured: false
status: "stable"
role: "Conception produit, architecture, développement backend et frontend, infrastructure et gouvernance, en solo"
period_start: ""
period_end: ""
repo_url: "https://github.com/Kovelt/carbon-fr"
demo_url: "https://carbon-fr.kovelt.fr/dashboard"
client: ""
is_fil_rouge: false
tags: [rust, postgresql, architecture-hexagonale, typescript, axum, machine-learning, open-data, docker, self-hosted, openapi]
skills: ["4.0.1/FR-09", "4.0.2/E09"]
---

carbon-fr est une API civique open source qui expose en temps réel l'intensité carbone de l'électricité française, à l'échelle nationale et pour les douze régions, avec un tableau de bord public. C'est l'équivalent français du carbonintensity.org.uk britannique, un service qui n'existait pas en France alors que les données ouvertes nécessaires — RTE, ADEME, ENTSO-E — y sont parmi les meilleures d'Europe. Double licence MIT / Apache-2.0, réutilisation référencée sur data.gouv.fr.

Au 26 octobre 2026, le projet est en version 0.11.0, stable : 30 endpoints documentés, un tableau de bord public, un SDK TypeScript publié. Je le conçois, le développe et l'héberge seul, et je continue à le faire évoluer.

## Le problème

Le Royaume-Uni dispose depuis plusieurs années de carbonintensity.org.uk, une API nationale d'intensité carbone devenue l'infrastructure de référence du *carbon-aware computing* : décaler les calculs informatiques vers les heures où l'électricité est la moins carbonée. La France n'avait rien d'équivalent. RTE publie une donnée brute excellente, éCO2mix, via la plateforme ODRÉ — mais aucune API civique stable, documentée et régionalisée ne la rendait exploitable par des développeurs ou des intégrateurs.

Les cas d'usage, eux, se multipliaient sans réponse. Le mouvement du numérique responsable (Boavizta, GreenIT.fr) veut ordonnancer ses calculs sur les créneaux bas-carbone. La filière hydrogène a besoin de savoir, heure par heure, si l'électricité du réseau rend un électrolyseur éligible aux cadres réglementaires RFNBO ou bas-carbone. Plus largement, personne ne pouvait accéder simplement à la décomposition du prix de l'électricité ni à des références de coût de production correctement sourcées.

Le problème n'était donc pas l'absence de données, mais l'absence d'un instrument : des données dispersées entre plusieurs producteurs, révisées dans le temps, méthodologiquement ambiguës — émissions directes ou analyse de cycle de vie ? — et jamais servies sous une forme directement consommable. C'est ce vide que carbon-fr comble, avec une contrainte fixée dès le premier commit : rester un instrument de mesure strictement neutre, qui expose la donnée sans jamais trancher les débats énergétiques qu'elle informe.

## Les décisions techniques

**L'architecture est hexagonale, pour isoler le calcul du carbone de sources qui changent sans prévenir.** Le domaine (`core`, `eligibility`) est écrit en types purs, sans dépendance externe ; les adaptateurs (ODRÉ, ENTSO-E, météo, prévision, GBDT, Postgres, axum, webhooks) se branchent sur des ports. Les sources de données publiques changent de format, de fréquence et de disponibilité sans préavis ; l'hexagonal rend chaque source remplaçable et testable isolément. Preuve : un workspace Cargo de dix crates et un seul binaire serveur.

**Chaque valeur porte un `vintage`, parce que RTE révise ses propres chiffres.** Une même heure passe par trois états successifs — temps réel, consolidé, définitif — avec des valeurs différentes à chaque étape ; une API naïve servirait silencieusement des chiffres contradictoires selon le moment de la requête. L'ingestion repose sur un upsert à clé composite (région, timestamp, méthodologie) complété d'un champ `vintage`. Conséquence directe : pas d'ORM, persistance en SQL natif via sqlx (requêtes vérifiées à la compilation), pour garder un contrôle fin sur ces upserts.

**Les seuils réglementaires sont des données de configuration, jamais des constantes dans le code.** Les critères d'éligibilité (part renouvelable ≥ 90 % de l'article 4, exception de surplus à 20 €/MWh, seuil bas-carbone à 64 g) viennent de textes européens qui évoluent ; ils sont versionnés et paramétrés plutôt que codés en dur, pour que l'API suive les textes sans réécriture.

**La granularité juridique n'est pas la granularité physique du calcul.** L'éligibilité réglementaire s'apprécie à l'échelle de la zone de dépôt nationale, alors que carbon-fr calcule au niveau régional : l'API ne présente donc jamais une région « éligible » comme si cela avait un sens réglementaire propre — ce n'est qu'une maille d'affichage plus fine que celle qui compte juridiquement.

**Aucune version du modèle de prévision ne part en production sans franchir un gate de backtest formel.** RTE ne prévoit pas l'avenir : je modélise la prévision moi-même, avec un modèle climatologique de référence et un modèle de gradient boosting (GBDT) nourri par les données météo d'Open-Meteo. Cette prévision alimente `/schedule` et la fenêtre la plus verte ; la faire évoluer sans contrôle reviendrait à fonder des décisions d'ordonnancement sur un modèle dégradé. Preuve : gates de backtest actés par ADR-0012 et ADR-0028.

**La neutralité se vérifie par une revue adversariale, elle ne se déclare pas.** Je sers systématiquement les dualités plutôt que de trancher à la place de l'utilisateur : deux méthodologies carbone en parallèle (`rte-direct`, `acv-ademe`), deux référentiels d'éligibilité hydrogène (RFNBO, bas-carbone), des LCOE en fourchettes multi-sources. Les couches les plus sensibles — comparaison LCOE (ADR-0024), overlays d'éligibilité (ADR-0026) — ont chacune une revue de neutralité dédiée et versionnée. Même exigence pour le code : la v0.7.0 est une release d'audit issue d'une revue adversariale complète du workspace, dix-neuf défauts confirmés et corrigés. Et quand la donnée dérange — la Bretagne affiche 329 g de production quand le national est à 32 g —, la réponse est de la documenter, pas de la lisser : la région produit peu, et ce qu'elle produit est plus carboné, ce qui ne dit rien de ce qu'elle consomme (ADR-0008) ; la série de consommation `acv-ademe@2`, corrigée des échanges transfrontaliers, complète l'image (ADR-0010).

## Ce que j'en ai appris

Que la neutralité n'est pas une posture qu'on affiche mais une contrainte d'ingénierie qu'on vérifie : paramétrer un seuil, dédoubler un référentiel, faire relire une formulation par une revue adversariale sont des actes concrets, pas des intentions déclarées. Que documenter une donnée dérangeante vaut mieux que la lisser : le cas breton tient parce que l'explication existe par écrit avant qu'on en ait besoin.

J'ai aussi mesuré que la diligence sur les licences des sources est une compétence sous-estimée dans un projet open source solo : vérifier ce qu'on a le droit de réutiliser, écarter une source dont la licence ne convient pas, auditer les dépendances à chaque CI via `cargo-deny`. Rien de tout ça n'est visible dans l'API finale, mais c'est une des raisons pour lesquelles le projet est citable en référence sur data.gouv.fr.

Et qu'un projet solo n'oblige pas à sacrifier la discipline d'équipe : les ADR immuables et les revues adversariales jouent le rôle qu'aurait joué une revue par les pairs. La release d'audit v0.7.0, dix-neuf défauts trouvés et corrigés, en est la preuve par le résultat, pas par la seule intention.

## En chiffres

- Version 0.11.0 (release du 20 octobre 2026), stable ; v0.7.0 = release d'audit, dix-neuf défauts confirmés et corrigés sur une revue adversariale complète du workspace
- 30 endpoints sous `/v1`, documentés en OpenAPI
- Backend en Rust (édition 2024, tokio) : workspace Cargo de onze crates et un binaire serveur, architecture hexagonale
- Persistance PostgreSQL en SQL natif (sqlx), sans ORM
- Couverture géographique : national + 12 régions ; 3 méthodologies carbone servies en parallèle (`rte-direct`, `acv-ademe@1`, `acv-ademe@2`)
- 2 référentiels d'éligibilité hydrogène (RFNBO, bas-carbone) ; 233 électrolyseurs européens cartographiés (European Hydrogen Observatory)
- 35 ADR (Architecture Decision Records) gouvernant le projet
- Double licence MIT / Apache-2.0 ; SDK TypeScript publié (`@carbon-fr/sdk`)
- Infrastructure 100 % UE : API sur VPS Hetzner derrière Traefik, vitrine chez o2switch (France) ; image Docker publiée par la CI à chaque tag de version, auto-hébergeable (un tier hébergé à clés API coexiste)
- Marque déposée à l'INPI ; réutilisation des données référencée sur data.gouv.fr

## Liens

- Dépôt : public, sur GitHub (`github.com/Kovelt/carbon-fr`), organisation Kovelt, double licence MIT / Apache-2.0.
- Démo : tableau de bord public (`carbon-fr.kovelt.fr/dashboard`) : carte interactive, vues régionales et widget du créneau le plus vert.
- SDK : `@carbon-fr/sdk`, publié en TypeScript.
- Documentation : référence OpenAPI de l'API (`carbon-fr-api.kovelt.fr/docs`).
- Référencement : réutilisation listée sur data.gouv.fr.
