<?php

namespace LeTots\WorkflowExtension\DependencyInjection\Compiler;

use InvalidArgumentException;
use LeTots\WorkflowExtension\Attribute\AsWorkflow;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Workflow\EventListener\AuditTrailListener;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\WorkflowInterface;

class WorkflowExtensionCompilerPass implements CompilerPassInterface
{
	/**
	 * @throws ReflectionException
	 */
	public function process(ContainerBuilder $container): void
	{
		/** @var array<string, true> $processedClasses */
		$processedClasses = [];
		/** @var array<string, string> $registeredNames */
		$registeredNames = [];
		/** @var array<string, true> $registryWorkflows */
		$registryWorkflows = [];

		foreach ($container->getDefinitions() as $definition) {
			$class = $definition->getClass();

			if (!$class || !class_exists($class, false)) {
				continue;
			}

			if (isset($processedClasses[$class])) {
				continue;
			}

			$reflectionClass = new ReflectionClass($class);
			$attributes = $reflectionClass->getAttributes(AsWorkflow::class);

			if ([] === $attributes) {
				continue;
			}

			$processedClasses[$class] = true;

			foreach ($attributes as $attribute) {
				/** @var AsWorkflow $workflowAttr */
				$workflowAttr = $attribute->newInstance();
				$workflowName = $workflowAttr->name;
				$workflowAlias = 'workflow.'.$workflowName;

				if (isset($registeredNames[$workflowName]) && $registeredNames[$workflowName] !== $class) {
					throw new InvalidArgumentException(sprintf(
						'The workflow name "%s" is already used by "%s", cannot register it again for "%s".',
						$workflowName,
						$registeredNames[$workflowName],
						$class,
					));
				}

				if ($container->hasDefinition($workflowAlias)) {
					throw new InvalidArgumentException(sprintf(
						'The workflow "%s" is already registered as service "%s". Rename the #[AsWorkflow] name or remove the conflicting configuration.',
						$workflowName,
						$workflowAlias,
					));
				}

				if ($container->hasAlias($workflowAlias)) {
					$existingTarget = (string) $container->getAlias($workflowAlias);

					if ($existingTarget !== $class) {
						throw new InvalidArgumentException(sprintf(
							'The workflow "%s" is already registered as alias "%s" pointing to "%s". Rename the #[AsWorkflow] name or remove the conflicting configuration.',
							$workflowName,
							$workflowAlias,
							$existingTarget,
						));
					}
				} else {
					$container->setAlias($workflowAlias, $class)->setPublic(true);
				}

				$registeredNames[$workflowName] = $class;

				if ($container->hasDefinition($class)) {
					$workflowDefinition = $container->findDefinition($class);
				} else {
					$workflowDefinition = $container->register($class)->setClass($class);
				}

				$workflowDefinition
					->setPublic(true)
					->setAutowired(true)
					->setArgument('$eventDispatcher', new Reference('event_dispatcher'));

				$container->registerAliasForArgument(
					$class,
					WorkflowInterface::class,
					$workflowName,
				);

				if ($workflowAttr->auditTrail && $container->hasDefinition('logger')) {
					$this->registerAuditTrailListener($container, $workflowName);
				}

				if ($container->hasDefinition(Registry::class) && null !== $workflowAttr->supportStrategy && !isset($registryWorkflows[$class])) {
					$registryDefinition = $container->findDefinition(Registry::class);
					$supportStrategy = is_string($workflowAttr->supportStrategy)
						? new Definition(InstanceOfSupportStrategy::class, [$workflowAttr->supportStrategy])
						: $workflowAttr->supportStrategy;

					$registryDefinition->addMethodCall('addWorkflow', [
						new Reference($class),
						$supportStrategy,
					]);
					$registryWorkflows[$class] = true;
				}
			}
		}
	}

	private function registerAuditTrailListener(ContainerBuilder $container, string $workflowName): void
	{
		$listenerId = 'letots_workflow.'.$workflowName.'.audit_trail';

		if ($container->hasDefinition($listenerId)) {
			return;
		}

		$listener = new Definition(AuditTrailListener::class);
		$listener->addArgument(new Reference('logger'));
		$listener->addTag('monolog.logger', ['channel' => 'workflow']);
		$listener->addTag('kernel.event_listener', [
			'event' => sprintf('workflow.%s.leave', $workflowName),
			'method' => 'onLeave',
		]);
		$listener->addTag('kernel.event_listener', [
			'event' => sprintf('workflow.%s.transition', $workflowName),
			'method' => 'onTransition',
		]);
		$listener->addTag('kernel.event_listener', [
			'event' => sprintf('workflow.%s.enter', $workflowName),
			'method' => 'onEnter',
		]);

		$container->setDefinition($listenerId, $listener);
	}
}
