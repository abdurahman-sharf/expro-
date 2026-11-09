<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationException;
use App\Libraries\Integrations\IntegrationMessage;
use App\Libraries\Organization\BranchException;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The base of the screens of the integration settings. They open while accounting is off, and the
 * screens that change settings need the grant of the configuration screens as well as the grant of
 * their own module (the assumption of the owner decision on who may open the secrets screens).
 */
abstract class IntegrationController extends AccountingController
{
    protected IntegrationAccess $access;

    public function __construct(string $moduleId, bool $needsConfig = true)
    {
        parent::__construct($moduleId, false);

        $this->access = new IntegrationAccess($this->employee, $this->branchGuard());

        if ($needsConfig && ! $this->access->holdsConfig($this->employeeId())) {
            throw new RedirectException('no_access/' . IntegrationAccess::CONFIG);
        }
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    protected function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (IntegrationException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => IntegrationMessage::of($exception), 'id' => NEW_ENTRY]);
        } catch (AccountingException|BranchException|PostingException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $this->messageFor($exception), 'id' => NEW_ENTRY]);
        }
    }

    protected function requireManage(): void
    {
        if (! $this->access->mayManage($this->employeeId())) {
            throw new RedirectException('no_access/' . IntegrationAccess::MANAGE);
        }
    }

    protected function postedText(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? trim($value) : '';
    }
}
