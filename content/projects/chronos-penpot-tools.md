---
title: "chronos-penpot-tools"
slug: "chronos-penpot-tools"
type: "project"
date: 2026-10-26
subtitle: "Un plugin Penpot qui transforme une maquette en composant Dioxus (Rust), via un cœur de conversion compilé en WebAssembly"
seo_description: "chronos-penpot-tools, plugin Penpot qui génère des composants Dioxus (Rust) depuis une maquette, via un cœur de conversion compilé en WebAssembly."
featured: false
status: "prototype"
role: "Conception, développement et documentation, en solo"
period_start: 2026-02-01
period_end: ""
repo_url: ""
demo_url: ""
client: ""
is_fil_rouge: false
tags: [rust, webassembly, dioxus, penpot, typescript, vite, design-to-code, wasm-bindgen]
skills: []
---

chronos-penpot-tools est un plugin pour Penpot, l'alternative open source à Figma, qui génère un composant Dioxus (RSX) en Rust à partir d'une maquette sélectionnée dans l'éditeur. Je l'ai construit pour outiller mon propre produit : au moment où j'ai écrit la v0.1, ce produit s'appelait encore Chronos ; il est devenu Vefta en juillet 2026. Le plugin, lui, garde pour l'instant son nom d'origine, le renommer n'est pas encore fait.

Le projet est privé et reste à l'état de prototype. La v0.1, livrée en février 2026, couvre les formes, le texte et les mises en page flexibles ; une feuille de route versionnée va jusqu'à une v0.5. La licence MIT est posée dès le départ, mais la publication en open source pour la communauté Rust et Dioxus est prévue seulement à la v1.0.

## Le problème

Dioxus est un framework Rust d'interface utilisateur multiplateforme — desktop, web, mobile — dont la syntaxe de rendu s'appelle RSX. Penpot est un outil de design d'interface, l'équivalent ouvert de Figma. Entre les deux, aucun pont : passer d'une maquette Penpot à un composant Dioxus se fait à la main, écran par écran, en relisant chaque calque pour en déduire la structure et les styles.

Pour Chronos, l'application cross-platform en Rust et Dioxus que je développe par ailleurs, cette traduction manuelle revenait à chaque nouvel écran. J'ai construit le pont moi-même : un plugin Penpot qui lit la sélection de l'utilisateur et génère le composant Dioxus RSX correspondant, prêt à coller dans le code. Un « Figma-to-code », mais pour un écosystème open source et une cible, Rust, qu'aucun outil de ce genre ne couvre.

Le vrai problème n'est pas de lire un fichier JSON Penpot : c'est de passer d'un arbre de calques pensé pour un éditeur visuel à du code qu'un développeur Rust aura envie de garder tel quel, sans verrouiller la traduction dans une étape qui bloquerait toute extension future vers d'autres cibles ou d'autres manières de styliser.

## Les décisions techniques

**Toute la logique de conversion vit dans un crate Rust compilé en WebAssembly, jamais dans le TypeScript du plugin.** Le plugin tourne dans l'iframe sandboxée de Penpot, mais la traduction d'un arbre de calques en composant Dioxus est un crate séparé, `penpot-to-dioxus`, appelé depuis le TypeScript via `wasm-bindgen`. Deux raisons à ce découpage : la logique complexe reste dans un langage typé, testable par `cargo test` indépendamment du navigateur, et le crate est pensé pour être réutilisable hors du plugin, en CLI ou directement dans l'outillage de Chronos/Vefta. Le crate est à double type, `cdylib` pour le WebAssembly et `rlib` pour les tests, compilé avec `opt-level = "s"`, LTO et strip pour garder le binaire WASM léger.

**Le convertisseur passe par une représentation intermédiaire plutôt que de traduire le JSON Penpot en RSX directement.** Le crate est structuré comme un petit compilateur en six modules : `parser.rs` désérialise, via serde, le JSON Penpot en arbre typé `PenpotNode` — remplissages, contours, effets, mises en page flex et grid, texte, informations de composant ; `transform.rs` convertit cet arbre en représentation intermédiaire `DioxusNode`, avec des heuristiques comme choisir entre `h1` à `h4`, `p` ou `span` selon la taille de police d'un texte ; `rsx_builder.rs` produit le code RSX final, indenté et lisible ; `styles.rs` traduit les propriétés visuelles en CSS — couleurs hexadécimales vers rgba, dégradés, alignements, pistes de grille ; `svg_extract.rs`, prévu pour la v0.4, détectera les formes à exporter en SVG ; `lib.rs` est le point d'entrée du crate, qui expose la fonction `#[wasm_bindgen] fn convert(json, options)` appelée depuis le TypeScript. Passer par cette représentation intermédiaire plutôt que par une traduction directe rend le générateur extensible à de nouvelles cibles et à de nouveaux modes de style, et le rend testable module par module.

**Le plugin TypeScript est scindé en deux mondes qui ne communiquent que par messages.** L'API Plugin de Penpot impose cette séparation : `plugin.ts` est le seul fichier avec un accès à l'API `penpot`, il lit la sélection et sérialise l'arbre de design ; `main.ts` est l'interface de l'iframe, qui initialise le module WebAssembly et affiche la prévisualisation du code généré avec copie et téléchargement ; `wasm-bridge.ts` charge ce module de façon asynchrone. Cette frontière limite la surface qui touche au document réel de l'utilisateur, et le plugin ne demande que la permission minimale `content:read` : il est volontairement en lecture seule, il ne peut rien modifier dans le design source.

**La spécification précède le code : une table de correspondance et un périmètre négatif ont été écrits avant la première ligne Rust.** `MAPPING.md` fixe la philosophie de conversion — fidélité visuelle d'abord, code idiomatique et modifiable à la main, pas de dump HTML brut, nommage sémantique dérivé des calques — et une feuille de route versionnée va de la v0.1 (formes, texte, flex) à la v0.5 (classes CSS et détection de patterns), en passant par les composants Penpot, les props typées, les grilles, dégradés et ombres, puis l'export SVG. Le périmètre négatif est assumé explicitement : pas de génération de logique métier, pas de responsive automatique en v1. Les limites connues sont documentées comme des TODO localisés dans le code, fichier et fonction, plutôt que cachées.

**La licence MIT est posée dès la v0.1, mais la publication en open source est différée à la v1.0.** L'outil est né d'un besoin interne, outiller Chronos/Vefta, et reste un dépôt privé sur mon GitLab personnel auto-hébergé tant que le générateur n'a pas atteint sa feuille de route complète. La licence est choisie dès le départ pour ne pas avoir à y revenir plus tard, mais je préfère livrer un outil qui tient ses promesses à la communauté Rust/Dioxus plutôt qu'un plugin à moitié fini portant déjà un nom public.

## Ce que j'en ai appris

Que poser le crate de conversion comme un module Rust pur, vérifiable par `cargo test` sans navigateur ni iframe, évite de déboguer en même temps la logique de traduction et la plomberie WebAssembly : quand un test de `transform.rs` échoue, je sais déjà qu'il ne s'agit pas d'un problème de chargement WASM ou de `postMessage`. Que l'étape la plus facile à sauter, par impatience, est aussi la plus coûteuse à sauter : `MAPPING.md` et son périmètre négatif, écrits avant la première ligne Rust, posent l'exigence d'un RSX idiomatique et le refus explicite du dump HTML brut comme principe du projet dès le départ, non comme une contrainte à redécouvrir au fil du code. Que choisir une représentation intermédiaire plutôt qu'une traduction directe a un coût immédiat, six modules au lieu d'un seul fichier qui ferait tout, mais que ce coût s'efface dès qu'on veut ajouter une cible ou un mode de style, ce que la v0.4 et la v0.5 feront sans toucher au cœur déjà écrit. Et que fabriquer l'outil dont mon propre produit a besoin, plutôt que de me contenter d'outils existants, m'a fait entrer dans Rust et WebAssembly par la porte la plus exigeante : comprendre une chaîne de compilation complète, de `wasm-pack` jusqu'à deux points d'entrée Vite distincts, avant qu'elle devienne invisible.

## En chiffres

- Crate Rust `penpot-to-dioxus` : environ 1 400 lignes, 6 modules, compilé en WebAssembly
- Plugin TypeScript : environ 500 lignes, outillé avec Vite
- 4 documents de conception, dont `MAPPING.md`
- v0.1 (MVP) livrée en février 2026 ; feuille de route versionnée jusqu'à la v0.5
- Licence MIT posée dès la v0.1

## Liens

- Dépôt : privé, hébergé sur mon GitLab personnel auto-hébergé (infrastructure Kovelt). Publication en open source sous licence MIT prévue à la v1.0, pour la communauté Rust et Dioxus.
- Démo : aucune démonstration publique pour le moment.
