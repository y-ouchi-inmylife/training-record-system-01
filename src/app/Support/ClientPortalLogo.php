<?php

namespace App\Support;

/**
 * 会員側のヘッダー帯の左に出すロゴ画像（config('app.client_portal_logo')。2026-10-18 追加。設計書 §9-3・S-1402「ヘッダー帯」）。
 *
 * - 設定がない（空）なら null（ヘッダーはプロダクト名の文字を出す）
 * - `public/` の中の画像（`/images/...` のように / で始まる）なら、画像のファイルから幅と高さを読み、<img> の
 *   width・height にする（読み込み中に画面がずれないようにするため。表示の大きさは client.scss の .c-nav-brand-img で決める）
 * - 外の URL・ファイルがない・画像として読めないときは、幅と高さを付けない
 */
class ClientPortalLogo
{
    /**
     * @return array{src: string, width: int|null, height: int|null}|null
     */
    public static function forHeader(): ?array
    {
        $src = config('app.client_portal_logo');
        if (! is_string($src) || trim($src) === '') {
            return null;
        }

        $width = null;
        $height = null;
        if (str_starts_with($src, '/') && ! str_starts_with($src, '//')) {
            $path = public_path(ltrim($src, '/'));
            $size = is_file($path) ? @getimagesize($path) : false;
            if ($size !== false) {
                [$width, $height] = $size;
            }
        }

        return ['src' => $src, 'width' => $width, 'height' => $height];
    }
}
