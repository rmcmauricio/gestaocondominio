<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Condominium;
use App\Models\CondominiumFeePeriod;
use App\Models\CondominiumSetupWizardProgress;
use App\Models\Fee;
use App\Models\Fraction;
use App\Models\FractionAccount;

class SetupWizardController extends Controller
{
    protected $condominiumModel;
    protected $wizardProgressModel;

    public function __construct()
    {
        parent::__construct();
        $this->condominiumModel = new Condominium();
        $this->wizardProgressModel = new CondominiumSetupWizardProgress();
    }

    /**
     * Show wizard for condominium at current step
     */
    public function index(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);
        RoleMiddleware::requireAdminInCondominium($id);

        $condominium = $this->condominiumModel->findById($id);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        $currentStep = $this->wizardProgressModel->getCurrentStep($id);
        $wizardComplete = $currentStep >= CondominiumSetupWizardProgress::STEP_DONE;

        $displayStep = $currentStep;
        if (isset($_GET['step']) && is_numeric($_GET['step'])) {
            $req = (int)$_GET['step'];
            if ($req >= 1 && $req <= CondominiumSetupWizardProgress::MAX_STEP) {
                $displayStep = $req;
            }
        }
        if ($wizardComplete) {
            $displayStep = isset($_GET['step']) && is_numeric($_GET['step'])
                ? max(1, min((int)$_GET['step'], CondominiumSetupWizardProgress::MAX_STEP))
                : CondominiumSetupWizardProgress::STEP_DONE;
        }

        $this->loadPageTranslations('setup-wizard');
        $this->loadStepData($id, $displayStep);
        $this->data['wizard_condominium_id'] = $id;
        $this->data['wizard_current_step'] = $displayStep;
        $stepHasContent = $this->getStepHasContent($id);
        $this->data['step_has_content'] = $stepHasContent;
        // Reconhecer passos criados manualmente: permitir navegar até ao último passo que tem conteúdo (1..7) ou até currentStep
        $maxStepWithContent = $currentStep;
        for ($s = 1; $s < CondominiumSetupWizardProgress::STEP_DONE; $s++) {
            if (!empty($stepHasContent[$s])) {
                $maxStepWithContent = max($maxStepWithContent, $s);
            }
        }
        $this->data['wizard_max_reached_step'] = $wizardComplete
            ? CondominiumSetupWizardProgress::STEP_DONE
            : max($currentStep, $maxStepWithContent);
        $this->data['wizard_max_step'] = CondominiumSetupWizardProgress::MAX_STEP;
        $this->data['viewName'] = 'pages/setup-wizard/index.html.twig';
        $this->data['page'] = ['titulo' => 'Configuração do Condomínio'];
        $this->data['condominium'] = $condominium;
        $this->data['csrf_token'] = Security::generateCSRFToken();
        $this->data['error'] = $_SESSION['error'] ?? null;
        $this->data['success'] = $_SESSION['success'] ?? null;
        $this->data['wizard_warning'] = $_SESSION['wizard_warning'] ?? $this->getDependencyWarningForStep($id, $displayStep);
        $oldInputKey = 'wizard_old_input_' . $displayStep;
        $errorFieldKey = 'wizard_error_field_' . $displayStep;
        $this->data['old_input'] = $_SESSION[$oldInputKey] ?? null;
        $this->data['error_field'] = $_SESSION[$errorFieldKey] ?? null;
        unset($_SESSION['error'], $_SESSION['success'], $_SESSION['wizard_warning'], $_SESSION[$oldInputKey], $_SESSION[$errorFieldKey]);
        $this->renderMainTemplate();
    }

    /**
     * Process step submit (POST) and advance or stay on error
     */
    public function processStep(int $id, int $step)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);
        RoleMiddleware::requireAdminInCondominium($id);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard');
            exit;
        }

        $condominium = $this->condominiumModel->findById($id);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        $currentStep = $this->wizardProgressModel->getCurrentStep($id);
        if ($step > $currentStep) {
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard');
            exit;
        }

        $action = $_POST['wizard_action'] ?? '';
        $skip = $action === 'skip' || !empty($_POST['wizard_skip']);
        $addMore = $action === 'add' || (!$skip && !empty($_POST['wizard_add']));
        $goNext = $action === 'next' || (!$skip && !$addMore && !empty($_POST['wizard_next']));

        if ($skip) {
            $nextStep = min($currentStep + 1, CondominiumSetupWizardProgress::STEP_DONE);
            $this->wizardProgressModel->advanceToStep($id, $nextStep, $this->getCompletedSteps($id), array_merge(
                $this->getSkippedSteps($id),
                [$step]
            ));
            if ($nextStep >= CondominiumSetupWizardProgress::STEP_DONE) {
                $this->wizardProgressModel->markDone($id);
                $_SESSION['success'] = 'Configuração concluída. Pode completar o resto mais tarde nas respetivas secções.';
                header('Location: ' . BASE_URL . 'condominiums/' . $id);
                exit;
            }
            $this->setDependencyWarningIfNeeded($id, $nextStep);
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard');
            exit;
        }

        if ($addMore) {
            $handled = $this->handleStepSubmit($id, $step);
            if ($handled === true) {
                $url = BASE_URL . 'condominiums/' . $id . '/setup-wizard?step=' . $step;
                header('Location: ' . $url);
                exit;
            }
            if ($handled === false && isset($_SESSION['error'])) {
                header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard?step=' . $step);
                exit;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard?step=' . $step);
            exit;
        }

        if ($goNext) {
            $nextStep = min($step + 1, CondominiumSetupWizardProgress::STEP_DONE);
            $this->wizardProgressModel->advanceToStep($id, $nextStep, array_merge($this->getCompletedSteps($id), [$step]), $this->getSkippedSteps($id));
            if ($nextStep >= CondominiumSetupWizardProgress::STEP_DONE) {
                $this->wizardProgressModel->markDone($id);
                $_SESSION['success'] = 'Configuração do condomínio concluída!';
                header('Location: ' . BASE_URL . 'condominiums/' . $id);
                exit;
            }
            $this->setDependencyWarningIfNeeded($id, $nextStep);
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard');
            exit;
        }

        $handled = $this->handleStepSubmit($id, $step);
        if ($handled === true) {
            // Sempre avançar para o passo seguinte ao atual (independentemente de estar “concluído” ou não)
            $nextStep = min($step + 1, CondominiumSetupWizardProgress::STEP_DONE);
            $this->wizardProgressModel->advanceToStep($id, $nextStep, array_merge($this->getCompletedSteps($id), [$step]), $this->getSkippedSteps($id));
            if ($nextStep >= CondominiumSetupWizardProgress::STEP_DONE) {
                $this->wizardProgressModel->markDone($id);
                $_SESSION['success'] = 'Configuração do condomínio concluída!';
                header('Location: ' . BASE_URL . 'condominiums/' . $id);
                exit;
            }
            $this->setDependencyWarningIfNeeded($id, $nextStep);
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard?step=' . $nextStep);
            exit;
        }

        if ($handled === false && isset($_SESSION['error'])) {
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard?step=' . $step);
            exit;
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $id . '/setup-wizard?step=' . $step);
        exit;
    }

    /**
     * Set session warning when advancing to a step that has unmet dependencies (e.g. step 3 needs accounts, step 5 needs fractions)
     */
    protected function setDependencyWarningIfNeeded(int $condominiumId, int $nextStep): void
    {
        if ($nextStep === CondominiumSetupWizardProgress::STEP_ENTRY_DEBTS) {
            $bankAccountModel = new BankAccount();
            $accounts = $bankAccountModel->getByCondominium($condominiumId);
            if (count($accounts) === 0) {
                $_SESSION['wizard_warning'] = 'Para registar movimentos e saldos históricos, convém ter pelo menos uma conta bancária configurada. Pode configurar mais tarde na secção Contas.';
            }
        }
        if ($nextStep === CondominiumSetupWizardProgress::STEP_QUOTAS) {
            $fractionModel = new Fraction();
            $fractions = $fractionModel->getByCondominiumId($condominiumId);
            if (count($fractions) === 0) {
                $_SESSION['wizard_warning'] = 'Para gerar quotas, precisa de ter frações e orçamento configurados. Pode configurar na secção Frações e Finanças → Orçamentos.';
            }
        }
    }

    /**
     * Get dependency warning message for the current step (when loading the step, not only when advancing)
     */
    protected function getDependencyWarningForStep(int $condominiumId, int $step): ?string
    {
        if ($step === CondominiumSetupWizardProgress::STEP_ENTRY_DEBTS) {
            $bankAccountModel = new BankAccount();
            $accounts = $bankAccountModel->getByCondominium($condominiumId);
            if (count($accounts) === 0) {
                return 'Para registar movimentos e saldos históricos, convém ter pelo menos uma conta bancária configurada. Pode configurar mais tarde na secção Contas.';
            }
        }
        if ($step === CondominiumSetupWizardProgress::STEP_QUOTAS) {
            $fractionModel = new Fraction();
            $fractions = $fractionModel->getByCondominiumId($condominiumId);
            if (count($fractions) === 0) {
                return 'Para gerar quotas, precisa de ter frações e orçamento configurados. Pode configurar na secção Frações e Finanças → Orçamentos.';
            }
        }
        return null;
    }

    protected function getHistoricalDebtsForCondominium(int $condominiumId): array
    {
        global $db;
        if (!$db) {
            return [];
        }
        try {
            $stmt = $db->prepare("
                SELECT f.*, fr.identifier as fraction_identifier
                FROM fees f
                INNER JOIN fractions fr ON fr.id = f.fraction_id
                WHERE f.condominium_id = :condominium_id AND COALESCE(f.is_historical, 0) = 1
                ORDER BY f.period_year DESC, f.period_month DESC, fr.identifier ASC
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $rows = $stmt->fetchAll() ?: [];
            foreach ($rows as &$d) {
                $d['period_display'] = Fee::formatPeriodForDisplay($d);
            }
            unset($d);
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function getHistoricalCreditsForCondominium(int $condominiumId): array
    {
        global $db;
        if (!$db) {
            return [];
        }
        try {
            $stmt = $db->prepare("
                SELECT fam.*, fa.fraction_id, fr.identifier as fraction_identifier
                FROM fraction_account_movements fam
                INNER JOIN fraction_accounts fa ON fa.id = fam.fraction_account_id
                INNER JOIN fractions fr ON fr.id = fa.fraction_id
                WHERE fa.condominium_id = :condominium_id
                AND fam.type = 'credit' AND fam.source_type = 'historical_credit'
                ORDER BY fam.created_at DESC, fr.identifier ASC
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            return $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Returns for each step (1..8) whether that step has actual content (so stepper can show green only when filled).
     */
    protected function getStepHasContent(int $condominiumId): array
    {
        $out = array_fill(1, CondominiumSetupWizardProgress::MAX_STEP, false);
        global $db;
        if (!$db) {
            return $out;
        }
        try {
            $bankAccountModel = new BankAccount();
            $out[CondominiumSetupWizardProgress::STEP_BANK_ACCOUNTS] = count($bankAccountModel->getByCondominium($condominiumId)) > 0;

            $fractionModel = new Fraction();
            $out[CondominiumSetupWizardProgress::STEP_FRACTIONS] = count($fractionModel->getByCondominiumId($condominiumId)) > 0;

            $condo = $this->condominiumModel->findById($condominiumId);
            $hasEntryDate = !empty($condo['entry_date']);
            $debts = $this->getHistoricalDebtsForCondominium($condominiumId);
            $credits = $this->getHistoricalCreditsForCondominium($condominiumId);
            $out[CondominiumSetupWizardProgress::STEP_ENTRY_DEBTS] = $hasEntryDate || count($debts) > 0 || count($credits) > 0;

            $budgetModel = new Budget();
            $out[CondominiumSetupWizardProgress::STEP_BUDGETS] = count($budgetModel->getByCondominium($condominiumId)) > 0;

            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM fees WHERE condominium_id = :cid AND COALESCE(is_historical, 0) = 0");
            $stmt->execute([':cid' => $condominiumId]);
            $row = $stmt->fetch();
            $out[CondominiumSetupWizardProgress::STEP_QUOTAS] = ($row && (int)($row['cnt'] ?? 0) > 0);

            $supplierModel = new \App\Models\Supplier();
            $out[CondominiumSetupWizardProgress::STEP_SUPPLIERS] = count($supplierModel->getByCondominium($condominiumId)) > 0;

            $spaceModel = new \App\Models\Space();
            $out[CondominiumSetupWizardProgress::STEP_SPACES] = count($spaceModel->getAllByCondominium($condominiumId)) > 0;

            $out[CondominiumSetupWizardProgress::STEP_DONE] = true;
        } catch (\Throwable $e) {
            // keep defaults
        }
        return $out;
    }

    /**
     * Store form data and error field in session so the wizard can repopulate and highlight on redirect
     */
    protected function setWizardStepError(int $step, array $oldInput, ?string $errorField = null): void
    {
        $_SESSION['wizard_old_input_' . $step] = $oldInput;
        if ($errorField !== null) {
            $_SESSION['wizard_error_field_' . $step] = $errorField;
        }
    }

    protected function getCompletedSteps(int $condominiumId): array
    {
        $row = $this->wizardProgressModel->getByCondominium($condominiumId);
        if (!$row || empty($row['completed_steps'])) {
            return [];
        }
        $decoded = json_decode($row['completed_steps'], true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function getSkippedSteps(int $condominiumId): array
    {
        $row = $this->wizardProgressModel->getByCondominium($condominiumId);
        if (!$row || empty($row['skipped_steps'])) {
            return [];
        }
        $decoded = json_decode($row['skipped_steps'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Load data needed for the current step view
     */
    protected function loadStepData(int $condominiumId, int $step): void
    {
        switch ($step) {
            case CondominiumSetupWizardProgress::STEP_BANK_ACCOUNTS:
                $bankAccountModel = new BankAccount();
                $this->data['bank_accounts'] = $bankAccountModel->getByCondominium($condominiumId);
                $this->data['can_edit_initial_balance'] = true;
                break;
            case CondominiumSetupWizardProgress::STEP_FRACTIONS:
                $fractionModel = new Fraction();
                $this->data['fractions'] = $fractionModel->getByCondominiumId($condominiumId);
                break;
            case CondominiumSetupWizardProgress::STEP_ENTRY_DEBTS:
                $fractionModel = new Fraction();
                $this->data['fractions'] = $fractionModel->getByCondominiumId($condominiumId);
                $this->data['current_year'] = (int)date('Y');
                $this->data['debt_due_date_default'] = date('Y-m-d');
                $this->data['historical_debts'] = $this->getHistoricalDebtsForCondominium($condominiumId);
                $this->data['historical_credits'] = $this->getHistoricalCreditsForCondominium($condominiumId);
                break;
            case CondominiumSetupWizardProgress::STEP_BUDGETS:
                $budgetModel = new Budget();
                $budgetItemModel = new BudgetItem();
                $budgets = $budgetModel->getByCondominium($condominiumId);
                foreach ($budgets as &$b) {
                    $b['items'] = $budgetItemModel->getByBudget((int)$b['id']);
                }
                unset($b);
                $this->data['budgets'] = $budgets;
                $this->data['current_year'] = (int)date('Y');
                $this->data['available_years'] = range($this->data['current_year'] - 2, $this->data['current_year'] + 1);
                $this->data['draft_year'] = $_SESSION['wizard_step4_draft_year'] ?? $this->data['current_year'];
                $this->data['draft_revenues'] = $_SESSION['wizard_step4_draft_revenues'] ?? [];
                $this->data['draft_expenses'] = $_SESSION['wizard_step4_draft_expenses'] ?? [];
                $this->data['revenue_categories'] = ['Quotas', 'Multas', 'Juros', 'Outras Receitas'];
                $this->data['expense_categories'] = ['Limpeza', 'Manutenção', 'Segurança', 'Energia', 'Água', 'Seguros', 'Administração', 'Outras Despesas'];
                break;
            case CondominiumSetupWizardProgress::STEP_QUOTAS:
                $this->data['current_year'] = (int)date('Y');
                $fractionModel = new Fraction();
                $this->data['fractions'] = $fractionModel->getByCondominiumId($condominiumId);
                $this->loadFeesMapDataForWizard($condominiumId);
                break;
            case CondominiumSetupWizardProgress::STEP_SUPPLIERS:
                $supplierModel = new \App\Models\Supplier();
                $this->data['suppliers'] = $supplierModel->getByCondominium($condominiumId);
                break;
            case CondominiumSetupWizardProgress::STEP_SPACES:
                $spaceModel = new \App\Models\Space();
                $this->data['spaces'] = $spaceModel->getAllByCondominium($condominiumId);
                break;
            default:
                break;
        }
    }

    /**
     * Load fees map data for step 5 (Quotas) when there are fees. Sets fees_map, fees_map_fractions,
     * fee_period_labels, selected_fees_year, available_years, month_names, wizard_has_fees_map.
     */
    protected function loadFeesMapDataForWizard(int $condominiumId): void
    {
        $feeModel = new Fee();
        $fractionModel = new Fraction();
        // Apenas anos com quotas regulares (criadas); não incluir anos só com dívidas/créditos históricos
        $availableYears = $feeModel->getAvailableYearsWithRegularFees($condominiumId);
        $currentYear = (int)date('Y');
        if (empty($availableYears)) {
            $this->data['wizard_has_fees_map'] = false;
            $this->data['fees_map'] = [];
            $this->data['fees_map_fractions'] = $this->data['fractions'] ?? [];
            $this->data['available_years'] = [];
            $this->data['selected_fees_year'] = $currentYear;
            $this->data['fee_period_labels'] = $this->buildFeePeriodLabelsForWizard('monthly', $this->getMonthNames());
            $this->data['month_names'] = $this->getMonthNames();
            return;
        }
        $this->data['wizard_has_fees_map'] = true;
        $this->data['available_years'] = $availableYears;
        $selectedFeesYear = !empty($_GET['fees_year']) ? (int)$_GET['fees_year'] : (in_array($currentYear, $availableYears) ? $currentYear : $availableYears[0]);
        if (!in_array($selectedFeesYear, $availableYears)) {
            $selectedFeesYear = in_array($currentYear, $availableYears) ? $currentYear : $availableYears[0];
        }
        $this->data['selected_fees_year'] = $selectedFeesYear;

        $condoFeePeriod = new CondominiumFeePeriod();
        $feePeriodType = $condoFeePeriod->get($condominiumId, $selectedFeesYear);
        if ($feePeriodType === null) {
            $feePeriodType = $this->inferFeePeriodTypeForWizard($condominiumId, $selectedFeesYear);
        }
        $monthNames = $this->getMonthNames();
        $this->data['fee_period_labels'] = $this->buildFeePeriodLabelsForWizard($feePeriodType, $monthNames);
        $this->data['month_names'] = $monthNames;

        $feesMap = $feeModel->getFeesMapByYear($condominiumId, $selectedFeesYear, false, $feePeriodType);
        $this->data['fees_map'] = $feesMap;
        $fractions = $this->data['fractions'] ?? $fractionModel->getByCondominiumId($condominiumId);
        $fracInfo = $fractionModel->getOwnerAndFloorByFractionIds(array_column($fractions, 'id'));
        foreach ($fractions as &$fr) {
            $x = $fracInfo[(int)($fr['id'] ?? 0)] ?? [];
            $fr['owner_name'] = $x['owner_name'] ?? '';
            $fr['floor'] = $fr['floor'] ?? $x['floor'] ?? '';
        }
        unset($fr);
        $feesMapFractions = $fractions;
        if (!$feeModel->hasRegularFeesInYear($condominiumId, $selectedFeesYear) && !empty($feesMap)) {
            $fractionIdsInMap = [];
            foreach ($feesMap as $slotData) {
                foreach (array_keys($slotData) as $fid) {
                    $fractionIdsInMap[$fid] = true;
                }
            }
            $feesMapFractions = array_values(array_filter($fractions, function ($f) use ($fractionIdsInMap) {
                return isset($fractionIdsInMap[(int)$f['id']]);
            }));
        }
        $this->data['fees_map_fractions'] = $feesMapFractions;
    }

    private function getMonthNames(): array
    {
        return [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];
    }

    private function inferFeePeriodTypeForWizard(int $condominiumId, int $year): string
    {
        $feeModel = new Fee();
        $count = $feeModel->getRegularFeeSlotCount($condominiumId, $year);
        if ($count === null || $count <= 0) {
            return 'monthly';
        }
        if ($count >= 12) {
            return 'monthly';
        }
        if ($count === 6) {
            return 'bimonthly';
        }
        if ($count === 4) {
            return 'quarterly';
        }
        if ($count === 2) {
            return 'semiannual';
        }
        if ($count === 1) {
            return 'annual';
        }
        return 'monthly';
    }

    private function buildFeePeriodLabelsForWizard(?string $periodType, array $monthNames): array
    {
        $periodType = $periodType ?? 'monthly';
        switch ($periodType) {
            case 'bimonthly':
                return [1 => 'Jan-Fev', 2 => 'Mar-Abr', 3 => 'Mai-Jun', 4 => 'Jul-Ago', 5 => 'Set-Out', 6 => 'Nov-Dez'];
            case 'quarterly':
                return [1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'];
            case 'semiannual':
                return [1 => '1º Sem', 2 => '2º Sem'];
            case 'annual':
            case 'yearly':
                return [1 => 'Anual'];
            default:
                return array_map(fn($m) => mb_substr($m, 0, 3), $monthNames);
        }
    }

    /**
     * Handle step form submit. Return true on success, false on error (session error set), null to just advance
     */
    protected function handleStepSubmit(int $condominiumId, int $step)
    {
        switch ($step) {
            case CondominiumSetupWizardProgress::STEP_BANK_ACCOUNTS:
                return $this->handleBankAccountsStep($condominiumId);
            case CondominiumSetupWizardProgress::STEP_FRACTIONS:
                return $this->handleFractionsStep($condominiumId);
            case CondominiumSetupWizardProgress::STEP_ENTRY_DEBTS:
                return $this->handleEntryDebtsStep($condominiumId);
            case CondominiumSetupWizardProgress::STEP_BUDGETS:
                return $this->handleBudgetsStep($condominiumId);
            case CondominiumSetupWizardProgress::STEP_QUOTAS:
                return $this->handleQuotasStep($condominiumId);
            case CondominiumSetupWizardProgress::STEP_SUPPLIERS:
                return $this->handleSuppliersStep($condominiumId);
            case CondominiumSetupWizardProgress::STEP_SPACES:
                return $this->handleSpacesStep($condominiumId);
            default:
                return true;
        }
    }

    protected function handleBankAccountsStep(int $condominiumId): bool
    {
        $step = CondominiumSetupWizardProgress::STEP_BANK_ACCOUNTS;
        $oldInput = [
            'name' => Security::sanitize($_POST['name'] ?? ''),
            'account_type' => Security::sanitize($_POST['account_type'] ?? 'bank'),
            'bank_name' => Security::sanitize($_POST['bank_name'] ?? ''),
            'account_number' => Security::sanitize($_POST['account_number'] ?? ''),
            'iban' => Security::sanitize($_POST['iban'] ?? ''),
            'swift' => Security::sanitize($_POST['swift'] ?? ''),
            'initial_balance' => $_POST['initial_balance'] ?? '0.00',
            'initial_balance_date' => $_POST['initial_balance_date'] ?? '',
        ];
        $name = $oldInput['name'];
        $accountType = $oldInput['account_type'];
        $initialBalance = (float)($oldInput['initial_balance']);
        $initialBalanceDate = !empty($oldInput['initial_balance_date']) ? $oldInput['initial_balance_date'] : null;
        if (empty($name)) {
            $_SESSION['error'] = 'O nome da conta é obrigatório.';
            $this->setWizardStepError($step, $oldInput, 'name');
            return false;
        }
        if ($accountType === 'bank') {
            $iban = $oldInput['iban'];
            $swift = $oldInput['swift'];
            if (empty($iban) || empty($swift)) {
                $_SESSION['error'] = 'Para contas bancárias, IBAN e SWIFT são obrigatórios.';
                $this->setWizardStepError($step, $oldInput, empty($iban) ? 'iban' : 'swift');
                return false;
            }
            if (!Security::validateIban($iban)) {
                $_SESSION['error'] = 'Formato de IBAN inválido.';
                $this->setWizardStepError($step, $oldInput, 'iban');
                return false;
            }
        }
        try {
            $bankAccountModel = new BankAccount();
            $bankAccountModel->create([
                'condominium_id' => $condominiumId,
                'name' => $name,
                'account_type' => $accountType,
                'bank_name' => Security::sanitize($_POST['bank_name'] ?? ''),
                'account_number' => Security::sanitize($_POST['account_number'] ?? ''),
                'iban' => $accountType === 'bank' ? Security::sanitize($_POST['iban'] ?? '') : null,
                'swift' => $accountType === 'bank' ? Security::sanitize($_POST['swift'] ?? '') : null,
                'initial_balance' => $initialBalance,
                'initial_balance_date' => $initialBalanceDate,
                'current_balance' => $initialBalance,
                'is_active' => true
            ]);
            $_SESSION['success'] = 'Conta adicionada com sucesso.';
            return true;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar conta: ' . $e->getMessage();
            $this->setWizardStepError($step, $oldInput, 'name');
            return false;
        }
    }

    protected function handleFractionsStep(int $condominiumId): bool
    {
        $step = CondominiumSetupWizardProgress::STEP_FRACTIONS;
        $oldInput = [
            'identifier' => Security::sanitize($_POST['identifier'] ?? ''),
            'permillage' => $_POST['permillage'] ?? '0',
            'floor' => Security::sanitize($_POST['floor'] ?? ''),
            'typology' => Security::sanitize($_POST['typology'] ?? ''),
            'area' => $_POST['area'] ?? '',
        ];
        $identifier = $oldInput['identifier'];
        $permillage = (float)($oldInput['permillage']);
        if (empty($identifier)) {
            $_SESSION['error'] = 'O identificador da fração é obrigatório.';
            $this->setWizardStepError($step, $oldInput, 'identifier');
            return false;
        }
        try {
            $fractionModel = new Fraction();
            $fractionModel->create([
                'condominium_id' => $condominiumId,
                'identifier' => $identifier,
                'permillage' => $permillage,
                'floor' => Security::sanitize($_POST['floor'] ?? ''),
                'typology' => Security::sanitize($_POST['typology'] ?? ''),
                'area' => !empty($_POST['area']) ? (float)$_POST['area'] : null,
                'notes' => Security::sanitize($_POST['notes'] ?? ''),
                'receives_convocation_by_email' => isset($_POST['receives_convocation_by_email']) ? 1 : 1
            ]);
            $_SESSION['success'] = 'Fração adicionada com sucesso.';
            return true;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar fração: ' . $e->getMessage();
            $this->setWizardStepError($step, $oldInput, 'identifier');
            return false;
        }
    }

    protected function handleEntryDebtsStep(int $condominiumId): bool
    {
        $submitAction = $_POST['wizard_step3_submit'] ?? '';

        if ($submitAction === 'add_debt') {
            $this->handleStep3AddDebt($condominiumId);
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=3');
            exit;
        }

        if ($submitAction === 'add_credit') {
            $this->handleStep3AddCredit($condominiumId);
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=3');
            exit;
        }

        $entryDate = !empty($_POST['entry_date']) ? $_POST['entry_date'] : null;
        if ($entryDate) {
            global $db;
            if ($db) {
                $stmt = $db->query("SHOW COLUMNS FROM condominiums LIKE 'entry_date'");
                if ($stmt && $stmt->rowCount() > 0) {
                    $this->condominiumModel->update($condominiumId, ['entry_date' => $entryDate]);
                }
            }
        }
        $_SESSION['success'] = 'Dados guardados.';
        return true;
    }

    protected function handleStep3AddDebt(int $condominiumId): void
    {
        $fractionId = (int)($_POST['fraction_id'] ?? 0);
        $year = (int)($_POST['debt_year'] ?? date('Y'));
        $month = !empty($_POST['debt_month']) ? (int)$_POST['debt_month'] : null;
        $amount = (float)($_POST['debt_amount'] ?? 0);
        $dueDate = $_POST['debt_due_date'] ?? date('Y-m-d');
        $notes = Security::sanitize($_POST['debt_notes'] ?? '');

        if ($fractionId <= 0 || $amount <= 0) {
            $_SESSION['error'] = 'Selecione a fração e indique o valor da dívida.';
            return;
        }

        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($fractionId);
        if (!$fraction || (int)$fraction['condominium_id'] !== $condominiumId) {
            $_SESSION['error'] = 'Fração inválida.';
            return;
        }

        try {
            $feeModel = new Fee();
            $feeModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => $fractionId,
                'period_type' => $month ? 'monthly' : 'yearly',
                'period_year' => $year,
                'period_month' => $month,
                'amount' => $amount,
                'base_amount' => $amount,
                'status' => 'pending',
                'due_date' => $dueDate,
                'notes' => $notes ? 'Dívida histórica: ' . $notes : 'Dívida histórica',
                'is_historical' => 1
            ]);
            $_SESSION['success'] = 'Dívida histórica registada.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar dívida: ' . $e->getMessage();
        }
    }

    protected function handleStep3AddCredit(int $condominiumId): void
    {
        $fractionId = (int)($_POST['credit_fraction_id'] ?? 0);
        $amount = (float)($_POST['credit_amount'] ?? 0);
        $notes = Security::sanitize($_POST['credit_notes'] ?? '');

        if ($fractionId <= 0 || $amount <= 0) {
            $_SESSION['error'] = 'Selecione a fração e indique o valor do crédito.';
            return;
        }

        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($fractionId);
        if (!$fraction || (int)$fraction['condominium_id'] !== $condominiumId) {
            $_SESSION['error'] = 'Fração inválida.';
            return;
        }

        try {
            $faModel = new FractionAccount();
            $account = $faModel->getOrCreate($fractionId, $condominiumId);
            $accountId = (int)$account['id'];
            $description = $notes ? 'Crédito histórico (wizard): ' . $notes : 'Crédito histórico (wizard)';
            $faModel->addCredit($accountId, $amount, 'historical_credit', null, $description);
            $_SESSION['success'] = 'Crédito histórico registado.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar crédito: ' . $e->getMessage();
        }
    }

    protected function handleBudgetsStep(int $condominiumId): bool
    {
        $submitAction = $_POST['wizard_step4_submit'] ?? '';

        if ($submitAction === 'add_revenue') {
            $this->handleStep4AddRevenue($condominiumId);
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=4');
            exit;
        }
        if ($submitAction === 'add_expense') {
            $this->handleStep4AddExpense($condominiumId);
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=4');
            exit;
        }
        if ($submitAction === 'remove_revenue') {
            $this->handleStep4RemoveRevenue();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=4');
            exit;
        }
        if ($submitAction === 'remove_expense') {
            $this->handleStep4RemoveExpense();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=4');
            exit;
        }
        if ($submitAction === 'remove_budget_item') {
            $this->handleStep4RemoveBudgetItem($condominiumId);
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=4');
            exit;
        }
        if ($submitAction === 'create_budget') {
            $this->handleStep4CreateBudget($condominiumId);
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/setup-wizard?step=5');
            exit;
        }

        $_SESSION['success'] = 'Pode criar mais orçamentos na secção Finanças.';
        return true;
    }

    protected function handleStep4AddRevenue(int $condominiumId): void
    {
        $year = (int)($_POST['budget_year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $_SESSION['error'] = 'Ano do orçamento inválido.';
            return;
        }
        $cat = Security::sanitize($_POST['revenue_category'] ?? 'Quotas');
        if ($cat === '__outras__') {
            $cat = trim(Security::sanitize($_POST['revenue_category_other'] ?? ''));
            if ($cat === '') {
                $_SESSION['error'] = 'Indique o nome da categoria de receita.';
                return;
            }
        }
        $description = Security::sanitize($_POST['revenue_description'] ?? '');
        $amount = (float)($_POST['revenue_amount'] ?? 0);
        if ($amount <= 0) {
            $_SESSION['error'] = 'Indique o valor da receita.';
            return;
        }
        $_SESSION['wizard_step4_draft_year'] = $year;
        if (!isset($_SESSION['wizard_step4_draft_revenues']) || !is_array($_SESSION['wizard_step4_draft_revenues'])) {
            $_SESSION['wizard_step4_draft_revenues'] = [];
        }
        $_SESSION['wizard_step4_draft_revenues'][] = ['category' => $cat, 'description' => $description, 'amount' => $amount];
        $_SESSION['success'] = 'Receita adicionada.';
    }

    protected function handleStep4AddExpense(int $condominiumId): void
    {
        $year = (int)($_POST['budget_year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $_SESSION['error'] = 'Ano do orçamento inválido.';
            return;
        }
        $cat = Security::sanitize($_POST['expense_category'] ?? 'Outras Despesas');
        if ($cat === '__outras__') {
            $cat = trim(Security::sanitize($_POST['expense_category_other'] ?? ''));
            if ($cat === '') {
                $_SESSION['error'] = 'Indique o nome da categoria de despesa.';
                return;
            }
        }
        $description = Security::sanitize($_POST['expense_description'] ?? '');
        $amount = (float)($_POST['expense_amount'] ?? 0);
        if ($amount <= 0) {
            $_SESSION['error'] = 'Indique o valor da despesa.';
            return;
        }
        $_SESSION['wizard_step4_draft_year'] = $year;
        if (!isset($_SESSION['wizard_step4_draft_expenses']) || !is_array($_SESSION['wizard_step4_draft_expenses'])) {
            $_SESSION['wizard_step4_draft_expenses'] = [];
        }
        $_SESSION['wizard_step4_draft_expenses'][] = ['category' => $cat, 'description' => $description, 'amount' => $amount];
        $_SESSION['success'] = 'Despesa adicionada.';
    }

    protected function handleStep4RemoveRevenue(): void
    {
        $index = isset($_POST['remove_revenue_index']) ? (int)$_POST['remove_revenue_index'] : -1;
        if (!isset($_SESSION['wizard_step4_draft_revenues']) || !is_array($_SESSION['wizard_step4_draft_revenues'])) {
            return;
        }
        if ($index >= 0 && $index < count($_SESSION['wizard_step4_draft_revenues'])) {
            array_splice($_SESSION['wizard_step4_draft_revenues'], $index, 1);
            $_SESSION['success'] = 'Receita removida.';
        }
    }

    protected function handleStep4RemoveExpense(): void
    {
        $index = isset($_POST['remove_expense_index']) ? (int)$_POST['remove_expense_index'] : -1;
        if (!isset($_SESSION['wizard_step4_draft_expenses']) || !is_array($_SESSION['wizard_step4_draft_expenses'])) {
            return;
        }
        if ($index >= 0 && $index < count($_SESSION['wizard_step4_draft_expenses'])) {
            array_splice($_SESSION['wizard_step4_draft_expenses'], $index, 1);
            $_SESSION['success'] = 'Despesa removida.';
        }
    }

    protected function handleStep4RemoveBudgetItem(int $condominiumId): void
    {
        $itemId = (int)($_POST['budget_item_id'] ?? 0);
        if ($itemId <= 0) {
            $_SESSION['error'] = 'Item inválido.';
            return;
        }
        $budgetItemModel = new BudgetItem();
        $item = $budgetItemModel->findById($itemId);
        if (!$item) {
            $_SESSION['error'] = 'Item não encontrado.';
            return;
        }
        $budgetModel = new Budget();
        $budget = $budgetModel->findById((int)$item['budget_id']);
        if (!$budget || (int)$budget['condominium_id'] !== $condominiumId) {
            $_SESSION['error'] = 'Orçamento não encontrado ou não pertence a este condomínio.';
            return;
        }
        $amount = (float)($item['amount'] ?? 0);
        $isRevenue = isset($item['category']) && strpos($item['category'], 'Receita:') === 0;
        if ($budgetItemModel->delete($itemId)) {
            if ($isRevenue && $amount > 0) {
                $newTotal = max(0, (float)($budget['total_amount'] ?? 0) - $amount);
                $budgetModel->update((int)$budget['id'], ['total_amount' => $newTotal]);
            }
            $_SESSION['success'] = $isRevenue ? 'Receita removida.' : 'Despesa removida.';
        } else {
            $_SESSION['error'] = 'Erro ao remover o item.';
        }
    }

    protected function handleStep4CreateBudget(int $condominiumId): void
    {
        $year = (int)($_POST['budget_year'] ?? $_SESSION['wizard_step4_draft_year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $_SESSION['error'] = 'Ano do orçamento inválido.';
            return;
        }
        $revenues = $_SESSION['wizard_step4_draft_revenues'] ?? [];
        $expenses = $_SESSION['wizard_step4_draft_expenses'] ?? [];
        if (!is_array($revenues)) {
            $revenues = [];
        }
        if (!is_array($expenses)) {
            $expenses = [];
        }
        $totalRevenue = 0;
        foreach ($revenues as $r) {
            $totalRevenue += (float)($r['amount'] ?? 0);
        }
        $hasAnyItem = $totalRevenue > 0;
        foreach ($expenses as $e) {
            if ((float)($e['amount'] ?? 0) > 0) {
                $hasAnyItem = true;
                break;
            }
        }
        if (!$hasAnyItem) {
            unset($_SESSION['wizard_step4_draft_year'], $_SESSION['wizard_step4_draft_revenues'], $_SESSION['wizard_step4_draft_expenses']);
            $_SESSION['success'] = 'Nenhuma receita nem despesa adicionada. Avançou para o próximo passo.';
            return;
        }

        $budgetModel = new Budget();
        $existing = $budgetModel->getByCondominiumAndYear($condominiumId, $year);
        $notes = Security::sanitize($_POST['budget_notes'] ?? '');

        global $db;
        if (!$db) {
            $_SESSION['error'] = 'Erro de ligação à base de dados.';
            return;
        }

        $budgetItemModel = new BudgetItem();
        $sortOrder = 0;
        if ($existing) {
            $existingItems = $budgetItemModel->getByBudget((int)$existing['id']);
            $sortOrder = count($existingItems);
        }

        try {
            $db->beginTransaction();
            if ($existing) {
                $budgetId = (int)$existing['id'];
                $newTotal = (float)($existing['total_amount'] ?? 0) + $totalRevenue;
                $budgetModel->update($budgetId, ['total_amount' => $newTotal]);
            } else {
                $budgetId = $budgetModel->create([
                    'condominium_id' => $condominiumId,
                    'year' => $year,
                    'total_amount' => $totalRevenue,
                    'status' => 'draft',
                    'notes' => $notes
                ]);
            }
            foreach ($revenues as $r) {
                $amt = (float)($r['amount'] ?? 0);
                if ($amt <= 0) {
                    continue;
                }
                $budgetItemModel->create([
                    'budget_id' => $budgetId,
                    'category' => 'Receita: ' . Security::sanitize($r['category'] ?? 'Outras'),
                    'description' => Security::sanitize($r['description'] ?? ''),
                    'amount' => $amt,
                    'sort_order' => $sortOrder++
                ]);
            }
            foreach ($expenses as $e) {
                $amt = (float)($e['amount'] ?? 0);
                if ($amt <= 0) {
                    continue;
                }
                $budgetItemModel->create([
                    'budget_id' => $budgetId,
                    'category' => 'Despesa: ' . Security::sanitize($e['category'] ?? 'Outras'),
                    'description' => Security::sanitize($e['description'] ?? ''),
                    'amount' => $amt,
                    'sort_order' => $sortOrder++
                ]);
            }
            $db->commit();
            unset($_SESSION['wizard_step4_draft_year'], $_SESSION['wizard_step4_draft_revenues'], $_SESSION['wizard_step4_draft_expenses']);
            $_SESSION['success'] = $existing
                ? 'Receitas e despesas adicionadas ao orçamento de ' . $year . '.'
                : 'Orçamento de ' . $year . ' criado. Pode editar em Finanças → Orçamentos.';
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Erro ao guardar orçamento: ' . $e->getMessage();
        }
    }

    protected function handleQuotasStep(int $condominiumId): bool
    {
        $_SESSION['success'] = 'Pode gerar quotas na secção Finanças.';
        return true;
    }

    protected function handleSuppliersStep(int $condominiumId): bool
    {
        $step = CondominiumSetupWizardProgress::STEP_SUPPLIERS;
        $oldInput = [
            'name' => Security::sanitize($_POST['name'] ?? ''),
            'nif' => Security::sanitize($_POST['nif'] ?? ''),
            'email' => $_POST['email'] ?? '',
            'phone' => Security::sanitize($_POST['phone'] ?? ''),
            'address' => Security::sanitize($_POST['address'] ?? ''),
            'notes' => Security::sanitize($_POST['notes'] ?? ''),
        ];
        $name = $oldInput['name'];
        if (empty($name)) {
            $_SESSION['error'] = 'O nome do fornecedor é obrigatório.';
            $this->setWizardStepError($step, $oldInput, 'name');
            return false;
        }
        try {
            $supplierModel = new \App\Models\Supplier();
            $supplierModel->create([
                'condominium_id' => $condominiumId,
                'name' => $name,
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);
            $_SESSION['success'] = 'Fornecedor adicionado com sucesso.';
            return true;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar fornecedor: ' . $e->getMessage();
            $this->setWizardStepError($step, $oldInput, 'name');
            return false;
        }
    }

    protected function handleSpacesStep(int $condominiumId): bool
    {
        $step = CondominiumSetupWizardProgress::STEP_SPACES;
        $oldInput = [
            'name' => Security::sanitize($_POST['name'] ?? ''),
            'description' => Security::sanitize($_POST['description'] ?? ''),
            'capacity' => $_POST['capacity'] ?? '',
            'type' => Security::sanitize($_POST['type'] ?? ''),
        ];
        $name = $oldInput['name'];
        if (empty($name)) {
            $_SESSION['error'] = 'O nome do espaço é obrigatório.';
            $this->setWizardStepError($step, $oldInput, 'name');
            return false;
        }
        try {
            $spaceModel = new \App\Models\Space();
            $spaceModel->create([
                'condominium_id' => $condominiumId,
                'name' => $name,
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? ''),
                'capacity' => !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null,
                'price_per_hour' => !empty($_POST['price_per_hour']) ? (float)$_POST['price_per_hour'] : 0,
                'price_per_day' => !empty($_POST['price_per_day']) ? (float)$_POST['price_per_day'] : 0,
                'deposit_required' => !empty($_POST['deposit_required']) ? (float)$_POST['deposit_required'] : 0,
                'requires_approval' => isset($_POST['requires_approval']) ? 1 : 0,
                'rules' => Security::sanitize($_POST['rules'] ?? ''),
                'available_hours' => null
            ]);
            $_SESSION['success'] = 'Espaço adicionado com sucesso.';
            return true;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar espaço: ' . $e->getMessage();
            $this->setWizardStepError($step, $oldInput, 'name');
            return false;
        }
    }
}
