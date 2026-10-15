<?php

namespace App\Http\Requests;

class UpdateGameRequest extends CreateGameRequest
{
    protected function gameId(): ?int
    {
        return $this->route('game')?->id;
    }
}