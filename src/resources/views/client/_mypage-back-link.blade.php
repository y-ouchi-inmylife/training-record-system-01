{{-- ［マイページへ戻る］（愛犬アンケート・性格診断の回答完了の画面と使えない案内の画面。2026-10 追加）。
     ログインしている会員が自分の愛犬の案内に回答したときだけ出す（App\Support\ClientMypageLink）。
     主な操作ではないので、会員側の補助のボタン（btn-outline-secondary）。カードの下、中央 --}}
@if($showMypageLink ?? false)
    <div class="text-center mt-4" data-client-mypage-back>
        <a href="{{ route('client-portal.dashboard') }}" class="btn btn-outline-secondary">{{ __('messages.client_back_to_mypage') }}</a>
    </div>
@endif
