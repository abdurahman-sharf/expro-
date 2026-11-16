<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingActivation;
use App\Libraries\Accounting\ActivationScreen;
use App\Libraries\Setup\FirstRun;
use App\Libraries\Setup\FirstRunException;
use App\Libraries\Setup\InstallState;
use App\Libraries\Setup\Regions;
use App\Models\Employee;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class Setup extends BaseController
{
    private const DEFAULT_LANGUAGE = 'ar-EG:arabic';
    private const DEFAULT_COUNTRY  = 'EG';
    private const FIELDS           = [
        FirstRun::LANGUAGE => ['language', 'country', 'timezone', 'currency_code', 'currency_symbol', 'currency_decimals'],
        FirstRun::COMPANY  => ['company', 'address', 'phone', 'email', 'tax_id'],
        FirstRun::BRANCH   => ['branch_name', 'location_name'],
        FirstRun::TAX      => ['tax_name', 'tax_rate', 'tax_included'],
    ];

    public function getIndex(): RedirectResponse|ResponseInterface|string
    {
        helper('locale');

        if (! (new InstallState())->needsSetup()) {
            return redirect()->to('home');
        }

        $isOwner = $this->personId() === InstallState::OWNER_ID;
        $run     = new FirstRun();

        if (! $isOwner) {
            return view('setup/wizard', ['waiting' => true]);
        }

        $progress  = $run->progress();
        $current   = $run->currentStep();
        $requested = $this->request->getGet('step');
        $step      = is_string($requested) && in_array($requested, [...FirstRun::STEPS, FirstRun::FINISH], true) && (($progress[$requested] ?? false) || $requested === $current || $current === FirstRun::FINISH) ? $requested : $current;
        $values    = $progress[FirstRun::LANGUAGE] ? $run->values() : $this->firstVisitDefaults($run->values());
        $language  = explode(':', $values['language'])[0] ?: 'en';

        return view('setup/wizard', [
            'waiting'    => false,
            'step'       => $step,
            'steps'      => FirstRun::STEPS,
            'progress'   => $progress,
            'values'     => $values,
            'languages'  => get_languages(),
            'regions'    => Regions::all($language),
            'timezones'  => Regions::timezones(),
            'problems'   => $step === FirstRun::FINISH ? $run->problems() : [],
            'activation' => $step === FirstRun::ACCOUNTING ? (new ActivationScreen(new AccountingActivation()))->data(null) : null,
        ]);
    }

    public function postLanguage(): ResponseInterface
    {
        return $this->saveStep(FirstRun::LANGUAGE, static fn (FirstRun $run, array $input, int $employeeId) => $run->saveLanguage($input, $employeeId));
    }

    public function postCompany(): ResponseInterface
    {
        return $this->saveStep(FirstRun::COMPANY, static fn (FirstRun $run, array $input, int $employeeId) => $run->saveCompany($input, $employeeId));
    }

    public function postBranch(): ResponseInterface
    {
        return $this->saveStep(FirstRun::BRANCH, static fn (FirstRun $run, array $input, int $employeeId) => $run->saveBranch($input, $employeeId));
    }

    public function postTax(): ResponseInterface
    {
        return $this->saveStep(FirstRun::TAX, static fn (FirstRun $run, array $input, int $employeeId) => $run->saveTax($input, $employeeId));
    }

    public function postFinish(): ResponseInterface
    {
        $refusal = $this->refusal();

        if ($refusal !== null) {
            return $refusal;
        }

        try {
            (new FirstRun())->finish($this->personId());
        } catch (FirstRunException $failure) {
            return $this->response->setJSON(['success' => false, 'message' => $this->messageOf($failure)]);
        }

        session()->remove(Employee::SETUP_PENDING_KEY);

        return $this->response->setJSON(['success' => true, 'message' => lang('Setup.finished'), 'next' => site_url('home')]);
    }

    /**
     * @param callable(FirstRun, array<string, string>, int): void $action
     */
    private function saveStep(string $step, callable $action): ResponseInterface
    {
        $refusal = $this->refusal();

        if ($refusal !== null) {
            return $refusal;
        }

        $input = [];

        foreach (self::FIELDS[$step] as $field) {
            $value = $this->request->getPost($field);

            if ($value !== null && ! is_string($value)) {
                return $this->response->setStatusCode(400)->setJSON(['success' => false, 'message' => lang('Setup.malformed_request')]);
            }

            $input[$field] = (string) $value;
        }

        $run = new FirstRun();

        try {
            $action($run, $input, $this->personId());
        } catch (FirstRunException $failure) {
            return $this->response->setJSON(['success' => false, 'message' => $this->messageOf($failure)]);
        }

        return $this->response->setJSON(['success' => true, 'message' => lang('Setup.saved'), 'next' => $run->currentStep()]);
    }

    private function refusal(): ?ResponseInterface
    {
        if (! (new InstallState())->needsSetup()) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($this->personId() !== InstallState::OWNER_ID) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Setup.owner_only')]);
        }

        return null;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function firstVisitDefaults(array $values): array
    {
        $egypt = Regions::find(self::DEFAULT_COUNTRY);

        return [
            'language'          => self::DEFAULT_LANGUAGE,
            'country'           => self::DEFAULT_COUNTRY,
            'timezone'          => $egypt['timezone'],
            'currency_code'     => $egypt['currency'],
            'currency_symbol'   => $egypt['symbol'],
            'currency_decimals' => (string) $egypt['decimals'],
        ] + $values;
    }

    private function personId(): int
    {
        return (int) session('person_id');
    }

    private function messageOf(FirstRunException $failure): string
    {
        if ($failure->key === 'incomplete') {
            $problems = array_map(static fn (string $problem): string => lang('Setup.problem_' . $problem), (array) ($failure->context['problems'] ?? []));

            return lang('Setup.error_incomplete') . ' ' . implode(' ', $problems);
        }

        return lang('Setup.error_' . $failure->key, array_map('strval', array_values($failure->context)));
    }
}
