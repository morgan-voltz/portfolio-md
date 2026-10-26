<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Domain\InvalidSkillsGridException;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Domain\Referential;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Domain\SkillsGrid;

/**
 * Reads content/skills.yaml into a validated SkillsGrid.
 *
 * Validation covers what the taxonomy needs to stay consistent: every
 * referential and skill has a code, a slug, a name and a description; slugs
 * are WordPress-safe; a skill code starts with its referential code; codes
 * and slugs are unique across the whole grid, because WordPress enforces
 * slug uniqueness across a taxonomy whatever the parent (wp_unique_term_slug
 * would silently suffix an insert, and wp_update_term refuses the update).
 */
final class SkillsGridLoader
{
    /**
     * @throws InvalidSkillsGridException
     */
    public function load(string $path): SkillsGrid
    {
        if (!is_file($path) || !is_readable($path)) {
            throw InvalidSkillsGridException::because("file \"{$path}\" is not readable");
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw InvalidSkillsGridException::because('invalid YAML (' . $exception->getMessage() . ')');
        }

        if (!is_array($data)) {
            throw InvalidSkillsGridException::because('the file must be a YAML mapping with a "referentials" list');
        }

        return $this->fromArray($data);
    }

    /**
     * @param array<string, mixed> $data decoded YAML
     *
     * @throws InvalidSkillsGridException
     */
    public function fromArray(array $data): SkillsGrid
    {
        $rawReferentials = $data['referentials'] ?? null;
        if (!is_array($rawReferentials) || !array_is_list($rawReferentials) || $rawReferentials === []) {
            throw InvalidSkillsGridException::because('"referentials" must be a non-empty list');
        }

        $referentials = [];
        $seenCodes = [];
        $seenSlugs = [];

        foreach ($rawReferentials as $index => $raw) {
            if (!is_array($raw)) {
                throw InvalidSkillsGridException::because("referential #{$index} must be a mapping");
            }
            $code = $this->requiredString($raw, 'code', "referential #{$index}");
            if (preg_match(Patterns::REFERENTIAL_CODE, $code) !== 1) {
                throw InvalidSkillsGridException::because("referential code \"{$code}\" must look like 4.0.1");
            }
            $slug = $this->requiredString($raw, 'slug', "referential {$code}");
            if (preg_match(Patterns::SLUG, $slug) !== 1) {
                throw InvalidSkillsGridException::because("referential slug \"{$slug}\" is not a slug");
            }
            if (isset($seenSlugs[$slug])) {
                throw InvalidSkillsGridException::because("slug \"{$slug}\" is used twice (slugs are unique across the whole taxonomy)");
            }
            $seenSlugs[$slug] = true;
            if (isset($seenCodes[$code])) {
                throw InvalidSkillsGridException::because("code \"{$code}\" is used twice");
            }
            $seenCodes[$code] = true;

            $rawSkills = $raw['skills'] ?? null;
            if (!is_array($rawSkills) || !array_is_list($rawSkills) || $rawSkills === []) {
                throw InvalidSkillsGridException::because("referential {$code} must have a non-empty \"skills\" list");
            }

            $skills = [];
            foreach ($rawSkills as $skillIndex => $rawSkill) {
                if (!is_array($rawSkill)) {
                    throw InvalidSkillsGridException::because("skill #{$skillIndex} of referential {$code} must be a mapping");
                }
                $skillCode = $this->requiredString($rawSkill, 'code', "skill #{$skillIndex} of referential {$code}");
                if (preg_match(Patterns::SKILL_CODE, $skillCode) !== 1) {
                    throw InvalidSkillsGridException::because("skill code \"{$skillCode}\" must look like 4.0.1/FR-01");
                }
                if (!str_starts_with($skillCode, $code . '/')) {
                    throw InvalidSkillsGridException::because("skill code \"{$skillCode}\" does not belong to referential {$code}");
                }
                if (isset($seenCodes[$skillCode])) {
                    throw InvalidSkillsGridException::because("code \"{$skillCode}\" is used twice");
                }
                $seenCodes[$skillCode] = true;
                $skillSlug = $this->requiredString($rawSkill, 'slug', "skill {$skillCode}");
                if (preg_match(Patterns::SLUG, $skillSlug) !== 1) {
                    throw InvalidSkillsGridException::because("skill slug \"{$skillSlug}\" is not a slug");
                }
                if (isset($seenSlugs[$skillSlug])) {
                    throw InvalidSkillsGridException::because("slug \"{$skillSlug}\" is used twice (slugs are unique across the whole taxonomy)");
                }
                $seenSlugs[$skillSlug] = true;

                $skills[] = new Skill(
                    $skillCode,
                    $skillSlug,
                    $this->requiredString($rawSkill, 'name', "skill {$skillCode}"),
                    $this->requiredString($rawSkill, 'description', "skill {$skillCode}"),
                );
            }

            $referentials[] = new Referential(
                $code,
                $slug,
                $this->requiredString($raw, 'name', "referential {$code}"),
                $this->requiredString($raw, 'description', "referential {$code}"),
                $skills,
            );
        }

        return new SkillsGrid($referentials);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function requiredString(array $raw, string $key, string $where): string
    {
        $value = $raw[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw InvalidSkillsGridException::because("{$where}: \"{$key}\" must be a non-empty string");
        }

        return trim($value);
    }
}
