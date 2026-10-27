---
title: "AUTOFIN Control"
slug: "autofin-control"
type: "project"
date: 2026-10-26
subtitle: "Lire des bilans PDF hétérogènes par géométrie plutôt que par IA, pour un contrôle financier qui doit rester en local"
seo_description: "AUTOFIN Control extrait et vérifie des données comptables de PDF pour le contrôle financier des associations subventionnées par m2A, en .NET 10."
featured: true
status: "stable"
role: "Développeur principal, équipe de 7 à 8"
period_start: 2026-01-09
period_end: 2026-05-31
repo_url: ""
demo_url: ""
client: "m2A (Mulhouse Alsace Agglomération)"
is_fil_rouge: false
tags: [csharp, dotnet, wpf, mvvm, clean-architecture, pdf-extraction, sqlite, excel, xunit, msix]
skills: ["4.0.1/PM-01", "4.0.1/PM-02", "4.0.1/PM-08", "4.0.1/FR-01", "4.0.1/FR-15"]
---

AUTOFIN Control est le projet client de ma première année UHA 4.0 : une application de bureau pour le service de contrôle financier de m2A, l'agglomération de Mulhouse. Développé avec une équipe de sept à huit étudiants entre janvier et mai 2026, j'y ai tenu le rôle de développeur principal — environ 38 % des trois cent quatre-vingt-treize commits, l'architecture, le pipeline d'extraction, et une bonne partie des conventions et de la documentation d'équipe.

Le logiciel a été livré au commanditaire en trois versions MSIX signées.

## Le problème

Les contrôleurs financiers de m2A vérifient chaque année les comptes des associations que l'agglomération subventionne. Chaque association envoie un bilan et un compte de résultat au format PDF, produits par son propre expert-comptable — autant de mises en page différentes que de cabinets. Le travail consistait jusque-là à ressaisir à la main les montants de l'exercice en cours et de l'exercice précédent dans une trame Excel de suivi standardisée : une tâche longue, répétitive, et surtout source d'erreurs de saisie sur des chiffres où l'erreur n'est pas acceptable.

AUTOFIN Control automatise cette chaîne sans en retirer le contrôleur : importer le PDF, en extraire les lignes comptables, les faire vérifier et corriger visuellement, les agréger, les rattacher aux bonnes catégories de la trame, puis exporter. Une contrainte s'est imposée dès le départ et a pesé sur chaque choix qui a suivi : les données financières des associations ne doivent jamais quitter le poste du contrôleur. Pas de serveur, pas de cloud, pas d'appel réseau — une base SQLite locale et un traitement sur la machine, parce que c'est de l'argent public et des données d'associations tierces.

Le vrai problème technique n'était pas de lire un PDF, mais de le lire de façon fiable malgré des mises en page qu'on ne contrôle pas, avec une équipe de sept à huit personnes de niveaux très différents, pour un commanditaire qui allait réellement s'en servir.

## Les décisions techniques

**L'extraction lit la géométrie du PDF, pas son texte brut, et ne passe par aucune IA.** Deux autres pistes ont existé avant celle-ci — un mode qui passait par un modèle de langage local et un pont vers un script Python — puis ont été retirées du projet en bloc, `LlmExtractionService` et `RegexGeneratorService` compris, ainsi que leurs dépendances NuGet. Ce qui reste est un pipeline en cinq phases qui ne travaille que sur les coordonnées X/Y des mots : `WordRowGrouper` regroupe les mots en lignes, `RowCellParser` découpe chaque ligne en cellules, `ColumnPositionDetector` repère les colonnes numériques par clustering, `NetColumnIdentifier` distingue les colonnes Net N et Net N-1 du Brut et des amortissements, et `AccountingLineExtractor` construit les lignes finales. La raison : sur un outil de contrôle financier, le même PDF doit toujours donner le même résultat, et rester explicable à quelqu'un qui n'a pas écrit le code — ce qu'un modèle de langage ne garantit pas.

**Le cœur du domaine ne dépend de rien, et ça se vérifie en ouvrant le fichier projet.** `Autofin.Core` ne référence aucun paquet NuGet : seulement des modèles et des interfaces de service. `Autofin.Infrastructure` les implémente (SQLite, ClosedXML, extraction PDF, mapping), et `Autofin.Client` assemble le tout par un point d'entrée unique d'injection de dépendances. Avec sept ou huit personnes de niveaux hétérogènes sur le même dépôt, c'est cette séparation qui permet à quelqu'un de toucher l'extraction sans risquer de casser l'export.

**Le rattachement d'une ligne extraite à une catégorie comptable se fait par score, pas par premier mot-clé trouvé.** Les libellés changent d'un cabinet comptable à l'autre : abréviations, accents et casse différents pour la même notion. Un simple mot-clé qui matche en premier aurait classé trop de lignes dans la mauvaise catégorie. Le moteur normalise le libellé, additionne des mots-clés positifs, retranche des mots-clés négatifs qui disqualifient une catégorie, et ajoute un bonus si une configuration externe reconnaît déjà ce libellé pour ce client. Soixante-trois tests dédiés couvrent les onze catégories du compte de résultat et les vingt catégories du bilan. Une seconde brique plus récente (tickets AC-343/AC-344), `ExcelCategoryReader` et `ExcelCategoryRepository`, lit directement les catégories depuis le classeur Excel importé par le contrôleur et doit à terme remplacer ces listes codées en dur — qui ne survivront que comme filet de secours.

**Le pipeline d'export Excel a été entièrement repensé en cours de route pour ne plus jamais écraser une formule du classeur client.** La première version recréait une partie du fichier à chaque export, au risque de perdre des formules déjà en place, y compris entre feuilles. Le ticket AC-395 a changé le contrat : un nouveau service, `ExcelInsertionExportService`, insère uniquement les colonnes de l'exercice courant, capture puis réécrit les formules existantes, décale l'historique vers la droite sans y toucher. Le changement est consigné dans un journal de décisions daté : cent trente et un tests sur cent trente et un passent après la bascule.

**La dette technique a été mesurée par des audits commandés auprès d'un outil d'IA, vérifiés et appliqués, pas découverte au hasard.** Un audit global dès février, repris en mars, puis un audit par couche en avril, avec des tickets classés par impact et par effort. L'un d'eux a chiffré une régression que personne n'avait vue venir : un fichier de la page d'agrégation, censé rester autour de trois cents lignes, en comptait neuf cent vingt-deux. Le correctif a déplacé la logique métier vers le ViewModel associé et a fait retomber le fichier à quatre cent soixante-dix-huit lignes, réduit de près de moitié — déplacée plus qu'éliminée : ce ViewModel dépasse lui-même les neuf cents lignes, deux fichiers voisins n'ont jamais eu le même traitement, et le fichier est déjà reparti à la hausse. La leçon : pas d'avoir réglé le problème, mais de s'être donné les moyens de le voir revenir.

## Ce que j'en ai appris

Que mener un projet pour un vrai client change la nature de l'exigence : une erreur de montant n'est pas un bug comme un autre, et la discipline de tests, d'audits et de documentation cesse d'être une case à cocher pour devenir la condition pour qu'un contrôleur financier fasse confiance à l'outil. Que documenter pour une équipe de sept à huit personnes de niveaux hétérogènes est un travail à part entière : un guide d'accueil pédagogique et un lexique pour les développeurs venant du web, qui cherchent des routes là où il y a des ViewModels, ne sont pas du confort, ils déterminent qui peut réellement contribuer.

Que ma discipline Git s'est construite au fil du projet plutôt que d'exister dès le premier commit : les premières semaines portent des messages vagues et des commits qui touchent plusieurs sujets à la fois, les suivantes des préfixes rattachés à un ticket précis. Ce n'est pas qu'un changement de vocabulaire, c'est ce qui rend un historique relisible par quelqu'un d'autre que soi.

Ce que je ferais autrement : fixer le contrat de l'export Excel avec le besoin réel avant d'écrire les neuf cents premières lignes, plutôt que de devoir le repenser une fois le problème des formules écrasées constaté. Traiter un défaut signalé par un audit interne le jour même plutôt que de le laisser remonter dans l'audit suivant. Et découper mon travail en tâches plus ciblées plutôt que d'avancer sur plusieurs fronts à la fois, pour tenir les délais plus facilement.

## En chiffres

- 7 à 8 étudiants dans l'équipe, 393 commits au total dont environ 150 (38 %) les miens
- environ 26 500 lignes de C# et XAML, plus des scripts Python auxiliaires, dans l'état le plus avancé du projet
- 117 tests documentés (54 sur l'extraction, 63 sur le mapping), 131 sur 131 après la refonte de l'export Excel
- 13 diagrammes PlantUML, une vingtaine de documents techniques (architecture par couche, onboarding, audits, dossier client)
- 3 versions livrées en MSIX signé, de janvier à mai 2026

## Liens

- Dépôt : GitLab interne du programme UHA 4.0, non public (accès réservé aux étudiants et à l'équipe pédagogique).
- Pas de démo en ligne : application de bureau Windows à usage interne, sans version web.
- Documentation : dossier technique livré au commanditaire en février 2026, conservé avec une vingtaine de documents d'architecture et d'audit dans le dépôt du projet.
