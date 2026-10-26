<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Taxonomies;

use Voltz\PortfolioMd\Meta\Sanitizer;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;

/**
 * Enregistre les taxonomies du portfolio.
 *
 * Cette classe est invoquée par la composition root (Plugin) au moment
 * du hook WordPress 'init', juste après PostTypeRegistrar. L'ordre
 * importe : on doit avoir déclaré les post types avant de pouvoir y
 * attacher des taxonomies.
 *
 * Les trois taxonomies du portfolio :
 *   - tech_tag       : technologies / concepts. Partagée article + project.
 *   - project_status : statut du projet. Réservée à project, valeurs fermées.
 *   - skill          : compétences académiques UHA 4.0. Réservée à project,
 *                      hiérarchique (référentiel > compétence).
 *
 * Voir docs/architecture/04-structure-wordpress.md section 4 et les
 * ADR-011 / ADR-014 pour le raisonnement complet.
 */
final class TaxonomyRegistrar
{
    /**
     * Domaine de traduction du plugin.
     */
    private const TEXT_DOMAIN = 'portfolio-md';

    /**
     * Slug de la taxonomie tech_tag (utilisé dans le code et les URLs).
     */
    public const TECH_TAG = 'tech_tag';

    /**
     * Slug de la taxonomie project_status.
     */
    public const PROJECT_STATUS = 'project_status';

    /**
     * Slug de la taxonomie skill (compétences académiques).
     */
    public const SKILL = 'skill';

    /**
     * Clé du term meta qui porte le code officiel d'une compétence,
     * tel qu'il apparaît dans le référentiel UHA 4.0 (ex. « 4.0.1/C1.3 »).
     *
     * Le code ne peut pas servir de slug (le point et la barre oblique
     * sont interdits dans un slug WordPress), d'où ce stockage séparé.
     * Le slug du terme est dérivé du code par le pipeline Markdown.
     */
    public const SKILL_CODE_META = '_portfolio_skill_code';

    /**
     * Longueur maximale acceptée pour un code de compétence.
     */
    public const SKILL_CODE_MAX_LENGTH = 32;

    /**
     * Statuts fermés du post type project, sous forme [slug => libellé].
     *
     * Le slug est l'identifiant technique stocké en base.
     * Le libellé est ce qu'on affiche dans l'admin et le front.
     *
     * Cette liste est délibérément fermée : on ne s'attend pas à voir
     * de nouvelles valeurs ajoutées à la volée. Si un nouveau statut
     * devait apparaître un jour, ça mériterait une décision documentée.
     */
    public const PROJECT_STATUS_TERMS = [
        'en-cours'  => 'En cours',
        'stable'    => 'Stable',
        'archive'   => 'Archivé',
        'prototype' => 'Prototype',
    ];

    /**
     * Référentiels de compétences UHA 4.0, sous forme [slug => libellé].
     *
     * Ce sont les termes parents de la taxonomie skill. Les compétences
     * elles-mêmes sont des termes enfants, créés par le pipeline Markdown
     * à partir du frontmatter (ou à la main dans l'admin). Les référentiels
     * sont stables sur la durée de la formation, donc auto-insérés à
     * l'activation comme les statuts de projet.
     */
    public const SKILL_REFERENTIALS = [
        '4-0-1' => 'Compétences 4.0.1',
        '4-0-2' => 'Compétences 4.0.2',
    ];

    /**
     * Méthode appelée par le hook WordPress 'init'.
     * Enregistre toutes les taxonomies du plugin.
     */
    public function register(): void
    {
        $this->registerTechTag();
        $this->registerProjectStatus();
        $this->registerSkill();
    }

    /**
     * Insère les termes fermés de project_status si absents.
     *
     * Cette méthode est appelée à l'activation du plugin (depuis Plugin::onActivation),
     * pas au hook 'init'. Elle est idempotente : si les termes existent déjà,
     * elle ne fait rien.
     *
     * Note : WordPress ne fournit pas de mécanisme natif pour interdire
     * la création de termes additionnels via l'admin. À l'étape 3 du
     * plan de plugin, on pourra durcir cela (capabilities custom ou
     * masquage du formulaire d'ajout). Pour l'instant, c'est documenté
     * dans 04-structure-wordpress.md comme une convention.
     */
    public function ensureProjectStatusTerms(): void
    {
        $this->ensureTerms(self::PROJECT_STATUS, self::PROJECT_STATUS_TERMS);
    }

    /**
     * Insère les termes parents (référentiels) de skill si absents.
     *
     * Même mécanique et même moment d'appel que ensureProjectStatusTerms().
     */
    public function ensureSkillReferentialTerms(): void
    {
        $this->ensureTerms(self::SKILL, self::SKILL_REFERENTIALS);
    }

    /**
     * Insère chaque terme [slug => libellé] absent de la taxonomie donnée.
     *
     * @param array<string, string> $terms
     */
    private function ensureTerms(string $taxonomy, array $terms): void
    {
        foreach ($terms as $slug => $label) {
            // term_exists retourne null si absent, ou un tableau/int si présent.
            if (term_exists($slug, $taxonomy) === null) {
                wp_insert_term($label, $taxonomy, ['slug' => $slug]);
            }
        }
    }

    /**
     * Taxonomie tech_tag : tags techniques partagés entre articles et projets.
     *
     * Pourquoi pas la taxonomie post_tag native :
     *   - séparation des concerns (post_tag est conçue pour les posts natifs) ;
     *   - slug d'URL propre (/tag/rust/ uniquement pour nos tags techniques) ;
     *   - expressivité du nom (tech_tag dit clairement de quoi on parle).
     */
    private function registerTechTag(): void
    {
        register_taxonomy(self::TECH_TAG, [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT], [
            'labels' => [
                'name'                       => _x('Tags techniques', 'taxonomy general name', self::TEXT_DOMAIN),
                'singular_name'              => _x('Tag technique', 'taxonomy singular name', self::TEXT_DOMAIN),
                'menu_name'                  => __('Tags techniques', self::TEXT_DOMAIN),
                'all_items'                  => __('Tous les tags', self::TEXT_DOMAIN),
                'edit_item'                  => __('Modifier le tag', self::TEXT_DOMAIN),
                'view_item'                  => __('Voir le tag', self::TEXT_DOMAIN),
                'update_item'                => __('Mettre à jour le tag', self::TEXT_DOMAIN),
                'add_new_item'               => __('Ajouter un tag', self::TEXT_DOMAIN),
                'new_item_name'              => __('Nom du nouveau tag', self::TEXT_DOMAIN),
                'search_items'               => __('Rechercher un tag', self::TEXT_DOMAIN),
                'popular_items'              => __('Tags les plus utilisés', self::TEXT_DOMAIN),
                'separate_items_with_commas' => __('Séparer les tags par des virgules', self::TEXT_DOMAIN),
                'add_or_remove_items'        => __('Ajouter ou retirer des tags', self::TEXT_DOMAIN),
                'choose_from_most_used'      => __('Choisir parmi les tags les plus utilisés', self::TEXT_DOMAIN),
                'not_found'                  => __('Aucun tag trouvé.', self::TEXT_DOMAIN),
            ],

            // Visibilité.
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true, // Indispensable pour Gutenberg.
            'show_tagcloud'      => true,
            'show_admin_column'  => true, // Affiche une colonne dans la liste des contenus.

            // Taxonomie plate (comme les tags natifs, pas comme les catégories).
            'hierarchical' => false,

            // URL : /tag/<slug>/.
            'rewrite' => [
                'slug'         => 'tag',
                'with_front'   => false,
                'hierarchical' => false,
            ],
        ]);
    }

    /**
     * Taxonomie project_status : statut du projet (En cours, Stable, etc.).
     *
     * Caractéristiques notables :
     *   - réservée au post type 'project' uniquement ;
     *   - valeurs fermées (voir PROJECT_STATUS_TERMS et ensureProjectStatusTerms) ;
     *   - URL en /statut/<slug>/.
     */
    private function registerProjectStatus(): void
    {
        register_taxonomy(self::PROJECT_STATUS, [PostTypeRegistrar::PROJECT], [
            'labels' => [
                'name'                  => _x('Statuts de projet', 'taxonomy general name', self::TEXT_DOMAIN),
                'singular_name'         => _x('Statut de projet', 'taxonomy singular name', self::TEXT_DOMAIN),
                'menu_name'             => __('Statuts', self::TEXT_DOMAIN),
                'all_items'             => __('Tous les statuts', self::TEXT_DOMAIN),
                'edit_item'             => __('Modifier le statut', self::TEXT_DOMAIN),
                'view_item'             => __('Voir le statut', self::TEXT_DOMAIN),
                'update_item'           => __('Mettre à jour le statut', self::TEXT_DOMAIN),
                'add_new_item'          => __('Ajouter un statut', self::TEXT_DOMAIN),
                'new_item_name'         => __('Nom du nouveau statut', self::TEXT_DOMAIN),
                'search_items'          => __('Rechercher un statut', self::TEXT_DOMAIN),
                'parent_item'           => __('Statut parent', self::TEXT_DOMAIN),
                'parent_item_colon'     => __('Statut parent :', self::TEXT_DOMAIN),
                'not_found'             => __('Aucun statut trouvé.', self::TEXT_DOMAIN),
            ],

            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'show_tagcloud'      => false, // Un statut n'est pas un tag.
            'show_admin_column'  => true,

            // Plate (comme tech_tag).
            'hierarchical' => false,

            // URL : /statut/<slug>/.
            'rewrite' => [
                'slug'         => 'statut',
                'with_front'   => false,
                'hierarchical' => false,
            ],
        ]);
    }

    /**
     * Taxonomie skill : compétences académiques UHA 4.0 démontrées par un projet.
     *
     * Caractéristiques notables :
     *   - réservée au post type 'project' (ce sont les réalisations qui
     *     prouvent une compétence, pas les articles) ;
     *   - hiérarchique : un terme parent par référentiel (voir
     *     SKILL_REFERENTIALS), un terme enfant par compétence. Le jury
     *     raisonne par référentiel, et les archives WordPress donnent
     *     gratuitement « tous les projets qui démontrent cette compétence » ;
     *   - le code officiel de la compétence est stocké en term meta
     *     (SKILL_CODE_META), car il n'est pas un slug valide ;
     *   - URL en /competences/<referentiel>/<competence>/.
     */
    private function registerSkill(): void
    {
        register_taxonomy(self::SKILL, [PostTypeRegistrar::PROJECT], [
            'labels' => [
                'name'              => _x('Compétences', 'taxonomy general name', self::TEXT_DOMAIN),
                'singular_name'     => _x('Compétence', 'taxonomy singular name', self::TEXT_DOMAIN),
                'menu_name'         => __('Compétences', self::TEXT_DOMAIN),
                'all_items'         => __('Toutes les compétences', self::TEXT_DOMAIN),
                'edit_item'         => __('Modifier la compétence', self::TEXT_DOMAIN),
                'view_item'         => __('Voir la compétence', self::TEXT_DOMAIN),
                'update_item'       => __('Mettre à jour la compétence', self::TEXT_DOMAIN),
                'add_new_item'      => __('Ajouter une compétence', self::TEXT_DOMAIN),
                'new_item_name'     => __('Nom de la nouvelle compétence', self::TEXT_DOMAIN),
                'search_items'      => __('Rechercher une compétence', self::TEXT_DOMAIN),
                'parent_item'       => __('Référentiel', self::TEXT_DOMAIN),
                'parent_item_colon' => __('Référentiel :', self::TEXT_DOMAIN),
                'not_found'         => __('Aucune compétence trouvée.', self::TEXT_DOMAIN),
            ],

            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => true,
            'show_in_rest'       => true,
            'show_tagcloud'      => false,
            'show_admin_column'  => true,

            // Hiérarchique : référentiel > compétence.
            'hierarchical' => true,

            // URL : /competences/<referentiel>/<competence>/.
            'rewrite' => [
                'slug'         => 'competences',
                'with_front'   => false,
                'hierarchical' => true,
            ],
        ]);

        register_term_meta(self::SKILL, self::SKILL_CODE_META, [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'description'       => 'Official competency code from the UHA 4.0 referential, e.g. "4.0.1/C1.3".',
            'sanitize_callback' => [self::class, 'sanitizeSkillCode'],
            'auth_callback'     => [self::class, 'authorizeEditSkillTerm'],
            'show_in_rest'      => true,
        ]);
    }

    /**
     * Code de compétence : texte court sans balises, tronqué à SKILL_CODE_MAX_LENGTH.
     *
     * Le format exact des codes dépend du référentiel UHA 4.0 ; on reste
     * volontairement permissif ici, la validation stricte viendra avec le
     * pipeline Markdown quand les vrais codes seront connus.
     */
    public static function sanitizeSkillCode(mixed $value): string
    {
        return Sanitizer::text($value, self::SKILL_CODE_MAX_LENGTH);
    }

    /**
     * auth_callback du term meta : la clé commence par un underscore, donc
     * WordPress la protège par défaut (__return_false). On rétablit la règle
     * naturelle : qui peut éditer le terme peut éditer sa meta.
     */
    public static function authorizeEditSkillTerm(bool $allowed, string $metaKey, int $termId, int $userId): bool
    {
        return user_can($userId, 'edit_term', $termId);
    }
}
