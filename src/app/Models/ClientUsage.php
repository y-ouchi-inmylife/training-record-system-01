<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用モデル（D-1000）
 *
 * 契約回数のうち使った回数の記録。どの契約（ClientContract）の分かを
 * client_contract_id で持つ（NULL は「契約なし」＝未割り当て）。契約を削除すると
 * DB の外部キー（ON DELETE SET NULL）で「契約なし」に戻る。トレーニング記録
 * （TrainingRecord）とはひも付けない（記録の件数と利用回数は一致しないため）。
 * 1 件で複数回分を登録できる（count）。契約の残りを超える割り当ては
 * ClientUsageRequest でエラーにするが、会員の残り回数がマイナスになる登録は止めない。
 * 削除は物理削除、権限は一般トレーナーも可（設計書 requirements.md 6-17-6）。
 */
class ClientUsage extends Model
{
    protected $fillable = [
        'client_id',
        'client_contract_id',
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
     * 割り当てた契約（D-0900）。未割り当て（「契約なし」）なら null
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ClientContract::class, 'client_contract_id');
    }

    /**
     * 最終更新者（トレーナー）
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'updated_by');
    }
}
