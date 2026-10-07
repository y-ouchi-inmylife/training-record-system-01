<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

/**
 * 2026-10 追加：会員側 S-1402 の写真枠は、スマホで幅いっぱいに広げるため
 * `.c-trainee-photo-col` / `.c-trainee-photo-frame` を付けるようにした
 * （SCSS のメディアクエリで切り替え）。トレーナー側 S-0309 の写真枠
 * （180px 四方のままにする）には、同クラスが付かないことを確かめる。
 *
 * クラスが付いてしまうと `client.scss` 側のメディアクエリに引っかかって
 * スマホで枠が広がる可能性があるが、トレーナー側は `app.scss` を使うため
 * 実害はない。ただし「同じクラス名を混用しない」規約を保つことで、
 * 将来 SCSS を他ポータルに展開する際の事故を防ぐ。
 */
class TraineeShowPhotoFrameRenderingTest extends TestCase
{
    private function renderTraineeShow(?string $photoUrl = null): string
    {
        // トレーナー（削除ボタン表示判定のため admin ロールで actingAs）
        $trainer = new Trainer();
        $trainer->id = 1;
        $trainer->role = 'admin';
        $this->actingAs($trainer);

        // 会員（トレーニーの client リレーション先）
        $client = new Client();
        $client->id = 1;
        $client->last_name = '山田';
        $client->first_name = '太郎';
        $client->internal_id = 1;
        $client->exists = true;

        // トレーニー（measurements は空の Collection を関連として直接セット）
        $trainee = new Trainee();
        $trainee->id = 1;
        $trainee->name = 'ポチ';
        $trainee->breed = null;
        $trainee->sex = null;
        $trainee->birth_date = null;
        $trainee->note = null;
        $trainee->exists = true;
        $trainee->setRelation('client', $client);
        $trainee->setRelation('measurements', new EloquentCollection([]));

        // $errors（ViewErrorBag）はビュー内の partial（_measurement-modal.blade.php）で
        // 参照されるため、withViewErrors([]) で空のバッグを bind してから描画する。
        $view = $this->withViewErrors([])->view('trainees.show', [
            'trainee' => $trainee,
            'weightChart' => [
                'id' => 1,
                'name' => 'ポチ',
                'photoUrl' => $photoUrl,
                'datasets' => [],
            ],
            'deleteConfirmMessage' => 'このトレーニーを削除しますか？',
            'measurementCount' => 0,
        ]);

        return $view->__toString();
    }

    public function test_トレーナー側トレーニー詳細の写真枠に会員側専用クラスが付かない(): void
    {
        $html = $this->renderTraineeShow(photoUrl: null);

        // 会員側（client.scss）専用のクラスが S-0309 に紛れ込んでいないこと
        $this->assertStringNotContainsString('c-trainee-photo-frame', $html);
        $this->assertStringNotContainsString('c-trainee-photo-col', $html);

        // トレーナー側は従前どおり 180px 四方のインライン style を使っていること
        // （写真なし時は「写真なし」のプレースホルダ枠）
        $this->assertStringContainsString('width: 180px; height: 180px;', $html);
        $this->assertStringContainsString('写真なし', $html);
    }

    public function test_写真ありでもトレーナー側は180pxの固定サイズのまま(): void
    {
        $html = $this->renderTraineeShow(photoUrl: 'https://example.com/photo.jpg');

        $this->assertStringNotContainsString('c-trainee-photo-frame', $html);
        $this->assertStringNotContainsString('c-trainee-photo-col', $html);
        $this->assertStringContainsString('width: 180px; height: 180px;', $html);
    }
}
