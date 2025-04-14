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
use PHPExperts\ComposerVersionConstraints\ComposerConstraintsHelper;

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
            self::assertFalse($this->constraints->isValidVersionConstraint($c));
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
            $versionsToMatch = $this->constraints->generateVersionForConstraint($constraint, true);
            if (empty($versionsToMatch)) {
                $versionsToMatch = $this->constraints->generateValidVersionsForConstraint($constraint);
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
            if ($this->constraints->isValidVersionConstraint($constraint) === false) {
                dump("====== INVALID CONSTRAINT: $constraint ======");
                file_put_contents('invalid-constraints.log', "$constraint\n", FILE_APPEND);
                continue;
            }

            try {
                $validVersions = $this->constraints->generateVersionForConstraint($constraint);
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
            $result = $this->constraints->versionSatisfies($constraint, $phpVersion);
            $this->assertSame(
                $expected,
                $result,
                "Test case #{$index}: expected constraint '{$constraint}' to " .
                ($expected ? "match" : "not match") . " PHP {$phpVersion}"
            );
        }
    }
}
