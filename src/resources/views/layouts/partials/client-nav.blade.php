{{--
    クライアントポータルのヘッダー帯（設計書 client-portal-design-plan.md §4-0）。
    ログイン前後で見た目を揃えるため、`layouts.client`（認証後 + login）と
    `layouts.client-public`（その他 pre-auth）の両方から参照する。

    左のブランド位置：
      - config('app.client_portal_logo') が設定されていれば <img> を出力（alt はプロダクト名。画像が読み込めないときも名前が分かる）。
        width・height は画像のファイルから読む（App\Support\ClientPortalLogo。読み込み中に画面がずれないように）。
        表示の高さは client.scss の .c-nav-brand-img（パソコン 40px・スマホ 38px〔360px 未満は 32px〕。横幅は縦横比のまま）
      - なければテキスト（config('app.client_portal_name')）を出力
      - 2026-10-18 変更：ユーザーレビューで、Mirai Dog Wellness のロゴ画像（public/images/mirai-dog-wellness-logo.png）を
        既定にした（config/app.php）。その前は設定がなく、文字を出していた
    右のメニュー：
      - $authed=true のときのみ「ご利用回数」「登録情報」「ログアウト」を出す（post-auth）
      - 「ご利用回数」（S-1412）は項目名だけで、回数の数字は出さない（どの画面を人に見せても
        回数が見えないようにするため）。契約・利用の有無にかかわらず常に出す（出し分けると
        どの画面でも毎回データを引くことになるため。契約がなければ S-1412 の空の状態が出る）
      - pre-auth は左のブランドのみ

    ブランドリンクの遷移先：
      - post-auth: ダッシュボード（client-portal.dashboard）
      - pre-auth: リンクなし（現在ログイン前で辿れる先が画面自体のため）
--}}
@php
    $portalName = config('app.client_portal_name', 'トレーニング記録');
    $portalLogo = \App\Support\ClientPortalLogo::forHeader();
    $authed = $authed ?? false;
@endphp

<nav class="navbar navbar-expand-lg c-nav">
    <div class="container">
        @if($authed)
            <a class="navbar-brand" href="{{ route('client-portal.dashboard') }}">
                @if($portalLogo)
                    <img src="{{ $portalLogo['src'] }}" alt="{{ $portalName }}" class="c-nav-brand-img"@if($portalLogo['width']) width="{{ $portalLogo['width'] }}" height="{{ $portalLogo['height'] }}"@endif>
                @else
                    {{ $portalName }}
                @endif
            </a>
        @else
            <span class="navbar-brand mb-0">
                @if($portalLogo)
                    <img src="{{ $portalLogo['src'] }}" alt="{{ $portalName }}" class="c-nav-brand-img"@if($portalLogo['width']) width="{{ $portalLogo['width'] }}" height="{{ $portalLogo['height'] }}"@endif>
                @else
                    {{ $portalName }}
                @endif
            </span>
        @endif

        @if($authed)
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#clientNav" aria-controls="clientNav"
                    aria-expanded="false" aria-label="メニューを開く">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="clientNav">
                <ul class="navbar-nav align-items-lg-center">
                    <li class="nav-item">
                        <a class="btn btn-link btn-sm" href="{{ route('client-portal.contracts') }}">ご利用回数</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-link btn-sm" href="{{ route('client-portal.profile.show') }}">登録情報</a>
                    </li>
                    <li class="nav-item">
                        <form method="POST" action="{{ route('client-portal.logout') }}" class="m-0">
                            @csrf
                            <button type="submit" class="btn btn-link btn-sm">ログアウト</button>
                        </form>
                    </li>
                </ul>
            </div>
        @endif
    </div>
</nav>
