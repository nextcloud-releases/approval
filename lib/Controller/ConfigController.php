<?php
/**
 * Nextcloud - Approval
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2021
 */

namespace OCA\Approval\Controller;

use OCP\IUserManager;

use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Approval\Service\RuleService;
use OCA\Approval\AppInfo\Application;

class ConfigController extends Controller {

	private $userId;
	private $config;
	private $dbtype;

	public function __construct($AppName,
								IRequest $request,
								IConfig $config,
								IUserManager $userManager,
								IL10N $l,
								LoggerInterface $logger,
								RuleService $ruleService,
								?string $userId) {
		parent::__construct($AppName, $request);
		$this->l = $l;
		$this->userId = $userId;
		$this->config = $config;
		$this->logger = $logger;
		$this->userManager = $userManager;
		$this->ruleService = $ruleService;
	}

	/**
	 *
	 * @return DataResponse
	 */
	public function getRules(): DataResponse {
		$rules = $this->ruleService->getRules();
		foreach ($rules as $id => $rule) {
			foreach ($rule['who'] as $k => $elem) {
				if (isset($elem['userId'])) {
					$user = $this->userManager->get($elem['userId']);
					$rules[$id]['who'][$k]['displayName'] = $user ? $user->getDisplayName() : $elem['userId'];
				} else {
					$rules[$id]['who'][$k]['displayName'] = $elem['groupId'];
				}
			}
		}
		return new DataResponse($rules);
	}

	/**
	 *
	 * @return DataResponse
	 */
	public function createRule(int $tagPending, int $tagApproved, int $tagRejected, array $who): DataResponse {
		$result = $this->ruleService->createRule($tagPending, $tagApproved, $tagRejected, $who);
		return isset($result['error'])
			? new DataResponse($result, 400)
			: new DataResponse($result['id']);
	}

	/**
	 *
	 * @return DataResponse
	 */
	public function saveRule(int $id, int $tagPending, int $tagApproved, int $tagRejected, array $who): DataResponse {
		$result = $this->ruleService->saveRule($id, $tagPending, $tagApproved, $tagRejected, $who);
		return isset($result['error'])
			? new DataResponse($result, 400)
			: new DataResponse($result['id']);
	}

	/**
	 *
	 * @return DataResponse
	 */
	public function deleteRule(int $id): DataResponse {
		$result = $this->ruleService->deleteRule($id);
		return isset($result['error'])
			? new DataResponse($result, 400)
			: new DataResponse();
	}
}
