# 02 — Le Markdown comme format pivot

**Temps de lecture** : environ 10 minutes
**Dernière mise à jour** : 22 avril 2026

---

Prends un fichier Word que tu as créé il y a dix ans, si tu en as un qui traîne quelque part sur un disque dur. Demande-toi honnêtement : peux-tu l'ouvrir aujourd'hui sans friction, dans cinq ans de la même façon, dans vingt ans sans dépendre de Microsoft ? La réponse est probablement « oui, mais c'est fragile ». Word a déjà changé de format une fois, en 2007, avec le passage de `.doc` à `.docx`. Rien ne garantit qu'il ne le fera pas encore. Si Microsoft disparaissait demain — hypothèse absurde mais utile mentalement — tes fichiers Word deviendraient progressivement illisibles, ou du moins difficiles à manipuler par autre chose que les programmes de Microsoft.

Maintenant fais l'expérience inverse. Ouvre un fichier texte que tu as écrit il y a vingt ans sur n'importe quelle machine. Tu peux le lire partout, sans effort, sans outil spécial. Il survivra à peu près à tout, y compris à la fin de la plupart des entreprises qui existent aujourd'hui. Le texte brut est presque éternel.

Cette durabilité n'est pas un hasard. Elle vient du fait que le texte brut est un **format simple, public, et non-propriétaire**. Personne ne le possède, personne ne peut le rendre obsolète unilatéralement, et pour le lire il suffit de n'importe quel outil capable d'afficher des caractères. Le Markdown, c'est exactement ça, avec juste un peu plus de structure pour distinguer un titre d'un paragraphe et une liste d'un bloc de code. C'est pour cette raison que j'en ai fait la colonne vertébrale de tout ce projet.

## Qu'est-ce que le Markdown, en une page

Si tu as déjà écrit un message sur Discord, un ticket sur GitHub ou un post sur Reddit, tu as probablement déjà utilisé du Markdown sans savoir que ça s'appelait comme ça. Le Markdown est une convention d'écriture qui permet d'obtenir des mises en forme simples — gras, italique, titres, listes, liens, images, code — en utilisant juste des caractères normaux qu'on tape au clavier.

Pour mettre en gras, tu encadres le mot de deux étoiles : `**comme ça**`. Pour un titre, tu mets un dièse en début de ligne : `# Mon titre`. Pour une liste à puces, tu commences chaque ligne par un tiret. C'est tout. Le fichier reste lisible même sans rendu, et un programme de rendu (GitHub, un éditeur, ou dans notre cas le plugin WordPress) peut transformer ces conventions en mise en forme réelle.

Le Markdown a été inventé en 2004 par John Gruber, un développeur web qui voulait une façon simple d'écrire pour le web sans avoir à taper du HTML à la main. Vingt ans plus tard, c'est devenu le standard de fait pour à peu près tout le contenu technique : documentation de projets, articles techniques, notes personnelles, forums de développeurs, et même des livres entiers. Si tu tapes « how to... » dans un moteur de recherche, il y a de très fortes chances que les premiers résultats soient rendus depuis du Markdown quelque part.

## La vraie question : source ou sortie

Maintenant qu'on a posé le décor, on peut aborder la question qui change tout. Quand tu écris un document pour le web, tu as deux façons fondamentalement différentes de penser ton travail.

La première façon est de considérer que tu écris **un document final**. Tu ouvres Word ou Google Docs, tu mets ton texte en forme, tu choisis une police, tu ajustes les marges, tu alignes les images, et quand c'est fini tu as un document qui est son propre résultat. Si quelqu'un veut le voir autrement — en noir et blanc, avec une autre typographie, sur mobile au lieu de bureau — il faut transformer le document lui-même.

La deuxième façon est de considérer que tu écris **une source**. Tu tapes ton texte avec juste assez de structure pour distinguer un titre d'un paragraphe et une liste d'un bloc de code, sans te préoccuper du rendu visuel. Un outil (ou plusieurs) se charge ensuite de transformer cette source en sortie finale, selon le contexte de présentation.

Cette distinction entre source et sortie est la même qui existe entre le code source d'un programme et son exécutable compilé. Un développeur ne distribue pas son binaire comme unique forme de son travail — il garde le code source, parce que c'est à partir du source qu'il peut recompiler pour d'autres systèmes d'exploitation, ajuster le comportement, ou collaborer avec d'autres développeurs. Le binaire est une cible, pas un original. Si tu perds le code source et que tu n'as plus que le binaire, tu as perdu ta marge de manœuvre.

Pour du contenu écrit, la même logique s'applique. Le document Word est un binaire — une cible optimisée pour un usage précis (être affiché dans Word ou imprimé depuis Word). Le Markdown est une source — qu'on peut recompiler vers un site web, un PDF, une page WordPress, un document imprimé, ou n'importe quel autre format cible qu'on inventera demain.

## Le concept de format pivot

Un **format pivot** est une source qui peut être transformée vers plusieurs cibles différentes, indépendantes les unes des autres. Dans notre projet, le Markdown joue exactement ce rôle. Tu écris un article une seule fois. Le plugin WordPress le transforme en blocs Gutenberg pour alimenter le site WordPress. L'API C# le récupère et le parse avec Markdig pour alimenter le front React. Demain, si on veut générer un livre PDF de tous les articles, on écrit un script qui lit les Markdowns et produit du LaTeX. Si on veut une application mobile qui lit les articles hors ligne, on embarque directement les fichiers Markdown dans le bundle et on les rend avec une librairie mobile.

Chaque cible est indépendante. Chaque cible peut évoluer à son rythme. Aucune cible n'est la source de vérité — la source de vérité, c'est le Markdown.

Pour bien sentir la différence, imagine la situation inverse. Si tu écrivais directement dans l'éditeur Gutenberg de WordPress, ton contenu vivrait dans la base de données WordPress sous forme de HTML avec des commentaires Gutenberg spécifiques. Le jour où tu voudrais le lire sans WordPress — dans un nouveau CMS, dans un site statique, dans un export, dans une app — tu devrais parser ce HTML, en extraire la vraie substance, et espérer ne rien perdre dans la conversion. Le HTML Gutenberg est une cible, pas une source. Il a été fait pour être rendu, pas pour être relu ou retraité.

Avec le Markdown comme pivot, ce problème disparaît. Ta source reste propre, portable, lisible par un humain dans un éditeur de texte basique, et re-compilable vers n'importe quelle cible que tu voudras un jour.

## L'expérience de pensée de la disparition

Voilà une petite expérience de pensée que je trouve utile quand je choisis un format ou un outil. Imagine que l'entreprise derrière l'outil disparaisse demain. Combien de temps te faudrait-il pour migrer ailleurs ?

Si WordPress était ton seul stockage de contenu, et si un jour WordPress devenait inutilisable pour une raison quelconque (changement de modèle économique, arrêt du projet, faille de sécurité non corrigée, ce qui n'arrivera probablement jamais mais imagine), il te faudrait des semaines de travail pour exporter proprement ton contenu, le reformater, et le réimporter ailleurs. Tu perdrais probablement une partie du travail dans l'opération, notamment les métadonnées fines qui n'ont pas d'équivalent direct dans le nouveau système.

Avec du Markdown versionné dans un repo Git, la migration coûte une demi-heure. Tu copies les fichiers `.md` dans ton nouvel environnement, tu configures un nouveau renderer, et c'est fini. Rien n'est perdu parce que rien n'était verrouillé.

Cette propriété — la possibilité de partir sans friction — n'a aucune valeur tant que tout va bien. Elle en acquiert énormément quand une situation se dégrade. Et surtout, elle change ta posture mentale au quotidien : tu utilises un outil parce qu'il te sert, pas parce que tu es coincé avec lui. C'est une différence psychologique qui finit par avoir des conséquences techniques, parce qu'on prend de meilleures décisions quand on se sait libre de changer d'avis.

## Le choix précis : GFM avec frontmatter YAML

Puisqu'il existe plusieurs dialectes de Markdown qui diffèrent sur des détails, il faut en choisir un. Le dialecte retenu pour ce projet est le **GitHub Flavored Markdown**, ou GFM. C'est celui utilisé par GitHub pour ses issues, ses README, ses wikis. Il étend le Markdown standard (appelé CommonMark) avec quelques fonctionnalités pratiques dont on a besoin en contexte technique : tableaux, listes à cases à cocher, barré avec `~~texte~~`, et liens automatiquement détectés.

Ce choix est pragmatique. GFM est devenu le standard de fait du contenu technique moderne. Il est supporté nativement par Obsidian, Hugo, Astro, VS Code, JetBrains, Zed, et la plupart des outils qu'un développeur utilise au quotidien. Si tu écris un article aujourd'hui dans un éditeur moderne, il est déjà compatible avec ce projet, sans configuration particulière.

Par-dessus le corps Markdown, chaque article commence par un bloc de **métadonnées en YAML** délimité par des triples tirets :

```yaml
---
title: "Le vrai problème que Rust résout"
date: 2026-04-22
tags: [rust, systems]
---
```

Cette convention, popularisée par Jekyll en 2008, permet d'attacher des informations structurées (titre, date, tags, description SEO, configuration de rendu) au contenu sans les mélanger dans le corps du texte. Tous les générateurs de site statique modernes utilisent cette convention, et les éditeurs modernes comme Obsidian savent la lire nativement.

Le résultat est qu'un article de ce projet est un fichier texte qui contient tout ce qu'il faut pour être publié — le contenu et les métadonnées, l'un à côté de l'autre — sans dépendre d'un système externe pour les relier. Si tu me donnes un fichier `.md` avec son frontmatter, je peux reconstruire toute sa présence sur le site sans avoir accès à la base de données.

## Pourquoi pas MDX ou HTML directement

Deux alternatives populaires ont été écartées volontairement, et la justification est intéressante à poser parce qu'elle éclaire ce qu'est réellement un bon format pivot.

La première est **MDX**. C'est une extension du Markdown qui permet d'insérer des composants React directement dans le contenu. Tu pourrais écrire `<VideoPlayer src="..." />` en plein milieu d'un article et ça rendrait une vraie vidéo interactive avec des contrôles. C'est séduisant, mais ça a un coût qu'il faut voir : ton fichier n'est plus du Markdown portable. Il exige un renderer qui comprend React, et il ne peut plus alimenter le site WordPress qui ne sait pas exécuter du JSX. En choisissant MDX, tu optimises pour une cible précise (le front React) au détriment des autres — exactement l'inverse de ce qu'un format pivot est censé faire.

La seconde alternative est **le HTML directement**. On pourrait se dire « puisque WordPress stocke du HTML, autant écrire directement du HTML et éviter une étape de conversion ». Mais le HTML est un format de sortie, pas de source. Il est plus verbeux à écrire à la main (chaque balise doit être ouverte et fermée), il mélange structure et présentation dès qu'on ajoute des attributs de style, et il ne porte pas nativement les métadonnées sémantiques qu'un frontmatter YAML expose proprement. Écrire directement du HTML, c'est court-circuiter l'étape où tu sépares ton contenu de sa présentation — et cette séparation est exactement le bénéfice principal du Markdown.

Le Markdown réussit quelque chose de subtil : il est assez structuré pour être transformable automatiquement, et assez simple pour être lu agréablement par un humain sans rendu. MDX perd la première qualité (il n'est plus transformable vers toutes les cibles), HTML perd la seconde (il n'est pas agréable à lire sans rendu). Markdown garde les deux.

## Ce que tu retiendras de ce chapitre

Si tu ne devais retenir qu'une seule idée, ce serait celle-ci : **choisir un format de contenu, c'est choisir une stratégie d'indépendance**. Un format propriétaire te lie à un éditeur ou à une entreprise. Un format de sortie te lie à un moteur de rendu spécifique. Un format pivot comme le Markdown te libère des deux. Tu possèdes tes fichiers, tu peux les lire sans outil spécial, tu peux les transformer vers toutes les cibles dont tu auras besoin, et tu n'as besoin de demander la permission à personne.

Cette propriété qui semble abstraite au démarrage d'un projet prend toute son importance quand tu te projettes à cinq ou dix ans. Les outils que j'utilise aujourd'hui (Zed, WordPress, Vite, C#) ne seront peut-être plus ceux que j'utiliserai dans dix ans. Mais les fichiers `.md` que j'écris aujourd'hui seront encore là, lisibles par n'importe quel outil capable de les parser, et prêts à alimenter la stack qui aura remplacé l'actuelle. C'est cette perspective de long terme qui justifie le choix du Markdown, bien plus que le confort immédiat d'écriture.

## Ce que tu liras dans le chapitre suivant

Le chapitre trois va s'attaquer à une question légitime qu'on pourrait se poser à ce stade. « D'accord, le Markdown est un bon format. Mais pourquoi ne pas simplement l'utiliser dans WordPress via un plugin Markdown existant, et s'arrêter là ? Pourquoi toute cette architecture en plus — une API C#, un front React, une séparation en plusieurs couches ? » On va regarder en face ce que WordPress fait bien, ce qu'il fait moins bien, et pourquoi l'architecture additionnelle n'est pas de la sur-ingénierie mais une réponse à des besoins réels que WordPress seul ne peut pas couvrir.

---

**Chapitre précédent** : [01 — Pourquoi ce projet existe](01-pourquoi-ce-projet-existe.md)
**Chapitre suivant** : [03 — Pourquoi pas juste WordPress](03-pourquoi-pas-juste-wordpress.md) *(à rédiger)*

**Retour à** : [documentation pédagogique](README.md) • [documentation d'architecture](../architecture/00-overview.md)
