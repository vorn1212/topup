<?php

namespace App\Http\Controllers\Site;

use App\Exceptions\ProviderException;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\TopUp\FazerCardsService;
use App\Services\TopUp\PlayerFields;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlayerValidationController extends Controller
{
    public function __invoke(Request $request, string $slug, FazerCardsService $api): JsonResponse
    {
        $game = Game::query()->active()->where('slug', $slug)->with('fields')->firstOrFail();

        // Staff get the technical reason. Customers never do.
        $staff = (bool) $request->user()?->can('provider.manage');

        if (blank($game->validation_category_id) || ! $api->isConfigured()) {
            return ApiResponse::success(['supported' => false], 'Player check is not available for this game.');
        }

        $input = $request->input('fields');
        $validator = PlayerFields::validator($game, is_array($input) ? $input : []);

        if ($validator->fails()) {
            return ApiResponse::error('Please check the player details.', $validator->errors()->toArray(), 422);
        }

        $fields = PlayerFields::clean($game, $validator->validated());

        // Send only the fields the provider's validation category expects.
        try {
            $list = Cache::remember('fazercards:validatable', 3600, fn () => $api->getValidatableGames());
        } catch (ProviderException) {
            $list = null;
        }

        $entry = collect($list ?? [])->firstWhere('category_id', $game->validation_category_id);

        if ($list !== null && $entry === null) {
            Log::warning('Player check category not found at the provider', ['game' => $game->slug, 'category' => $game->validation_category_id]);

            return ApiResponse::success(
                ['supported' => false] + ($staff ? ['detail' => "The player check category \"{$game->validation_category_id}\" is not in the provider's list. Pick it again in Admin > Games > edit."] : []),
                'Player check is not available for this game.'
            );
        }

        $expected = $entry ? array_column($entry['fields'], 'key') : [];

        if ($expected !== []) {
            $forward = array_intersect_key($fields, array_flip($expected));

            if (array_diff($expected, array_keys($forward)) !== []) {
                Log::warning('Player check field names do not match the provider', [
                    'game' => $game->slug,
                    'expected' => $expected,
                    'ours' => array_keys($fields),
                ]);

                return ApiResponse::success(
                    ['supported' => false] + ($staff ? ['detail' => 'The provider expects the fields ['.implode(', ', $expected).'] but this game sends ['.implode(', ', array_keys($fields)).']. Use the provider\'s field names (Provider page > Import player fields).'] : []),
                    'Player check is not available for this game.'
                );
            }

            $fields = $forward;
        }

        $cacheKey = 'fazercards:validate:'.hash('sha256', $game->validation_category_id.'|'.json_encode($fields));
        $result = Cache::get($cacheKey);

        if ($result === null) {
            try {
                $result = $api->validatePlayer($game->validation_category_id, $fields);
            } catch (ProviderException $e) {
                Log::warning('Player check failed', [
                    'game' => $game->slug,
                    'status' => $e->httpStatus,
                    'code' => $e->providerCode,
                    'message' => Str::limit($e->getMessage(), 150),
                ]);

                $detail = $staff
                    ? 'HTTP '.($e->httpStatus ?? 'none').($e->providerCode ? " ({$e->providerCode})" : '').': '.Str::limit($e->getMessage(), 150)
                        .'. Sent ['.implode(', ', array_keys($fields)).'], expected ['.implode(', ', $expected).'].'
                    : null;

                // The check is advisory: the customer may still continue.
                return ApiResponse::error(
                    'We could not check the player right now. You can still continue.',
                    $detail !== null ? ['detail' => $detail] : [],
                    503
                );
            }

            Cache::put($cacheKey, $result, now()->addMinutes(5));
        }

        return ApiResponse::success([
            'supported' => true,
            'valid' => $result['valid'],
            'player_name' => $result['player_name'],
            'region' => $result['region'],
        ], $result['valid'] ? 'Player found.' : 'Player not found. Check the ID.');
    }
}