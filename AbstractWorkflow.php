<?php

namespace LeTots\WorkflowExtension;

use InvalidArgumentException;
use LeTots\WorkflowExtension\Attribute\AsWorkflow;
use LeTots\WorkflowExtension\Attribute\Place;
use LeTots\WorkflowExtension\Attribute\Transition;
use ReflectionClass;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\Transition as WorkflowTransition;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

abstract class AbstractWorkflow extends Workflow implements WorkflowInterface
{
	// private $initial
	private string|array|null $initial = null;
	
	public function __construct()
	{
		$reflectionClass = new ReflectionClass($this);
		$workflowAttributes = $reflectionClass->getAttributes(AsWorkflow::class);
		
		if (empty($workflowAttributes)) {
			throw new InvalidArgumentException('Workflow attribute is required');
		}
		
		if(count($workflowAttributes) > 1) {
			throw new InvalidArgumentException('Only one Workflow attribute is allowed per constant');
		}
		
		$places = $this->getPlaces();
		$transitions = $this->getTransitions($places);
		$attributeArguments = $workflowAttributes[0]->getArguments();
		
		if(is_array($this->initial)) {
			if(count($this->initial) === 0) {
				$this->initial = null;
			}
			if(count($this->initial) === 1) {
				$this->initial = $this->initial[0];
			}
		}
		
		$definition = new Definition($places, $transitions, $this->initial);
		$markingStore = new MethodMarkingStore(
			isset($attributeArguments['type']) && $attributeArguments['type'] === AsWorkflow::TYPE_STATE_MACHINE,
			isset($attributeArguments['markingStoreProperty']) ? $attributeArguments['markingStoreProperty'] : 'status'
		);
		
		parent::__construct($definition, $markingStore, null, $attributeArguments['name'], null);
	}
	
	/**
	 * @throws ReflectionException
	 */
	public function getPlaces(): array
	{
		$places = [];
		$this->initial = [];
		
		$reflectionClass = new ReflectionClass($this);
		
		foreach ($reflectionClass->getReflectionConstants() as $reflectionClassConstant) {
			$constantPlaceAttributes = $reflectionClassConstant->getAttributes(Place::class);
			
			if (empty($constantPlaceAttributes)) {
				continue;
			}
			
			if(count($constantPlaceAttributes) > 1) {
				throw new InvalidArgumentException('Only one Place attribute is allowed per constant');
			}
			
			$attributeArguments = $constantPlaceAttributes[0]->getArguments();
			
			if(isset($attributeArguments['initial']) && $attributeArguments['initial']) {
				$this->initial[] = $reflectionClassConstant->getValue();
			}
			
			$places[] = $reflectionClassConstant->getValue();
		}
		
		return $places;
	}
	
	public function getTransitions(array $places): array
	{
		$transitions = [];
		
		$reflectionClass = new ReflectionClass($this);
		
		foreach ($reflectionClass->getReflectionConstants() as $reflectionClassConstant) {
			$constantTransitionAttributes = $reflectionClassConstant->getAttributes(Transition::class);
			
			if (empty($constantTransitionAttributes)) {
				continue;
			}
			
			if(count($constantTransitionAttributes) > 1) {
				throw new InvalidArgumentException('Only one Transition attribute is allowed per constant');
			}
			
			$attributeArguments = $constantTransitionAttributes[0]->getArguments();
			
			if(!isset($attributeArguments['from']) || !isset($attributeArguments['to'])) {
				throw new InvalidArgumentException('From and To places are required for transition '.$reflectionClassConstant->getValue());
			}
			
			if(is_string($attributeArguments['from'])) {
				if(!in_array($attributeArguments['from'], $places)) {
					throw new InvalidArgumentException('From place not found for transition '.$reflectionClassConstant->getValue());
				}
			} elseif (is_array($attributeArguments['from'])) {
				foreach($attributeArguments['from'] as $from) {
					if(!in_array($from, $places)) {
						throw new InvalidArgumentException('From place not found for transition '.$reflectionClassConstant->getValue());
					}
				}
			}
			
			if(is_string($attributeArguments['to'])) {
				if(!in_array($attributeArguments['to'], $places)) {
					throw new InvalidArgumentException('To place not found for transition '.$reflectionClassConstant->getValue());
				}
			} elseif (is_array($attributeArguments['to'])) {
				foreach($attributeArguments['to'] as $to) {
					if(!in_array($to, $places)) {
						throw new InvalidArgumentException('To place not found for transition '.$reflectionClassConstant->getValue());
					}
				}
			}
			
			$transitions[] = new WorkflowTransition($reflectionClassConstant->getValue(), $attributeArguments['from'], $attributeArguments['to']);
		}
		
		return $transitions;
	}
	
	// Helper to get all available places to go cause Workflow bundle only provide can function that returns availables transitions
	public static function canPlaces(WorkflowInterface $workflow, mixed $object): array
	{
		$places = [];
		foreach($workflow->getEnabledTransitions($object) as $transition) {
			foreach ($transition->getTos() as $to) {
				$places[$to] = $transition->getName();
			}
		}
		return $places;
	}
}
