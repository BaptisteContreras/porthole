<?php

namespace Porthole\Tui;

use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class Navigator
{
    /** @var array<array{class-string, \Closure}> */
    private array $pageListeners = [];

    public function __construct(
        private readonly Tui $tui,
        private readonly ContainerWidget $root,
    ) {
    }

    public function navigateTo(PageInterface $page): void
    {
        $dispatcher = $this->tui->getEventDispatcher();
        foreach ($this->pageListeners as [$eventClass, $listener]) {
            $dispatcher->removeListener($eventClass, $listener);
        }
        $this->pageListeners = [];

        $this->root->clear();
        $this->root->add($page->mount($this));
    }

    public function listen(\Closure $listener): void
    {
        $this->tui->addListener($listener);
        $this->pageListeners[] = [$this->resolveEventClass($listener), $listener];
    }

    public function getTui(): Tui
    {
        return $this->tui;
    }

    public function focusNextVisibleWidget(): void
    {
        $this->tui->getFocusManager()->focusNext();
        if($this->tui->getFocusManager()->getFocus()?->getStyle()?->getHidden()) {
            $this->tui->getFocusManager()->focusNext();
        }
    }

    public function focusPreviousVisibleWidget(): void
    {
        $this->tui->getFocusManager()->focusPrevious();
        if($this->tui->getFocusManager()->getFocus()?->getStyle()?->getHidden()) {
            $this->tui->getFocusManager()->focusPrevious();
        }
    }

    public function updateFocus(): void
    {
        if ($this->tui->getFocusManager()->getFocus()?->getStyle()?->getHidden()) {
            $this->tui->getFocusManager()->focusNext();
        }
    }

    public function requestPageRender(bool $updateFocus = false): void
    {
        $this->tui->requestRender();
        if ($updateFocus) {
            $this->updateFocus();
        }
    }

    /** @return class-string */
    private function resolveEventClass(\Closure $listener): string
    {
        $params = (new \ReflectionFunction($listener(...)))->getParameters();
        if (!$params || !($type = $params[0]->getType()) instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new \InvalidArgumentException('The listener\'s first parameter must have an event class type hint.');
        }

        if (!class_exists($class = $type->getName())) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid event class.', $type->getName()));
        }

        return $class;
    }
}
