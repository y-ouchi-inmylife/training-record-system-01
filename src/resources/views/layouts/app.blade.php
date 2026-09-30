<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="/icons/favicon-trainer.ico" sizes="any">
    <link rel="icon" href="/icons/favicon-trainer.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon-trainer.png">
    <link rel="manifest" href="/manifest-trainer.json">
    <meta name="theme-color" content="#0a4fa8">
    <title>@hasSection('title')@yield('title') - @endif{{ config('app.trainer_portal_name') }}</title>
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    @stack('styles')
    <style>
        /* ネストドロップダウンのスタイル */
        .dropend .dropdown-toggle::after {
            border-left: 0.3em solid transparent;
            border-right: 0;
            border-top: 0.3em solid;
            border-bottom: 0.3em solid transparent;
            margin-left: 0.5em;
            vertical-align: middle;
        }

        .dropend .dropdown-menu {
            top: 0;
            left: 100%;
            margin-top: 0;
        }

        /* 全入力フィールドのプレースホルダーを薄く表示 */
        input::placeholder,
        textarea::placeholder {
            color: #ccc !important;
            opacity: 1;
        }
    </style>
</head>
<body>
    @auth
    <nav class="navbar navbar-expand-lg navbar-dark bg-trainer-nav sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ url('/dashboard') }}">トレーニング記録管理システム</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    @if(!Auth::user()->isSystemAdmin())
                        {{-- 通常のトレーナー（system_admin以外） --}}
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle {{ request()->is('clients*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                                会員
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="{{ route('clients.index') }}">会員一覧</a></li>
                                <li><a class="dropdown-item" href="{{ route('clients.create') }}">会員登録</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->is('training-records*') ? 'active' : '' }}" href="{{ route('training-records.index') }}">
                                トレーニング記録
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->is('media-records*') ? 'active' : '' }}" href="{{ route('media-records.index') }}">
                                メディア
                            </a>
                        </li>
                        {{-- 音声記録：音声記録一覧への直接リンク。登録（録音・音声ファイル・文字起こしテキスト）は一覧の画面上部から入る。
                             登録画面（/recording-v2・/audio-records/…/create）にいるときも強調する（設計書 §2-1「音声記録」参照） --}}
                        <li class="nav-item">
                            <a class="nav-link {{ request()->is('audio-records*') || request()->is('recording*') ? 'active' : '' }}" href="{{ route('audio-records.index') }}">
                                音声記録
                            </a>
                        </li>
                    @endif
                    @if(Auth::user()->isPractitioner())
                        {{-- レポート（admin + staff のみ表示） --}}
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle {{ request()->is('statistics*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                                レポート
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="{{ route('statistics.clients') }}">トレーニング記録数推移</a></li>
                            </ul>
                        </li>
                    @endif
                    @if(Auth::user()->isAdmin())
                        {{-- 管理者メニュー（admin+system_admin。トレーナー管理はsystem_adminにも表示、他はadminのみ） --}}
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle {{ request()->is('trainers*') || request()->is('access-logs*') || request()->is('settings/summary-prompts*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                                【管理者】
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="{{ route('trainers.index') }}">トレーナー管理</a></li>
                                @if(Auth::user()->isAdminOnly())
                                    <li><a class="dropdown-item" href="{{ route('access-logs.index') }}">トレーナー操作履歴</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="{{ route('settings.summary-prompts.edit') }}">要約プロンプト</a></li>
                                @endif
                            </ul>
                        </li>
                    @endif
                    @if(Auth::user()->isSystemAdmin())
                        {{-- システム管理者用メニュー（system_adminのみ） --}}
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle {{ request()->is('usage-stats*') ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown">
                                【システム管理者】
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="{{ route('usage-stats.index') }}">音声ファイル一覧</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ route('settings.ip-restriction.edit') }}">IPアドレス制限</a></li>
                            </ul>
                        </li>
                    @endif
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            {{ Auth::user()->name }}
                            <span class="badge bg-light text-trainer-nav ms-1">
                                {{ Auth::user()->role_display_name }}
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="{{ route('profile.edit') }}">マイプロフィール</a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="{{ route('profile.password.edit') }}">パスワード変更</a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ url('/logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item">ログアウト</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    @endauth

    <main class="py-4">
        <div class="container">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert" data-auto-dismiss>
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
        </div>
        @yield('content')
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ネストドロップダウンのホバー動作
            const dropdownSubmenus = document.querySelectorAll('.dropend .dropdown-toggle');

            dropdownSubmenus.forEach(function(element) {
                element.addEventListener('mouseenter', function() {
                    const submenu = this.nextElementSibling;
                    if (submenu && submenu.classList.contains('dropdown-menu')) {
                        submenu.classList.add('show');
                    }
                });

                element.parentElement.addEventListener('mouseleave', function() {
                    const submenu = this.querySelector('.dropdown-menu');
                    if (submenu) {
                        submenu.classList.remove('show');
                    }
                });
            });
        });
    </script>
    <script>
        // successフラッシュメッセージを5秒後に自動消去する（data-auto-dismiss 属性を持つ要素のみ）
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-auto-dismiss]').forEach(function (el) {
                setTimeout(function () {
                    bootstrap.Alert.getOrCreateInstance(el).close();
                }, 5000);
            });
        });
    </script>
    @stack('scripts')
</body>
</html>
