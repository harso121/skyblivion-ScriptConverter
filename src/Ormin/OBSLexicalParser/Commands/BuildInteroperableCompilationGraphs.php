<?php
/**
 * Created by PhpStorm.
 * User: Ormin
 */

namespace Ormin\OBSLexicalParser\Commands;

use Dariuszp\CliProgressBar;
use Ormin\OBSLexicalParser\Builds\BuildTargetFactory;
use Ormin\OBSLexicalParser\TES4\AST\Value\ObjectAccess\TES4ObjectProperty;
use Ormin\OBSLexicalParser\TES4\Context\ESMAnalyzer;
use Ormin\OBSLexicalParser\TES5\AST\Property\TES5Property;
use Ormin\OBSLexicalParser\TES5\Context\TypeMapper;
use Ormin\OBSLexicalParser\TES5\Exception\ConversionException;
use Ormin\OBSLexicalParser\TES5\Graph\TES5ScriptDependencyGraph;
use Ormin\OBSLexicalParser\TES5\Service\TES5TypeInferencer;
use Ormin\OBSLexicalParser\TES5\Types\TES5BasicType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ormin\OBSLexicalParser\TES5\Types\TES5Type;

class BuildInteroperableCompilationGraphs extends Command
{

    protected function configure()
    {
        $this
            ->setName('skyblivion:parser:buildGraphs')
            ->setDescription('Build graphs of scripts which are interconnected to be transpiled together');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        set_time_limit(10800);

        $target = 'Standalone';
        $errorLog = fopen("graph_error_log","w+");
        $log = fopen("graph_debug_log","w+");
        $buildTarget = BuildTargetFactory::get($target);

        if (
            (count(array_slice(scandir($buildTarget->getWorkspacePath()), 2)) > 0) ||
            (count(array_slice(scandir($buildTarget->getTranspiledPath()), 2))) > 0 ||
            (count(array_slice(scandir($buildTarget->getArtifactsPath()), 2))) > 0
        ) {
            $output->writeln("Target " . $target . " current build dir not clean, archive it manually.");
            return;
        }

        $sourceFiles = array_slice(scandir($buildTarget->getSourcePath()), 2);
        $inferencer = new TES5TypeInferencer(new ESMAnalyzer(new TypeMapper()),$buildTarget->getSourcePath());

        $dependencyGraph = [];
        $usageGraph = [];

        $progressBar = new CliProgressBar(count($sourceFiles));
        $progressBar->display();

        foreach($sourceFiles as $sourceFile) {

            try {
                $scriptName = substr($sourceFile, 0, -4);
                $AST = $buildTarget->getAST($buildTarget->getSourceFromPath($scriptName));

                /**
                 * @var TES4ObjectProperty[] $propertiesAccesses
                 */
                $propertiesAccesses = [];
                $AST->filter(function ($data) use (&$propertiesAccesses) {

                    if($data instanceof TES4ObjectProperty) {
                        $propertiesAccesses[] = $data;
                    }

                });

                /**
                 * @var TES5Property[] $preparedProperties
                 */
                $preparedProperties = [];
                /**
                 * @var TES5Type[] $preparedPropertiesTypes
                 */

                $preparedPropertiesTypes = [];
                foreach($propertiesAccesses as $property) {
                    preg_match("#([0-9a-zA-Z]+)\.([0-9a-zA-Z]+)#i", $property->getData(), $matches);
                    $propertyName = $matches[1];
                    $propertyKeyName = strtolower($propertyName);
                    if (!isset($preparedProperties[$propertyKeyName])) {
                        $preparedProperty = new TES5Property($propertyName, TES5BasicType::T_FORM(), $matches[1]);
                        $preparedProperties[$propertyKeyName] = $preparedProperty;
                        $inferencingType = $inferencer->resolveInferenceTypeByReferenceEdid($preparedProperty);
                        $preparedPropertiesTypes[$propertyKeyName] = $inferencingType;
                    } else {
                        $preparedProperty = $preparedProperties[$propertyKeyName];
                        $inferencingType = $inferencer->resolveInferenceTypeByReferenceEdid($preparedProperty);
                        if($inferencingType != $preparedPropertiesTypes[$propertyKeyName]) {
                            throw new ConversionException("Cannot settle up the properties types - conflict.");
                        }
                    }
                }

                fwrite($log, $scriptName." - ".count($preparedProperties)." prepared".PHP_EOL);

                foreach($preparedProperties as $preparedPropertyKey => $preparedProperty) {

                    //Only keys are lowercased.
                    $lowerPropertyType = strtolower($preparedPropertiesTypes[$preparedPropertyKey]->value());
                    $lowerScriptType = strtolower($scriptName);

                    if(!isset($dependencyGraph[$lowerPropertyType])) {
                        $dependencyGraph[$lowerPropertyType] = [];
                    }
                    $dependencyGraph[$lowerPropertyType][] = $lowerScriptType;

                    if(!isset($usageGraph[$lowerScriptType])) {
                        $usageGraph[$lowerScriptType] = [];
                    }

                    $usageGraph[$lowerScriptType][] = $lowerPropertyType;
                    fwrite($log,'Registering a dependency from '.$scriptName.' to '.$preparedPropertiesTypes[$preparedPropertyKey]->value().PHP_EOL);
                }
                $progressBar->progress();

            } catch(\Exception $e) {
                fwrite($errorLog, $sourceFile.PHP_EOL.$e->getMessage());
                continue;
            }
        }

        $progressBar->end();
        $graph = new TES5ScriptDependencyGraph($dependencyGraph, $usageGraph);
        file_put_contents('app/graph',serialize($graph));
        fclose($errorLog);
        fclose($log);
    }


}