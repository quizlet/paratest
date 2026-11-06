<?php
/**
 * Child process for the coverage temp-path tests.
 *
 * Loaded under LD_PRELOAD so the stat of the predictable coverage name can
 * disagree with the open that follows. Arguments: runner class, coverage option.
 */
$root = dirname(dirname(__DIR__));
require $root . '/test/bootstrap.php';
require $root . '/test/unit/ParaTest/Runners/PHPUnit/SharedTempCoverageReportTest.php';

if ($argc < 3) {
    fwrite(STDERR, "usage: runner-class coverage-html|coverage-clover\n");
    exit(2);
}

SharedTempCoverageReportTest::performWrite($argv[1], $argv[2]);
