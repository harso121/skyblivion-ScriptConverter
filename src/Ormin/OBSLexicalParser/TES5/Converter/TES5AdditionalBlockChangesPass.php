<?php
/**
 * Created by PhpStorm.
 * User: Ormin
 */

namespace Ormin\OBSLexicalParser\TES5\Converter;


use Ormin\OBSLexicalParser\TES4\AST\Block\TES4CodeBlock;
use Ormin\OBSLexicalParser\TES5\AST\Block\TES5EventBlockList;
use Ormin\OBSLexicalParser\TES5\AST\Block\TES5EventCodeBlock;
use Ormin\OBSLexicalParser\TES5\AST\Code\Branch\TES5Branch;
use Ormin\OBSLexicalParser\TES5\AST\Code\Branch\TES5SubBranch;
use Ormin\OBSLexicalParser\TES5\AST\Expression\Operators\TES5ArithmeticExpressionOperator;
use Ormin\OBSLexicalParser\TES5\AST\Object\TES5ObjectCallArguments;
use Ormin\OBSLexicalParser\TES5\AST\Property\TES5LocalVariable;
use Ormin\OBSLexicalParser\TES5\AST\Scope\TES5GlobalScope;
use Ormin\OBSLexicalParser\TES5\AST\Scope\TES5MultipleScriptsScope;
use Ormin\OBSLexicalParser\TES5\AST\Value\Primitive\TES5Bool;
use Ormin\OBSLexicalParser\TES5\AST\Value\Primitive\TES5Float;
use Ormin\OBSLexicalParser\TES5\AST\Value\Primitive\TES5None;
use Ormin\OBSLexicalParser\TES5\Context\TES5LocalVariableParameterMeaning;
use Ormin\OBSLexicalParser\TES5\Factory\TES5BlockLocalScopeFactory;
use Ormin\OBSLexicalParser\TES5\Factory\TES5BranchFactory;
use Ormin\OBSLexicalParser\TES5\Factory\TES5CodeScopeFactory;
use Ormin\OBSLexicalParser\TES5\Factory\TES5ExpressionFactory;
use Ormin\OBSLexicalParser\TES5\Factory\TES5ReferenceFactory;
use Ormin\OBSLexicalParser\TES5\Factory\TES5ValueFactory;
use Ormin\OBSLexicalParser\TES5\Factory\TES5VariableAssignationFactory;
use Ormin\OBSLexicalParser\TES5\Types\TES5BasicType;

class TES5AdditionalBlockChangesPass {

    /**
     * @var TES5ValueFactory
     */
    private $valueFactory;

    /**
     * @var TES5BlockLocalScopeFactory
     */
    private $blockLocalScopeFactory;

    /**
     * @var TES5CodeScopeFactory
     */
    private $codeScopeFactory;

    /**
     * @var TES5ExpressionFactory
     */
    private $expressionFactory;

    /**
     * @var TES5ReferenceFactory
     */
    private $referenceFactory;

    /**
     * @var TES5BranchFactory
     */
    private $branchFactory;

    /**
     * @var TES5VariableAssignationFactory
     */
    private $assignationFactory;

    public function __construct(TES5ValueFactory $valueFactory,
                                TES5BlockLocalScopeFactory $blockLocalScopeFactory,
                                TES5CodeScopeFactory $codeScopeFactory,
                                TES5ExpressionFactory $expressionFactory,
                                TES5ReferenceFactory $referenceFactory,
                                TES5BranchFactory $branchFactory,
                                TES5VariableAssignationFactory $assignationFactory) {
        $this->valueFactory = $valueFactory;
        $this->blockLocalScopeFactory = $blockLocalScopeFactory;
        $this->codeScopeFactory = $codeScopeFactory;
        $this->expressionFactory = $expressionFactory;
        $this->referenceFactory = $referenceFactory;
        $this->branchFactory = $branchFactory;
        $this->assignationFactory = $assignationFactory;
    }

    const ON_UPDATE_TICK = 1;

    /**
     * @param TES4CodeBlock $block
     * @param TES5EventBlockList $blockList
     * @param TES5EventCodeBlock $newBlock
     * @param TES5MultipleScriptsScope $multipleScriptsScope
     * @param TES5GlobalScope $globalScope
     */
    public function modify(TES4CodeBlock $block, TES5EventBlockList $blockList, TES5EventCodeBlock $newBlock, TES5GlobalScope $globalScope, TES5MultipleScriptsScope $multipleScriptsScope) {

        $blockLocalScope = $newBlock->getLocalScope();

        switch (strtolower($block->getBlockType())) {

            case "gamemode":
            case 'scripteffectupdate':
            {
                $onInitLocalScope = $this->blockLocalScopeFactory->createFromBlockType("OnInit", $globalScope);
                $newInitBlock = new TES5EventCodeBlock("OnInit",$onInitLocalScope,$this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($onInitLocalScope)));
                $args = new TES5ObjectCallArguments();
                $args->add(new TES5Float(self::ON_UPDATE_TICK));

                $function = $this->valueFactory->createObjectCall($this->referenceFactory->createReferenceToSelf($globalScope), "RegisterForUpdate", $multipleScriptsScope, $args);
                $newInitBlock->addChunk($function);
                $blockList->add($newInitBlock);


                break;
            }

            case "onactivate": {
                $onInitLocalScope = $this->blockLocalScopeFactory->createFromBlockType("OnInit", $globalScope);
                $newInitBlock = new TES5EventCodeBlock("OnInit",$onInitLocalScope,$this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($onInitLocalScope)));

                $function = $this->valueFactory->createObjectCall($this->referenceFactory->createReferenceToSelf($globalScope), "BlockActivation", $multipleScriptsScope);
                $newInitBlock->addChunk($function);
                $blockList->add($newInitBlock);
                break;
            }

            case 'onactorequip':
            {

                $parameterList = $block->getBlockParameterList();

                if($parameterList == null){
                    break;
                }

                $parameterList = $parameterList->getVariableList();
                $tesEquippedTarget = $parameterList[0];
                $localScope = $newBlock->getCodeScope()->getLocalScope();
                $newContainer = $this->valueFactory->createReadReference($tesEquippedTarget->getBlockParameter(),$globalScope, $multipleScriptsScope, $localScope);

                $expression = $this->expressionFactory->createArithmeticExpression(
                    $this->referenceFactory->createReferenceToVariable($localScope->findVariableWithMeaning(TES5LocalVariableParameterMeaning::CONTAINER())),
                    TES5ArithmeticExpressionOperator::OPERATOR_EQUAL(),
                    $newContainer
                );

                $newCodeScope = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($blockLocalScope));

                $outerBranchCode = unserialize(serialize($newBlock->getCodeScope()));
                $outerBranchCode->getLocalScope()->setParentScope($newCodeScope->getLocalScope());

                $newCodeScope->add(new TES5Branch(
                    new TES5SubBranch(
                        $expression,
                        $outerBranchCode
                    )
                ));
                $newBlock->setCodeScope($newCodeScope);

                break;
            }

            case "ontriggeractor": {

                $parameterList = $block->getBlockParameterList();

                $localScope = $newBlock->getCodeScope()->getLocalScope();

                $activator = $localScope->findVariableWithMeaning(TES5LocalVariableParameterMeaning::ACTIVATOR());

                $castedToActor = new TES5LocalVariable("akAsActor",TES5BasicType::T_ACTOR());
                $referenceToCastedVariable = $this->referenceFactory->createReferenceToVariable($castedToActor);
                $referenceToNonCastedVariable = $this->referenceFactory->createReferenceToVariable($activator);

                $expression = $this->expressionFactory->createArithmeticExpression(
                    $referenceToCastedVariable,
                    TES5ArithmeticExpressionOperator::OPERATOR_NOT_EQUAL(),
                    new TES5None()
                );
                $newCodeScope = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($blockLocalScope));
                $newCodeScope->getLocalScope()->addVariable(
                    $castedToActor
                );
                $newCodeScope->add($this->assignationFactory->createAssignation($referenceToCastedVariable,$referenceToNonCastedVariable));

                if($parameterList !== null) {

                    //NOT TESTED
                    $parameterList = $parameterList->getVariableList();

                    $targetActor = $this->valueFactory->createReadReference($parameterList[0]->getBlockParameter(),$globalScope, $multipleScriptsScope, $localScope);

                    $interExpression = $this->expressionFactory->createArithmeticExpression(
                        $this->referenceFactory->createReferenceToVariable($activator),
                        TES5ArithmeticExpressionOperator::OPERATOR_EQUAL(),
                        $targetActor
                    );

                    $interBranchCode = unserialize(serialize($newBlock->getCodeScope()));

                    $outerBranchCode = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($newCodeScope->getLocalScope()));
                    $interBranchCode->getLocalScope()->setParentScope($outerBranchCode->getLocalScope());
                    $outerBranchCode->add(new TES5Branch(
                        new TES5SubBranch(
                            $interExpression,
                            $interBranchCode
                        )
                    ));

                } else {

                    $outerBranchCode = unserialize(serialize($newBlock->getCodeScope()));
                    $outerBranchCode->getLocalScope()->setParentScope($newCodeScope->getLocalScope());

                }

                $newCodeScope->add(new TES5Branch(
                    new TES5SubBranch(
                        $expression,
                        $outerBranchCode
                    )
                ));
                $newBlock->setCodeScope($newCodeScope);

                break;
            }

            case 'onadd': {

                $parameterList = $block->getBlockParameterList();

                if($parameterList == null){
                    break;
                }

                $parameterList = $parameterList->getVariableList();
                $tesEquippedTarget = $parameterList[0];
                $localScope = $newBlock->getCodeScope()->getLocalScope();
                $newContainer = $this->valueFactory->createReadReference($tesEquippedTarget->getBlockParameter(),$globalScope, $multipleScriptsScope, $localScope);

                $expression = $this->expressionFactory->createArithmeticExpression(
                    $this->referenceFactory->createReferenceToVariable($localScope->getVariableByName("akNewContainer")),
                    TES5ArithmeticExpressionOperator::OPERATOR_EQUAL(),
                    $newContainer
                );

                $newCodeScope = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($blockLocalScope));

                $outerBranchCode = unserialize(serialize($newBlock->getCodeScope()));
                $outerBranchCode->getLocalScope()->setParentScope($newCodeScope->getLocalScope());

                $newCodeScope->add(new TES5Branch(
                    new TES5SubBranch(
                        $expression,
                        $outerBranchCode
                    )
                ));
                $newBlock->setCodeScope($newCodeScope);

                break;
            }

            case 'ondrop': {


                $parameterList = $block->getBlockParameterList();

                if($parameterList == null){
                    break;
                }

                $parameterList = $parameterList->getVariableList();
                $tesEquippedTarget = $parameterList[0];
                $localScope = $newBlock->getCodeScope()->getLocalScope();
                $newContainer = $this->valueFactory->createReadReference($tesEquippedTarget->getBlockParameter(),$globalScope, $multipleScriptsScope, $localScope);

                $expression = $this->expressionFactory->createArithmeticExpression(
                    $this->referenceFactory->createReferenceToVariable($localScope->getVariableByName("akOldContainer")),
                    TES5ArithmeticExpressionOperator::OPERATOR_EQUAL(),
                    $newContainer
                );

                $newCodeScope = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($blockLocalScope));

                $outerBranchCode = unserialize(serialize($newBlock->getCodeScope()));
                $outerBranchCode->getLocalScope()->setParentScope($newCodeScope->getLocalScope());

                $newCodeScope->add(new TES5Branch(
                    new TES5SubBranch(
                        $expression,
                        $outerBranchCode
                    )
                ));
                $newBlock->setCodeScope($newCodeScope);

                break;
            }


            case 'onpackagestart': {

                $parameterList = $block->getBlockParameterList();

                if($parameterList == null){
                    break;
                }

                $parameterList = $parameterList->getVariableList();
                $tesEquippedTarget = $parameterList[0];
                $localScope = $newBlock->getCodeScope()->getLocalScope();
                $newContainer = $this->valueFactory->createReadReference($tesEquippedTarget->getBlockParameter(), $globalScope, $multipleScriptsScope, $localScope);

                $expression = $this->expressionFactory->createArithmeticExpression(
                    $this->referenceFactory->createReferenceToVariable($localScope->getVariableByName("akNewPackage")),
                    TES5ArithmeticExpressionOperator::OPERATOR_EQUAL(),
                    $newContainer
                );

                $newCodeScope = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($blockLocalScope));

                $outerBranchCode = unserialize(serialize($newBlock->getCodeScope()));
                $outerBranchCode->getLocalScope()->setParentScope($newCodeScope->getLocalScope());

                $newCodeScope->add(new TES5Branch(
                    new TES5SubBranch(
                        $expression,
                        $outerBranchCode
                    )
                ));
                $newBlock->setCodeScope($newCodeScope);

                break;
            }

            case 'onpackagedone':
            case 'onpackagechange':
            case 'onpackageend': {

                $parameterList = $block->getBlockParameterList()->getVariableList();
                $tesEquippedTarget = $parameterList[0];
                $localScope = $newBlock->getCodeScope()->getLocalScope();
                $newContainer = $this->valueFactory->createReadReference($tesEquippedTarget->getBlockParameter(),$globalScope, $multipleScriptsScope, $localScope);

                $expression = $this->expressionFactory->createArithmeticExpression(
                    $this->referenceFactory->createReferenceToVariable($localScope->getVariableByName("akOldPackage")),
                    TES5ArithmeticExpressionOperator::OPERATOR_EQUAL(),
                    $newContainer
                );

                $newCodeScope = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($blockLocalScope));

                $outerBranchCode = unserialize(serialize($newBlock->getCodeScope()));
                $outerBranchCode->getLocalScope()->setParentScope($newCodeScope->getLocalScope());

                $newCodeScope->add(new TES5Branch(
                    new TES5SubBranch(
                        $expression,
                        $outerBranchCode
                    )
                ));
                $newBlock->setCodeScope($newCodeScope);

                break;
            }


        case 'onalarm': {


            //@INCONSISTENCE - We don't account for alarm type.

            $expression = $this->expressionFactory->createArithmeticExpression(
                $this->valueFactory->createObjectCall($this->referenceFactory->createReferenceToSelf($globalScope), "IsAlarmed",$multipleScriptsScope),
                TES5ArithmeticExpressionOperator::OPERATOR_EQUAL(),
                new TES5Bool(true)
            );

            $newCodeScope = $this->codeScopeFactory->createCodeScope($this->blockLocalScopeFactory->createRecursiveScope($blockLocalScope));

            $outerBranchCode = unserialize(serialize($newBlock->getCodeScope()));
            $outerBranchCode->getLocalScope()->setParentScope($newCodeScope->getLocalScope());

            $newCodeScope->add(new TES5Branch(
                new TES5SubBranch(
                    $expression,
                    $outerBranchCode
                )
            ));
            $newBlock->setCodeScope($newCodeScope);

            break;
        }


        /**

        case 'onalarm':
        {

            $this->skyrimGroupEventName = 'onhit';

            if ($this->eventArgs[1] != 3) {
                //Nothing eelse is supported really..
                $this->omit = true;
                break;
            }

            $branch = new TES4ConditionalBranch();
            $expression = new TES4Expression();
            $leftConstant = new TES4Constant("akAggressor", "ObjectReference");
//                $actionConstant        = new TES4Constant($this->eventArgs[1],"Package");

            $actionConstant = TES4Factories::createReference($this->eventArgs[2], $this);

            $expression->left_side = $leftConstant;
            $expression->right_side = $actionConstant;
            $expression->comparision_operator = TES4Expression::COMPARISION_OPERATOR_EQUAL;

            $codeBlock = new TES4CodeBlock();
            $codeBlock->chunks = $this->chunks;

            $branch->ifs[] = array(
                'rawExpression' => 'SCRIPT_GENERATED',
                'expression' => $expression,
                'codeBlock' => $codeBlock
            );
            $this->chunks = new TES4ChunkContainer();
            $this->chunks->parent = $this;
            $this->chunks->addChunk($branch);

            break;
        }
            */

        }

    }

} 