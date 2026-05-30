<?php

namespace LeTots\WorkflowExtension\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AsWorkflow
{
	public const string TYPE_STATE_MACHINE = 'state_machine';
	public const string TYPE_WORKFLOW = 'workflow';
	
	public function __construct(
		public string $name,
		public string $markingStoreProperty = 'status',
		public string $type = self::TYPE_STATE_MACHINE,
		public string|array|null $supportStrategy = null,
		public ?array $metadata = null,
		public bool $auditTrail = false,
	) {
	}
}
