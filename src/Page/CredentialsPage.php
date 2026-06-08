<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Tui\Navigator;
use Porthole\Tui\PageInterface;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class CredentialsPage implements PageInterface
{
    public function __construct(
        private readonly GenerateReportHandler $handler,
    ) {
    }

    public function mount(Navigator $navigator): ContainerWidget
    {
        $title = new TextWidget('Porthole');
        $title->addStyleClass('font-big text-cyan-400 bold');

        $urlInput = new InputWidget();
        $urlInput->setValue(getenv('HARBOR_URL') ?: 'https://');
        $urlInput->addStyleClass('input');

        $tokenInput = new InputWidget();
        $tokenInput->setValue(getenv('HARBOR_TOKEN') ?: '');
        $tokenInput->addStyleClass('input');

        $usernameInput = new InputWidget();
        $usernameInput->setValue(getenv('HARBOR_USERNAME') ?: '');
        $usernameInput->addStyleClass('input');

        $sslWidget = new SelectListWidget(
            items: [
                ['value' => 'yes', 'label' => 'Yes (recommended)', 'description' => 'verify SSL certificates'],
                ['value' => 'no', 'label' => 'No', 'description' => 'skip SSL verification (self-signed certs)'],
            ],
            maxVisible: 2,
        );

        $hint = new TextWidget('Press Enter to confirm, Ctrl+C to exit');
        $hint->addStyleClass('hint');

        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $container->addStyleClass('page');
        $container->add($title);
        $container->add(new TextWidget('Harbor URL'));
        $container->add($urlInput);
        $container->add(new TextWidget('Token'));
        $container->add($tokenInput);
        $container->add(new TextWidget('Username (optional)'));
        $container->add($usernameInput);
        $container->add(new TextWidget('Verify SSL'));
        $container->add($sslWidget);
        $container->add($hint);

        $keybindings = new Keybindings([
            'submit' => ['enter'],
            'next' => [Key::TAB],
            'previous' => ['shift+tab'],
        ]);

        $navigator->listen(function (InputEvent $event) use (
            $keybindings,
            $navigator,
            $hint,
            $urlInput,
            $tokenInput,
            $usernameInput,
            $sslWidget,
        ): void {
            $data = $event->getData();

            if ($keybindings->matches($data, 'next')) {
                $navigator->getTui()->getFocusManager()->focusNext();
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'previous')) {
                $navigator->getTui()->getFocusManager()->focusPrevious();
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'submit')) {
                $url = $urlInput->getValue();
                $token = $tokenInput->getValue();

                if ('' === $url || '' === $token) {
                    $hint->setText('Harbor URL and Token are required.');
                    $event->stopPropagation();

                    return;
                }

                $sslItem = $sslWidget->getSelectedItem();
                $username = '' !== $usernameInput->getValue() ? $usernameInput->getValue() : null;

                $navigator->navigateTo(new HomePage(
                    new HarborContext(
                        url: $url,
                        token: $token,
                        username: $username,
                        verifySsl: null === $sslItem || 'yes' === $sslItem['value'],
                    ),
                    $this->handler,
                ));
                $event->stopPropagation();
            }
        });

        return $container;
    }
}
