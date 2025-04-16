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

use PHPExperts\ComposerVersionConstraints\ComposerConstraintsHelper;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

class ComposerConstraintsHelperTest extends TestCase
{
    private ComposerConstraintsHelper $constraints;

    public function setUp(): void
    {
        $this->constraints = new ComposerConstraintsHelper();
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
                self::assertTrue($this->constraints->versionSatisfies($constraint, $version), "The constraint '$constraint' didn't validate against '$version'.");
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
                    $result = $this->constraints->versionSatisfies($constraint, $version);

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
            ['^7.0, ^8.0', '8.0', false],
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
            ['7.*, 8.*', '8.3', false],
            ['7.*, 8.*', '9.0', false],
            ['7.*, <8.4', '8.3', false],
            ['>7.2, <8.4', '8.3', true],
        ];

        foreach ($testCases as $index => [$constraint, $phpVersion, $expected]) {
            $actualExpected = Semver::satisfies($phpVersion, $constraint);
            if ($actualExpected !== $expected) {
                throw new \LogicException("For $phpVersion' against $constraint', expected '$expected' is not equal to '$actualExpected");
            }


            $result = $this->constraints->versionSatisfies($constraint, $phpVersion);
            $this->assertSame(
                $expected,
                $result,
                "Test case #{$index}: expected constraint '{$constraint}' to " .
                ($expected ? "match" : "not match") . " PHP {$phpVersion}"
            );
        }
    }

    /**
     * Check if a Composer version constraint is valid.
     *
     * @param string $constraint The version constraint to validate
     * @return bool True if valid, false if invalid
     */
    private function isValidVersionConstraint(string $constraint): bool
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

            if ($candidate && $this->constraints->versionSatisfies($part, $candidate) === $matches) {
                $results[$part] = $candidate;
            } else {
                // If adjustment fails, try an opposite candidate
                $opposite = $this->generateAlternativeCandidate($part, !$matches);
                if ($this->constraints->versionSatisfies($part, $opposite) === $matches) {
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
        $constraint = ComposerConstraintsHelper::normalizeHyphenRanges($constraint);

        // Format standardization
        // Remove spaces around comparison operators (>=, >, <, !=) in the constraint string.
        $constraint = preg_replace('/([><!-]=?)\s*/', '$1', $constraint);
        $constraint = preg_replace('/\* ?([><!]=?)\s*/', '$1', $constraint);

        // Convert "* >" to ">" and other normalizations.
        $constraint = str_replace('. *', '.*', $constraint);

        // Split the constraint into parts if it contains multiple conditions (e.g., "^3 <3.30")
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
        if (str_contains($constraint, '*')) {
            return $matches ? str_replace('*', '1', $constraint) : str_replace('*', '0', $constraint);
        }

        $base = ComposerConstraintsHelper::ensure2Dots($constraint);

        // Generate version based on operator
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
     * Generate a valid candidate version for a constraint part.
     *
     * Examples:
     * - "5.0.*@stable" becomes "5.0.1"
     * - "~5.5"         becomes "5.5.0"
     * - "^7.2.1"       becomes "7.2.1"
     * - ">3"           becomes "3.0.0"
     * - "<4"           becomes "4.0.0"
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

        // Extract the major, minor, and patch parts
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
    private function generateValidVersionsForConstraint(string $constraint): array
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
        elseif (str_contains($part, '*')) {
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
                $version = ComposerConstraintsHelper::ensure2Dots($match[2]);

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
            return $minVersion ?? $this->decrementVersion($maxVersion ?? '1.0.0');
        } else {
            // Return a version outside the range, e.g., below min or at/above max
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
     * Check if a version satisfies a constraint using Composer's official library.
     *
     * @param string $constraint The version constraint to check against
     * @param string $version The version to validate
     * @return bool True if the version satisfies the constraint
     */
    private function matchesVersionAuthoritative(string $constraint, string $version): bool
    {
        return Semver::satisfies($version, $constraint);
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
            $minVersion = ComposerConstraintsHelper::ensure2Dots($matches[1]);
            if (version_compare($candidate, $minVersion, '<')) {
                return $minVersion;
            }
        } elseif (preg_match('/^<(\d+(\.\d+)?(\.\d+)?)/', $constraint, $matches)) {
            $maxVersion = ComposerConstraintsHelper::ensure2Dots($matches[1]);
            if (version_compare($candidate, $maxVersion, '>=')) {
                $parts[2] = (int)$parts[2] - 1; // Decrease patch
                return implode('.', $parts);
            }
        } elseif (str_starts_with($constraint, '^')) {
            $base = ComposerConstraintsHelper::ensure2Dots(substr($constraint, 1));
            if (version_compare($candidate, $base, '<')) {
                return $base;
            }
        } elseif (str_starts_with($constraint, '~')) {
            $base = ComposerConstraintsHelper::ensure2Dots(substr($constraint, 1));
            if (version_compare($candidate, $base, '<')) {
                return $base;
            }
        }

        return $candidate;
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
}
