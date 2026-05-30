<?php

namespace LeTots\WorkflowExtension\DependencyInjection\Compiler;

use LeTots\WorkflowExtension\Attribute\AsWorkflow;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
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
		foreach ($container->getDefinitions() as $definition) {
			$class = $definition->getClass();

			if (!$class || !class_exists($class, false)) {
				continue;
			}

			$reflectionClass = new ReflectionClass($class);
			$attributes = $reflectionClass->getAttributes(AsWorkflow::class);

			foreach ($attributes as $attribute) {
				/** @var AsWorkflow $workflowAttr */
				$workflowAttr = $attribute->newInstance();
				$workflowName = $workflowAttr->name;
				$workflowAlias = 'workflow.'.$workflowName;

				if ($container->hasDefinition($class)) {
					$workflowDefinition = $container->findDefinition($class);
				} else {
					$workflowDefinition = $container->register($class)->setClass($class);
				}

				$workflowDefinition
					->setPublic(true)
					->setAutowired(true)
					->setArgument('$eventDispatcher', new Reference('event_dispatcher'));

				if ($container->hasDefinition($workflowAlias)) {
					$container->removeDefinition($workflowAlias);
				}

				$container->setAlias($workflowAlias, $class)->setPublic(true);

				$container->registerAliasForArgument(
					$class,
					WorkflowInterface::class,
					$workflowName,
				);

				if ($container->hasDefinition(Registry::class) && null !== $workflowAttr->supportStrategy) {
					$registryDefinition = $container->findDefinition(Registry::class);
					$supportStrategy = is_string($workflowAttr->supportStrategy)
						? new Definition(InstanceOfSupportStrategy::class, [$workflowAttr->supportStrategy])
						: $workflowAttr->supportStrategy;

					$registryDefinition->addMethodCall('addWorkflow', [
						new Reference($class),
						$supportStrategy,
					]);
				}
			}
		}
	}
}
