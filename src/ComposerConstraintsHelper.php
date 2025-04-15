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

namespace PHPExperts\ComposerVersionConstraints;

// Assuming this is your base test case
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

// Useful for inspecting parsed constraints
use UnexpectedValueException;

/**
 * Utility class for working with Composer version constraints.
 *
 * Provides methods for validating constraints, checking if a version satisfies
 * a constraint (using the authoritative Composer library), and generating
 * example versions that match constraints.
 *
 * Note: Version generation is heuristic and may not find a valid version for
 * all complex or contradictory constraints.
 */
class ComposerConstraintsHelper
{
    /**
     * Check if a version satisfies a constraint using Composer's official library.
     *
     * @param string $constraint The version constraint to check against
     * @param string $version The version to validate
     * @return bool True if the version satisfies the constraint
     */
    public function matchesVersionAuthoritative(string $constraint, string $version): bool
    {
        return Semver::satisfies($version, $constraint);
    }

    /**
     * Check if a Composer version constraint is valid.
     *
     * @param string $constraint The version constraint to validate
     * @return bool True if valid, false if invalid
     */
    public function isValidVersionConstraint(string $constraint): bool
    {
        $parser = new VersionParser();

        try {
            $parser->parseConstraints($constraint);
            return true;
        } catch (UnexpectedValueException $e) {
            return false;
        }
    }

    /**
     * Ensures a version string has at least 3 parts (major.minor.patch).
     *
     * @param string $version Version to normalize
     * @return string Normalized version with at least 3 parts
     */
    private function ensure2Dots(string $version): string
    {
        $versionParts = explode('.', $version);
        if (count($versionParts) < 3) {
            $version .= str_repeat('.0', 3 - count($versionParts));
        }

        return $version;
    }

    /**
     * Check if a version satisfies a constraint.
     *
     * @param string $constraints Version constraint (can contain OR/AND operators)
     * @param string $version Version to check
     * @return bool True if the version satisfies the constraint
     */
    public function versionSatisfies(string $constraints, string $version): bool
    {
        // Normalize version to have at least 3 parts
        $version = $this->ensure2Dots($version);

        // Normalize constraint formatting
        $constraints = $this->normalizeConstraints($constraints);

        // Split constraint by OR operator (| or -)
        $orConstraints = preg_split('/[|-]/', $constraints);

        foreach ($orConstraints as $orConstraint) {
            $orConstraint = trim($orConstraint);

            // Split by AND operator (,)
            $andConstraints = preg_split('/[ ,]/', $orConstraint);
            $allAndSatisfied = true;

            foreach ($andConstraints as $singleConstraint) {
                if (!empty($singleConstraint) && !$this->satisfiesSingleConstraint($singleConstraint, $version)) {
                    $allAndSatisfied = false;
                    break;
                }
            }

            if ($allAndSatisfied) {
                return true; // If all AND conditions in this OR branch are satisfied, version is valid
            }
        }

        return false;
    }

    /**
     * Normalize constraint string formatting.
     *
     * @param string $constraints Constraints to normalize
     * @return string Normalized constraints
     */
    private function normalizeConstraints(string $constraints): string
    {
        // Convert "* >" to ">" and other normalizations
        $constraints = preg_replace('/\* ?([><!]=?)\s*/', '$1', $constraints);
        $constraints = preg_replace('/^ ?([><!]=?)\s*/', '$1', $constraints);
        $constraints = str_replace('. *', '.*', $constraints);

        // Normalize spaces around commas and hyphens
        $constraints = str_replace([' ,', ', '], ',', $constraints);
        $constraints = str_replace([' -', '- '], '-', $constraints);

        return $constraints;
    }

    /**
     * Check if a version satisfies a single constraint.
     *
     * @param string $constraint Single constraint without AND/OR operators
     * @param string $version Version to check
     * @return bool True if the version satisfies the constraint
     */
    private function satisfiesSingleConstraint(string $constraint, string $version): bool
    {
        // Edge cases
        if ($constraint === 'x' || $constraint === '*') {
            return true;
        }

        // Always pass dev/rc branches
        if (str_ends_with($constraint, '-dev') || str_ends_with($constraint, '-rc')) {
            return true;
        }

        // Strip v prefix
        $constraint = preg_replace('/([><=~^]+)?v/i', '$1', $constraint);
        $version = preg_replace('/v([0-9]+)/i', '$1', $version);

        // Normalize hyphen ranges (e.g., "1.0 - 2.0" to ">=1.0 <2.0")
        $constraint = $this->normalizeHyphenRanges($constraint);

        // Format standardization
        $constraint = preg_replace('/([><!]=?)\s*/', '$1', $constraint);
        $constraint = preg_replace('/\* ?([><!]=?)\s*/', '$1', $constraint);
        $constraint = str_replace('. *', '.*', $constraint);

        // Handle trailing dot (e.g., "1.0.")
        if (str_ends_with($constraint, '.')) {
            $constraint = substr($constraint, 0, -1);
        }

        // Replace .x with .*
        $constraint = str_ireplace('.x', '.*', $constraint);

        // Handle wildcards
        if (str_contains($constraint, '*')) {
            $pattern = str_replace('.', '\.', $constraint);
            $pattern = str_replace('*', '(\d+){1,2}', $pattern);
            return preg_match('/^' . $pattern . '/', $version) === 1;
        }

        // Handle caret (^) - allows changes that don't modify the left-most non-zero digit
        if (str_starts_with($constraint, '^')) {
            return $this->handleCaretConstraint($constraint, $version);
        }

        // Handle tilde (~) - allows the specified precision of version
        if (str_starts_with($constraint, '~')) {
            return $this->handleTildeConstraint($constraint, $version);
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
     * Handle caret (^) constraint matching.
     *
     * @param string $constraint Caret constraint (e.g., "^1.2.3")
     * @param string $version Version to check
     * @return bool True if the version satisfies the constraint
     */
    private function handleCaretConstraint(string $constraint, string $version): bool
    {
        $baseVersion = substr($constraint, 1);
        $baseVersion = $this->ensure2Dots($baseVersion);

        if (preg_match('/^0\.(\d+)/', $baseVersion, $matches)) {
            // Special case for 0.x: only allow changes in patch version
            $minor = (int)$matches[1];
            $nextMinor = "0." . ($minor + 1) . ".0";
            return version_compare($version, $baseVersion, '>=') &&
                version_compare($version, $nextMinor, '<');
        } elseif (preg_match('/^(\d+)/', $baseVersion, $matches)) {
            // Normal case: allow everything that doesn't change the major version
            $major = (int)$matches[0];
            $nextMajor = ($major + 1) . ".0.0";
            return version_compare($version, $baseVersion, '>=') &&
                version_compare($version, $nextMajor, '<');
        }

        return false;
    }

    /**
     * Handle tilde (~) constraint matching.
     *
     * @param string $constraint Tilde constraint (e.g., "~1.2.3")
     * @param string $version Version to check
     * @return bool True if the version satisfies the constraint
     */
    private function handleTildeConstraint(string $constraint, string $version): bool
    {
        $baseVersion = substr($constraint, 1);
        $parts = explode('.', $baseVersion);
        $major = (int)($parts[0] ?? 0);
        $minor = (int)($parts[1] ?? 0);
        $nextMinor = "$major." . ($minor + 1) . ".0";
        $baseVersion = $this->ensure2Dots($baseVersion);
        return version_compare($version, $baseVersion, '>=') &&
            version_compare($version, $nextMinor, '<');
    }

    /**
     * Check if a version matches a single condition.
     *
     * @param string $condition Single condition (e.g., '>=2.2')
     * @param string $version Version to check
     * @return bool True if the version satisfies the condition
     */
    private function matchesSingleCondition(string $condition, string $version): bool
    {
        if (preg_match('/^([><=!]=?|<=|>=)\s*(\d+(\.\d+(\.\d+)?)?)$/', $condition, $matches)) {
            $operator = $matches[1];
            $versionToCompare = $matches[2];

            // Normalize version format
            if (preg_match('/^\d+$/', $versionToCompare)) {
                $versionToCompare .= '.0.0';
            } elseif (preg_match('/^\d+\.\d+$/', $versionToCompare)) {
                $versionToCompare .= '.0';
            }

            return version_compare($version, $versionToCompare, $operator);
        }

        return false;
    }

    /**
     * Generate version(s) for a given composer version constraint.
     *
     * @param string $constraint A composer version constraint (may be compound)
     * @param bool $matches Whether to generate a version that matches the constraint
     * @return array|int An array of candidate versions or 0 if none could be generated
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

    /**
     * Generate a candidate version for a single constraint.
     *
     * @param string $constraint Single constraint without AND/OR operators
     * @param bool $matches Whether to generate a version that matches the constraint
     * @return string Generated version
     */
    private function generateCandidateForSingleConstraint(string $constraint, bool $matches): string
    {
        // Normalize hyphen ranges
        $constraint = $this->normalizeHyphenRanges($constraint);

        // Format standardization
        $constraint = preg_replace('/([><!-]=?)\s*/', '$1', $constraint);
        $constraint = preg_replace('/\* ?([><!]=?)\s*/', '$1', $constraint);
        $constraint = str_replace('. *', '.*', $constraint);

        // Split the constraint if it contains multiple conditions
        $parts = preg_split('/\s+/', trim($constraint));
        if (count($parts) > 1) {
            return $this->handleCompoundConstraint($parts, $matches);
        }

        // Extract operator and version
        $operator = '';
        if (preg_match('/^([<>=!~^]+)/', $constraint, $matchesOperator)) {
            $operator = $matchesOperator[1];
            $constraint = preg_replace('/^[<>=!~^]+\s*/', '', $constraint);
        }

        // Handle wildcards
        if (strpos($constraint, '*') !== false) {
            return $matches ? str_replace('*', '1', $constraint) : str_replace('*', '0', $constraint);
        }

        $base = $this->ensure2Dots($constraint);

        // Generate version based on operator
        switch ($operator) {
            case '^':
                $parts = explode('.', $base);
                if ($matches) {
                    return $base; // Matches the base version
                }
                return ((int)$parts[0] + 1) . '.0.0'; // Next major for non-matching
            case '<':
                if ($matches) {
                    return $this->decrementVersion($base); // One step below base
                }
                return $base; // Base is non-matching for strict <
            case '>=':
                if ($matches) {
                    return $base; // Base satisfies >=
                }
                return $this->decrementVersion($base); // Below base for non-matching
            default:
                return $base; // Default to exact version
        }
    }

    /**
     * Handle compound constraints like "^3 <3.30".
     *
     * @param array $parts Parts of the compound constraint
     * @param bool $matches Whether to generate a version that matches the constraint
     * @return string Generated version
     */
    private function handleCompoundConstraint(array $parts, bool $matches): string
    {
        $minVersion = null;
        $maxVersion = null;

        foreach ($parts as $part) {
            if (preg_match('/^([<>=!~^]+)(.*)/', $part, $match)) {
                $operator = $match[1];
                $version = $this->ensure2Dots($match[2]);

                if ($operator === '^') {
                    $minVersion = $version;
                    $maxVersion = ((int)explode('.', $version)[0] + 1) . '.0.0';
                } elseif ($operator === '<') {
                    $maxVersion = $version;
                } elseif ($operator === '>=') {
                    $minVersion = $version;
                }
            }
        }

        if ($matches) {
            // Return a version in the range
            return $minVersion ?? $this->decrementVersion($maxVersion ?? '1.0.0');
        } else {
            // Return a version outside the range
            if ($minVersion && $this->versionCompare($minVersion, $maxVersion) < 0) {
                return $this->decrementVersion($minVersion); // Below min
            }
            return $maxVersion ?? '999.999.999'; // At or above max
        }
    }

    /**
     * Decrement a version (e.g. 1.2.3 -> 1.2.2).
     *
     * @param string $version Version to decrement
     * @return string Decremented version
     */
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

    /**
     * Compare two versions (wrapper for version_compare).
     *
     * @param string $v1 First version
     * @param string $v2 Second version
     * @return int Result of comparison (-1, 0, 1)
     */
    private function versionCompare(string $v1, string $v2): int
    {
        return version_compare($v1, $v2);
    }

    /**
     * Adjust a candidate version to satisfy an AND constraint.
     *
     * @param string $candidate Current candidate version
     * @param string $constraint Additional constraint to satisfy
     * @param bool $matches Whether to generate a version that matches the constraint
     * @return string Adjusted version
     */
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
     * Generate a valid candidate version for a constraint part.
     *
     * @param string $constraintPart Constraint part
     * @return string Valid candidate version
     */
    private function generateValidCandidate(string $constraintPart): string
    {
        // Remove operators and stability flags
        $candidate = preg_replace('/^[<>=!~^]+\s*/', '', $constraintPart);
        $candidate = preg_replace('/\@[a-z]+$/i', '', $candidate);

        // Normalize wildcards
        $candidate = str_ireplace('x', '*', $candidate);
        $candidate = str_replace('*', '1', $candidate);

        // Remove trailing dot
        if (str_ends_with($candidate, '.')) {
            $candidate = substr($candidate, 0, -1);
        }

        // Ensure proper version format (major.minor.patch)
        if (preg_match('/^\d+$/', $candidate)) {
            $candidate .= '.0.0';
        } elseif (preg_match('/^\d+\.\d+$/', $candidate)) {
            $candidate .= '.0';
        }

        // Ensure we have at least 3 parts
        $parts = explode('.', $candidate);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', $parts);
    }

    /**
     * Generate an alternative candidate version.
     *
     * @param string $constraintPart Constraint part
     * @param bool $shouldMatch Whether the result should match the constraint
     * @return string Alternative candidate version
     */
    private function generateAlternativeCandidate(string $constraintPart, bool $shouldMatch): string
    {
        $validCandidate = $this->generateValidCandidate($constraintPart);

        // Extract version parts
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $validCandidate, $matches)) {
            $major = (int)$matches[1];
            $minor = $matches[2];
            $patch = $matches[3];

            if ($shouldMatch) {
                return $validCandidate;
            } else {
                // Special handling for '>' operator
                if (str_starts_with($constraintPart, '>') && !str_starts_with($constraintPart, '>=')) {
                    ++$patch;
                    return "$major.$minor.$patch";
                }
                // Generate non-matching version by changing major
                $newMajor = ($major === 0) ? 1 : $major - 1;
                return $newMajor . '.' . $minor . '.' . $patch;
            }
        } elseif (preg_match('/^(\d+)\.(\d+)/', $validCandidate, $matches)) {
            $major = (int)$matches[1];
            $minor = $matches[2];

            if ($shouldMatch) {
                return $validCandidate;
            } else {
                $newMajor = ($major === 0) ? 1 : $major - 1;
                return $newMajor . '.' . $minor . '.0';
            }
        }

        // Fallback values
        return $shouldMatch ? '1.0.0' : '0.0.1';
    }

    /**
     * Generate valid versions for a composer constraint.
     *
     * @param string $constraint The composer constraint
     * @return array Valid versions that satisfy the constraint
     */
    public function generateValidVersionsForConstraint(string $constraint): array
    {
        $versionParser = new VersionParser();
        $validVersions = [];

        // Split by OR operators
        $parts = preg_split('/\s*\|\|\s*|\s*\|\s*/', $constraint);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            try {
                $parsedConstraint = $versionParser->parseConstraints($part);
                $version = $this->generateVersionForPart($part);

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
     * Generate a version for a specific constraint part.
     *
     * @param string $part Constraint part
     * @return string|null Generated version or null if not possible
     */
    private function generateVersionForPart(string $part): ?string
    {
        // Handle exact version
        if (preg_match('/^\d+\.\d+\.\d+$/', $part)) {
            return $part;
        }

        // Handle wildcard
        elseif (strpos($part, '*') !== false) {
            return $this->handleWildcardPart($part);
        }

        // Handle ^X (caret with single digit)
        elseif (preg_match('/^\^(\d+)$/', $part, $matches)) {
            return $matches[1] . '.0.0';
        }

        // Handle ~X (tilde with single digit)
        elseif (preg_match('/^~(\d+)$/', $part, $matches)) {
            return $matches[1] . '.0.0';
        }

        // Handle ^X.Y
        elseif (preg_match('/^\^(\d+\.\d+)$/', $part, $matches)) {
            return $matches[1] . '.0';
        }

        // Handle ~X.Y
        elseif (preg_match('/^~(\d+\.\d+)$/', $part, $matches)) {
            return $matches[1] . '.0';
        }

        // Handle ^X.Y.Z or ~X.Y.Z
        elseif (preg_match('/^[\^~](\d+\.\d+\.\d+)$/', $part, $matches)) {
            return $matches[1];
        }

        // Handle >=X.Y
        elseif (preg_match('/^>=(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
            return $this->normalizeVersionParts($matches[1]);
        }

        // Handle >X.Y
        elseif (preg_match('/^>(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
            return $this->handleGreaterThanPart($matches[1]);
        }

        // Handle <X.Y or <=X.Y
        elseif (preg_match('/^<[=]?(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
            return $this->handleLessThanPart($matches[1], $part);
        }

        // For complex constraints, use our main algorithm
        else {
            $versions = $this->generateVersionForConstraint($part);
            return is_array($versions) ? reset($versions) : null;
        }
    }

    /**
     * Handle wildcard part of a constraint.
     *
     * @param string $part Wildcard constraint part
     * @return string Generated version
     */
    private function handleWildcardPart(string $part): string
    {
        $version = str_replace('*', '0', $part);
        if (substr($part, -1) === '*') {
            $version = rtrim($version, '.');
        }

        return $this->normalizeVersionParts($version);
    }

    /**
     * Handle greater than operator in constraint.
     *
     * @param string $version Version part of constraint
     * @return string Version that satisfies >version
     */
    private function handleGreaterThanPart(string $version): string
    {
        $versionParts = explode('.', $this->normalizeVersionParts($version));
        $versionParts[2] = (int)$versionParts[2] + 1;
        return implode('.', $versionParts);
    }

    /**
     * Handle less than operator in constraint.
     *
     * @param string $version Version part of constraint
     * @param string $fullPart Full constraint including operator
     * @return string Version that satisfies <version
     */
    private function handleLessThanPart(string $version, string $fullPart): string
    {
        $versionParts = explode('.', $this->normalizeVersionParts($version));

        if (str_starts_with($fullPart, '<') && !str_starts_with($fullPart, '<=')) {
            // For strict < operator, we need to be less than the specified version
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
        }

        return implode('.', $versionParts);
    }

    /**
     * Normalize version parts to have 3 segments.
     *
     * @param string $version Version to normalize
     * @return string Normalized version
     */
    private function normalizeVersionParts(string $version): string
    {
        $versionParts = explode('.', $version);
        while (count($versionParts) < 3) {
            $versionParts[] = '0';
        }
        return implode('.', $versionParts);
    }

    /**
     * Converts hyphen ranges (e.g., "5 - 6") to standard comparison operators.
     *
     * @param string $constraint Constraint with potential hyphen ranges
     * @return string Normalized constraint
     */
    private function normalizeHyphenRanges(string $constraint): string
    {
        // Convert "X - Y" to ">=X <Y"
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
}
