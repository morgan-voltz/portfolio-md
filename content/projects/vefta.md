---
title: "Vefta"
slug: "vefta"
type: "project"
date: 2026-10-26
subtitle: "Une suite de productivité personnelle en Rust de bout en bout, pensée pour fonctionner hors ligne sans jamais monétiser les données de l'utilisateur"
seo_description: "Vefta, suite de productivité privacy-first et offline-first en Rust (Axum, Dioxus, PostgreSQL), développée en solo pour Kovelt SAS."
featured: false
status: "en-cours"
role: "Fondateur et unique développeur, Kovelt SAS"
period_start: ""
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, axum, dioxus, postgresql, webassembly, architecture-hexagonale, offline-first, privacy-first, ci-cd, sync]
skills: ["4.0.2/E09"]
---

# Vefta

Vefta est la suite de productivité personnelle que je développe pour Kovelt SAS, l'entreprise que j'ai fondée et où j'en suis l'unique développeur. Jusqu'en juillet 2026, elle s'appelait Chronos. L'idée de départ : un seul outil qui tienne l'agenda, les tâches, les contacts, les notes, le mail et les messageries d'une personne, qui fonctionne sans connexion et dont les données ne quittent jamais le contrôle de l'utilisateur.

Le socle natif et la synchronisation bidirectionnelle avec Google Calendar sont livrés depuis juin 2026 (MVP1). La cellule mail est en test auprès d'alpha-testeurs. Les messageries unifiées sont conçues et documentées, pas encore construites. Le back-office professionnel pour les organisations (Équipes) suit le même principe, mais sa construction reste explicitement gelée derrière une condition de réveil économique mesurable : qu'un utilisateur indépendant préfère Vefta à Google et paie l'abonnement. L'IA embarquée suit un régime différent : c'est un chantier de fond déjà engagé, avec le fine-tuning du petit modèle de langage local en cours — gratuit parce qu'il tourne sur l'appareil de l'utilisateur à coût marginal quasi nul, contrairement à l'IA cloud qui restera payante.

## Le problème

Le constat de départ est concret : une graphiste freelance qui travaille avec cinq onglets ouverts en permanence — l'agenda, le mail, les notes, un traducteur, la facturation — et dont aucun outil ne sait rien des autres. Son carnet d'adresses ignore l'historique des rendez-vous, son agenda ignore ses mails, et l'ensemble vit chez des éditeurs dont le modèle économique repose sur l'exploitation de ses données.

Trois frictions concrètes en découlent. La fragmentation : la même information sur une même personne est éclatée entre des silos qui ne se parlent pas. La dépendance : les suites dominantes imposent leur compte comme identité, rendent la sortie coûteuse et financent leur gratuité par la publicité ciblée. L'absence d'ancrage local : aucun grand acteur ne modélise vraiment la France — jours fériés par zone, vacances scolaires par académie, calendrier fiscal, guichets administratifs comme Ameli ou la CAF.

Vefta répond à chacune de ces frictions par un parti pris technique plutôt que commercial : une seule application plutôt qu'une suite d'outils cloisonnés, un client qui fonctionne sans le cloud et des données exportables plutôt qu'un compte captif, des calendriers publics français superposés nativement plutôt qu'ignorés.

## Les décisions techniques

**Rust de bout en bout, du serveur jusqu'aux trois cibles client.** Le backend est écrit en Axum avec PostgreSQL ; le client, en Dioxus, compile vers WebAssembly pour le web et vers des binaires natifs pour le desktop et le mobile. Les couches domaine et application forment un seul code partagé entre le serveur et les trois cibles client : une règle métier n'existe qu'une fois, pas une fois par plateforme. C'est ce qui me permet de porter les trois cibles en parallèle sans tripler le travail, un point qui compte quand on est seul sur le projet.

**La règle de dépendance hexagonale est vérifiée par une porte CI bloquante, pas seulement par la structure des dossiers.** Le domaine pur est séparé en crates par couche, et au-delà de ce que le compilateur impose déjà, un gate CI bloquant analyse le graphe de `cargo metadata` et fait tourner des lints clippy dédiés pour refuser toute dépendance qui violerait le sens des flèches. Sur un projet mené seul et sur la durée, je préfère un mur automatique à une discipline que je devrais sans cesse me rappeler à moi-même.

**Le produit est découpé en cellules isolées des pannes plutôt qu'en couches horizontales.** Cœur Suite, IA, Plugin-host et Mail sont quatre macro-unités indépendantes, chacune testable seule, et aucune ne lit la base de données d'une autre : chaque cellule définit elle-même son mode dégradé. La promesse « si l'IA tombe, l'agenda continue de marcher » est une conséquence de cette topologie, pas une intention qu'il faudrait vérifier à la main à chaque changement.

**La synchronisation montre les conflits plutôt que de les écraser en silence.** Un noyau de synchronisation générique, basé sur des ETag et des identifiants externes portés par des liens de synchronisation, résout les conflits en dernière écriture gagnante mais les rend visibles plutôt que de les faire disparaître. Cette boucle a d'abord été prouvée dans son intégralité sur le cas le plus étroit possible — la synchronisation bidirectionnelle de Google Calendar, livrée en MVP1 en juin 2026 — avant d'être déclinée sur les autres sources.

**L'infrastructure est auto-hébergée et conteneurisée plutôt que déléguée à un PaaS.** Le dépôt tourne sur un GitLab personnel (git.kovelt.fr) ; la production tourne sur du Hetzner Cloud derrière un reverse proxy Caddy qui gère le TLS automatiquement, dans des conteneurs Docker/Podman ; la CI/CD tourne pour l'instant sur la machine de développement, avec un cache de couches via cargo-chef, en attendant un VPS de build dédié pour la bêta. C'est la même logique que celle appliquée aux données de l'utilisateur : contrôler la chaîne d'hébergement de bout en bout plutôt que la confier entièrement à un tiers.

**Chaque décision structurante est tracée dans un journal qui ne se réécrit jamais.** Plus de quarante-huit ADR documentent les décisions avec leur contexte et leurs alternatives, et une correction s'ajoute toujours comme une nouvelle entrée datée plutôt que de remplacer l'ancienne. Au-dessus de ce journal, une Constitution fixe l'identité du produit et une poignée de refus non négociables, dont celui de ne jamais imposer Google comme identifiant de connexion. Le renommage de Chronos en Vefta, en juillet 2026, est passé par ce même canal : une recherche d'antériorité complète a révélé une quasi-homophonie avec Kronos, un acteur du workforce management, et un nom devenu trop étroit pour une suite qui dépasse largement l'agenda — l'ADR correspondant documente la décision plutôt que de simplement renommer un dépôt.

## Ce que j'en ai appris

Que la discipline qui tient dans la durée est celle que l'outillage impose, pas celle qu'on se promet à soi-même : le gate CI sur l'architecture et le journal d'ADR immuable jouent, en solo, le rôle qu'une revue de pairs jouerait en équipe. Qu'un fil mince de bout en bout — prouver toute la chaîne sur le cas le plus simple avant de l'élargir — coûte moins cher à corriger qu'un système construit en largeur avant d'être prouvé en profondeur : c'est pour ça que la synchronisation Google n'a été prouvée que sur l'agenda avant d'être déclinée ailleurs. Et que geler volontairement la construction d'un chantier déjà conçu derrière une condition de réveil mesurable — c'est le cas du back-office professionnel Équipes — évite une dette de conception sans renoncer à l'avoir pensé.

## En chiffres

- Plus de 48 ADR (Architecture Decision Records) immuables
- 4 cellules macro indépendantes : Cœur Suite, IA, Plugin-host, Mail
- MVP1 livré en juin 2026 : socle natif + synchronisation bidirectionnelle Google
- Renommage Chronos → Vefta acté en juillet 2026, avec recherche d'antériorité complète
- 2 chaînes d'outillage ROCm comparées pour le fine-tuning du SLM embarqué (Mistral 7B, QLoRA)

## Liens

- Dépôt : privé, hébergé sur le GitLab auto-hébergé de Kovelt. Le code est propriétaire au lancement ; une ouverture différée du code client est préparée mais pas déclenchée.
- Démo : pas de démonstration publique ; le produit est testé en alpha privée par quelques utilisateurs.
