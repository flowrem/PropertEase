<?php

namespace App\Enums;

enum ConditionCheckKind: string
{
    case MoveIn = 'move_in';
    case MoveOut = 'move_out';
    case Routine = 'routine';

    public function label(): string
    {
        return match ($this) {
            self::MoveIn => 'Move-in',
            self::MoveOut => 'Move-out',
            self::Routine => 'Routine',
        };
    }
}
