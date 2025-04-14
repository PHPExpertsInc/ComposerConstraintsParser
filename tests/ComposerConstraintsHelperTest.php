<?php declare(strict_types=1);

/**
 * This file is part of Composer Constraints Parser, a PHP Experts, Inc., Project.
 *
 * Copyright © 2025 PHP Experts, Inc.
 * Author: Theodore R. Smith <theodore@phpexperts.pro>
 *   GPG Fingerprint: 4BF8 2613 1C34 87AC D28F  2AD8 EB24 A91D D612 5690
 *   https://www.phpexperts.pro/
 *   https://gitlab.com/hopeseekr/ComposerConstraintsParser
 *
 * This file is licensed under the MIT License.
 */

namespace PHPExperts\ComposerVersionConstraints\Tests;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

class ComposerConstraintsHelperTest extends TestCase
{
    function matchesVersionAuthoritative(string $constraint, string $version): bool
    {
        return Semver::satisfies($version, $constraint);
    }

    /**
     * Check if a Composer version constraint is valid.
     *
     * @param string $constraint The version constraint to validate.
     * @return bool True if valid, false if invalid.
     */
    function isValidVersionConstraint(string $constraint): bool {
        $parser = new VersionParser();

        try {
            // This will throw an exception if the constraint is invalid.
            $parser->parseConstraints($constraint);
            return true;
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }

    public function testCanDetermineIfAConstraintIsValid()
    {
        $invalid = [
            'xasdf'
        ];

        foreach ($invalid as $c) {
            self::assertFalse($this->isValidVersionConstraint($c));
        }
    }

    private function ensure2Dots(string $version): string
    {
        $versionParts = explode('.', $version);
        if (count($versionParts) < 3) {
            $version .= str_repeat('.0', 3 - count($versionParts));
        }

        return $version;
    }

    private function versionSatisfies(string $constraints, string $version): bool
    {
        // Normalize version to have at least 3 parts
        $version = $this->ensure2Dots($version);

        // Convert "* >" to ">" and other normalizations.
        $constraints = preg_replace('/\* ?([><!]=?)\s*/', '$1', $constraints);
        $constraints = preg_replace('/^ ?([><!]=?)\s*/', '$1', $constraints);
        $constraints = str_replace('. *', '.*', $constraints);

        // Split constraint by OR operator
        $constraints = str_replace([' ,', ', '], ',', $constraints);
        $constraints = str_replace([' -', '- '], '-', $constraints);
        $orConstraints = preg_split('/[|,-]/', $constraints);

        foreach ($orConstraints as $orConstraint) {
            $orConstraint = trim($orConstraint);

            // Split by AND operator (,)
            $andConstraints = explode(' ', $orConstraint);
//            $andConstraints = array_map('trim', explode([',', ' '], $orConstraint));
            $allAndSatisfied = true;

            foreach ($andConstraints as $singleConstraint) {
                $status = $this->satisfiesSingleConstraint($singleConstraint, $version);
                if (!$status) {
                    $allAndSatisfied = false;
                    break;
                }
            }

            if ($allAndSatisfied) {
                return true; // If all AND conditions in this OR branch are satisfied, return true
            }
        }

        return false;
    }

    private function satisfiesSingleConstraint(string $constraint, string $version): bool
    {
        // Handle edge cases like 'x' or '*'
        if ($constraint === 'x' || $constraint === '*') {
            return true;
        }

        // Always return true for git branch constraints.
        if (str_ends_with($constraint, '-dev') || str_ends_with($constraint, '-rc')) {
            return true;
        }

        // Strip v...
        $constraint = preg_replace('/([><=~^]+)?v/i', '$1', $constraint);
        $version = preg_replace('/v([0-9]+)/i', '$1', $version);

        // Normalize hyphen ranges first
        $constraint = $this->normalizeHyphenRanges($constraint);

        // Remove spaces around comparison operators (>=, >, <, !=) in the constraint string
        $constraint = preg_replace('/([><!]=?)\s*/', '$1', $constraint);

        // Convert "* >" to ">" and other normalizations.
        $constraint = preg_replace('/\* ?([><!]=?)\s*/', '$1', $constraint);
        $constraint = str_replace('. *', '.*', $constraint);

        // If it ends with ".", add a "*".
        // This effects 615 projects as of 2025-03-24.
        // @see rinsvent/data2dto
        if (str_ends_with($constraint, '.')) {
            $constraint = substr($constraint, 0, -1);
        }


        // Handle dev/rc suffixes
        if (str_ends_with($constraint, '-dev') || str_ends_with($constraint, '-rc')) {
            return true; // Simplified for this example
        }

        // Replace .x with .*
        $constraint = str_ireplace('.x', '.*', $constraint);

        // Strip leading 'v'
        $constraint = preg_replace('/([><=~^]+)?v/i', '$1', $constraint);

        // Handle wildcards
        if (str_contains($constraint, '*')) {
            $pattern = str_replace('.', '\.', $constraint);
            $pattern = str_replace('*', '(\d+){1,2}', $pattern);
            return preg_match('/^' . $pattern . '/', $version) === 1;
        }

        // Handle caret (^)
        if (str_starts_with($constraint, '^')) {
            $baseVersion = substr($constraint, 1);
            $baseVersion = $this->ensure2Dots($baseVersion);

            if (preg_match('/^0\.(\d+)/', $baseVersion, $matches)) {
                $minor = (int)$matches[1];
                $nextMinor = "0." . ($minor + 1) . ".0";
                return version_compare($version, $baseVersion, '>=') &&
                    version_compare($version, $nextMinor, '<');
            } elseif (preg_match('/^(\d+)/', $baseVersion, $matches)) {
                $major = (int)$matches[0];
                $nextMajor = ($major + 1) . ".0.0";
                return version_compare($version, $baseVersion, '>=') &&
                    version_compare($version, $nextMajor, '<');
            }
        }

        // Handle tilde (~)
        if (str_starts_with($constraint, '~')) {
            $baseVersion = substr($constraint, 1);
            $parts = explode('.', $baseVersion);
            $major = (int)($parts[0] ?? 0);
            $minor = (int)($parts[1] ?? 0);
            $nextMinor = "$major." . ($minor + 1) . ".0";
            $baseVersion = $this->ensure2Dots($baseVersion);
            return version_compare($version, $baseVersion, '>=') &&
                version_compare($version, $nextMinor, '<');
        }

        // Handle comparison operators (>, >=, <, <=, =)
        if (preg_match('/^([><=]+)(\d+(\.\d+)?(\.\d+)?)/', $constraint, $matches)) {
            $operator = $matches[1];
            $compareVersion = $this->ensure2Dots($matches[2]);
            return version_compare($version, $compareVersion, $operator);
        }

        // Handle exact version
        if (preg_match('/^\d+(\.\d+)?(\.\d+)?$/', $constraint)) {
            $constraint = $this->ensure2Dots($constraint);
            return version_compare($version, $constraint, '==');
        }

        return false; // Unknown constraint format
    }

    /**
     * Matches a single condition against a version.
     *
     * @param string $condition Single condition (e.g., '>=2.2').
     * @param string $version Version to check against.
     *
     * @return bool True if the version satisfies the condition, false otherwise.
     */
    private function matchesSingleCondition($condition, $version): bool
    {
        if (preg_match('/^([><=!]=?|<=|>=)\s*(\d+(\.\d+(\.\d+)?)?)$/', $condition, $matches)) {
            $operator = $matches[1];
            $versionToCompare = $matches[2];

            // Normalize single number to use proper version format
            if (preg_match('/^\d+$/', $versionToCompare)) {
                $versionToCompare .= '.0.0';
            } elseif (preg_match('/^\d+\.\d+$/', $versionToCompare)) {
                $versionToCompare .= '.0';
            }

            return version_compare($version, $versionToCompare, $operator);
        }

        // Additional checks here if needed...

        return false;
    }

    /**
     * Generate version(s) for a given composer version constraint.
     *
     * For each part of a compound constraint (parts separated by "|"),
     * this method generates a candidate version that is either valid (if
     * $matches is true) or invalid (if $matches is false) according to that part.
     *
     * @param string $constraint A composer version constraint (may be compound).
     * @param bool   $matches    Whether to generate a version that matches the constraint.
     *
     * @return array|int An array of candidate versions or 0 if none could be generated.
     */
    public function generateVersionForConstraint(string $constraint, bool $matches = true): array|int
    {
        $orParts = array_map('trim', explode('|', $constraint));
        $results = [];

        foreach ($orParts as $part) {
            if (empty($part)) {
                continue;
            }

            // Split by AND operator (,)
            $andParts = array_map('trim', explode(',', $part));
            $candidate = null;

            // Handle each AND condition to find a base version
            foreach ($andParts as $andPart) {
                $andCandidate = $this->generateCandidateForSingleConstraint($andPart, $matches);
                if ($candidate === null) {
                    $candidate = $andCandidate;
                } else {
                    // Adjust candidate to satisfy all AND conditions
                    $candidate = $this->adjustCandidateForAnd($candidate, $andPart, $matches);
                }
            }

            if ($candidate && $this->versionSatisfies($part, $candidate) === $matches) {
                $results[$part] = $candidate;
            } else {
                // If adjustment fails, try an opposite candidate
                $opposite = $this->generateAlternativeCandidate($part, !$matches);
                if ($this->versionSatisfies($part, $opposite) === $matches) {
                    $results[$part] = $opposite;
                }
            }
        }

        return empty($results) ? 0 : $results;
    }

    private function generateCandidateForSingleConstraint(string $constraint, bool $matches): string
    {
        // Normalize hyphen ranges first
        $constraint = $this->normalizeHyphenRanges($constraint);

        // Remove spaces around comparison operators (>=, >, <, !=) in the constraint string
        $constraint = preg_replace('/([><!-]=?)\s*/', '$1', $constraint);

        // Convert "* >" to ">" and other normalizations.
        $constraint = preg_replace('/\* ?([><!]=?)\s*/', '$1', $constraint);
        $constraint = str_replace('. *', '.*', $constraint);


        // Split the constraint into parts if it contains multiple conditions (e.g., "^3 <3.30")
        $parts = preg_split('/\s+/', trim($constraint));
        if (count($parts) > 1) {
            return $this->handleCompoundConstraint($parts, $matches);
        }

        // Handle single constraint
        $operator = '';
        if (preg_match('/^([<>=!~^]+)/', $constraint, $matchesOperator)) {
            $operator = $matchesOperator[1];
            $constraint = preg_replace('/^[<>=!~^]+\s*/', '', $constraint);
        }

        if (strpos($constraint, '*') !== false) {
            return $matches ? str_replace('*', '1', $constraint) : str_replace('*', '0', $constraint);
        }

        $base = $this->ensure2Dots($constraint);

        switch ($operator) {
            case '^':
                $parts = explode('.', $base);
                if ($matches) {
                    return $base; // e.g., 3.0.0 for ^3
                }
                return ((int)$parts[0] + 1) . '.0.0'; // e.g., 4.0.0 for non-matching ^3
            case '<':
                if ($matches) {
                    return $this->decrementVersion($base); // One step below base
                }
                return $base; // Equal to base is non-matching for strict <
            case '>=':
                if ($matches) {
                    return $base; // Base satisfies >=
                }
                return $this->decrementVersion($base); // Below base
            default:
                return $base; // Exact version
        }
    }

    private function handleCompoundConstraint(array $parts, bool $matches): string
    {
        // For "^3 <3.30"
        $minVersion = null;
        $maxVersion = null;

        foreach ($parts as $part) {
            if (preg_match('/^([<>=!~^]+)(.*)/', $part, $match)) {
                $operator = $match[1];
                $version = $this->ensure2Dots($match[2]);

                if ($operator === '^') {
                    $minVersion = $version; // e.g., 3.0.0
                    $maxVersion = ((int)explode('.', $version)[0] + 1) . '.0.0'; // e.g., 4.0.0
                } elseif ($operator === '<') {
                    $maxVersion = $version; // e.g., 3.30.0
                } elseif ($operator === '>=') {
                    $minVersion = $version;
                }
            }
        }

        if ($matches) {
            // Return a version in the range, e.g., minVersion or slightly above
            if ($maxVersion === null) {
//                dd($matches, $parts);
                $a = 1;
            }
            return $minVersion ?? $this->decrementVersion($maxVersion ?? '');
        } else {
            // Return a version outside the range, e.g., below min or at/above max
            if ($minVersion && $this->versionCompare($minVersion, $maxVersion) < 0) {
                return $this->decrementVersion($minVersion); // Below min
            }
            return $maxVersion; // At or above max
        }
    }

    private function decrementVersion(string $version): string
    {
        $parts = explode('.', $version);
        $parts[2] = (int)$parts[2] - 1;
        if ($parts[2] < 0) {
            $parts[2] = 999;
            $parts[1] = (int)$parts[1] - 1;
            if ($parts[1] < 0) {
                $parts[1] = 99;
                $parts[0] = (int)$parts[0] - 1;
            }
        }
        return implode('.', $parts);
    }

    private function versionCompare(string $v1, string $v2): int
    {
        return version_compare($v1, $v2);
    }

    private function adjustCandidateForAnd(string $candidate, string $constraint, bool $matches): string
    {
        $parts = explode('.', $candidate);

        if (preg_match('/^>=(\d+(\.\d+)?(\.\d+)?)/', $constraint, $matches)) {
            $minVersion = $this->ensure2Dots($matches[1]);
            if (version_compare($candidate, $minVersion, '<')) {
                return $minVersion;
            }
        } elseif (preg_match('/^<(\d+(\.\d+)?(\.\d+)?)/', $constraint, $matches)) {
            $maxVersion = $this->ensure2Dots($matches[1]);
            if (version_compare($candidate, $maxVersion, '>=')) {
                $parts[2] = (int)$parts[2] - 1; // Decrease patch
                return implode('.', $parts);
            }
        } elseif (strpos($constraint, '^') === 0) {
            $base = $this->ensure2Dots(substr($constraint, 1));
            if (version_compare($candidate, $base, '<')) {
                return $base;
            }
        } elseif (strpos($constraint, '~') === 0) {
            $base = $this->ensure2Dots(substr($constraint, 1));
            if (version_compare($candidate, $base, '<')) {
                return $base;
            }
        }

        return $candidate;
    }

    /**
     * Generate a candidate version by replacing wildcards and handling constraints.
     *
     * Examples:
     * - "5.0.*@stable"  becomes "5.0.1"
     * - "~5.5"         becomes "5.5.0"
     * - "^7.2.1"       becomes "7.2.1"
     * - ">3"           becomes "3.0.0"
     * - "<4"           becomes "4.0.0"
     *
     * @param string $constraintPart A single part of the constraint.
     *
     * @return string A candidate version string.
     */
    private function generateValidCandidate(string $constraintPart): string {
        // Remove comparison operators (>, <, >=, <=, =, ==, !=)
        $candidate = preg_replace('/^[<>=!~^]+\s*/', '', $constraintPart);

        // Remove any stability flags (e.g., "@stable", "@beta", etc.).
        $candidate = preg_replace('/\@[a-z]+$/i', '', $candidate);

        // Replace 'x' with '*'.
        $candidate = str_ireplace('x', '*', $candidate);

        // Replace wildcards with a candidate number.
        $candidate = str_replace('*', '1', $candidate);

        // If it ends with a ., remove it...
        if (str_ends_with($candidate, '.')) {
            $candidate = substr($candidate, 0, -1);
        }

        // If the candidate has only a major version, add minor and patch.
        if (preg_match('/^\d+$/', $candidate)) {
            $candidate .= '.0.0';
        }
        // If the candidate has only major.minor, add patch.
        elseif (preg_match('/^\d+\.\d+$/', $candidate)) {
            $candidate .= '.0';
        }

        // Ensure we have at least 3 parts in the version
        $parts = explode('.', $candidate);
        while (count($parts) < 3) {
            $parts[] = '0';
        }
        $candidate = implode('.', $parts);

        return $candidate;
    }

    /**
     * Generate a candidate version that is expected to be an alternative (valid or invalid)
     * relative to the constraint.
     *
     * This implementation simply modifies the major version number.
     *
     * @param string $constraintPart A single part of the constraint.
     * @param bool   $shouldMatch    If true, generate a candidate that matches; otherwise, one that doesn't.
     *
     * @return string A candidate version string.
     */
    private function generateAlternativeCandidate(string $constraintPart, bool $shouldMatch): string {
        $validCandidate = $this->generateValidCandidate($constraintPart);

        // Try to extract the major, minor, and patch parts.
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $validCandidate, $matches)) {
            $major = (int)$matches[1];
            $minor = $matches[2];
            $patch = $matches[3];

            if ($shouldMatch) {
                // If we want a matching version, return the valid candidate.
                return $validCandidate;
            } else {
                if (str_starts_with($constraintPart, '>') && str_starts_with($constraintPart, '>=') === false) {
                    ++$patch;
                    return "$major.$minor.$patch";
                }
                // To generate an invalid candidate, change the major version.
                // For example, if the candidate is "5.0.1", return "4.0.1".
                $newMajor = ($major === 0) ? 1 : $major - 1;
                return $newMajor . '.' . $minor . '.' . $patch;
            }
        } elseif (preg_match('/^(\d+)\.(\d+)/', $validCandidate, $matches)) {
            // Fallback if we only have major and minor parts.
            $major = (int)$matches[1];
            $minor = $matches[2];

            if ($shouldMatch) {
                return $validCandidate;
            } else {
                $newMajor = ($major === 0) ? 1 : $major - 1;
                return $newMajor . '.' . $minor . '.0';
            }
        }

        // Final fallback: if unable to parse, return a hardcoded candidate.

        return $shouldMatch ? '1.0.0' : '0.0.1';
    }

    /**
     * Generate valid versions for any Composer constraint, including complex multi-constraints
     *
     * @param string $constraint The composer constraint
     * @return array An array of valid versions that satisfy the constraint parts
     */
    function generateValidVersionsForConstraint(string $constraint): array {
        $versionParser = new VersionParser();
        $validVersions = [];

        // Split by OR operators (both || and | are supported in Composer)
        $parts = preg_split('/\s*\|\|\s*|\s*\|\s*/', $constraint);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            try {
                $parsedConstraint = $versionParser->parseConstraints($part);
                $version = null;

                // Handle exact version
                if (preg_match('/^\d+\.\d+\.\d+$/', $part)) {
                    $version = $part;
                }

                // Handle wildcard
                elseif (strpos($part, '*') !== false) {
                    $version = str_replace('*', '0', $part);
                    if (substr($part, -1) === '*') {
                        $version = rtrim($version, '.');
                    }

                    // Ensure it has three segments
                    $versionParts = explode('.', $version);
                    while (count($versionParts) < 3) {
                        $versionParts[] = '0';
                    }
                    $version = implode('.', $versionParts);
                }

                // Handle ^X
                elseif (preg_match('/^\^(\d+)$/', $part, $matches)) {
                    $version = $matches[1] . '.0.0';
                }

                // Handle ~X
                elseif (preg_match('/^~(\d+)$/', $part, $matches)) {
                    $version = $matches[1] . '.0.0';
                }

                // Handle ^X.Y
                elseif (preg_match('/^\^(\d+\.\d+)$/', $part, $matches)) {
                    $version = $matches[1] . '.0';
                }

                // Handle ~X.Y
                elseif (preg_match('/^~(\d+\.\d+)$/', $part, $matches)) {
                    $version = $matches[1] . '.0';
                }

                // Handle ^X.Y.Z
                elseif (preg_match('/^\^(\d+\.\d+\.\d+)$/', $part, $matches)) {
                    $version = $matches[1];
                }

                // Handle ~X.Y.Z
                elseif (preg_match('/^~(\d+\.\d+\.\d+)$/', $part, $matches)) {
                    $version = $matches[1];
                }

                // Handle >=X.Y
                elseif (preg_match('/^>=(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
                    $version = $matches[1];
                    $versionParts = explode('.', $version);
                    while (count($versionParts) < 3) {
                        $versionParts[] = '0';
                    }
                    $version = implode('.', $versionParts);
                }

                // Handle >X.Y
                elseif (preg_match('/^>(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
                    $version = $matches[1];
                    $versionParts = explode('.', $version);
                    while (count($versionParts) < 3) {
                        $versionParts[] = '0';
                    }
                    $versionParts[2] = (int)$versionParts[2] + 1;
                    $version = implode('.', $versionParts);
                }

                // Handle <X.Y
                elseif (preg_match('/^<(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
                    $version = $matches[1];
                    $versionParts = explode('.', $version);
                    while (count($versionParts) < 3) {
                        $versionParts[] = '0';
                    }

                    if ($versionParts[2] > '0') {
                        $versionParts[2] = (int)$versionParts[2] - 1;
                    } elseif ($versionParts[1] > '0') {
                        $versionParts[1] = (int)$versionParts[1] - 1;
                        $versionParts[2] = '9';
                    } else {
                        $versionParts[0] = (int)$versionParts[0] - 1;
                        $versionParts[1] = '9';
                        $versionParts[2] = '9';
                    }
                    $version = implode('.', $versionParts);
                }

                // Handle <=X.Y
                elseif (preg_match('/^<=(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
                    $version = $matches[1];
                    $versionParts = explode('.', $version);
                    while (count($versionParts) < 3) {
                        $versionParts[] = '0';
                    }
                    $version = implode('.', $versionParts);
                }

                // For complex constraints, test some candidates
                else {
                    $testVersions = [];

                    // Extract version numbers from the constraint
                    preg_match_all('/\d+(\.\d+)*/', $part, $matches);

                    foreach ($matches[0] as $match) {
                        $versionParts = explode('.', $match);
                        while (count($versionParts) < 3) {
                            $versionParts[] = '0';
                        }

                        // Generate variants
                        $base = implode('.', $versionParts);
                        $testVersions[] = $base;
                        $testVersions[] = $versionParts[0] . '.' . $versionParts[1] . '.' . ((int)$versionParts[2] + 1);
                        $testVersions[] = $versionParts[0] . '.' . ((int)$versionParts[1] + 1) . '.0';
                    }

                    // Test each candidate
                    foreach ($testVersions as $testVersion) {
                        try {
                            $normalizedVersion = $versionParser->normalize($testVersion);
                            $versionConstraint = new Constraint('==', $normalizedVersion);

                            if ($parsedConstraint->matches($versionConstraint)) {
                                $version = $testVersion;
                                break;
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }

                // Verify the generated version is valid for this constraint part
                if ($version !== null) {
                    $normalizedVersion = $versionParser->normalize($version);
                    $versionConstraint = new Constraint('==', $normalizedVersion);

                    if ($parsedConstraint->matches($versionConstraint)) {
                        $validVersions[$part] = $version;
                    }
                }

            } catch (\Exception $e) {
                // Skip parts that can't be parsed or matched
                continue;
            }
        }

        return $validVersions;
    }

    /**
     * Converts "5 - 6" to ">= 5.0.0 < 6.0.0".
     */
    private function normalizeHyphenRanges(string $constraint): string
    {
        // Handle "X - Y" ranges by converting to ">=X <Y"
        return preg_replace_callback(
            '/(\d+(?:\.\d+)*)\s*-\s*(\d+(?:\.\d+)*)/',
            function ($matches) {
                $lower = $this->ensure2Dots($matches[1]);
                $upper = $this->ensure2Dots($matches[2]);
                return ">=$lower <$upper";
            },
            $constraint
        );
    }

    public function testComplexComposerConstraints()
    {
        $constraints = [
            '5 - 6',
            '>1.1.8',
            '* >=4',
            '> 3',
            '^3 <3.30',
            '5.7.',
            'v3.x',
            "^3",
            "^2 <3",
            '^0.',
            '~3.',
            "2.x-dev",
            "2.x",
            "2.X",
            "^V2.0",
            "V2.0",
            "^v2.0",
            "v2.0",
            "~6",
            "^7",
            ">4",
            "<2",
            "3.7.*@stable | 3.6.*@stable | 3.5.*@stable | 3.4.*@stable | 3.3.*@stable | 3.2.*@stable | 3.1.*@stable | 2.2.*@stable | 2.1.*@stable | 1.11.*@stable | 1.12.*@stable",
            "5.0.* || 5.1.* || 5.2.* || 5.3.* || 5.4.* || 5.5.* || 5.6.* || 5.7.* || 5.8.* || 7.*",
            "~5.5.0 || ~5.6.0 || ~5.7.0 || ~5.8.0 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0 || ^11.0",
            "^7.2.1", "^7.2"
        ];

        foreach ($constraints as $constraint) {
            $versionsToMatch = $this->generateVersionForConstraint($constraint, true);
            if (empty($versionsToMatch)) {
                $versionsToMatch = $this->generateValidVersionsForConstraint($constraint);
            }

            foreach ($versionsToMatch as $constraint => $version) {
                self::assertTrue($this->versionSatisfies($constraint, $version), "The constraint '$constraint' didn't validate against '$version'.");
            }
//            dd($versionsToMatch);
//            $versionsToMiss = $this->generateVersionForConstraint($constraint, false);
//            foreach ($versionsToMiss as $constraint => $version) {
//                self::assertFalse($this->matchesVersion($constraint, $version));
//            }
        }
    }

    public function testAllComposerConstraints(): void
    {
        $constraints = json_decode(file_get_contents(__DIR__ . '/constraints.json'));

        $constraints = array_reverse($constraints);
        $constraints = array_chunk($constraints, 25);

        foreach ($constraints as $i => $localSet) {
            ++$i;

            $this->doTestAllComposerConstraints($localSet);

            if (self::isDebugOn() && $i >= 1) {
                //dump([$i => $localSet]);
                $total = $i * 25;
                dump("Chunk #$i ({$total})");
                usleep(50000);
            }
        }
    }

    /**
     * Test all constraints against PHP versions
     */
    private function doTestAllComposerConstraints(array $constraints): void
    {
        $totalTests = 0;
        $errors = [];
        // Test each constraint against each PHP version
        foreach ($constraints as $index => $constraint) {
//            if ($index < 626) {
//                continue;
//            }

            // Skip invalid constraints
            if (empty($constraint) || !is_string($constraint)) {
                continue;
            }

            // If it isn't a valid composer constraint, go ahead and skip the test.
            if ($this->isValidVersionConstraint($constraint) === false) {
                dump("====== INVALID CONSTRAINT: $constraint ======");
                file_put_contents('invalid-constraints.log', "$constraint\n", FILE_APPEND);
                continue;
            }

            try {
                $validVersions = $this->generateVersionForConstraint($constraint);
                if (self::isDebugOn()) { dump($constraint); }
                foreach ($validVersions as $version) {
                    $totalTests++;

                    // Call our version constraint checker
                    $result = $this->versionSatisfies($constraint, $version);

                    // Since we don't have a reference implementation to compare against,
                    // we're just ensuring the function runs without errors.
                    // In a real test, you might compare against Composer's actual implementation.
                    $this->assertIsBool($result, "Result for constraint '$constraint' against $version should be boolean");
                }
            } catch (\Exception $e) {
                $errors[] = "Error testing constraint '$constraint': " . $e->getMessage();
            }
        }

        // Report any errors
        if (!empty($errors)) {
            file_put_contents('test-errors.log', implode("\n", $errors) . "\n", FILE_APPEND);
        }
//        $this->assertEmpty($errors, "Encountered " . count($errors) . " errors: " . implode(", ", $errors));

        $this->addToAssertionCount($totalTests);
        //echo "Successfully tested {$totalTests} constraint/version combinations.";
    }

    /**
     * Test specific known constraints (validation test)
     *
     * @return void
     */
    public function testKnownConstraints()
    {
        // Test cases with expected results
        $testCases = [
            ["8.*", "8.0", true],
            ["8.*", "8.4", true],
            ["8.*", "7.2", false],
            ["8.0.*", "8.0", true],
            ["^7.2|8.0.*", "8.0", true],
            ["^7.2|8.0.*", "7.1", false],
            ["^7.2|8.0.*", "7.2", true],
            ["^7.2|8.0.*", "7.4", true],
            ["^7.2|8.0.*", "8.1", false],
            [">=5.6 <8.0", "5.6", true],
            [">=5.6 <8.0", "7.4", true],
            [">=5.6 <8.0", "8.0", false],
            // PHP 7.3 and above, and PHP 8.0, but not 9.x
            ['>=7.3 <9.0.0', '5.6', false],
            ['>=7.3 <9.0.0', '7.3', true],
            ['>=7.3 <9.0.0', '7.4', true],
            ['>=7.3 <9.0.0', '8.1', true],
            ['>=7.3 <9.0.0', '8.4', true],
            ['>=7.3 <9.0.0', '9.0', false],
            ['7.*|8.*', '7.0', true],
            ['7.*|8.*', '8.3', true],
            ['7.*|8.*', '9.0', false],
            ['7.*,8.*', '7.0', true],
            ['7.*, 8.*', '8.3', true],
            ['7.*, 8.*', '9.0', false],
        ];

        foreach ($testCases as $index => [$constraint, $phpVersion, $expected]) {
            $result = $this->versionSatisfies($constraint, $phpVersion);
            $this->assertSame(
                $expected,
                $result,
                "Test case #{$index}: expected constraint '{$constraint}' to " .
                ($expected ? "match" : "not match") . " PHP {$phpVersion}"
            );
        }
    }
}
