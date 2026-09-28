<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 文字起こし前のサーバー側変換をしても、なお API の 1 リクエスト上限に収まらない場合の例外。
 *
 * この例外は「何度やり直しても結果が変わらない」種類のエラー。
 * TranscribeAudioJob の catch でこの型を区別し、tries を消費させず即失敗させる。
 * コンストラクタで渡すメッセージは、そのまま利用者への表示に使われる。
 */
class TranscriptionInputTooLargeException extends RuntimeException
{
}
