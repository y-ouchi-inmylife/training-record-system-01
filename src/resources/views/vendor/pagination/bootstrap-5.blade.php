{{-- 一覧のページ送り（全画面共通。screen-design.md §2-9）。
     Laravel 標準の Bootstrap 5 用のビュー（AppServiceProvider の Paginator::useBootstrapFive() で既定）を
     この場所に置いて上書きする。件数の表示「全N件中 a〜b件」とページ切り替えを横に並べ、まとまりごと中央に置く。
     PC の幅とスマホの幅で同じ形にし、幅が足りないときは 2 段に折り返す（どちらも中央）。
     1 ページに収まるときは何も出さない（標準のビューと同じ）。 --}}
@if ($paginator->hasPages())
    <nav class="d-flex flex-wrap justify-content-center align-items-center column-gap-3 row-gap-2 w-100 mb-3" aria-label="ページ送り">
        <p class="small text-muted mb-0">
            全{{ number_format($paginator->total()) }}件中 {{ number_format($paginator->firstItem()) }}〜{{ number_format($paginator->lastItem()) }}件
        </p>

        {{-- ページ番号が多いときにスマホの幅ではみ出さないよう、折り返して中央に置く --}}
        <ul class="pagination flex-wrap justify-content-center mb-0">
            {{-- 前のページ --}}
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="前のページ">
                    <span class="page-link" aria-hidden="true">&lsaquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="前のページ">&lsaquo;</a>
                </li>
            @endif

            {{-- ページ番号（多いときは「...」で省略する） --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- 次のページ --}}
            @if ($paginator->hasMorePages())
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="次のページ">&rsaquo;</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true" aria-label="次のページ">
                    <span class="page-link" aria-hidden="true">&rsaquo;</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
