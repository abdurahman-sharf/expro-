<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Models\Stock_location;
use CodeIgniter\HTTP\ResponseInterface;

abstract class DocumentScreen extends Secure_Controller
{
    protected const PAGE_SIZE = 25;

    private ?BranchGuard $branchGuard = null;

    protected function employeeId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    protected function can(string $permissionId): bool
    {
        return $this->employee->has_grant($permissionId, $this->employeeId());
    }

    protected function branchGuard(): BranchGuard
    {
        return $this->branchGuard ??= new BranchGuard(null, $this->employee);
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    protected function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (AccountingException|BranchException|PostingException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($exception), 'id' => NEW_ENTRY]);
        }
    }

    protected function forbidden(): ResponseInterface
    {
        return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Accounting.error_permission_denied'), 'id' => NEW_ENTRY]);
    }

    protected function dateOf(string $name): string
    {
        $value = trim((string) $this->request->getGet($name));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    protected function postText(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Stock locations the employee works in, by id.
     *
     * @return array<int, string>
     */
    protected function locationOptions(): array
    {
        $options = [];

        foreach (model(Stock_location::class)->get_allowed_locations('sales', true) as $id => $name) {
            $options[(int) $id] = (string) $name;
        }

        return $options;
    }
}
