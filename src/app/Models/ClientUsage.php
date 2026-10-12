<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用モデル（D-1000）
 *
 * 契約回数のうち使った回数の記録。契約（ClientContract）ともトレーニング記録
 * （TrainingRecord）ともひも付けない（記録の件数と利用回数は一致しないため）。
 * 1 件で複数回分を登録できる（count）。残り回数がマイナスになる登録も止めない。
 * 削除は物理削除、権限は一般トレーナーも可（設計書 requirements.md 6-17-6）。
 */
class ClientUsage extends Model
{
    protected $fillable = [
        'client_id',
        'used_on',
        'count',
        'note',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'used_on' => 'date',
            'count' => 'integer',
        ];
    }

    /**
     * 会員
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }
}
