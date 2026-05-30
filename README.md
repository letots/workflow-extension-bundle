# workflow-extension-bundle

Define Symfony Workflows as PHP classes using attributes, with helpers to work with them.

## Workflow class

```php
#[AsWorkflow(name: 'order', type: AsWorkflow::TYPE_STATE_MACHINE)]
class OrderWorkflow extends AbstractWorkflow
{
    public const PLACE_NEW = 'new';

    #[Place(initial: true)]
    public const PLACE_DRAFT = 'draft';

    #[Transition(from: self::PLACE_DRAFT, to: self::PLACE_NEW)]
    public const TRANSITION_PUBLISH = 'publish';
}
```

Inject the workflow by name:

```php
public function __construct(
    private WorkflowInterface $order,
) {}
```

Or use the alias `workflow.order`.

## Transitions

- `#[Transition]` must be placed on a **class constant**. The constant value is the transition name.
- In `TYPE_STATE_MACHINE`, each constant declares a single arc: one `from` place and one `to` place.
- To reach the same destination from several places, declare several constants with the **same transition name** (same constant value), each with its own `from` place.

```php
#[Transition(from: self::PLACE_DRAFT, to: self::PLACE_NEW)]
public const TRANSITION_PUBLISH = 'publish';

#[Transition(from: self::PLACE_REVIEW, to: self::PLACE_NEW)]
public const TRANSITION_PUBLISH_FROM_REVIEW = 'publish';
```

In `TYPE_WORKFLOW`, `from` and `to` may be arrays for multi-place transitions.

## Guards

Guards are standard Symfony workflow guard listeners. The bundle injects the application `event_dispatcher` into each workflow service so guard events are dispatched.

```php
#[AsEventListener(event: 'workflow.order.guard')]
public function onGuard(GuardEvent $event): void
{
    if ($event->getTransitionName() === 'publish' && !$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
        $event->setBlocked(true, 'Not allowed.');
    }
}
```

Replace `order` with the workflow name from `#[AsWorkflow(name: '...')]`.

## Registry

When using `supportStrategy` with a class name string, the bundle registers an `InstanceOfSupportStrategy` automatically:

```php
#[AsWorkflow(name: 'order', supportStrategy: Order::class)]
class OrderWorkflow extends AbstractWorkflow
{
}
```
