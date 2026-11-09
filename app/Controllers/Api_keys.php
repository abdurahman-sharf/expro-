<?php

namespace App\Controllers;

use App\Libraries\Integrations\ApiKeys;
use App\Libraries\Integrations\ApiScope;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The keys of the read-only API. The screen needs the grant of the configuration screens, the sensitive
 * permission to manage keys and every branch: a key reads the books of the whole company or of the one branch it
 * names. The secret of a key is shown once, in the answer to its creation, and is held nowhere after that.
 */
class Api_keys extends IntegrationController
{
    public function __construct()
    {
        parent::__construct(IntegrationAccess::MODULE);

        if (! $this->access->mayManageKeys($this->employeeId())) {
            throw new RedirectException('no_access/' . IntegrationAccess::API_KEYS);
        }
    }

    public function getIndex(): string
    {
        return view('api_keys/manage', [
            'keys'     => (new ApiKeys(null, $this->access))->all(),
            'scopes'   => ApiScope::cases(),
            'branches' => $this->branchChoices(),
            'enabled'  => (new IntegrationOptions())->apiEnabled(),
            'now'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function postCreate(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $scopes = $this->request->getPost('scopes');
            $made   = (new ApiKeys(null, $this->access))->create([
                'name'       => $this->postedText('name'),
                'scopes'     => is_array($scopes) ? $scopes : [],
                'branch_id'  => $this->postedText('branch_id'),
                'rate_limit' => $this->postedText('rate_limit'),
                'expires_on' => $this->postedText('expires_on'),
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.key_created'), 'id' => $made['key']->id, 'secret' => $made['secret'], 'prefix' => $made['key']->prefix];
        });
    }

    public function postRevoke(int $keyId): ResponseInterface
    {
        return $this->attempt(function () use ($keyId): array {
            (new ApiKeys(null, $this->access))->revoke($keyId, $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.key_revoked'), 'id' => $keyId];
        });
    }

    /**
     * @return array<int, string>
     */
    private function branchChoices(): array
    {
        $choices = [];

        foreach ((new BranchGuard(null, $this->employee))->selectableBranches($this->employeeId()) as $branch) {
            $choices[(int) $branch->branch_id] = $branch->code . ' - ' . $branch->name;
        }

        return $choices;
    }
}
