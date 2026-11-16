<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use UnexpectedValueException;

class BuildAdminReport
{
    public function __construct(private PixelforgeClient $client) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters): array
    {
        $start = CarbonImmutable::parse($filters['start_date'], 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($filters['end_date'], 'UTC')->startOfDay();
        $query = Generation::query()->where('status', GenerationStatus::Succeeded)
            ->where('api_key_hash', PixelforgeClient::keyHash())
            ->where('completed_at', '>=', $start)->where('completed_at', '<', $end->addDay());
        $groups = (clone $query)->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw('DATE(completed_at) AS date, users.id AS user_id, users.name, users.email, COUNT(*) AS generations, SUM(provider_cost_pico) AS provider_cost_pico, SUM(user_charge_pico) AS user_spend_pico')
            ->groupByRaw('DATE(completed_at), users.id, users.name, users.email')
            ->orderByDesc('date')->orderBy('users.id')->toBase()->get();
        $cost = 0;
        $spend = 0;
        $count = 0;
        $rows = [];
        foreach ($groups as $group) {
            $providerCost = (int) $group->provider_cost_pico;
            $userSpend = (int) $group->user_spend_pico;
            $cost += $providerCost;
            $spend += $userSpend;
            $count += (int) $group->generations;
            $rows[] = [
                'date' => $group->date,
                'user' => ['id' => (int) $group->user_id, 'name' => $group->name, 'email' => $group->email],
                'provider_cost' => Money::decimal($providerCost), 'user_spend' => Money::decimal($userSpend),
                'profit' => Money::decimal($userSpend - $providerCost), 'generations' => (int) $group->generations,
            ];
        }

        $reconciliation = ['provider_cost' => null, 'local_cost' => Money::decimal($cost), 'difference' => null, 'error' => null];
        try {
            if (! config('services.pixelforge.api_key')) {
                throw new UnexpectedValueException('Provider is not configured.');
            }
            $cacheKey = 'pixelforge.usage.'.PixelforgeClient::keyHash().'.'.$start->toDateString().'.'.$end->toDateString();
            $usage = Cache::remember($cacheKey, now()->addSeconds(30), fn (): array => $this->client->usage($start->toDateString(), $end->toDateString()));
            $providerTotal = $usage['total']['cost_usd'] ?? null;
            if (! is_string($providerTotal)) {
                throw new UnexpectedValueException('Provider returned invalid usage.');
            }
            $reconciliation['provider_cost'] = $providerTotal;
            $reconciliation['difference'] = Money::decimal($cost - Money::units($providerTotal));
        } catch (ConnectionException|RequestException|UnexpectedValueException|InvalidArgumentException) {
            $reconciliation['error'] = 'Provider usage is temporarily unavailable. Your local records are shown below.';
        }

        return [
            'rows' => $rows,
            'totals' => ['provider_cost' => Money::decimal($cost), 'user_spend' => Money::decimal($spend), 'profit' => Money::decimal($spend - $cost), 'generations' => $count],
            'reconciliation' => $reconciliation,
            'filters' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
        ];
    }
}
