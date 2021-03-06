<?php
/**
 * Created by PhpStorm.
 * User: Ormin
 */

namespace Ormin\OBSLexicalParser\Builds\Standalone;


use Ormin\OBSLexicalParser\Builds\CompilationUnsuccessfullException;
use Ormin\OBSLexicalParser\Utilities\ExternalExecution;

class CompileCommand implements \Ormin\OBSLexicalParser\Builds\CompileCommand {

    public function initialize()
    {
    }


    public function compile($sourcePath, $workspacePath, $outputPath)
    {
        $stdout = "";
        $stderr = "";

	$compilerPath = getcwd() . "/Compiler/";

	$command = 'mono "'.$compilerPath.'PapyrusCompiler.exe" "'.$sourcePath.'" -f="'.$compilerPath.'/TESV_Papyrus_Flags.flg" -i="'.$workspacePath.'" -o="'.$outputPath.'" -a';
	echo 'Executing command: '.$command.PHP_EOL;
	
	ExternalExecution::run($command,$stdout,$stderr);

        return implode(PHP_EOL,$stderr);

    }


} 
