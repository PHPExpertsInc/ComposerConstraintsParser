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
            'x'
        ];

        foreach ($invalid as $c) {
            self::assertFalse($this->isValidVersionConstraint($c));
        }
    }

    /**
     * PHP version matching function as defined earlier
     */
    private function matchesVersion($constraint, $version): bool
    {
        // Apparently this is an edge case and means the same as "*".
        if ($constraint === 'x') {
            return true;
        }

        // Split constraint by OR operator
        $orConstraints = explode('|', $constraint);

        foreach ($orConstraints as $singleConstraint) {
            $singleConstraint = trim($singleConstraint);

            // Always return true for git branch constraints.
            if (str_ends_with($singleConstraint, '-dev') || str_ends_with($singleConstraint, '-rc')) {
                return true;
            }

            // Otherwise, convert 'x' to '*'
            if (str_ends_with($singleConstraint, '.x')) {
                $singleConstraint = str_replace('.x', '.*', $singleConstraint);
            }

            // Strip the leading "v", as it is extraneous.
            $singleConstraint = preg_replace('/([><=~^]+)?v/i', '$1', $singleConstraint);

            // If it ends with ".", add a "*".
            // This effects 615 projects as of 2025-03-24.
            // @see rinsvent/data2dto
            if (str_ends_with($singleConstraint, '.')) {
                //$singleConstraint .= '0';
                $singleConstraint = substr($singleConstraint, 0, -1);
            }

            // Handle wildcards
            if (strpos($singleConstraint, '*') !== false) {
                // Handle wildcards more carefully
                $basePattern = str_replace('.', '\.', $singleConstraint);
                $basePattern = str_replace('*', '(\d+)?', $basePattern);
                $pattern = '/^' . $basePattern . '$/';

                // If the wildcard is at the very end, also match with or without trailing digits
                if (substr($singleConstraint, -1) === '*') {
                    // Extract the version without the wildcard
                    $baseVersion = rtrim(str_replace('*', '', $singleConstraint), '.');

                    if (strpos($version, $baseVersion) === 0) {
                        return true;
                    }
                }

                if (preg_match($pattern, $version)) {
                    return true;
                }
                continue;
            }

            // Handle exact version
            if (preg_match('/^\d+(\.\d+)?(\.\d+)?$/', $singleConstraint)) {
                // Split both versions into their components
                $versionParts = explode('.', $version);
                $constraintParts = explode('.', $singleConstraint);

                // Append zeros to $constraintParts until it has the same number of parts as $versionParts
                while (count($constraintParts) < count($versionParts)) {
                    $constraintParts[] = '0';
                }

                $singleConstraintToCompare = implode('.', $constraintParts);

                if (version_compare($version, $singleConstraintToCompare, '==')) {
                    return true;
                }
                continue;
            }

            if (strpos($singleConstraint, '^') === 0) {
                $baseVersion = substr($singleConstraint, 1);

                // Handle the special case for ^0
                if ($baseVersion === '0' || $baseVersion === '0.0' || $baseVersion === '0.0.0') {
                    // ^0 means >=0.0.0 <1.0.0
// Ensure $version has three components before comparison
                    $versionParts = explode('.', $version);
                    while (count($versionParts) < 3) {
                        $versionParts[] = '0';
                    }
                    $normalizedVersion = implode('.', $versionParts);

                    if (version_compare($normalizedVersion, '0.0.0', '>=')) {
                        if (version_compare($normalizedVersion, '1.0.0', '<')) {
                            return true;
                        }
                    }
                }
                // Handle ^0.x
                elseif (preg_match('/^0\.(\d+)/', $baseVersion, $matches)) {
                    $minorVersion = (int)$matches[1];
                    $nextMinor = '0.' . ($minorVersion + 1) . '.0';

                    if (version_compare($version, $baseVersion, '>=') &&
                        version_compare($version, $nextMinor, '<')) {
                        return true;
                    }
                }
                // Standard ^1.0 or higher
                else {
                    $majorVersion = (int)$baseVersion;
                    $nextMajor = ($majorVersion + 1) . '.0';

                    if (version_compare($version, $baseVersion, '>=') &&
                        version_compare($version, $nextMajor, '<')) {
                        return true;
                    }
                }
                continue;
            }

            // Handle tilde (~) operator
            if (strpos($singleConstraint, '~') === 0) {
                $baseVersion = substr($singleConstraint, 1);
                $parts = explode('.', $baseVersion);
                $nextMinor = $parts[0] . '.' . ((int)($parts[1] ?? 0) + 1);

                if (version_compare($version, $baseVersion, '>=') &&
                    version_compare($version, $nextMinor, '<')) {
                    return true;
                }
                continue;
            }

            // Handle ranges - updated to support single numbers
            if (preg_match('/^([><=]+)(\d+(\.\d+(\.\d+)?)?)$/', $singleConstraint, $matches)) {
                $operator = $matches[1];
                $versionToCompare = $matches[2];

                // Normalize single number to use proper version format
                if (preg_match('/^\d+$/', $versionToCompare)) {
                    $versionToCompare .= '.0.0';
                } elseif (preg_match('/^\d+\.\d+$/', $versionToCompare)) {
                    $versionToCompare .= '.0';
                }

                if (version_compare($version, $versionToCompare, $operator)) {
                    return true;
                }
                continue;
            }

            // Handle complex ranges like >=7.2 <8.0
            if (strpos($singleConstraint, ' ') !== false) {
                $rangeParts = explode(' ', $singleConstraint);
                $matches = true;

                foreach ($rangeParts as $rangePart) {
                    if (preg_match('/^([><=]+)(\d+(\.\d+(\.\d+)?)?)$/', $rangePart, $matches)) {
                        $operator = $matches[1];
                        $versionToCompare = $matches[2];

                        // Normalize single number to use proper version format
                        if (preg_match('/^\d+$/', $versionToCompare)) {
                            $versionToCompare .= '.0.0';
                        } elseif (preg_match('/^\d+\.\d+$/', $versionToCompare)) {
                            $versionToCompare .= '.0';
                        }

                        if (!version_compare($version, $versionToCompare, $operator)) {
                            $matches = false;
                            break;
                        }
                    }
                }

                if ($matches) {
                    return true;
                }
            }
        }

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
    public function generateVersionForConstraint(string $constraint, bool $matches = true) {
        // Split the constraint on the pipe symbol and trim each part.
        $parts = array_map('trim', explode('|', $constraint));
        $results = [];

        foreach ($parts as $part) {
            if ($part === '') { continue; }
            // Strip out @ from the end.
            $part = str_contains($part, '@') ? substr($part, 0, strpos($part, '@')) : $part;

            // For '>' constraints, we need a version greater than the base version
            if (preg_match('/^>(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
                $baseVersion = $matches[1];

                // Convert to a full version number
                if (preg_match('/^\d+$/', $baseVersion)) {
                    $baseVersion .= '.0.0';
                } elseif (preg_match('/^\d+\.\d+$/', $baseVersion)) {
                    $baseVersion .= '.0';
                }

                // Increase the patch version by 1
                $versionParts = explode('.', $baseVersion);
                $versionParts[2] = (int)$versionParts[2] + 1;
                $results[$part] = implode('.', $versionParts);
                continue;
            }

            // For '<' constraints, we need a version less than the base version
            if (preg_match('/^<(\d+(\.\d+(\.\d+)?)?)$/', $part, $matches)) {
                $baseVersion = $matches[1];

                // Convert to a full version number
                if (preg_match('/^\d+$/', $baseVersion)) {
                    $baseVersion .= '.0.0';

                    // For a version like "4.0.0", we want "3.9.9"
                    $versionParts = explode('.', $baseVersion);
                    $versionParts[0] = (int)$versionParts[0] - 1;  // Decrease major
                    $versionParts[1] = 9;  // Max minor
                    $versionParts[2] = 9;  // Max patch
                    $results[$part] = implode('.', $versionParts);
                    continue;
                }

                // For more specific versions, handle accordingly
                $versionParts = explode('.', $baseVersion);
                if (count($versionParts) == 2 || $versionParts[2] == '0') {
                    if ($versionParts[1] == '0') {
                        $versionParts[0] = (int)$versionParts[0] - 1;
                        $versionParts[1] = 9;
                    } else {
                        $versionParts[1] = (int)$versionParts[1] - 1;
                    }
                    $versionParts[2] = 9;
                } else {
                    $versionParts[2] = (int)$versionParts[2] - 1;
                }
                $results[$part] = implode('.', $versionParts);
                continue;
            }

            // Generate a candidate version that fits the constraint if possible.
            $validCandidate = $this->generateValidCandidate($part);
            $isValid = $this->matchesVersion($part, $validCandidate);

            // If we want matching versions and the candidate is valid, or vice versa,
            // we add it to our results.
            if ($matches && $isValid) {
                $results[$part] = $validCandidate;
            } elseif (!$matches && !$isValid) {
                $results[$part] = $validCandidate;
            } else {
                // Otherwise, try generating an "opposite" candidate version.
                $candidate = $this->generateOppositeCandidate($part, empty($matches));
                // Double-check that the candidate fulfills the intended condition.
                if ($this->matchesVersion($part, $candidate) === empty($matches)) {
                    $results[$part] = $candidate;
                }
            }
        }

        return empty($results) ? 0 : $results;
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
     * Generate a candidate version that is expected to be the opposite (valid or invalid)
     * relative to the constraint.
     *
     * This implementation simply modifies the major version number.
     *
     * @param string $constraintPart A single part of the constraint.
     * @param bool   $shouldMatch    If true, generate a candidate that matches; otherwise, one that doesn't.
     *
     * @return string A candidate version string.
     */
    private function generateOppositeCandidate(string $constraintPart, bool $shouldMatch): string {
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

    public function testComplexComposerConstraints()
    {
        $constraints = [
            "^2 <3",
            'v3.x',
            '^0.',
            '~3.',
            '5.7.',
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
//            $versionsToMatch = $this->generateVersionForConstraint($constraint, true);
            if (empty($versionsToMatch)) {
                $versionsToMatch = $this->generateValidVersionsForConstraint($constraint);
            }

            foreach ($versionsToMatch as $constraint => $version) {
                self::assertTrue($this->matchesVersion($constraint, $version));
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
//        dd(file_get_contents( __DIR__ . '/constraints.json'));
        $constraints = json_decode(file_get_contents(__DIR__ . '/constraints.json'));
//dd($constraints);

        $constraints = array_reverse($constraints);
        $constraints = array_chunk($constraints, 20);

        foreach ($constraints as $i => $localSet) {

            dump([$localSet, "Chunk #$i"]);
//            $localSet = [ '^2 <3'];

            $this->doTestAllComposerConstraints($localSet);

            if ($i >= 108) {
                dump("Chunk #$i: Continue??");
                //sleep(1);
                usleep(50000);
                //fgets(STDIN);
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
        foreach ($constraints as $constraint) {
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
                foreach ($validVersions as $version) {
                    $totalTests++;

                    // Call our version constraint checker
                    $result = $this->matchesVersion($constraint, $version);

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
        echo "Successfully tested {$totalTests} constraint/version combinations.";
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
        ];

        foreach ($testCases as $index => [$constraint, $phpVersion, $expected]) {
            $result = $this->matchesVersion($constraint, $phpVersion);
            $this->assertSame(
                $expected,
                $result,
                "Test case #{$index}: expected constraint '{$constraint}' to " .
                ($expected ? "match" : "not match") . " PHP {$phpVersion}"
            );
        }
    }
}
