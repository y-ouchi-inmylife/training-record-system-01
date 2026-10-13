<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 契約モデル（D-0900）
 *
 * 会員と回数で結んだ契約。利用（ClientUsage）は client_contract_id で
 * どの契約の分かを持つ。契約ごとの残り ＝ 回数 − 割り当てた利用の回数の合計。
 * 回数券型の表示は、この割り当てのとおりに ContractTicketService で組み立てる。
 * 割り当て済みの利用回数より少ない回数への編集は ClientContractRequest でエラーにする。
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
     * この契約に割り当てた利用（D-1000）
     */
    public function usages(): HasMany
    {
        return $this->hasMany(ClientUsage::class, 'client_contract_id');
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }
}
