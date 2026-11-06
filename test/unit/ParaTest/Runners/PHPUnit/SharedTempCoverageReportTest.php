<?php

/**
 * Driver stub so a PHP_CodeCoverage object can be built without xdebug.
 * logCoverage() writes getData(true), which does not call the driver.
 */
class CoverageReportNullDriver implements PHP_CodeCoverage_Driver
{
    public function start()
    {
    }

    public function stop()
    {
        return array();
    }
}

/**
 * The merged coverage PHP report must not be written through a predictable
 * name in the shared temp directory.
 */
class SharedTempCoverageReportTest extends PHPUnit_Framework_TestCase
{
    const SENTINEL = "VICTIM_FILE_SENTINEL\n";

    private $victim;

    public static function coverageWriters()
    {
        return array(
            array('ParaTest\\Runners\\PHPUnit\\Runner', 'coverage-html'),
            array('ParaTest\\Runners\\PHPUnit\\Runner', 'coverage-clover'),
            array('ParaTest\\Runners\\PHPUnit\\WrapperRunner', 'coverage-html'),
            array('ParaTest\\Runners\\PHPUnit\\WrapperRunner', 'coverage-clover'),
        );
    }

    protected function setUp()
    {
        $this->removeFixedPath();
        $this->victim = tempnam(sys_get_temp_dir(), 'covvictim');
        file_put_contents($this->victim, self::SENTINEL);
        chmod($this->victim, 0644);
    }

    protected function tearDown()
    {
        $this->removeFixedPath();
        if (is_string($this->victim) && file_exists($this->victim)) {
            unlink($this->victim);
        }
    }

    /**
     * @dataProvider coverageWriters
     */
    public function testMergedReportDoesNotFollowSymlinkAtPredictableTempPath($runnerClass, $format)
    {
        $this->assertTrue(symlink($this->victim, $this->fixedPath()));

        self::performWrite($runnerClass, $format);

        $this->assertSame(
            self::SENTINEL,
            file_get_contents($this->victim),
            $format . ' coverage on ' . $runnerClass . ' replaced the symlink target'
        );
        $this->assertFalse(
            is_file($this->fixedPath()) && !is_link($this->fixedPath()),
            'The predictable temp path was replaced with a regular coverage file'
        );
    }

    /**
     * @dataProvider coverageWriters
     */
    public function testMergedReportIsNotLeftInTheSharedTempDirectory($runnerClass, $format)
    {
        self::performWrite($runnerClass, $format);

        $path = $this->fixedPath();
        $detail = $path;
        if (file_exists($path) || is_link($path)) {
            $detail .= ' mode ' . substr(sprintf('%o', fileperms($path)), -4);
        }
        $this->assertFalse(
            file_exists($path) || is_link($path),
            'Merged coverage report was left in the shared temp directory at ' . $detail
        );
    }

    /**
     * lstat of the fixed name is forced to look like a regular file. A check
     * of that result, followed by another lookup and write of the same path,
     * still follows the symlink.
     *
     * @dataProvider coverageWriters
     */
    public function testMergedReportDoesNotFollowSymlinkWhenStatSaysRegularFile($runnerClass, $format)
    {
        $this->assertTrue(symlink($this->victim, $this->fixedPath()));

        $library = $this->compileLstatLie();
        $script = PARATEST_ROOT . '/test/fixtures/write_merged_coverage_report.php';
        $command = 'env LD_PRELOAD=' . escapeshellarg($library)
            . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($runnerClass)
            . ' ' . escapeshellarg($format);
        $descriptor = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = proc_open($command, $descriptor, $pipes, PARATEST_ROOT);
        $this->assertTrue(is_resource($process));
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(
            0,
            $exitCode,
            "coverage write subprocess failed\n" . $stdout . "\n" . $stderr
        );
        $this->assertSame(
            self::SENTINEL,
            file_get_contents($this->victim),
            $format . ' coverage on ' . $runnerClass
                . ' followed the temp symlink after lstat reported a regular file'
        );
    }

    public function testExplicitCoveragePhpPathIsWhatGetsWritten()
    {
        $explicit = tempnam(sys_get_temp_dir(), 'explicitcov');
        unlink($explicit);

        self::performWrite('ParaTest\\Runners\\PHPUnit\\Runner', 'coverage-html', $explicit);
        self::performWrite('ParaTest\\Runners\\PHPUnit\\WrapperRunner', 'coverage-clover', $explicit);

        $this->assertTrue(is_file($explicit), 'explicit coverage-php path was not written');
        $this->assertContains('CoverageMerger.php', file_get_contents($explicit));
        $this->assertFalse(
            file_exists($this->fixedPath()) || is_link($this->fixedPath()),
            'explicit coverage-php still left a report at the predictable temp path'
        );
        $this->assertSame(self::SENTINEL, file_get_contents($this->victim));
        unlink($explicit);
    }

    /**
     * Run the console option parsing and the runner finalization that writes
     * the merged PHP coverage report.
     *
     * @param string $runnerClass
     * @param string $format coverage-html or coverage-clover
     * @param string|null $explicitPhp
     */
    public static function performWrite($runnerClass, $format, $explicitPhp = null)
    {
        $output = tempnam(sys_get_temp_dir(), 'ptcov');
        unlink($output);
        if ($format === 'coverage-html') {
            mkdir($output);
            $optionValue = $output;
        } else {
            $optionValue = $output . '.xml';
        }

        $tester = new \ParaTest\Console\Testers\PHPUnit();
        $command = new \ParaTest\Console\Commands\ParaTestCommand($tester);
        $input = new \Symfony\Component\Console\Input\ArrayInput(array(), $command->getDefinition());
        $input->setOption($format, $optionValue);
        if ($explicitPhp !== null) {
            $input->setOption('coverage-php', $explicitPhp);
        }
        $input->setOption('configuration', PARATEST_ROOT . DIRECTORY_SEPARATOR . 'phpunit.xml.dist');
        $input->setArgument('path', PARATEST_ROOT);
        $options = $tester->getRunnerOptions($input);
        if (!isset($options['filter'])) {
            $options['filter'] = '';
        }

        $coveredFile = PARATEST_ROOT . '/src/ParaTest/Coverage/CoverageMerger.php';
        $coverage = new \PHP_CodeCoverage(new CoverageReportNullDriver());
        $coverage->setData(array(
            $coveredFile => array(1 => array('CoverageTemp')),
        ));
        $coverage->setTests(array(
            'CoverageTemp' => array('size' => '1', 'status' => 0),
        ));

        $runner = new $runnerClass($options);
        $init = new ReflectionMethod('ParaTest\\Runners\\PHPUnit\\BaseRunner', 'initCoverage');
        $init->setAccessible(true);
        $init->invoke($runner);
        if ($runner->getCoverage() === null) {
            throw new RuntimeException('coverage was not initialized for ' . $format);
        }
        $runner->getCoverage()->addCoverage($coverage);

        $complete = new ReflectionMethod($runnerClass, 'complete');
        $complete->setAccessible(true);
        ob_start();
        try {
            $complete->invoke($runner);
        } catch (Exception $exception) {
            ob_end_clean();
            self::removeTree($output);
            @unlink($optionValue);
            throw $exception;
        }
        ob_end_clean();

        self::removeTree($output);
        @unlink($optionValue);
    }

    private function fixedPath()
    {
        return sys_get_temp_dir() . '/will_be_overwritten.php';
    }

    private function removeFixedPath()
    {
        $path = sys_get_temp_dir() . '/will_be_overwritten.php';
        if (is_link($path) || file_exists($path)) {
            unlink($path);
        }
    }

    private function compileLstatLie()
    {
        $source = PARATEST_ROOT . '/test/fixtures/coverage_lstat_lie.c';
        $library = sys_get_temp_dir() . '/coverage_lstat_lie.so';
        if (is_file($library) && filemtime($library) >= filemtime($source)) {
            return $library;
        }

        $command = 'gcc -shared -fPIC -o ' . escapeshellarg($library)
            . ' ' . escapeshellarg($source) . ' -ldl 2>&1';
        $output = array();
        $exitCode = 1;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($library)) {
            $this->fail("could not compile lstat preload\n" . implode("\n", $output));
        }

        return $library;
    }

    private static function removeTree($path)
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            self::removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
        rmdir($path);
    }
}
