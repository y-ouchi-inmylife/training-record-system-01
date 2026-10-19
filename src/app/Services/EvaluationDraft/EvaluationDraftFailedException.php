<?php

namespace App\Services\EvaluationDraft;

/**
 * AI の下書きを作れなかった（呼び出しの失敗・時間切れ・返事が空。2026-10-19 追加）。
 * 画面には決まった文（「AIの下書きを作れませんでした。…」）だけを出し、細かい原因はログにだけ残す
 */
class EvaluationDraftFailedException extends \RuntimeException {}
