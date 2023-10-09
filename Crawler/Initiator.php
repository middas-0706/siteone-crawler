<?php

namespace Crawler;

use Crawler\Analysis\Analyzer;
use Crawler\Export\Exporter;
use Crawler\Options\Options;
use Crawler\Options\Type;

class Initiator
{

    /**
     * Command-line arguments for options parsing
     *
     * @var array
     */
    private readonly array $argv;

    /**
     * Absolute path to Crawler class directory where are subfolders Export and Analysis
     * @var string
     */
    private readonly string $crawlerClassDir;

    private Options $options;

    /**
     * @var CoreOptions
     */
    private CoreOptions $crawlerOptions;

    /**
     * @var Output\Output
     */
    private Output\Output $output;

    /**
     * @var Exporter[]
     */
    private array $exporters;

    /**
     * @var Analyzer[]
     */
    private array $analyzers;

    /**
     * Array of all known options for unknown option detection
     * @var string[]
     */
    private array $knownOptions = [];

    /**
     * @param array $argv
     * @param string $crawlerClassDir
     * @throws \Exception
     */
    public function __construct(array $argv, string $crawlerClassDir)
    {
        if (!is_dir($crawlerClassDir) || !is_dir($crawlerClassDir . '/Export') || !is_dir($crawlerClassDir . '/Analysis')) {
            throw new \InvalidArgumentException("Crawler class directory {$crawlerClassDir} does not exist or does not contain folders Export and Analysis.");
        }

        $this->argv = $argv;
        $this->crawlerClassDir = $crawlerClassDir;

        // import options config from crawler and all exporters/analyzers
        $this->setupOptions();
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function validateAndInit(): void
    {
        // set values to all configured options from CLI parameters
        $this->fillAllOptionsValues();

        // check for given unknown options
        $this->checkUnknownOptions();

        // import crawler options and fill with given parameters
        $this->importCrawlerOptions();

        // import all auto-activated exporters thanks to filled CLI parameter(s)
        $this->importExporters();

        // import all auto-activated analyzers thanks to filled CLI parameters(s)
        $this->importAnalyzers();
    }

    /**
     * Setu[ $this->options with options from Crawler and all founded exporters and analyzers
     * @return void
     */
    private function setupOptions(): void
    {
        $this->options = new Options();

        foreach (CoreOptions::getOptions()->getGroups() as $group) {
            $this->options->addGroup($group);
        }

        $exporterClasses = $this->getClassesOfExporters();
        foreach ($exporterClasses as $exporterClass) {
            foreach ($exporterClass::getOptions()->getGroups() as $group) {
                $this->options->addGroup($group);
            }
        }

        $analyzerClasses = $this->getClassesOfAnalyzers();
        foreach ($analyzerClasses as $analyzerClass) {
            foreach ($analyzerClass::getOptions()->getGroups() as $group) {
                $this->options->addGroup($group);
            }
        }
    }

    private function fillAllOptionsValues(): void
    {
        foreach ($this->options->getGroups() as $group) {
            foreach ($group->options as $option) {
                if (in_array($option->name, $this->knownOptions)) {
                    throw new \Exception("Detected duplicated option '{$option->name}' in more exporters/analyzers.");
                } else {
                    $this->knownOptions[] = $option->name;
                }

                if ($option->altName && in_array($option->altName, $this->knownOptions)) {
                    throw new \Exception("Detected duplicated option '{$option->altName}' in more exporters/analyzers.");
                } elseif ($option->altName) {
                    $this->knownOptions[] = $option->altName;
                }

                $option->setValueFromArgv($this->argv);
            }
        }
    }

    /**
     * @return void
     * @throws \Exception
     */
    private function checkUnknownOptions(): void
    {
        $scriptName = basename($_SERVER['PHP_SELF']);

        $unknownOptions = [];
        foreach ($this->argv as $arg) {
            $argWithoutValue = preg_replace('/\s*=.*/', '', $arg);
            if (!in_array($argWithoutValue, $this->knownOptions) && $arg !== $scriptName) {
                $unknownOptions[] = $arg;
            }
        }

        if ($unknownOptions) {
            throw new \Exception("Unknown options: " . implode(', ', $unknownOptions));
        }
    }

    private function importCrawlerOptions(): void
    {
        $this->crawlerOptions = new CoreOptions($this->options);
    }

    /**
     * Import all active exporters to $this->exporters based on filled CLI options
     * @return void
     */
    private function importExporters(): void
    {
        $this->exporters = [];

        $exporterClasses = $this->getClassesOfExporters();
        foreach ($exporterClasses as $exporterClass) {
            $exporter = new $exporterClass();
            /** @var Exporter $exporter */
            $exporter->setConfig($this->options);
            if ($exporter->shouldBeActivated()) {
                $this->exporters[] = $exporter;
            }
        }
    }

    private function importAnalyzers(): void
    {
        $this->analyzers = [];

        $analyzerClasses = $this->getClassesOfAnalyzers();
        foreach ($analyzerClasses as $analyzerClass) {
            $analyzer = new $analyzerClass();
            /** @var Analyzer $analyzer */
            $analyzer->setConfig($this->options);
            if ($analyzer->shouldBeActivated()) {
                $this->analyzers[] = $analyzer;
            }
        }
    }

    /**
     * @return string[]
     */
    private function getClassesOfExporters(): array
    {
        $classes = [];
        foreach (glob($this->crawlerClassDir . '/Export/*Exporter.php') as $file) {
            $classBaseName = basename($file, '.php');
            if ($classBaseName != 'Exporter' && $classBaseName != 'BaseExporter') {
                $classes[] = 'Crawler\\Export\\' . $classBaseName;
            }
        }
        return $classes;
    }

    /**
     * @return string[]
     */
    private function getClassesOfAnalyzers(): array
    {
        $classes = [];
        foreach (glob($this->crawlerClassDir . '/Analysis/*Analyzer.php') as $file) {
            $classBaseName = basename($file, '.php');
            if ($classBaseName != 'Analyzer' & $classBaseName != 'BaseAnalyzer') {
                $classes[] = 'Crawler\\Analysis\\' . $classBaseName;
            }
        }
        return $classes;
    }

    public function getCrawlerOptions(): CoreOptions
    {
        return $this->crawlerOptions;
    }

    /**
     * @return Exporter[]
     */
    public function getExporters(): array
    {
        return $this->exporters;
    }

    /**
     * @return Analyzer[]
     */
    public function getAnalyzers(): array
    {
        return $this->analyzers;
    }

    public function printHelp(): void
    {
        echo "\n";
        echo "Usage: ./swoole-cli crawler.php --url=https://mydomain.tld/ [options]\n";
        echo "Version: " . VERSION . "\n";
        echo "\n";

        foreach ($this->options->getGroups() as $group) {
            echo "{$group->name}:\n";
            echo str_repeat('-', strlen($group->name) + 1) . "\n";
            foreach ($group->options as $option) {
                $nameAndValue = $option->name;
                if ($option->type === Type::INT) {
                    $nameAndValue .= '=<int>';
                } elseif ($option->type === Type::STRING) {
                    $nameAndValue .= '=<val>';
                } elseif ($option->type === Type::FLOAT) {
                    $nameAndValue .= '=<val>';
                } elseif ($option->type === Type::REGEX) {
                    $nameAndValue .= '=<regex>';
                } elseif ($option->type === Type::EMAIL) {
                    $nameAndValue .= '=<email>';
                } elseif ($option->type === Type::URL) {
                    $nameAndValue .= '=<url>';
                } elseif ($option->type === Type::FILE) {
                    $nameAndValue .= '=<file>';
                } elseif ($option->type === Type::DIR) {
                    $nameAndValue .= '=<dir>';
                }

                echo str_pad($nameAndValue, 32) . " " . rtrim($option->description, '. ') . '.';
                if ($option->defaultValue != null) {
                    echo " Default values is `{$option->defaultValue}`.";
                }
                echo "\n";
            }
            echo "\n";
        }

        echo "\n";
        echo "For more detailed descriptions of parameters, see README.md.\n";
        echo "\n";
        echo "Created with ♥ by Ján Regeš (jan.reges@siteone.cz) from www.SiteOne.io (Czech Republic) [10/2023]\n";
    }

}