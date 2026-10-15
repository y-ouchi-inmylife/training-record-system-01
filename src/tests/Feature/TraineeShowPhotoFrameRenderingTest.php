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
 * （app.scss の .trainee-show-photo。2026-10 変更で sm 以上 280px 四方・スマホ 180px 四方）には、
 * 同クラスが付かないことを確かめる。
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
        $trainee->birth_year = null;
        $trainee->birth_month = null;
        $trainee->birth_day = null;
        $trainee->exists = true;
        $trainee->setRelation('client', $client);
        $trainee->setRelation('measurements', new EloquentCollection([]));
        // 受付アンケートの欄の状態（2026-10 追加）。この表示では未発行
        $trainee->setRelation('activeIntakeSurveyToken', null);
        $trainee->setRelation('latestIntakeSurveyResponse', null);
        // 性格診断の欄の結果（2026-10 追加）。結果なし
        $trainee->setRelation('personalityDiagnosisResults', new EloquentCollection([]));

        // $errors（ViewErrorBag）はビュー内の partial（_measurement-modal.blade.php）で
        // 参照されるため、withViewErrors([]) で空のバッグを bind してから描画する。
        $view = $this->withViewErrors([])->view('trainees.show', [
            'trainee' => $trainee,
            'weightChart' => [
                'id' => 1,
                'callName' => 'ポチちゃん',
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

        // トレーナー側は app.scss の .trainee-show-photo で大きさを決める（2026-10 変更：当初は 180px 四方の
        // インライン style）。写真なし時も同じ枠に「写真なし」のプレースホルダ
        $this->assertMatchesRegularExpression('/<div class="trainee-show-photo d-flex align-items-center justify-content-center"\s*style="background-color: #ffffff;[^"]*">\s*<span class="text-muted" style="font-size: 0.875rem;">写真なし<\/span>/u', $html);
        $this->assertStringNotContainsString('width: 180px; height: 180px;', $html);
    }

    public function test_写真ありでも写真なしと同じ固定サイズの枠(): void
    {
        $html = $this->renderTraineeShow(photoUrl: 'https://example.com/photo.jpg');

        $this->assertStringNotContainsString('c-trainee-photo-frame', $html);
        $this->assertStringNotContainsString('c-trainee-photo-col', $html);
        $this->assertMatchesRegularExpression('/<div class="trainee-show-photo d-flex align-items-center justify-content-center"\s*style="background-color: #ffffff;[^"]*">\s*<img src="https:\/\/example.com\/photo.jpg"/u', $html);
    }

    public function test_写真の枠の大きさはsm以上280pxでスマホは180px(): void
    {
        $scss = file_get_contents(resource_path('sass/app.scss'));

        $this->assertMatchesRegularExpression(
            '/\.trainee-show-photo \{\s*width: 180px;\s*height: 180px;\s*@include media-breakpoint-up\(sm\) \{\s*width: 280px;\s*height: 280px;\s*\}\s*\}/u',
            $scss
        );
    }

    public function test_会員側の写真の枠とグラフの大きさは変えない(): void
    {
        // 会員側 S-1402 は client.scss の .c-trainee-photo-frame（180px 四方）とグラフの高さ 180px のまま
        $clientScss = file_get_contents(resource_path('sass/client.scss'));
        $this->assertMatchesRegularExpression('/\.c-trainee-photo-frame \{\s*width: 180px;\s*height: 180px;/u', $clientScss);
        $this->assertStringContainsString("\$weightChartHeight = '180px';", file_get_contents(resource_path('views/client/dashboard.blade.php')));
        // トレーナー側のクラスは会員側に出さない
        $this->assertDoesNotMatchRegularExpression('/\.trainee-show-photo\s*\{/u', $clientScss);
        $this->assertStringNotContainsString('trainee-show-photo', file_get_contents(resource_path('views/client/dashboard.blade.php')));
    }
}
