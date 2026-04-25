# 07 — La discipline qui rend un projet durable

**Temps de lecture** : environ 10 minutes
**Dernière mise à jour** : 22 avril 2026

---

J'ai un dossier sur mon disque dur qui s'appelle `archives-old-projects`. À l'intérieur, il y a une vingtaine de dépôts que j'ai commencés au fil des années, avec chaque fois l'enthousiasme d'un projet neuf, et que j'ai abandonnés pour des raisons que je n'arrive souvent pas à reconstituer aujourd'hui. Certains sont vraiment morts — des idées qui ne menaient nulle part, qu'on a bien fait de laisser tomber. Mais d'autres étaient vraiment bien partis, et quand je les ouvre aujourd'hui pour essayer de reprendre là où j'étais, je ressens toujours la même frustration : **je ne comprends plus mon propre code**.

L'historique Git ressemble à un accident ferroviaire. Des commits intitulés « fix », « update », « wip », « essai ». Des branches abandonnées dont je ne sais plus ce qu'elles faisaient. Des fichiers dont le nom ne dit rien de leur contenu. Aucun journal de décisions, aucune trace écrite des raisons pour lesquelles j'avais structuré les choses d'une certaine manière plutôt que d'une autre. Le projet est techniquement récupérable, mais le coût mental pour y revenir est tel que je préfère souvent tout reprendre à zéro plutôt que de dérouiller l'ancien.

Cette expérience répétée m'a fait comprendre quelque chose d'important : **la discipline de méthode n'est pas une coquetterie de projets professionnels, c'est ce qui différencie les projets qui vivent des projets qui meurent**. Et c'est exactement pour ça que le projet que tu consultes applique des pratiques qui peuvent sembler disproportionnées pour un développeur seul — parce que je sais par expérience ce qui arrive aux projets qu'on laisse se délabrer méthodologiquement.

## Le vrai problème que la discipline résout

Quand on est débutant, on voit la discipline de projet comme un ensemble de règles imposées par des gens qui aiment les règles. Les Conventional Commits ressemblent à une lubie syntaxique. Les pull requests en solo paraissent absurdes — pourquoi se faire un code review à soi-même ? Les branches dédiées par fonctionnalité semblent un formalisme inutile quand on peut juste commiter sur `main`. Chacune de ces pratiques, prise isolément, est facile à écarter comme superflue.

Le piège de ce raisonnement est qu'il évalue chaque pratique par son coût immédiat — le temps qu'elle prend maintenant — sans voir son bénéfice différé. Le vrai problème que la discipline résout, ce n'est pas de rendre ton travail d'aujourd'hui plus agréable. C'est de **rendre ton travail accessible à toi-même dans six mois**, quand tu auras oublié le contexte, les priorités du moment, les arbitrages que tu avais en tête au moment de coder.

Tu écris du code pour deux publics. Le premier est toi-même maintenant, qui a toute l'information fraîche en mémoire et qui pourrait presque coder les yeux fermés. Le second est toi-même dans six mois, ou un collaborateur futur, qui arrive sur le projet sans aucun contexte et qui doit tout reconstituer. Le premier public n'a besoin d'aucune discipline — il comprend déjà. Le second a besoin de toute la discipline qu'on peut mettre en place, parce que c'est elle qui lui permettra de retrouver ses marques sans perdre des heures.

Le point crucial est que **les deux publics sont la même personne, décalée dans le temps**. La discipline de projet est un pacte que tu signes avec toi-même — ton toi présent investit un peu d'effort pour que ton toi futur puisse continuer le travail. Sans ce pacte, chaque projet devient un feu de paille qu'il faut rallumer entièrement à chaque retour après une pause.

## Les Conventional Commits : raconter ton historique

Prenons la pratique la plus visible pour commencer. Les **Conventional Commits** sont une convention qui impose à chaque message de commit de commencer par un type (comme `feat`, `fix`, `docs`, `refactor`) suivi d'un scope optionnel puis d'une description. Un commit typique ressemble à `feat(plugin): add YAML frontmatter parser`.

Au premier regard, c'est juste une forme imposée. On pourrait écrire la même information en prose libre, pourquoi ajouter une grammaire formelle ? La réponse se voit quand on utilise réellement l'historique Git comme un outil de travail.

Imagine que tu reviennes sur le projet après deux mois de pause et que tu veuilles répondre à une question simple : « qu'est-ce que j'ai changé en dernier dans le plugin WordPress ? ». Avec des messages libres, tu dois lire les quarante derniers commits pour trouver ceux qui concernent le plugin. Avec des Conventional Commits à scope, tu tapes `git log --grep="(plugin)"` et tu obtiens exactement les commits qui touchaient le plugin, dans l'ordre. Tu peux encore filtrer par type — `git log --grep="^feat(plugin)"` te donne les fonctionnalités ajoutées au plugin, `git log --grep="^fix(plugin)"` les bugs corrigés. L'historique devient une base de données interrogeable, pas juste un journal narratif.

Le bénéfice va au-delà de la recherche. La contrainte d'écrire un message au format `type(scope): description` force une réflexion implicite sur ce que tu viens de faire. Est-ce vraiment une nouvelle fonctionnalité, ou juste un refactor ? Est-ce que ça touche le plugin ou l'API ? Cette micro-réflexion, répétée à chaque commit, améliore la qualité de ton travail — parce qu'elle t'oblige à ne pas mélanger plusieurs intentions dans un même commit. Si tu te retrouves à vouloir écrire `feat(plugin): add parser and fix typo in README`, c'est que tu as deux commits à faire, pas un. La convention te signale le problème que tu n'aurais pas vu en écrivant librement.

Les Conventional Commits rendent aussi possibles des automatisations que sans elles serait impensables. Un script peut générer un CHANGELOG complet à partir de l'historique Git, en regroupant les entrées par type. Un outil comme *semantic-release* peut automatiquement déterminer si ta prochaine version doit être majeure, mineure ou patch en fonction des types de commits accumulés depuis la dernière release. Ces outils ne sont peut-être pas utiles aujourd'hui sur ton projet débutant, mais tu n'auras rien à changer le jour où ils le deviendront. La convention est un investissement gratuit qui paie en option sur l'avenir.

## Les pull requests en solo : te relire toi-même

Voici une pratique qui choque quand on l'entend pour la première fois : faire une pull request même quand on est seul sur le projet. Tu crées une branche, tu y fais ton travail, tu pousses la branche sur GitHub, tu ouvres une PR de cette branche vers `main`, tu la passes en revue, tu l'approuves toi-même, puis tu la merges. Quel intérêt, me diras-tu, de se faire un code review à soi-même ?

L'intérêt est précisément que **te relire dans le cadre d'une PR n'est pas te relire dans l'éditeur**. Ce sont deux actes mentaux différents. Quand tu codes dans ton éditeur, tu es en mode production — tu penses à ce que tu veux ajouter, pas à ce que tu viens de faire. Quand tu ouvres une PR et que tu regardes le diff complet dans l'interface GitHub, tu changes de posture. Tu deviens le relecteur de ton propre travail, avec une distance que tu n'as pas au moment de l'écriture. Cette distance révèle des choses que tu n'avais pas remarquées dans ton éditeur — des incohérences de nommage, des commentaires obsolètes, des fichiers modifiés sans raison, des lignes de debug oubliées.

Le bénéfice de la PR solo ne vient pas du fait que quelqu'un d'autre te relit. Il vient du fait que **tu passes deux fois sur le même code, dans deux états mentaux différents**. L'écriture et la relecture sont deux compétences distinctes, et un bon code nécessite les deux. Sauter la phase de relecture parce qu'on est seul, c'est abandonner la moitié de la valeur de la revue de code.

La PR solo apporte aussi une deuxième propriété utile : elle impose un moment de pause avant d'intégrer le changement à `main`. Tu as travaillé sur une branche, tu ouvres la PR, tu peux éventuellement la laisser ouverte une journée avant de la merger. Ce délai d'une journée te permet parfois de revenir le lendemain avec l'œil frais et de voir un problème évident que tu n'avais pas vu le jour même. Sur un projet important, ce genre de marge mentale empêche beaucoup d'erreurs de finir en production.

## La documentation comme archéologie préventive

La troisième discipline dont je veux parler est peut-être la moins populaire, et c'est précisément pour ça qu'elle mérite une attention particulière. Documenter un projet n'est pas quelque chose qu'on fait spontanément — on le fait parce qu'on s'est imposé de le faire, et on se bat régulièrement contre la tentation de passer à la tâche suivante sans avoir mis à jour la doc.

L'erreur classique est de voir la documentation comme un service rendu aux autres. Si tu travailles seul, pourquoi documenter pour quelqu'un qui n'existe pas ? Le renversement de perspective est le même que pour les commits : **la documentation est un service que tu rends à ton toi futur**. Tu documentes parce que dans six mois, tu ne te souviendras plus de la raison pour laquelle tu as choisi telle bibliothèque plutôt qu'une autre, pourquoi tu as structuré les dossiers de cette manière, pourquoi un fichier contient du code qui semble bizarre mais qui est en fait nécessaire pour résoudre un bug spécifique.

La forme la plus précieuse de documentation, et de loin la plus négligée, est le **journal des décisions architecturales**. Notre projet en a un, stocké dans `docs/architecture/99-decisions.md`, qui accumule au fil du temps une entrée par décision structurante. Chaque entrée suit un format fixe : contexte, décision, alternatives considérées, conséquences. Ce format force à documenter non seulement *ce qu'on a décidé*, mais *pourquoi*, et surtout *ce qu'on a envisagé d'autre*.

Cette dernière partie — les alternatives — est la plus souvent oubliée et la plus précieuse. Quand tu reviens sur une décision six mois plus tard et que tu veux la remettre en cause, tu ne peux le faire intelligemment que si tu sais ce que tu avais déjà envisagé à l'époque. Sinon tu risques de proposer une alternative que tu avais déjà évaluée et écartée pour des raisons valides que tu as oubliées. Le journal te protège contre ce gâchis.

L'investissement pour maintenir ce journal est minime : quelques minutes pour ajouter une entrée quand une décision est prise. Le retour sur investissement se manifeste chaque fois que tu reviens sur le projet après une absence et que tu peux reconstruire le contexte en cinq minutes au lieu de trente.

## La règle sous-jacente : rendre l'implicite explicite

Si tu prends du recul sur les trois pratiques que je viens de décrire, elles convergent toutes vers un même principe. Les Conventional Commits rendent explicite ce qu'un commit a changé et pourquoi. Les PR solo rendent explicite le moment de transition entre état expérimental et état intégré. La documentation rend explicite les raisons des choix qui sans elle resteraient dans ta tête.

Le principe commun est que **la discipline de méthode consiste à transformer en artefacts externes ce qui sinon resterait dans la mémoire volatile du développeur**. La mémoire volatile oublie, dérive, s'embrouille, disparaît avec les pauses. Les artefacts externes persistent, sont consultables, peuvent être transmis à d'autres. Un projet qui dure dans le temps est un projet qui a déplacé le maximum d'information de la tête de ses contributeurs vers ses propres fichiers et son propre historique.

Cette formulation éclaire pourquoi les pratiques apparemment disproportionnées pour un projet solo sont en fait particulièrement justifiées pour un projet solo. Dans une équipe, l'information transite par les conversations entre collaborateurs — les réunions, les discussions informelles, les revues en direct. Une partie de la mémoire du projet vit dans le réseau humain. Sur un projet solo, ce réseau n'existe pas. Si l'information n'est pas consignée par écrit, elle disparaît complètement dès que tu arrêtes de travailler dessus. La discipline méthodologique n'est pas un luxe qu'on peut se permettre en équipe et qu'on néglige en solo — c'est au contraire une nécessité accrue en solo, parce qu'elle compense l'absence de réseau humain qui porterait sinon une partie de la mémoire.

## Les coûts honnêtes de cette discipline

Je ne veux pas te vendre la discipline de méthode comme sans contrepartie. Elle a des coûts réels qu'il faut regarder honnêtement avant de s'y engager.

Le premier coût est un **ralentissement perceptible en début de projet**. Les premiers commits prennent plus de temps parce qu'il faut réfléchir à leur formulation. Les premières PRs demandent une gymnastique qu'on n'a pas encore intégrée. L'inertie initiale peut décourager — on a l'impression de passer plus de temps à formaliser son travail qu'à produire.

Le second coût est la **tentation permanente de dévier**. Tu auras des moments où tu auras juste besoin de tester quelque chose vite, de commiter un fix urgent sans respecter le format, de sauter la PR pour aller plus vite. Chaque déviation individuelle est défendable, mais la pente est glissante. Une fois qu'on s'autorise une exception, on s'en autorise une deuxième, puis une troisième, et bientôt la discipline n'existe plus.

Le troisième coût, plus subtil, est que **la discipline peut devenir un théâtre**. Si tu appliques les conventions mécaniquement sans en comprendre le sens, elles deviennent un rituel vide qui te ralentit sans te servir. Un commit intitulé `feat(repo): update` respecte la forme mais ne dit rien de plus qu'un commit `update`. Le pire des deux mondes — la friction de la convention sans son bénéfice informationnel.

Face à ces coûts, ma règle personnelle est de commencer strict et de relâcher éventuellement plus tard, jamais l'inverse. Une discipline relâchée se reprend difficilement. Une discipline stricte qu'on choisit d'assouplir sur un point précis reste saine. Sur ce projet, j'applique Conventional Commits et PR même en solo depuis le premier jour, et je le ferai jusqu'au dernier — non pas parce que c'est une obligation externe, mais parce que j'ai vu assez de projets mourir de la négligence méthodologique pour savoir ce qui est en jeu.

## Ce que tu liras dans le chapitre suivant

Le dernier chapitre de cette documentation pédagogique va aborder un sujet qu'on ne pouvait pas éviter dans un projet qui se construit en 2026 : **la collaboration avec une intelligence artificielle**. Comment j'ai travaillé avec Claude sur ce projet, pourquoi j'ai imposé des règles strictes à cette collaboration plutôt que de laisser l'IA coder à ma place, ce que j'ai appris de cette expérience, et pourquoi je pense que la façon dont notre génération apprend à collaborer avec l'IA sera déterminante pour notre trajectoire professionnelle. Ce chapitre sera plus personnel que les autres et assumera davantage de subjectivité — parce que le sujet est encore trop neuf pour qu'on puisse en parler avec la distance qu'on a sur les autres thèmes.

---

**Chapitre précédent** : [06 — WordPress sous le capot](06-wordpress-sous-le-capot.md)
**Chapitre suivant** : [08 — Collaborer avec une IA sans se faire remplacer](08-collaborer-avec-une-ia-sans-se-faire-remplacer.md) *(à rédiger)*

**Retour à** : [documentation pédagogique](README.md) • [documentation d'architecture](../docs/architecture/00-overview.md)
