<?php

namespace App\Policies;

use App\Models\AccomplishmentReport;
use App\Models\User;

class AccomplishmentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AccomplishmentReport $report): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AccomplishmentReport $report): bool
    {
        return $report->user_id === $user->id;
    }

    public function duplicate(User $user, AccomplishmentReport $report): bool
    {
        return $report->user_id === $user->id;
    }

    public function delete(User $user, AccomplishmentReport $report): bool
    {
        return $report->user_id === $user->id;
    }

    public function generate(User $user, AccomplishmentReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function generatePdf(User $user, AccomplishmentReport $report): bool
    {
        return $this->generate($user, $report) || $user->hasPermission('generate_all_report_pdfs');
    }

    public function importDtr(User $user, AccomplishmentReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function useAi(User $user, AccomplishmentReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function viewComputation(User $user, AccomplishmentReport $report): bool
    {
        return $this->view($user, $report) && ($user->hasPermission('view_ot_computation') || $user->hasPermission('edit_ot_computation'));
    }

    public function editComputation(User $user, AccomplishmentReport $report): bool
    {
        return $this->view($user, $report) && $user->hasPermission('edit_ot_computation');
    }
}
