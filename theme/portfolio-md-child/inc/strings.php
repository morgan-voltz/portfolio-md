<?php

declare(strict_types=1);

/**
 * The few words the theme says itself, in the three languages of the site
 * (ADR-022, point 14): the title of the project archive, the label of the
 * language switcher, the sentence behind the badge of a card served in
 * another language, the names of the languages, the heading and the link
 * of the recent projects block of the home page. Everything else on a page
 * comes from the documents. French is the fallback of an unknown language.
 */

/**
 * @return array<string, array<string, string>> language slug => key => text
 */
function portfolio_md_child_strings(): array
{
    return [
        'fr' => [
            'projects' => 'Projets',
            'no_projects' => 'Aucun projet pour le moment.',
            'languages' => 'Langues',
            'only_in' => 'Cette page n’est disponible qu’en %s.',
            'recent_projects' => 'Projets récents',
            'all_projects' => 'Tous les projets',
        ],
        'de' => [
            'projects' => 'Projekte',
            'no_projects' => 'Noch keine Projekte.',
            'languages' => 'Sprachen',
            'only_in' => 'Diese Seite ist nur auf %s verfügbar.',
            'recent_projects' => 'Neueste Projekte',
            'all_projects' => 'Alle Projekte',
        ],
        'en' => [
            'projects' => 'Projects',
            'no_projects' => 'No project yet.',
            'languages' => 'Languages',
            'only_in' => 'This page is only available in %s.',
            'recent_projects' => 'Recent projects',
            'all_projects' => 'All projects',
        ],
    ];
}

/**
 * @return array<string, array<string, string>> language of the reader => language slug => its name in that language
 */
function portfolio_md_child_language_names(): array
{
    return [
        'fr' => ['fr' => 'français', 'de' => 'allemand', 'en' => 'anglais'],
        'de' => ['fr' => 'Französisch', 'de' => 'Deutsch', 'en' => 'Englisch'],
        'en' => ['fr' => 'French', 'de' => 'German', 'en' => 'English'],
    ];
}

/** A text of the theme in the language of the reader, French when the language or the key is unknown. */
function portfolio_md_child_text(string $key, string $language): string
{
    $strings = portfolio_md_child_strings();
    $table = $strings[$language] ?? $strings['fr'];

    return $table[$key] ?? $strings['fr'][$key] ?? '';
}

/** The name of a language as a reader of $language calls it; the slug in capitals when unknown. */
function portfolio_md_child_language_name(string $slug, string $language): string
{
    $names = portfolio_md_child_language_names();
    $table = $names[$language] ?? $names['fr'];

    return $table[$slug] ?? strtoupper($slug);
}
