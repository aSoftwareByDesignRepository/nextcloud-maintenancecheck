<?php

declare(strict_types=1);

namespace OCA\MaintenanceCheck\Notification;

use OCA\MaintenanceCheck\AppInfo\Application;
use OCA\MaintenanceCheck\Service\OverdueReminderService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('MaintenanceCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode ?: null);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case OverdueReminderService::TYPE_VISIT_OVERDUE:
				$due = (string)($params['dueOn'] ?? '');
				$label = (string)($params['title'] ?? '');
				$notification->setParsedSubject($l->t('Overdue maintenance visit'))
					->setParsedMessage(
						$label !== ''
							? $l->t('Visit for “%1$s” was due on %2$s.', [$label, $due])
							: $l->t('A maintenance visit was due on %s.', [$due])
					)
					->setLink($this->url->linkToRouteAbsolute('maintenancecheck.page.due'));
				break;

			case OverdueReminderService::TYPE_WO_OVERDUE:
				$number = (string)($params['number'] ?? '');
				$due = (string)($params['dueOn'] ?? '');
				$notification->setParsedSubject($l->t('Overdue work order'))
					->setParsedMessage(
						$number !== ''
							? $l->t('Work order %1$s was due on %2$s.', [$number, $due])
							: $l->t('A work order was due on %s.', [$due])
					)
					->setLink($this->url->linkToRouteAbsolute('maintenancecheck.page.workOrders'));
				break;

			default:
				throw new UnknownNotificationException();
		}

		$notification->setIcon($this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, 'app-dark.svg')
		));

		return $notification;
	}
}
