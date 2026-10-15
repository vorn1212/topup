<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameFieldRequest;
use App\Models\Game;
use App\Models\GameField;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class GameFieldController extends Controller
{
    public function store(GameFieldRequest $request, Game $game, AuditService $audit): RedirectResponse
    {
        $field = new GameField($this->payload($request->validated()));
        $field->game_id = $game->id;
        $field->save();

        $audit->log('game_field.created', $field, [], $field->only(['provider_field_name', 'label', 'type', 'required']));

        return redirect()->route('admin.games.edit', $game)->with('status', 'Field added.');
    }

    public function update(GameFieldRequest $request, Game $game, GameField $field, AuditService $audit): RedirectResponse
    {
        abort_unless((int) $field->game_id === (int) $game->id, 404);

        $old = $field->only(['provider_field_name', 'label', 'type', 'required', 'validation_rules']);
        $field->fill($this->payload($request->validated()))->save();

        $audit->log('game_field.updated', $field, $old, $field->only(['provider_field_name', 'label', 'type', 'required', 'validation_rules']));

        return redirect()->route('admin.games.edit', $game)->with('status', 'Field saved.');
    }

    public function destroy(Game $game, GameField $field, AuditService $audit): RedirectResponse
    {
        abort_unless((int) $field->game_id === (int) $game->id, 404);

        $audit->log('game_field.deleted', $field, $field->only(['provider_field_name', 'label']));
        $field->delete();

        return redirect()->route('admin.games.edit', $game)->with('status', 'Field removed.');
    }

    private function payload(array $data): array
    {
        $options = null;

        if (($data['type'] ?? '') === 'select') {
            $options = collect(explode(',', (string) ($data['options'] ?? '')))
                ->map(fn ($option) => Str::limit(trim($option), 60, ''))
                ->filter()
                ->unique()
                ->values()
                ->take(100)
                ->all();
        }

        return [
            'provider_field_name' => $data['provider_field_name'],
            'label' => $data['label'],
            'type' => $data['type'],
            'required' => (bool) ($data['required'] ?? false),
            'validation_rules' => filled($data['validation_rules'] ?? null) ? trim($data['validation_rules']) : null,
            'options' => $options,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}