<?php

declare(strict_types=1);

/**
 * The few words the theme says itself, in the three languages of the site
 * (ADR-022, point 14; phase 10): the title of the project archive, the label
 * of the language switcher, the sentence behind the badge of a card served
 * in another language, the names of the languages, the heading and the link
 * of the recent projects block of the home page and, on the page of a
 * project, the labels of its facts (status, period, role, client,
 * repository, demo), the fil rouge mention, the headings of its tags and
 * skills, the asterisk and the note of a provisional wording and the names
 * of the months.
 * Everything else on a page comes from the documents. French is the
 * fallback of an unknown language.
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
            'project_facts' => 'Fiche du projet',
            'status' => 'Statut',
            'period' => 'Période',
            'since' => 'depuis %s',
            'role' => 'Rôle',
            'client' => 'Commanditaire',
            'repository' => 'Dépôt',
            'demo' => 'Démo',
            'fil_rouge' => 'Projet fil rouge UHA 4.0.2',
            'technologies' => 'Technologies',
            'skills' => 'Compétences démontrées',
            'provisional' => 'intitulé provisoire',
            'provisional_note' => 'Les intitulés marqués d’un astérisque sont provisoires : la feuille de suivi officielle de l’année n’est pas encore publiée.',
        ],
        'de' => [
            'projects' => 'Projekte',
            'no_projects' => 'Noch keine Projekte.',
            'languages' => 'Sprachen',
            'only_in' => 'Diese Seite ist nur auf %s verfügbar.',
            'recent_projects' => 'Neueste Projekte',
            'all_projects' => 'Alle Projekte',
            'project_facts' => 'Projektdaten',
            'status' => 'Status',
            'period' => 'Zeitraum',
            'since' => 'seit %s',
            'role' => 'Rolle',
            'client' => 'Auftraggeber',
            'repository' => 'Quellcode',
            'demo' => 'Demo',
            'fil_rouge' => 'Leitprojekt (roter Faden) UHA 4.0.2',
            'technologies' => 'Technologien',
            'skills' => 'Nachgewiesene Kompetenzen',
            'provisional' => 'vorläufige Bezeichnung',
            'provisional_note' => 'Mit einem Sternchen markierte Bezeichnungen sind vorläufig: Der offizielle Kompetenzbogen des Jahrgangs ist noch nicht veröffentlicht.',
        ],
        'en' => [
            'projects' => 'Projects',
            'no_projects' => 'No project yet.',
            'languages' => 'Languages',
            'only_in' => 'This page is only available in %s.',
            'recent_projects' => 'Recent projects',
            'all_projects' => 'All projects',
            'project_facts' => 'Project facts',
            'status' => 'Status',
            'period' => 'Period',
            'since' => 'since %s',
            'role' => 'Role',
            'client' => 'Client',
            'repository' => 'Repository',
            'demo' => 'Demo',
            'fil_rouge' => 'Storyline project (fil rouge) UHA 4.0.2',
            'technologies' => 'Technologies',
            'skills' => 'Skills demonstrated',
            'provisional' => 'provisional wording',
            'provisional_note' => 'Wordings marked with an asterisk are provisional: the official skills sheet of the year is not published yet.',
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

/**
 * The names of the months, January first, as a reader of each language
 * writes them in a date ("septembre 2026", "September 2026"): the period
 * of a project is rendered by the theme, not by WordPress's locale, so that
 * it follows the language of the page like every other word of the theme.
 *
 * @return array<string, list<string>> language slug => twelve names
 */
function portfolio_md_child_month_names(): array
{
    return [
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'de' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
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

/** The name of a month (1 to 12) as a reader of $language writes it, French for an unknown language, empty outside 1..12. */
function portfolio_md_child_month_name(int $month, string $language): string
{
    $names = portfolio_md_child_month_names();
    $table = $names[$language] ?? $names['fr'];

    return $table[$month - 1] ?? '';
}
