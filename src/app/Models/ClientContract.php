<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 契約モデル（D-0900）
 *
 * 会員と回数で結んだ契約。利用（ClientUsage）とは互いにひも付けない
 * （どの契約から引くかは持たない）。回数券型の表示で利用をどの契約の
 * マスに入れるかは、表示のときに ContractTicketService で計算する。
 * 有効期限（expires_on）は表示するだけで、計算には使わない。
 * 削除は物理削除、権限は一般トレーナーも可（設計書 requirements.md 6-17-3）。
 */
class ClientContract extends Model
{
    protected $fillable = [
        'client_id',
        'contracted_on',
        'count',
        'expires_on',
        'note',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'contracted_on' => 'date',
            'expires_on' => 'date',
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
