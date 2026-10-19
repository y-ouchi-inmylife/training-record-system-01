{{-- 回答を終えた完了の画面（S-1414・S-1416）の、マイページ登録へのお誘い（2026-10-19 追加。要件定義書 6-16-18）。
     $url（App\Support\MypageRegistrationInvite::url。答えた会員に有効なマイページ登録の案内があるとき。回答の送信で判定してフラッシュで渡す）があるときだけ出す。
     お礼のカードの下に、別のカードで置く（お礼とお誘いを分ける）。［マイページに登録する］は会員側の主な操作の青の塗りで、
     マイページ登録の案内のリンク（メールアドレスを入れる画面 S-1406）を同じタブで開く。
     2026-10-19 変更：マイページでできることを箇条書きで並べた（lang の lead と items。ビューは並べて出すだけ）。黒丸は出さず、
     頭にチェックの丸の印（会員側は Bootstrap Icons のフォントを読み込んでいないので bi-check-circle の形の SVG を直接書く。読み上げない）。
     折り返したときは 2 行目を文の頭にそろえる（client.scss の .c-invite-list） --}}
@if($url)
    <div class="card c-login-card mt-3" data-mypage-registration-invite>
        <div class="card-body p-4">
            <p class="mb-2 c-text-pretty">{{ __('messages.client_mypage_registration_invite.lead') }}</p>
            <ul class="list-unstyled c-invite-list mb-4" data-mypage-registration-invite-items>
                @foreach(__('messages.client_mypage_registration_invite.items') as $item)
                    <li><svg class="c-invite-list-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="m10.97 4.97-.02.022-3.473 4.425-2.093-2.094a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05"/></svg><span>{{ $item }}</span></li>
                @endforeach
            </ul>
            <a href="{{ $url }}" class="btn btn-primary">{{ __('messages.client_mypage_registration_invite.button') }}</a>
        </div>
    </div>
@endif
