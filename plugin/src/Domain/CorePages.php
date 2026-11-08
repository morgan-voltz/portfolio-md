<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

use InvalidArgumentException;

/**
 * The four pages a language must hold before it opens to the public
 * (ADR-022, point 2), with the slug of each in each language: a page has a
 * natural slug per language, never the same twice (ADR-020). The key of a
 * group is the slug of its French page, as `translation_key` is for every
 * document, so `content/de/pages/startseite.md` carries
 * `translation_key: accueil`.
 */
final class CorePages
{
    public const HOME = 'accueil';
    public const ABOUT = 'a-propos';
    public const CV = 'cv';
    public const CONTACT = 'contact';

    /** @var array<string, array<string, string>> translation key => language value => slug */
    public const SLUGS = [
        self::HOME => ['fr' => 'accueil', 'de' => 'startseite', 'en' => 'home'],
        self::ABOUT => ['fr' => 'a-propos', 'de' => 'ueber-mich', 'en' => 'about'],
        self::CV => ['fr' => 'cv', 'de' => 'lebenslauf', 'en' => 'resume'],
        self::CONTACT => ['fr' => 'contact', 'de' => 'kontakt', 'en' => 'get-in-touch'],
    ];

    private function __construct()
    {
    }

    /**
     * The translation keys of the four pages, in the order of the site's navigation.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::SLUGS);
    }

    public static function slug(string $key, Language $language): string
    {
        return self::SLUGS[$key][$language->value] ?? throw new InvalidArgumentException("No core page has the key \"{$key}\".");
    }
}
