<?php

namespace LeTots\WorkflowExtension;

use InvalidArgumentException;
use LeTots\WorkflowExtension\Attribute\AsWorkflow;
use LeTots\WorkflowExtension\Attribute\Place;
use LeTots\WorkflowExtension\Attribute\Transition;
use ReflectionClass;
use SplObjectStorage;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Validator\StateMachineValidator;
use Symfony\Component\Workflow\Validator\WorkflowValidator;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\Transition as WorkflowTransition;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

abstract class AbstractWorkflow extends Workflow implements WorkflowInterface
{
	private string|array|null $initial = null;

	/** @var array<string, array<string, mixed>> */
	private array $placesMetadata = [];

	private SplObjectStorage $transitionsMetadata;

	public function __construct(
		?EventDispatcherInterface $eventDispatcher = null,
	) {
		$reflectionClass = new ReflectionClass($this);
		$workflowAttributes = $reflectionClass->getAttributes(AsWorkflow::class);

		if (empty($workflowAttributes)) {
			throw new InvalidArgumentException('Workflow attribute is required');
		}

		if (count($workflowAttributes) > 1) {
			throw new InvalidArgumentException('Only one Workflow attribute is allowed per class');
		}

		$attributeInstance = $workflowAttributes[0]->newInstance();
		$this->placesMetadata = [];
		$this->transitionsMetadata = new SplObjectStorage();
		$places = $this->getPlaces();
		$transitions = $this->getTransitions($places, $attributeInstance->type);

		if (is_array($this->initial)) {
			if (count($this->initial) === 0) {
				$this->initial = null;
			}
			if (count($this->initial) === 1) {
				$this->initial = $this->initial[0];
			}
		}

		$metadataStore = $this->buildMetadataStore($attributeInstance->metadata);
		$definition = new Definition($places, $transitions, $this->initial, $metadataStore);
		$this->validateDefinition($definition, $attributeInstance->name, $attributeInstance->type);

		$markingStore = new MethodMarkingStore(
			$attributeInstance->type === AsWorkflow::TYPE_STATE_MACHINE,
			$attributeInstance->markingStoreProperty,
		);

		parent::__construct($definition, $markingStore, $eventDispatcher, $attributeInstance->name, null);
	}

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

			if (count($constantPlaceAttributes) > 1) {
				throw new InvalidArgumentException('Only one Place attribute is allowed per constant');
			}

			/** @var Place $placeAttribute */
			$placeAttribute = $constantPlaceAttributes[0]->newInstance();
			$placeName = $reflectionClassConstant->getValue();

			if ($placeAttribute->initial) {
				$this->initial[] = $placeName;
			}

			if (null !== $placeAttribute->metadata && [] !== $placeAttribute->metadata) {
				$this->placesMetadata[$placeName] = $placeAttribute->metadata;
			}

			$places[] = $placeName;
		}

		return $places;
	}

	public function getTransitions(array $places, string $type): array
	{
		$transitions = [];

		$reflectionClass = new ReflectionClass($this);

		foreach ($reflectionClass->getReflectionConstants() as $reflectionClassConstant) {
			$constantTransitionAttributes = $reflectionClassConstant->getAttributes(Transition::class);

			if (empty($constantTransitionAttributes)) {
				continue;
			}

			if (count($constantTransitionAttributes) > 1) {
				throw new InvalidArgumentException('Only one Transition attribute is allowed per constant');
			}

			/** @var Transition $transitionAttribute */
			$transitionAttribute = $constantTransitionAttributes[0]->newInstance();
			$from = $transitionAttribute->from;
			$to = $transitionAttribute->to;

			if ($type === AsWorkflow::TYPE_STATE_MACHINE) {
				if (is_array($from) && count($from) !== 1) {
					throw new InvalidArgumentException(sprintf(
						'State machine transition "%s" (%s) must declare a single `from` place per constant. '
						.'Use multiple #[Transition] constants sharing the same transition name instead of an array.',
						$reflectionClassConstant->getName(),
						$reflectionClassConstant->getValue(),
					));
				}
				if (is_array($to) && count($to) !== 1) {
					throw new InvalidArgumentException(sprintf(
						'State machine transition "%s" (%s) must declare a single `to` place per constant.',
						$reflectionClassConstant->getName(),
						$reflectionClassConstant->getValue(),
					));
				}

				$fromPlace = is_array($from) ? $from[0] : $from;
				$toPlace = is_array($to) ? $to[0] : $to;
			} else {
				$fromPlace = $from;
				$toPlace = $to;
			}

			if (is_string($from)) {
				if (!in_array($from, $places, true)) {
					throw new InvalidArgumentException('From place not found for transition '.$reflectionClassConstant->getValue());
				}
			} elseif (is_array($from)) {
				foreach ($from as $fromItem) {
					if (!in_array($fromItem, $places, true)) {
						throw new InvalidArgumentException('From place not found for transition '.$reflectionClassConstant->getValue());
					}
				}
			}

			if (is_string($to)) {
				if (!in_array($to, $places, true)) {
					throw new InvalidArgumentException('To place not found for transition '.$reflectionClassConstant->getValue());
				}
			} elseif (is_array($to)) {
				foreach ($to as $toItem) {
					if (!in_array($toItem, $places, true)) {
						throw new InvalidArgumentException('To place not found for transition '.$reflectionClassConstant->getValue());
					}
				}
			}

			$transition = new WorkflowTransition(
				$reflectionClassConstant->getValue(),
				$fromPlace,
				$toPlace,
			);

			if (null !== $transitionAttribute->metadata && [] !== $transitionAttribute->metadata) {
				$this->transitionsMetadata[$transition] = $transitionAttribute->metadata;
			}

			$transitions[] = $transition;
		}

		return $transitions;
	}

	// Helper to get all available places to go cause Workflow bundle only provide can function that returns availables transitions
	public static function canPlaces(WorkflowInterface $workflow, mixed $object): array
	{
		$places = [];
		foreach ($workflow->getEnabledTransitions($object) as $transition) {
			foreach ($transition->getTos() as $to) {
				$places[$to] = $transition->getName();
			}
		}

		return $places;
	}

	private function buildMetadataStore(?array $workflowMetadata): InMemoryMetadataStore
	{
		return new InMemoryMetadataStore(
			$workflowMetadata ?? [],
			$this->placesMetadata,
			$this->transitionsMetadata,
		);
	}

	private function validateDefinition(Definition $definition, string $name, string $type): void
	{
		if ($type === AsWorkflow::TYPE_STATE_MACHINE) {
			(new StateMachineValidator())->validate($definition, $name);

			return;
		}

		(new WorkflowValidator())->validate($definition, $name);
	}
}
