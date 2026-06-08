<?php

namespace Porthole\Tui;

use Symfony\Component\Tui\Widget\ContainerWidget;

interface PageInterface
{
    public function mount(Navigator $navigator): ContainerWidget;
}
