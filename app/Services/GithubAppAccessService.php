<?php

namespace App\Services;

use App\Models\GithubApp;
use App\Models\TestSuiteIntegration;
use App\Models\User;

/**
 * GitHub App dispatch access lists (Admin → GitHub Apps): when an app lists
 * specific users, only those users (and admins) may add or edit
 * github_action integrations that dispatch as it. An empty list keeps the
 * previous everyone-with-edit-rights behavior.
 *
 * Removing a user from a list force-disables the github_action integrations
 * their removal affects — in the suites they can edit, their own and
 * pre-feature (creator-unknown) integrations of that app — with an
 * explanatory note on the suite page, the same pattern as deleting a
 * GitHub App. Integrations a still-allowed member created are spared.
 */
class GithubAppAccessService
{
    /**
     * Why the user may not use the app behind the given github_app_id for a
     * github_action integration — null when allowed (or nothing to check:
     * github_action-only, and a null id resolves to the first dispatch app).
     */
    public function denialMessage(?User $user, ?int $githubAppId, string $type): ?string
    {
        if ($type !== 'github_action') {
            return null;
        }

        $app = $this->effectiveApp($githubAppId);

        if ($app === null || $app->allowsDispatchBy($user)) {
            return null;
        }

        return "You are not on the access list for the GitHub App \"{$app->name}\". Ask an administrator to add you under Admin → GitHub Apps.";
    }

    /**
     * Replace the app's access list. Removed users lose their suites'
     * github_action integrations of this app — unless a still-allowed user
     * created them (those keep running untouched).
     *
     * @param  array<int, int|string>  $userIds
     */
    public function syncAllowedUsers(GithubApp $app, array $userIds): void
    {
        $newUserIds = array_values(array_unique(array_map('intval', $userIds)));

        $currentIds = $app->allowedUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $app->allowedUsers()->sync($newUserIds);

        $removedIds = array_values(array_diff($currentIds, $newUserIds));

        foreach ($removedIds as $removedId) {
            $this->disableIntegrationsFor($app, $removedId, $newUserIds);
        }
    }

    /**
     * The app a github_action integration will actually dispatch as: its own
     * app, or the first dispatch-capable app as fallback (mirrors
     * GithubAppAuthenticator::resolveApp).
     */
    private function effectiveApp(?int $githubAppId): ?GithubApp
    {
        return GithubApp::find($githubAppId) ?? GithubApp::dispatchApps()->first();
    }

    /**
     * Force-disable the integrations the removed user's removal affects:
     * enabled github_action integrations of this app in suites where the
     * user has edit permission, sparing ones a still-allowed user created.
     *
     * @param  array<int, int>  $newUserIds
     */
    private function disableIntegrationsFor(GithubApp $app, int $removedUserId, array $newUserIds): void
    {
        $removed = User::find($removedUserId);

        if ($removed === null) {
            return;
        }

        // Integrations with no app of their own dispatch as the FIRST
        // dispatch-capable app — those count as this app's too.
        $isDefaultApp = GithubApp::dispatchApps()->first()?->id === $app->id;

        $suiteIds = $removed->testSuites()
            ->wherePivot('can_edit', true)
            ->pluck('test_suites.id');

        if ($suiteIds->isEmpty()) {
            return;
        }

        // Users whose integrations survive the sweep: still-allowed users
        // (the synced list) and admins, who bypass the list entirely.
        $sparedCreatorIds = User::whereIn('id', $newUserIds)->orWhere('is_admin', true)->pluck('id')->all();

        TestSuiteIntegration::query()
            ->where('type', 'github_action')
            ->where('enabled', true)
            ->whereIn('test_suite_id', $suiteIds)
            ->where(function ($query) use ($app, $isDefaultApp) {
                $query->where('github_app_id', $app->id);

                if ($isDefaultApp) {
                    $query->orWhereNull('github_app_id');
                }
            })
            ->where(function ($query) use ($removedUserId, $sparedCreatorIds) {
                // Sparing condition: created by someone else who is still
                // allowed (admins included). Integrations created by the
                // removed user or before the feature existed are hit.
                $query->whereNull('created_by')->orWhere('created_by', $removedUserId);

                if ($sparedCreatorIds !== []) {
                    $query->orWhereNotIn('created_by', $sparedCreatorIds);
                }
            })
            ->update([
                'enabled' => false,
                'disabled_note' => "The GitHub App \"{$app->name}\" access list changed — \"{$removed->name}\" no longer has access to this integration. Re-enable it as a user who still has access.",
            ]);
    }
}
