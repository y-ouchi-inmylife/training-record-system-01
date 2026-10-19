{{-- 回答を終えた完了の画面（S-1414・S-1416）の、マイページ登録へのお誘い（2026-10-19 追加。要件定義書 6-16-18）。
     $url（App\Support\MypageRegistrationInvite::url。答えた会員に有効なマイページ登録の案内があるとき。回答の送信で判定してフラッシュで渡す）があるときだけ出す。
     お礼のカードの下に、別のカードで置く（お礼とお誘いを分ける）。［マイページに登録する］は会員側の主な操作の青の塗りで、
     マイページ登録の案内のリンク（メールアドレスを入れる画面 S-1406）を同じタブで開く --}}
@if($url)
    <div class="card c-login-card mt-3" data-mypage-registration-invite>
        <div class="card-body p-4">
            <p>{{ __('messages.client_mypage_registration_invite.body') }}</p>
            <a href="{{ $url }}" class="btn btn-primary">{{ __('messages.client_mypage_registration_invite.button') }}</a>
        </div>
    </div>
@endif
