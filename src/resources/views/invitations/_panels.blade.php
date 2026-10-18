{{-- 3 つのご案内の画面（マイページ登録 S-0307・受付アンケート S-0311・愛犬性格診断 S-0312）の本文（2026-10-18 追加。
     設計書 screen-design.md S-0307「LINE・メールで送る／その場で読み取ってもらう」）。有効な案内があるときだけ置く。

     2026-10-18 変更：ユーザーレビューで、実際は印刷せず、LINE・メールで送るか、その場で QR コードを読み取ってもらうことが分かったため、
     印刷して渡す作り（印刷専用のレイアウト・A4・QR コードを読み取る手順の欄）をやめ、2 つの欄にした。
       - LINE・メールで送る（上・主な使い方）：コピーする文をそのまま出す（改行も同じ。何が送られるか見て分かるように）。
         ［コピー］はトレーナー側のいつも使う操作なので青の塗り（4-4）。押すと「コピーしました」（copy-message.js）。
         2026-10-18 変更：ユーザーレビューで、［文面をコピー］を［コピー］にし、文面の下から見出しの行の右に移した。大きさは
         ほかのカードの見出しの行のボタン（会員詳細の［トレーニング記録を登録］、S-0313 の［更新］）と同じふつうの大きさ。
         読み上げは「文面をコピー」（aria-label）。「コピーしました」に変わってもボタンの幅が変わらないよう、copy-message.js が
         いちばん長い表示に合わせた最低の幅を付ける。左右の見出しの行の高さは invitation-panel-header でそろえる（app.scss）
       - QR コード：QR コードだけ（その場で画面を見せて読み取ってもらうほか、画像にしてメール・LINE に添えることもある。qr-code.js）。
         2026-10-18 変更：ユーザーレビューで、見出しを「その場で読み取ってもらう」から「QR コード」にし、下のお客様向けの一文と
         有効期限（または 1 回かぎりの文）を外した（添付することもあるので説明は過剰なため。有効期限・1 回かぎりの文は左の文面に入っている）
     992px 以上（lg）は横に並べる（左に文面、右に QR コード）。それより狭いときは縦に積む（文面が上）。

     変数：$copyText（コピーする文。画面にも同じものを出す）、$url（QR コードの中身）、$qrLabel（QR コードの読み上げ） --}}
<div class="row g-3 align-items-start">
    <div class="col-12 col-lg-7">
        <section class="card h-100" aria-labelledby="invitation-message-heading" data-invitation-send>
            <div class="card-header invitation-panel-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h6 mb-0" id="invitation-message-heading">LINE・メールで送る</h2>
                <button type="button" class="btn btn-primary ms-auto" aria-label="文面をコピー" data-copy-message data-copy-text="{{ $copyText }}">コピー</button>
            </div>
            <div class="card-body">
                <div class="invitation-message" data-invitation-message>{{ $copyText }}</div>
            </div>
        </section>
    </div>
    <div class="col-12 col-lg-5">
        <section class="card h-100" aria-labelledby="invitation-qr-heading" data-invitation-qr>
            <div class="card-header invitation-panel-header d-flex align-items-center">
                <h2 class="h6 mb-0" id="invitation-qr-heading">QR コード</h2>
            </div>
            <div class="card-body text-center">
                {{-- 描く大きさは 280px（画面の幅が狭ければ枠いっぱいまで縮める。invitation-qr）。タブレット・スマホの画面を
                     お客様に見せて読み取ってもらうので、印刷していたときの 240px より大きくした --}}
                <div class="invitation-qr mx-auto">
                    <canvas data-qr-url="{{ $url }}" data-qr-size="280" aria-label="{{ $qrLabel }}"></canvas>
                </div>
            </div>
        </section>
    </div>
</div>
