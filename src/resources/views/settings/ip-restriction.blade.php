@extends('layouts.app')

@section('title', 'IPアドレス制限')

@section('content')
<div class="container">
    {{-- novalidate：ブラウザの吹き出しを止め、検証はサーバーに一本化する（設計書 §2-7）。
         入力欄に required などの属性は今は無いが、他画面と揃えて付けておく。 --}}
    <form method="POST" action="{{ route('settings.ip-restriction.update') }}" novalidate>
        @csrf
        @method('PUT')

        <div style="max-width: 700px;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">IPアドレス制限</h2>
                <button type="submit" class="btn btn-success">更新</button>
            </div>

            @if(session('success_ip'))
                <div class="alert alert-success">{{ session('success_ip') }}</div>
            @endif

            {{-- 画面上部の 1 文の案内（設計書 §2-7）。本画面はフォームが 1 つだけなので
                 判定の工夫なしで出せる（他画面と同じ扱い）。 --}}
            <x-form-error-summary />

            {{-- 有効/無効チェックボックス --}}
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="enable_ip_restriction"
                           name="enable_ip_restriction" value="1"
                           {{ old('enable_ip_restriction', $settings['enable_ip_restriction'] ?? '0') === '1' ? 'checked' : '' }}>
                    <label class="form-check-label" for="enable_ip_restriction">
                        IPアドレス制限を有効にする
                    </label>
                </div>
            </div>

            {{-- IPアドレスリスト --}}
            <div class="mb-3">
                <div class="d-flex align-items-baseline gap-3 mb-2">
                    <label class="form-label mb-0">許可するIPアドレス</label>
                    <span class="text-muted">現在のIPアドレス: <code>{{ $currentIp }}</code></span>
                </div>

                <div id="ip-list-container">
                    @forelse(old('ip_addresses', $ipWhitelist->pluck('ip_address')->toArray()) as $index => $ip)
                        <div class="row mb-2 ip-row">
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="ip_addresses[]"
                                       value="{{ $ip }}"
                                       placeholder="例: 192.168.1.100 または 192.168.1.0/24">
                            </div>
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="descriptions[]"
                                       value="{{ old('descriptions.' . $index, $ipWhitelist[$index]->description ?? '') }}"
                                       placeholder="備考（例: ○○オフィス）" maxlength="100">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-outline-danger btn-sm btn-remove-ip" title="この行を削除">
                                    <i class="bi bi-x-lg"></i> 削除
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="row mb-2 ip-row">
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="ip_addresses[]"
                                       placeholder="例: 192.168.1.100 または 192.168.1.0/24">
                            </div>
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="descriptions[]"
                                       placeholder="備考（例: ○○オフィス）" maxlength="100">
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-outline-danger btn-sm btn-remove-ip" title="この行を削除">
                                    <i class="bi bi-x-lg"></i> 削除
                                </button>
                            </div>
                        </div>
                    @endforelse
                </div>

                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="btn-add-ip">
                    <i class="bi bi-plus-lg"></i> IPアドレスを追加
                </button>
            </div>

            {{-- まとめた 1 つの文言を、行のまとまり（行の一覧と「IPアドレスを追加」ボタン）の
                 すぐ下に出す（設計書 §2-7「1 つの入力欄に収まらない項目（行を追加する形）」）。
                 入力欄の兄弟に置けないため block を付ける。改行区切りの複数行を表示する
                 ため、x-form-error ではなく専用の表示にする（部品は 1 件・1 行を想定）。
                 どの行の問題かは、文言に含めた「行 N: 「xxx」…」で伝える（行ごとの
                 is-invalid は付けない）。 --}}
            @error('ip_restriction')
                <div class="invalid-feedback d-block">{!! nl2br(e($message)) !!}</div>
            @enderror

            {{-- 注意事項 --}}
            <div class="alert alert-warning mb-3">
                <strong>注意:</strong>
                <ul class="mb-0">
                    <li>IPアドレス制限を有効にする前に、必ず現在のIPアドレスをリストに追加してください。</li>
                    <li>固定IPアドレスでない場合、IPアドレスが変わるとアクセスできなくなります。</li>
                    <li>localhost（127.0.0.1）は常に許可されます。</li>
                </ul>
            </div>
        </div>
    </form>
</div>
@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // IPアドレス行の追加
        document.getElementById('btn-add-ip').addEventListener('click', function() {
            const container = document.getElementById('ip-list-container');
            const row = document.createElement('div');
            row.className = 'row mb-2 ip-row';
            row.innerHTML = `
                <div class="col-md-5">
                    <input type="text" class="form-control" name="ip_addresses[]"
                           placeholder="例: 192.168.1.100 または 192.168.1.0/24">
                </div>
                <div class="col-md-5">
                    <input type="text" class="form-control" name="descriptions[]"
                           placeholder="備考（例: ○○オフィス）" maxlength="100">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-danger btn-sm btn-remove-ip" title="この行を削除">
                        <i class="bi bi-x-lg"></i> 削除
                    </button>
                </div>
            `;
            container.appendChild(row);

            // 新しい行のIPアドレス入力欄にフォーカス
            row.querySelector('input[name="ip_addresses[]"]').focus();
        });

        // IPアドレス行の削除（イベント委譲）
        document.getElementById('ip-list-container').addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-remove-ip');
            if (btn) {
                const row = btn.closest('.ip-row');
                const rows = document.querySelectorAll('.ip-row');

                if (rows.length <= 1) {
                    // 最後の1行は削除せず、入力値をクリア
                    row.querySelectorAll('input').forEach(input => input.value = '');
                } else {
                    row.remove();
                }
            }
        });
    });
</script>
@endpush
@endsection
