<?php

namespace Porthole\Page;

use Porthole\Harbor\ExtendedAuditLogEndpointStrategy;
use Porthole\Harbor\HarborContext;
use Porthole\Harbor\LegacyAuditLogEndpointStrategy;
use Porthole\Result\CsvReader;
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
        private readonly ?HarborContext $context = null,
        private readonly ?CsvReader $reader = null,
    ) {
    }

    public function mount(Navigator $navigator): ContainerWidget
    {
        $title = new TextWidget('Porthole');
        $title->addStyleClass('font-big text-cyan-400 bold');

        $urlInput = new InputWidget();
        $urlInput->setValue(null !== $this->context ? $this->context->url : (getenv('HARBOR_URL') ?: 'https://'));
        $urlInput->addStyleClass('input');

        $tokenInput = new InputWidget();
        $tokenInput->setValue(null !== $this->context ? $this->context->token : (getenv('HARBOR_TOKEN') ?: ''));
        $tokenInput->addStyleClass('input');

        $usernameInput = new InputWidget();
        $usernameInput->setValue(null !== $this->context ? ($this->context->username ?? '') : (getenv('HARBOR_USERNAME') ?: ''));
        $usernameInput->addStyleClass('input');

        $sslWidget = new SelectListWidget(
            items: [
                ['value' => 'yes', 'label' => 'Yes (recommended)', 'description' => 'verify SSL certificates'],
                ['value' => 'no', 'label' => 'No', 'description' => 'skip SSL verification (self-signed certs)'],
            ],
            maxVisible: 2,
        );

        if (null !== $this->context && !$this->context->verifySsl) {
            $sslWidget->setSelectedIndex(1);
        }

        $endpointWidget = new SelectListWidget(
            items: [
                ['value' => 'extended', 'label' => 'Extended (recommended)', 'description' => 'Harbor 2.13+ — /api/v2.0/auditlog-exts'],
                ['value' => 'legacy', 'label' => 'Legacy', 'description' => 'Harbor 2.12 and older — /api/v2.0/audit-logs'],
            ],
            maxVisible: 2,
        );

        if (null !== $this->context && 'legacy' === $this->context->auditLogEndpointStrategy->getKey()) {
            $endpointWidget->setSelectedIndex(1);
        }

        $tokenValue = $tokenInput->getValue();
        $maskWidget = new TextWidget('' !== $tokenValue ? str_repeat('●', mb_strlen($tokenValue)) : '(empty)');
        $maskWidget->addStyleClass('input');

        $tokenInput->setStyle(new Style(hidden: true));

        $hint = new TextWidget('Tab: next field  Ctrl+H: show/hide token  Enter: confirm  Ctrl+C: exit');
        $hint->addStyleClass('hint');

        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $container->addStyleClass('page');
        $container->add($title);
        $container->add(new TextWidget('Harbor URL'));
        $container->add($urlInput);
        $container->add(new TextWidget('Token'));
        $container->add($maskWidget);
        $container->add($tokenInput);
        $container->add(new TextWidget('Username (optional)'));
        $container->add($usernameInput);
        $container->add(new TextWidget('Verify SSL'));
        $container->add($sslWidget);
        $container->add(new TextWidget('Audit log endpoint'));
        $container->add($endpointWidget);
        $container->add($hint);

        $keybindings = new Keybindings([
            'submit' => ['enter'],
            'next' => [Key::TAB],
            'previous' => ['shift+tab'],
            'toggle_token' => ['ctrl+h'],
        ]);

        $tokenVisible = false;

        $navigator->listen(function (InputEvent $event) use (
            $keybindings,
            $navigator,
            $hint,
            $urlInput,
            $tokenInput,
            $maskWidget,
            $usernameInput,
            $sslWidget,
            $endpointWidget,
            &$tokenVisible,
        ): void {
            $data = $event->getData();

            if ($keybindings->matches($data, 'toggle_token')) {
                $tokenVisible = !$tokenVisible;
                if (!$tokenVisible) {
                    $value = $tokenInput->getValue();
                    $maskWidget->setText('' !== $value ? str_repeat('●', mb_strlen($value)) : '(empty)');
                }
                $tokenInput->setStyle(new Style(hidden: $tokenVisible ? null : true));
                $maskWidget->setStyle(new Style(hidden: $tokenVisible ? true : null));
                $navigator->requestPageRender(true);
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'next')) {
                $navigator->focusNextVisibleWidget();
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'previous')) {
                $navigator->focusPreviousVisibleWidget();
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

                $endpointItem = $endpointWidget->getSelectedItem();
                $strategy = ('legacy' === ($endpointItem['value'] ?? null))
                    ? new LegacyAuditLogEndpointStrategy()
                    : new ExtendedAuditLogEndpointStrategy();

                $navigator->navigateTo(new HomePage(
                    new HarborContext(
                        url: $url,
                        token: $token,
                        username: $username,
                        verifySsl: null === $sslItem || 'yes' === $sslItem['value'],
                        auditLogEndpointStrategy: $strategy,
                    ),
                    $this->handler,
                    $this->reader,
                ));
                $event->stopPropagation();
            }
        });

        return $container;
    }
}
