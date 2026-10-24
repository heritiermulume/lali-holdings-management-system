<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Kams\Models\KamsCandidate;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsMember;
use Closure;
use Illuminate\Database\Eloquent\Builder;

class ProfileContact
{
    public static function emailRule(
        ?int $ignoreUserId = null,
        ?int $ignoreMemberId = null,
        ?int $ignoreCandidateId = null,
        ?int $ignoreClientId = null,
    ): Closure {
        $except = self::except($ignoreUserId, $ignoreMemberId, $ignoreCandidateId, $ignoreClientId);

        return function (string $attribute, mixed $value, Closure $fail) use ($except): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (self::emailTaken($value, $except)) {
                $fail(__('This email address is already linked to another profile.'));
            }
        };
    }

    public static function phoneRule(
        ?int $ignoreUserId = null,
        ?int $ignoreMemberId = null,
        ?int $ignoreCandidateId = null,
        ?int $ignoreClientId = null,
    ): Closure {
        $except = self::except($ignoreUserId, $ignoreMemberId, $ignoreCandidateId, $ignoreClientId);

        return function (string $attribute, mixed $value, Closure $fail) use ($except): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (self::phoneTaken($value, $except)) {
                $fail(__('This phone number is already linked to another profile.'));
            }
        };
    }

    /**
     * @param  array{users: list<int>, members: list<int>, candidates: list<int>, clients: list<int>}  $except
     */
    public static function emailTaken(string $email, array $except): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        return self::matches($except, 'email', fn (Builder $query) => $query->whereRaw('LOWER(email) = ?', [$email]));
    }

    /**
     * @param  array{users: list<int>, members: list<int>, candidates: list<int>, clients: list<int>}  $except
     */
    public static function phoneTaken(string $phone, array $except): bool
    {
        $digits = self::digits($phone);
        if ($digits === '') {
            return false;
        }

        $normalized = self::normalizedPhoneSql('phone');

        return self::matches($except, 'phone', fn (Builder $query) => $query->whereRaw("{$normalized} = ?", [$digits]));
    }

    /**
     * @param  array{users: list<int>, members: list<int>, candidates: list<int>, clients: list<int>}  $except
     */
    private static function matches(array $except, string $column, Closure $constraint): bool
    {
        $sources = [
            [User::withTrashed(), 'users'],
            [KamsMember::withTrashed(), 'members'],
            [KamsCandidate::withTrashed(), 'candidates'],
            [KamsClient::withTrashed(), 'clients'],
        ];

        foreach ($sources as [$query, $key]) {
            $lookup = (clone $query)->whereNotNull($column)->where($column, '!=', '');
            if ($except[$key] !== []) {
                $lookup->whereNotIn('id', $except[$key]);
            }
            $constraint($lookup);
            if ($lookup->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The record being edited and the agent, candidate or account that belong to the same person.
     *
     * @return array{users: list<int>, members: list<int>, candidates: list<int>, clients: list<int>}
     */
    private static function except(?int $userId, ?int $memberId, ?int $candidateId, ?int $clientId): array
    {
        $users = array_values(array_filter([$userId]));
        $members = array_values(array_filter([$memberId]));
        $candidates = array_values(array_filter([$candidateId]));
        $clients = array_values(array_filter([$clientId]));

        if ($users !== []) {
            $linkedMembers = KamsMember::withTrashed()->whereIn('user_id', $users)->pluck('id')->all();
            $members = array_values(array_unique([...$members, ...$linkedMembers]));
        }

        if ($members !== []) {
            $linkedUsers = KamsMember::withTrashed()->whereIn('id', $members)->whereNotNull('user_id')->pluck('user_id')->all();
            $users = array_values(array_unique([...$users, ...array_map('intval', $linkedUsers)]));
            $linkedCandidates = KamsCandidate::withTrashed()->whereIn('member_id', $members)->pluck('id')->all();
            $candidates = array_values(array_unique([...$candidates, ...$linkedCandidates]));
        }

        if ($candidates !== []) {
            $candidateMembers = KamsCandidate::withTrashed()
                ->whereIn('id', $candidates)
                ->whereNotNull('member_id')
                ->pluck('member_id')
                ->all();
            $members = array_values(array_unique([...$members, ...array_map('intval', $candidateMembers)]));
            if ($candidateMembers !== []) {
                $linkedUsers = KamsMember::withTrashed()->whereIn('id', $candidateMembers)->whereNotNull('user_id')->pluck('user_id')->all();
                $users = array_values(array_unique([...$users, ...array_map('intval', $linkedUsers)]));
            }
        }

        return [
            'users' => $users,
            'members' => $members,
            'candidates' => $candidates,
            'clients' => $clients,
        ];
    }

    private static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private static function normalizedPhoneSql(string $column): string
    {
        $expression = $column;
        foreach ([' ', '-', '+', '(', ')', '.', '/'] as $character) {
            $expression = "REPLACE({$expression}, '{$character}', '')";
        }

        return $expression;
    }
}
