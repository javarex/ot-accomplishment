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

    public function delete(User $user, AccomplishmentReport $report): bool
    {
        return $report->user_id === $user->id;
    }

    public function generate(User $user, AccomplishmentReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function importDtr(User $user, AccomplishmentReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function useAi(User $user, AccomplishmentReport $report): bool
    {
        return $this->update($user, $report);
    }
}
