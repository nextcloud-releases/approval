<?php
/**
 * Nextcloud - Approval
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2021
 */

namespace OCA\Approval\Service;

use OCP\IL10N;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;

use OCA\Approval\AppInfo\Application;
use OCA\Approval\Activity\ActivityManager;

class ApprovalService {

	private $l10n;
	private $logger;

	/**
	 * Service to operate on tags
	 */
	public function __construct (string $appName,
								IConfig $config,
								LoggerInterface $logger,
								ISystemTagManager $tagManager,
								ISystemTagObjectMapper $tagObjectMapper,
								IRootFolder $root,
								IUserManager $userManager,
								INotificationManager $notificationManager,
								RuleService $ruleService,
								ActivityManager $activityManager,
								IL10N $l10n) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->root = $root;
		$this->userManager = $userManager;
		$this->notificationManager = $notificationManager;
		$this->activityManager = $activityManager;
		$this->tagManager = $tagManager;
		$this->tagObjectMapper = $tagObjectMapper;
		$this->ruleService = $ruleService;
	}

	/**
	 * @param string $name of the new tag
	 * @return array
	 */
	public function createTag(string $name): array {
		try {
			$this->tagManager->createTag($name, false, false);
			return [];
		} catch (TagAlreadyExistsException $e) {
			return ['error' => 'Tag already exists'];
		}
	}

	/**
	 * @param int $fileId
	 * @return bool
	 */
	public function getApprovalState(int $fileId, ?string $userId): int {
		// to return PENDING, 2 conditions:
		// - user matches
		// - tag matches
		$rules = $this->ruleService->getRules();
		foreach ($rules as $id => $rule) {
			try {
				if ($this->tagObjectMapper->haveTag($fileId, 'files', $rule['tagPending'])
					&& in_array($userId, $rule['users'])) {
					return Application::STATE_APPROVABLE;
				}
			} catch (TagNotFoundException $e) {
			}
		}

		// now check approved and rejected, we don't care about the user here
		foreach ($rules as $id => $rule) {
			try {
				if ($this->tagObjectMapper->haveTag($fileId, 'files', $rule['tagPending'])) {
					return Application::STATE_PENDING;
				} elseif ($this->tagObjectMapper->haveTag($fileId, 'files', $rule['tagApproved'])) {
					return Application::STATE_APPROVED;
				} elseif ($this->tagObjectMapper->haveTag($fileId, 'files', $rule['tagRejected'])) {
					return Application::STATE_REJECTED;
				}
			} catch (TagNotFoundException $e) {
			}
		}

		return Application::STATE_NOTHING;
	}

	/**
	 * @param int $fileId
	 * @return bool success
	 */
	public function approve(int $fileId, ?string $userId): bool {
		$fileState = $this->getApprovalState($fileId, $userId);
		// if file has pending tag and user is authorized to approve it
		if ($fileState === Application::STATE_APPROVABLE) {
			$rules = $this->ruleService->getRules();
			foreach ($rules as $id => $rule) {
				try {
					if ($this->tagObjectMapper->haveTag($fileId, 'files', $rule['tagPending'])
						&& in_array($userId, $rule['users'])) {
						$this->tagObjectMapper->assignTags($fileId, 'files', $rule['tagApproved']);
						$this->tagObjectMapper->unassignTags($fileId, 'files', $rule['tagPending']);

						$this->sendNotification($fileId, $userId, true);
						$this->activityManager->triggerEvent(
							ActivityManager::APPROVAL_OBJECT_NODE, $fileId,
							ActivityManager::SUBJECT_APPROVED,
							[]
						);
						return true;
					}
				} catch (TagNotFoundException $e) {
				}
			}
		}
		return false;
	}

	/**
	 * @param int $fileId
	 * @return bool success
	 */
	public function reject(int $fileId, ?string $userId): bool {
		$fileState = $this->getApprovalState($fileId, $userId);
		// if file has pending tag and user is authorized to approve it
		if ($fileState === Application::STATE_APPROVABLE) {
			$rules = $this->ruleService->getRules();
			foreach ($rules as $id => $rule) {
				try {
					if ($this->tagObjectMapper->haveTag($fileId, 'files', $rule['tagPending'])
						&& in_array($userId, $rule['users'])) {
						$this->tagObjectMapper->assignTags($fileId, 'files', $rule['tagRejected']);
						$this->tagObjectMapper->unassignTags($fileId, 'files', $rule['tagPending']);

						$this->sendNotification($fileId, $userId, false);
						$this->activityManager->triggerEvent(
							ActivityManager::APPROVAL_OBJECT_NODE, $fileId,
							ActivityManager::SUBJECT_REJECTED,
							[]
						);
						return true;
					}
				} catch (TagNotFoundException $e) {
				}
			}
		}
		return false;
	}

	private function sendNotification(int $fileId, ?string $approverId, bool $approved) {
		$paramsByUser = [];
		$root = $this->root;
		// notification for eveyone having access except the one approving/rejecting
		$this->userManager->callForSeenUsers(function (IUser $user) use ($root, $fileId, $approverId, &$paramsByUser) {
			$thisUserId = $user->getUID();
			if ($thisUserId !== $approverId) {
				$userFolder = $root->getUserFolder($thisUserId);
				$found = $userFolder->getById($fileId);
				if (count($found) > 0) {
					$node = $found[0];
					$path = $userFolder->getRelativePath($node->getPath());
					$paramsByUser[$thisUserId] = [
						'fileId' => $fileId,
						'fileName' => $node->getName(),
						'relativePath' => $path,
						'approverId' => $approverId,
					];
				}
			}
		});

		foreach ($paramsByUser as $userId => $params) {
			$manager = $this->notificationManager;
			$notification = $manager->createNotification();

			$subject = $approved ? 'approved' : 'rejected';
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('dum', 'dum')
				->setSubject($subject, $params);

			$manager->notify($notification);
		}
	}
}
