<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use Kaliop\eZMigrationBundle\API\Value\MigrationStep;
use Kaliop\eZMigrationBundle\Core\MigrationService;
use Kaliop\eZMigrationBundle\Core\ReferenceResolver\LoopResolver;

class LoopExecutor extends AbstractExecutor
{
    use IgnorableStepExecutorTrait;

    protected $supportedStepTypes = array('loop');

    /** @var MigrationService $migrationService */
    protected $migrationService;

    /** @var LoopResolver $loopResolver */
    protected $loopResolver;

    public function __construct($migrationService, $loopResolver)
    {
        $this->migrationService = $migrationService;
        $this->loopResolver = $loopResolver;
    }

    /**
     * @param MigrationStep $step
     * @return mixed
     * @throws \Exception
     */
    public function execute(MigrationStep $step)
    {
        parent::execute($step);

        if (!isset($step->dsl['repeat']) || $step->dsl['repeat'] < 0) {
            throw new \Exception("Invalid step definition: missing 'repeat' or not a positive integer");
        }

        if (!isset($step->dsl['steps']) || !is_array($step->dsl['steps'])) {
            throw new \Exception("Invalid step definition: missing 'steps' or not an array");
        }

        // no need for a 'mode' for now
        /*$action = $step->dsl['mode'];

        if (!in_array($action, $this->supportedActions)) {
            throw new \Exception("Invalid step definition: value '$action' is not allowed for 'mode'");
        }*/

        $this->skipStepIfNeeded($step);

        // before engaging in the loop, check that all steps are valid
        $stepExecutors = array();
        foreach ($step->dsl['steps'] as $i => $stepDef) {
            $type = $stepDef['type'];
            try {
                $stepExecutors[$i] = $this->migrationService->getExecutor($type);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException($e->getMessage() . " in sub-step of a loop step");
            }
        }

        $this->loopResolver->beginLoop();
        $result = null;

        // NB: we are *not* firing events for each pass of the loop... it might be worth making that optionally happen ?
        for ($i = 0; $i < $step->dsl['repeat']; $i++) {

            $this->loopResolver->loopStep();

            foreach ($step->dsl['steps'] as $j => $stepDef) {
                $type = $stepDef['type'];
                unset($stepDef['type']);
                $subStep = new MigrationStep($type, $stepDef, array_merge($step->context, array()));
                $result = $stepExecutors[$j]->execute($subStep);
            }
        }

        $this->loopResolver->endLoop();
        return $result;
    }

}
